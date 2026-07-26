<?php

declare(strict_types=1);

namespace App\Services\Collections\Quality;

use App\DTOs\CollectionQuality\CatalogCollectionQualityFacts;
use App\DTOs\CollectionQuality\CatalogCollectionQualityResult;
use App\Enums\CatalogCollectionQualityIssueStatus;
use App\Enums\CatalogCollectionQualityRunStatus;
use App\Enums\CatalogCollectionReportStatus;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogCollectionQualityIssue;
use App\Models\CatalogCollectionQualityRun;
use App\Models\CatalogCollectionReport;
use App\Services\Catalog\CatalogWatchableTitleQuery;
use App\Services\Collections\CatalogCollectionCacheInvalidator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CatalogCollectionQualityAssessor
{
    /** @var array<int, array{id: int, hash: string, tokens: array<string, true>}>|null */
    private ?array $cachedTextCorpus = null;

    /** @var array<string, list<int>>|null */
    private ?array $cachedTextBuckets = null;

    /** @var array<string, int>|null */
    private ?array $cachedTextHashCounts = null;

    public function __construct(
        private readonly CatalogCollectionQualityEvaluator $evaluator,
        private readonly CatalogCollectionQualityText $text,
        private readonly CatalogCollectionThemeMatcher $themeMatcher,
        private readonly CatalogCollectionEngagementQuery $engagement,
        private readonly CatalogWatchableTitleQuery $watchableTitles,
        private readonly CatalogCollectionCacheInvalidator $cache,
    ) {}

    /**
     * @return array{
     *     scanned: int,
     *     assessed: int,
     *     issues_opened: int,
     *     issues_resolved: int,
     *     dry_run: bool
     * }
     */
    public function refresh(int $limit, bool $all = false, bool $dryRun = false): array
    {
        $limit = min(500, max(1, $limit));
        $run = $dryRun ? null : CatalogCollectionQualityRun::query()->create([
            'status' => CatalogCollectionQualityRunStatus::Running,
            'started_at' => now(),
        ]);
        $totals = [
            'scanned' => 0,
            'assessed' => 0,
            'issues_opened' => 0,
            'issues_resolved' => 0,
            'dry_run' => $dryRun,
        ];
        $lastId = 0;

        try {
            do {
                $collections = $this->candidates($limit, $all, $lastId)->get();

                if ($collections->isEmpty()) {
                    break;
                }

                $batch = $this->assessBatch($collections, $dryRun);

                foreach (['scanned', 'assessed', 'issues_opened', 'issues_resolved'] as $key) {
                    $totals[$key] += $batch[$key];
                }

                $lastId = (int) $collections->last()->id;
            } while ($all);

            if ($run instanceof CatalogCollectionQualityRun) {
                $run->update([
                    'status' => CatalogCollectionQualityRunStatus::Completed,
                    'scanned_count' => $totals['scanned'],
                    'assessed_count' => $totals['assessed'],
                    'opened_issue_count' => $totals['issues_opened'],
                    'resolved_issue_count' => $totals['issues_resolved'],
                    'completed_at' => now(),
                ]);
            }
        } catch (Throwable $exception) {
            if ($run instanceof CatalogCollectionQualityRun) {
                $run->update([
                    'status' => CatalogCollectionQualityRunStatus::Failed,
                    'error_message' => 'catalog_collection_quality_refresh_failed',
                    'scanned_count' => $totals['scanned'],
                    'assessed_count' => $totals['assessed'],
                    'opened_issue_count' => $totals['issues_opened'],
                    'resolved_issue_count' => $totals['issues_resolved'],
                    'completed_at' => now(),
                ]);
            }

            throw $exception;
        }

        return $totals;
    }

    public function refreshCollection(int $collectionId): bool
    {
        if ($collectionId < 1) {
            return false;
        }

        $collection = $this->candidateQuery()
            ->whereKey($collectionId)
            ->first();

        if (! $collection instanceof CatalogCollection) {
            return false;
        }

        return $this->assessBatch(collect([$collection]), dryRun: false)['assessed'] === 1;
    }

    /** @return Builder<CatalogCollection> */
    private function candidates(int $limit, bool $all, int $lastId): Builder
    {
        return $this->candidateQuery()
            ->when(
                ! $all,
                fn (Builder $query): Builder => $query->where(function (Builder $dirty): void {
                    $dirty
                        ->whereNull('quality_score')
                        ->orWhereNull('quality_content_version')
                        ->orWhereNull('quality_evaluated_at')
                        ->orWhereColumn('quality_content_version', '!=', 'content_version')
                        ->orWhere(
                            'quality_evaluated_at',
                            '<',
                            now()->subDays(max(
                                1,
                                (int) config('catalog-collections.quality.stale_after_days', 14),
                            )),
                        );
                }),
            )
            ->when(
                $all && $lastId > 0,
                fn (Builder $query): Builder => $query->where('id', '>', $lastId),
            )
            ->orderBy('id')
            ->limit($limit);
    }

    /** @return Builder<CatalogCollection> */
    private function candidateQuery(): Builder
    {
        return CatalogCollection::query()
            ->select([
                'id',
                'catalog_collection_category_id',
                'name',
                'description',
                'type',
                'mode',
                'content_version',
                'quality_content_version',
                'quality_evaluated_at',
                'editorially_verified_at',
                'editorially_verified_content_version',
            ])
            ->with([
                'category:id,parent_id,slug,is_active',
                'category.parent:id,slug,is_active',
                'sourceRecord:id,catalog_collection_id,remote_name',
            ]);
    }

    /**
     * @param  Collection<int, CatalogCollection>  $collections
     * @return array{scanned: int, assessed: int, issues_opened: int, issues_resolved: int}
     */
    private function assessBatch(Collection $collections, bool $dryRun): array
    {
        $collectionIds = $collections->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $maximumItems = max(
            1,
            (int) config('catalog-collections.maximum_public_items_per_collection', 500),
        );
        $titleIdsByCollection = $this->titleIdsByCollection($collectionIds);
        $eligibleCollectionIds = array_keys(array_filter(
            $titleIdsByCollection,
            static fn (array $ids): bool => count($ids) <= $maximumItems,
        ));
        $itemsByCollection = $this->themeItems($eligibleCollectionIds);
        $eligibleTitleIds = $itemsByCollection
            ->flatten(1)
            ->pluck('catalog_title_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $watchableTitleIds = $eligibleTitleIds === []
            ? []
            : $this->watchableTitles
                ->visibleTo(null)
                ->whereIntegerInRaw('catalog_titles.id', $eligibleTitleIds)
                ->pluck('catalog_titles.id')
                ->mapWithKeys(static fn (mixed $id): array => [(int) $id => true])
                ->all();
        $engagement = $this->engagement->forCollections($eligibleCollectionIds);
        $reports = $this->reportCounts($collectionIds);
        $textCorpus = $this->textCorpus();
        $textBuckets = $this->textBuckets($textCorpus);
        $textHashCounts = $this->textHashCounts($textCorpus);
        $signaturesByCollection = [];

        foreach ($collections as $collection) {
            $signaturesByCollection[(int) $collection->id] = $this->text->contentSignature(
                $titleIdsByCollection[(int) $collection->id] ?? [],
            );
        }

        $persistedCanonicalBySignature = $this->persistedCanonicalIds(
            array_values(array_unique($signaturesByCollection)),
        );
        $batchCanonicalBySignature = [];
        $changed = [];
        $counters = [
            'scanned' => $collections->count(),
            'assessed' => 0,
            'issues_opened' => 0,
            'issues_resolved' => 0,
        ];

        foreach ($collections as $collection) {
            $titleIds = $titleIdsByCollection[(int) $collection->id] ?? [];
            $signature = $signaturesByCollection[(int) $collection->id];
            $textHash = $this->text->textHash($collection->name, $collection->description);
            $canonicalCandidates = array_filter([
                $persistedCanonicalBySignature[$signature] ?? null,
                $batchCanonicalBySignature[$signature] ?? null,
            ], static fn (?int $id): bool => $id !== null && $id < $collection->id);
            $canonicalId = $canonicalCandidates === []
                ? null
                : min($canonicalCandidates);

            if (! isset($batchCanonicalBySignature[$signature])) {
                $batchCanonicalBySignature[$signature] = (int) $collection->id;
            }

            $similarId = $this->similarCollectionId(
                $collection,
                $textCorpus,
                $textBuckets,
            );
            $repeatedTextCount = $textHashCounts[$textHash] ?? 0;
            $itemEvidence = $this->itemEvidence(
                $collection,
                $itemsByCollection->get((int) $collection->id, collect()),
            );
            $itemCount = count($titleIds);
            $watchableCount = count(array_filter(
                $titleIds,
                static fn (int $id): bool => isset($watchableTitleIds[$id]),
            ));
            $signals = $engagement[(int) $collection->id] ?? [
                'save_count' => 0,
                'completion_count' => 0,
                'return_count' => 0,
            ];
            $result = $this->evaluator->evaluate(CatalogCollectionQualityFacts::fromArray([
                'collection_id' => $collection->id,
                'content_version' => $collection->content_version,
                'name' => $collection->name,
                'description' => $collection->description,
                'category_present' => $collection->category !== null,
                'category_active' => $collection->category !== null
                    && $collection->category->is_active
                    && (
                        $collection->category->parent_id === null
                        || $collection->category->parent?->is_active === true
                    ),
                'item_count' => $itemCount,
                'watchable_item_count' => $watchableCount,
                'average_theme_match' => $itemEvidence['average'],
                'precise_reason_count' => $itemEvidence['precise_count'],
                'report_count' => $reports[(int) $collection->id] ?? 0,
                ...$signals,
                'exact_duplicate_collection_id' => $canonicalId,
                'similar_text_collection_id' => $similarId,
                'repeated_text_count' => $repeatedTextCount,
                'source_managed' => $collection->sourceRecord !== null,
                'editorially_verified_current' => $collection->editorially_verified_at !== null
                    && $collection->editorially_verified_content_version === $collection->content_version,
            ]));

            if ($dryRun) {
                $counters['assessed']++;

                continue;
            }

            $persisted = $this->persist(
                $collection,
                $signature,
                $textHash,
                $result,
                $itemEvidence['items'],
            );
            $counters['assessed'] += $persisted['assessed'];
            $counters['issues_opened'] += $persisted['issues_opened'];
            $counters['issues_resolved'] += $persisted['issues_resolved'];

            if ($persisted['assessed'] === 1) {
                $changed[] = $collection;
            }
        }

        if ($changed !== []) {
            $this->cache->changedMany($changed);
        }

        return $counters;
    }

    /**
     * @param  list<int>  $collectionIds
     * @return array<int, list<int>>
     */
    private function titleIdsByCollection(array $collectionIds): array
    {
        $result = array_fill_keys($collectionIds, []);

        foreach (CatalogCollectionItem::query()
            ->select(['catalog_collection_id', 'catalog_title_id'])
            ->whereIntegerInRaw('catalog_collection_id', $collectionIds)
            ->orderBy('catalog_collection_id')
            ->orderBy('catalog_title_id')
            ->cursor() as $item) {
            $result[(int) $item->catalog_collection_id][] = (int) $item->catalog_title_id;
        }

        return $result;
    }

    /**
     * @param  list<int>  $collectionIds
     * @return Collection<int, Collection<int, CatalogCollectionItem>>
     */
    private function themeItems(array $collectionIds): Collection
    {
        if ($collectionIds === []) {
            return collect();
        }

        return CatalogCollectionItem::query()
            ->select(['id', 'catalog_collection_id', 'catalog_title_id', 'added_by_id', 'position'])
            ->whereIntegerInRaw('catalog_collection_id', $collectionIds)
            ->with([
                'catalogTitle:id,title,description,type',
                'catalogTitle.genres:id,name,slug',
                'catalogTitle.countries:id,name,slug',
                'catalogTitle.networks:id,name,slug',
                'catalogTitle.studios:id,name,slug',
            ])
            ->orderBy('catalog_collection_id')
            ->orderBy('id')
            ->get()
            ->toBase()
            ->groupBy('catalog_collection_id');
    }

    /**
     * @param  list<int>  $collectionIds
     * @return array<int, int>
     */
    private function reportCounts(array $collectionIds): array
    {
        return DB::table((new CatalogCollectionReport)->getTable())
            ->whereIntegerInRaw('catalog_collection_id', $collectionIds)
            ->where('status', '!=', CatalogCollectionReportStatus::Dismissed->value)
            ->select('catalog_collection_id')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('catalog_collection_id')
            ->pluck('aggregate', 'catalog_collection_id')
            ->mapWithKeys(
                static fn (mixed $count, mixed $id): array => [(int) $id => (int) $count],
            )
            ->all();
    }

    /**
     * @return array<int, array{id: int, hash: string, tokens: array<string, true>}>
     */
    private function textCorpus(): array
    {
        return $this->cachedTextCorpus ??= CatalogCollection::query()
            ->select(['id', 'name', 'description'])
            ->orderBy('id')
            ->limit(10_000)
            ->get()
            ->mapWithKeys(function (CatalogCollection $collection): array {
                $id = (int) $collection->id;

                return [$id => [
                    'id' => $id,
                    'hash' => $this->text->textHash(
                        $collection->name,
                        $collection->description,
                    ),
                    'tokens' => $this->text->tokens(
                        trim($collection->name.' '.(string) $collection->description),
                    ),
                ]];
            })
            ->all();
    }

    /**
     * @param  list<string>  $signatures
     * @return array<string, int>
     */
    private function persistedCanonicalIds(array $signatures): array
    {
        if ($signatures === []) {
            return [];
        }

        $canonical = [];

        foreach (CatalogCollection::query()
            ->select(['id', 'content_signature'])
            ->whereIn('content_signature', $signatures)
            ->whereColumn('quality_content_version', 'content_version')
            ->orderBy('id')
            ->cursor() as $collection) {
            $signature = (string) $collection->content_signature;
            $canonical[$signature] ??= (int) $collection->id;
        }

        return $canonical;
    }

    /**
     * @param  array<int, array{id: int, hash: string, tokens: array<string, true>}>  $corpus
     * @param  array<string, list<int>>  $buckets
     */
    private function similarCollectionId(
        CatalogCollection $collection,
        array $corpus,
        array $buckets,
    ): ?int {
        $threshold = min(
            1.0,
            max(0.50, (float) config('catalog-collections.quality.similarity_threshold', 0.80)),
        );
        $tokens = $this->text->tokens(
            trim($collection->name.' '.(string) $collection->description),
        );
        $candidateIds = [];

        foreach (array_keys($tokens) as $token) {
            foreach ($buckets[$token] ?? [] as $candidateId) {
                if ($candidateId < $collection->id) {
                    $candidateIds[$candidateId] = true;
                }
            }
        }

        ksort($candidateIds, SORT_NUMERIC);

        foreach (array_keys($candidateIds) as $candidateId) {
            $candidate = $corpus[$candidateId];
            $smallestTokenCount = min(count($tokens), count($candidate['tokens']));
            $largestTokenCount = max(count($tokens), count($candidate['tokens']));

            if ($largestTokenCount === 0
                || $smallestTokenCount / $largestTokenCount < $threshold) {
                continue;
            }

            if ($this->text->similarityTokenSets(
                $tokens,
                $candidate['tokens'],
            ) >= $threshold) {
                return $candidate['id'];
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{id: int, hash: string, tokens: array<string, true>}>  $corpus
     * @return array<string, list<int>>
     */
    private function textBuckets(array $corpus): array
    {
        if ($this->cachedTextBuckets !== null) {
            return $this->cachedTextBuckets;
        }

        $buckets = [];

        foreach ($corpus as $candidate) {
            foreach (array_keys($candidate['tokens']) as $token) {
                $buckets[$token][] = $candidate['id'];
            }
        }

        return $this->cachedTextBuckets = $buckets;
    }

    /**
     * @param  array<int, array{id: int, hash: string, tokens: array<string, true>}>  $corpus
     * @return array<string, int>
     */
    private function textHashCounts(array $corpus): array
    {
        if ($this->cachedTextHashCounts !== null) {
            return $this->cachedTextHashCounts;
        }

        $counts = [];

        foreach ($corpus as $candidate) {
            $counts[$candidate['hash']] = ($counts[$candidate['hash']] ?? 0) + 1;
        }

        return $this->cachedTextHashCounts = $counts;
    }

    /**
     * @param  Collection<int, CatalogCollectionItem>  $items
     * @return array{
     *     average: int,
     *     precise_count: int,
     *     items: list<array{
     *         id: int,
     *         catalog_collection_id: int,
     *         catalog_title_id: int,
     *         added_by_id: int|null,
     *         position: int,
     *         theme_match_percent: int,
     *         inclusion_reason_code: string,
     *         quality_content_version: int,
     *         updated_at: mixed
     *     }>
     * }
     */
    private function itemEvidence(CatalogCollection $collection, Collection $items): array
    {
        $evidence = [];
        $total = 0;
        $preciseCount = 0;

        foreach ($items as $item) {
            if ($item->catalogTitle === null) {
                continue;
            }

            $match = $this->themeMatcher->match($collection, $item->catalogTitle);
            $total += $match->percent;
            $preciseCount += $match->reason->isPrecise() ? 1 : 0;
            $evidence[] = [
                'id' => (int) $item->id,
                'catalog_collection_id' => (int) $item->catalog_collection_id,
                'catalog_title_id' => (int) $item->catalog_title_id,
                'added_by_id' => $item->added_by_id !== null ? (int) $item->added_by_id : null,
                'position' => (int) $item->position,
                'theme_match_percent' => $match->percent,
                'inclusion_reason_code' => $match->reason->value,
                'quality_content_version' => (int) $collection->content_version,
                'updated_at' => now(),
            ];
        }

        return [
            'average' => $items->isEmpty() ? 0 : (int) round($total / $items->count()),
            'precise_count' => $preciseCount,
            'items' => $evidence,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $itemEvidence
     * @return array{assessed: int, issues_opened: int, issues_resolved: int}
     */
    private function persist(
        CatalogCollection $collection,
        string $signature,
        string $textHash,
        CatalogCollectionQualityResult $result,
        array $itemEvidence,
    ): array {
        return DB::transaction(function () use (
            $collection,
            $signature,
            $textHash,
            $result,
            $itemEvidence,
        ): array {
            $locked = CatalogCollection::query()->lockForUpdate()->find($collection->id);

            if (! $locked instanceof CatalogCollection
                || $locked->content_version !== $collection->content_version) {
                return ['assessed' => 0, 'issues_opened' => 0, 'issues_resolved' => 0];
            }

            if ($itemEvidence !== []) {
                CatalogCollectionItem::query()->upsert(
                    $itemEvidence,
                    ['id'],
                    [
                        'theme_match_percent',
                        'inclusion_reason_code',
                        'quality_content_version',
                        'updated_at',
                    ],
                );
            }

            CatalogCollection::withoutTimestamps(function () use (
                $locked,
                $result,
                $signature,
                $textHash,
            ): void {
                $locked->forceFill([
                    'quality_score' => $result->score,
                    'quality_content_version' => $locked->content_version,
                    'quality_evaluated_at' => now(),
                    'content_signature' => $signature,
                    'normalized_text_hash' => $textHash,
                    'quality_details' => [
                        'components' => $result->components,
                        ...$result->details,
                    ],
                ])->save();
            });

            $issueCounters = $this->reconcileIssues($locked, $result);

            return ['assessed' => 1, ...$issueCounters];
        }, 3);
    }

    /** @return array{issues_opened: int, issues_resolved: int} */
    private function reconcileIssues(
        CatalogCollection $collection,
        CatalogCollectionQualityResult $result,
    ): array {
        $activeFingerprints = [];
        $opened = 0;

        foreach ($result->issues as $issue) {
            $relatedId = isset($issue->evidence['related_collection_id'])
                ? (int) $issue->evidence['related_collection_id']
                : null;
            $fingerprint = hash(
                'sha256',
                implode('|', [$collection->id, $issue->code, $relatedId ?? '']),
            );
            $activeFingerprints[] = $fingerprint;
            $model = CatalogCollectionQualityIssue::query()->firstOrNew([
                'fingerprint' => $fingerprint,
            ]);
            $wasOpen = $model->exists
                && $model->status === CatalogCollectionQualityIssueStatus::Open;

            $model->fill([
                'catalog_collection_id' => $collection->id,
                'related_catalog_collection_id' => $relatedId,
                'code' => $issue->code,
                'severity' => $issue->severity,
                'status' => CatalogCollectionQualityIssueStatus::Open,
                'evidence' => $issue->evidence,
                'first_detected_at' => $model->first_detected_at ?? now(),
                'last_detected_at' => now(),
                'resolved_at' => null,
            ])->save();

            $opened += $wasOpen ? 0 : 1;
        }

        $resolve = CatalogCollectionQualityIssue::query()
            ->whereBelongsTo($collection, 'collection')
            ->where('status', CatalogCollectionQualityIssueStatus::Open->value)
            ->when(
                $activeFingerprints !== [],
                fn (Builder $query): Builder => $query->whereNotIn('fingerprint', $activeFingerprints),
            );
        $resolved = $resolve->count();

        if ($resolved > 0) {
            $resolve->update([
                'status' => CatalogCollectionQualityIssueStatus::Resolved->value,
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return ['issues_opened' => $opened, 'issues_resolved' => $resolved];
    }
}
