<?php

declare(strict_types=1);

namespace App\Services\DemoData;

use App\DTOs\DemoData\DemoDataOptions;
use App\Enums\TagModerationStatus;
use App\Enums\TagSource;
use App\Enums\TagType;
use App\Enums\TagVisibility;
use App\Models\CatalogRecommendationBuild;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\CatalogTitleTagSource;
use App\Models\Tag;
use App\Services\Catalog\CatalogRecommendationDirtyTitleTracker;
use App\Services\Seasonvar\SeasonvarImportActivity;
use App\Services\Tags\TagCacheInvalidator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class DemoPublicTagAssignmentCleaner
{
    private const LEGACY_USER_INDEX = 1;

    private const MINIMUM_MATCH_BASIS_POINTS = 9_000;

    public function __construct(
        private DemoStableValue $stable,
        private SeasonvarImportActivity $importActivity,
        private CatalogRecommendationDirtyTitleTracker $dirtyTitles,
        private TagCacheInvalidator $tagCache,
    ) {}

    /** @return array<string, int> */
    public function inspect(DemoDataOptions $options): array
    {
        return $this->scan($options)['summary'];
    }

    /** @return array<string, int> */
    public function repair(DemoDataOptions $options): array
    {
        $scan = $this->scan($options);
        $summary = $scan['summary'];

        if ($summary['cleanup_candidates'] === 0 && $summary['archivable_demo_tags'] === 0) {
            return [
                ...$summary,
                'removed_assignments' => 0,
                'archived_demo_tags' => 0,
                'invalidated_recommendations' => 0,
            ];
        }

        $this->assertSafeToRepair($summary);

        $result = DB::transaction(function () use ($scan): array {
            $removedAssignments = $this->deleteAssignments($scan['removals_by_tag']);
            $archivedDemoTags = $this->archiveOwnedDemoTags(
                $scan['archivable_demo_tag_ids'],
                $scan['owned_demo_tag_prefix'],
            );
            $affectedTitleIds = $scan['affected_title_ids'];
            $invalidatedRecommendations = $this->invalidateRecommendations($affectedTitleIds);

            if ($affectedTitleIds !== []) {
                $this->dirtyTitles->markMany($affectedTitleIds, 'demo-tag-quality-repair');
            }

            if ($removedAssignments > 0 || $archivedDemoTags > 0) {
                $this->tagCache->publicChanged();
            }

            return [
                'removed_assignments' => $removedAssignments,
                'archived_demo_tags' => $archivedDemoTags,
                'invalidated_recommendations' => $invalidatedRecommendations,
            ];
        }, attempts: 3);

        return [...$summary, ...$result];
    }

    /**
     * @return array{
     *     summary: array<string, int>,
     *     removals_by_tag: array<int, list<int>>,
     *     affected_title_ids: list<int>,
     *     archivable_demo_tag_ids: list<int>,
     *     owned_demo_tag_prefix: string
     * }
     */
    private function scan(DemoDataOptions $options): array
    {
        $tagIds = Tag::query()
            ->orderBy('id')
            ->limit($options->publicTagTarget)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $ownedDemoTagIds = $this->ownedDemoTagIds($options);
        $removalsByTag = [];
        $selectedTitleCount = 0;
        $expectedPairCount = 0;
        $attachedExpectedPairCount = 0;
        $protectedCurrentAssignments = 0;
        $pivotTable = $this->pivotTable();
        $sourceTable = (new CatalogTitleTagSource)->getTable();
        $selector = new DemoTitleSelector($options);

        if ($tagIds !== []) {
            foreach ($selector->selectedIds(self::LEGACY_USER_INDEX)->chunk($options->chunkSize) as $titleChunk) {
                $titleIds = $titleChunk->values()->all();
                $selectedTitleCount += count($titleIds);
                $expected = $this->expectedPairs($titleIds, $tagIds);
                $expectedPairCount += count($expected);
                $attached = DB::table($pivotTable)
                    ->whereIn('catalog_title_id', $titleIds)
                    ->whereIn('tag_id', $tagIds)
                    ->get(['catalog_title_id', 'tag_id']);
                $current = DB::table($sourceTable)
                    ->whereIn('catalog_title_id', $titleIds)
                    ->whereIn('tag_id', $tagIds)
                    ->where('is_current', true)
                    ->get(['catalog_title_id', 'tag_id'])
                    ->mapWithKeys(fn (object $row): array => [
                        $this->pairKey((int) $row->catalog_title_id, (int) $row->tag_id) => true,
                    ]);

                foreach ($attached as $row) {
                    $titleId = (int) $row->catalog_title_id;
                    $tagId = (int) $row->tag_id;
                    $key = $this->pairKey($titleId, $tagId);

                    if (! isset($expected[$key])) {
                        continue;
                    }

                    $attachedExpectedPairCount++;

                    if ($current->has($key)) {
                        $protectedCurrentAssignments++;

                        continue;
                    }

                    $removalsByTag[$tagId][$titleId] = true;
                }
            }
        }

        $ownedDemoAssignmentCandidates = 0;

        if ($ownedDemoTagIds !== []) {
            $currentOwnedPairs = DB::table($sourceTable)
                ->whereIn('tag_id', $ownedDemoTagIds)
                ->where('is_current', true)
                ->get(['catalog_title_id', 'tag_id'])
                ->mapWithKeys(fn (object $row): array => [
                    $this->pairKey((int) $row->catalog_title_id, (int) $row->tag_id) => true,
                ]);

            DB::table($pivotTable)
                ->whereIn('tag_id', $ownedDemoTagIds)
                ->orderBy('catalog_title_id')
                ->orderBy('tag_id')
                ->chunk(1_000, function ($assignments) use (&$ownedDemoAssignmentCandidates, &$removalsByTag, $currentOwnedPairs): void {
                    foreach ($assignments as $assignment) {
                        $titleId = (int) $assignment->catalog_title_id;
                        $tagId = (int) $assignment->tag_id;

                        if ($currentOwnedPairs->has($this->pairKey($titleId, $tagId))) {
                            continue;
                        }

                        $ownedDemoAssignmentCandidates++;
                        $removalsByTag[$tagId][$titleId] = true;
                    }
                });
        }

        $removalsByTag = collect($removalsByTag)
            ->map(fn (array $titleIds): array => collect(array_keys($titleIds))
                ->map(static fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all())
            ->all();
        $affectedTitleIds = collect($removalsByTag)
            ->flatten()
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $cleanupCandidates = array_sum(array_map('count', $removalsByTag));
        $archivableDemoTagIds = $this->archivableOwnedDemoTagIds($ownedDemoTagIds);
        $matchBasisPoints = $expectedPairCount > 0
            ? intdiv($attachedExpectedPairCount * 10_000, $expectedPairCount)
            : 0;

        return [
            'summary' => [
                'legacy_tag_pool_size' => count($tagIds),
                'selected_titles' => $selectedTitleCount,
                'expected_pairs' => $expectedPairCount,
                'attached_expected_pairs' => $attachedExpectedPairCount,
                'protected_current_assignments' => $protectedCurrentAssignments,
                'owned_demo_tags' => count($ownedDemoTagIds),
                'owned_demo_assignment_candidates' => $ownedDemoAssignmentCandidates,
                'cleanup_candidates' => $cleanupCandidates,
                'orphaned_demo_public_tag_assignments' => $cleanupCandidates,
                'archivable_demo_tags' => count($archivableDemoTagIds),
                'affected_titles' => count($affectedTitleIds),
                'match_basis_points' => $matchBasisPoints,
            ],
            'removals_by_tag' => $removalsByTag,
            'affected_title_ids' => $affectedTitleIds,
            'archivable_demo_tag_ids' => $archivableDemoTagIds,
            'owned_demo_tag_prefix' => $this->ownedDemoTagPrefix($options),
        ];
    }

    /**
     * @param  list<int>  $titleIds
     * @param  list<int>  $tagIds
     * @return array<string, true>
     */
    private function expectedPairs(array $titleIds, array $tagIds): array
    {
        $pairs = [];
        $minimum = min(3, count($tagIds));
        $maximum = min(12, count($tagIds));

        foreach ($titleIds as $titleId) {
            $count = min(count($tagIds), $this->stable->integer(
                "organization:title:{$titleId}:public-tag-count",
                $minimum,
                $maximum,
            ));
            $offset = $this->stable->integer(
                "organization:title:{$titleId}:public-tag-offset",
                0,
                count($tagIds) - 1,
            );

            for ($ordinal = 0; $ordinal < $count; $ordinal++) {
                $tagId = $tagIds[($offset + $ordinal) % count($tagIds)];
                $pairs[$this->pairKey($titleId, $tagId)] = true;
            }
        }

        return $pairs;
    }

    /** @return list<int> */
    private function ownedDemoTagIds(DemoDataOptions $options): array
    {
        $prefix = $this->ownedDemoTagPrefix($options);

        return Tag::query()
            ->where('code', 'like', $prefix.'%')
            ->where('type', TagType::System->value)
            ->where('source', TagSource::System->value)
            ->orderBy('id')
            ->get(['id', 'public_id', 'code'])
            ->filter(function (Tag $tag) use ($prefix): bool {
                $code = (string) $tag->code;
                $suffix = substr($code, strlen($prefix));

                if (! ctype_digit($suffix)) {
                    return false;
                }

                $ordinal = (int) $suffix;

                return $ordinal > 0
                    && $code === $prefix.$ordinal
                    && (string) $tag->public_id === $this->stable->uuid(
                        'organization:public-tag:'.($ordinal - 1),
                    );
            })
            ->map(static fn (Tag $tag): int => (int) $tag->id)
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $ownedDemoTagIds
     * @return list<int>
     */
    private function archivableOwnedDemoTagIds(array $ownedDemoTagIds): array
    {
        if ($ownedDemoTagIds === []) {
            return [];
        }

        $sourceTable = (new CatalogTitleTagSource)->getTable();

        return Tag::query()
            ->whereKey($ownedDemoTagIds)
            ->whereNull('archived_at')
            ->whereNotExists(function (Builder $query) use ($sourceTable): void {
                $query
                    ->selectRaw('1')
                    ->from($sourceTable.' as current_tag_sources')
                    ->whereColumn('current_tag_sources.tag_id', 'tags.id')
                    ->where('current_tag_sources.is_current', true);
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @param array<string, int> $summary */
    private function assertSafeToRepair(array $summary): void
    {
        if ($this->importActivity->active()) {
            throw new LogicException('Очистка demo-тегов запрещена во время активного импорта Seasonvar.');
        }

        if (CatalogRecommendationBuild::query()->whereIn('status', ['building', 'evaluated'])->exists()) {
            throw new LogicException('Очистка demo-тегов запрещена при незавершённой сборке рекомендаций.');
        }

        if ($summary['owned_demo_tags'] < 1) {
            throw new LogicException('Не найден exact fingerprint прежнего demo public-tag набора; очистка остановлена.');
        }

        if ($summary['expected_pairs'] < 1
            || $summary['legacy_tag_pool_size'] < 1
            || $summary['match_basis_points'] < self::MINIMUM_MATCH_BASIS_POINTS) {
            throw new LogicException('Historical demo public-tag footprint не подтверждён с достаточной точностью; очистка остановлена.');
        }
    }

    /**
     * @param  array<int, list<int>>  $removalsByTag
     */
    private function deleteAssignments(array $removalsByTag): int
    {
        $deleted = 0;
        $pivotTable = $this->pivotTable();
        $sourceTable = (new CatalogTitleTagSource)->getTable();

        foreach ($removalsByTag as $tagId => $titleIds) {
            foreach (array_chunk($titleIds, 1_000) as $titleChunk) {
                $deleted += DB::table($pivotTable)
                    ->where('tag_id', $tagId)
                    ->whereIn('catalog_title_id', $titleChunk)
                    ->whereNotExists(function (Builder $query) use ($pivotTable, $sourceTable): void {
                        $query
                            ->selectRaw('1')
                            ->from($sourceTable.' as current_tag_sources')
                            ->whereColumn('current_tag_sources.catalog_title_id', $pivotTable.'.catalog_title_id')
                            ->whereColumn('current_tag_sources.tag_id', $pivotTable.'.tag_id')
                            ->where('current_tag_sources.is_current', true);
                    })
                    ->delete();
            }
        }

        return $deleted;
    }

    /** @param list<int> $tagIds */
    private function archiveOwnedDemoTags(array $tagIds, string $prefix): int
    {
        if ($tagIds === []) {
            return 0;
        }

        $sourceTable = (new CatalogTitleTagSource)->getTable();

        return Tag::query()
            ->whereKey($tagIds)
            ->where('code', 'like', $prefix.'%')
            ->where('type', TagType::System->value)
            ->where('source', TagSource::System->value)
            ->whereNull('archived_at')
            ->whereNotExists(function (Builder $query) use ($sourceTable): void {
                $query
                    ->selectRaw('1')
                    ->from($sourceTable.' as current_tag_sources')
                    ->whereColumn('current_tag_sources.tag_id', 'tags.id')
                    ->where('current_tag_sources.is_current', true);
            })
            ->update([
                'archived_from_visibility' => DB::raw('visibility'),
                'archived_from_moderation_status' => DB::raw('moderation_status'),
                'visibility' => TagVisibility::Internal->value,
                'moderation_status' => TagModerationStatus::Archived->value,
                'archived_at' => now(),
                'content_version' => DB::raw('content_version + 1'),
                'updated_at' => now(),
            ]);
    }

    /** @param list<int> $titleIds */
    private function invalidateRecommendations(array $titleIds): int
    {
        $deleted = 0;

        foreach (array_chunk($titleIds, 1_000) as $titleChunk) {
            $deleted += CatalogTitleRecommendation::query()
                ->where(function ($query) use ($titleChunk): void {
                    $query
                        ->whereIn('catalog_title_id', $titleChunk)
                        ->orWhereIn('recommended_title_id', $titleChunk);
                })
                ->delete();
        }

        return $deleted;
    }

    private function pivotTable(): string
    {
        return (new CatalogTitle)->tags()->getTable();
    }

    private function pairKey(int $titleId, int $tagId): string
    {
        return $titleId.':'.$tagId;
    }

    private function ownedDemoTagPrefix(DemoDataOptions $options): string
    {
        return 'demo-tag-'.substr(hash('sha256', $options->version), 0, 12).'-';
    }
}
