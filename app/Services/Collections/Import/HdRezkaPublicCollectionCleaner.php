<?php

declare(strict_types=1);

namespace App\Services\Collections\Import;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSyncStatus;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionSyncRun;
use App\Models\CatalogRecommendationBuild;
use App\Models\CatalogTitleRecommendation;
use App\Models\CatalogTitleRecommendationSignal;
use App\Services\Catalog\CatalogRecommendationDirtyTitleTracker;
use App\Services\Collections\CatalogCollectionCacheInvalidator;
use App\Services\Seasonvar\SeasonvarImportActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class HdRezkaPublicCollectionCleaner
{
    private const PROVIDER = 'hdrezka';

    public function __construct(
        private SeasonvarImportActivity $importActivity,
        private CatalogRecommendationDirtyTitleTracker $dirtyTitles,
        private CatalogCollectionCacheInvalidator $cache,
    ) {}

    /** @return array<string, int> */
    public function inspect(): array
    {
        return $this->scan()['summary'];
    }

    /** @return array<string, int> */
    public function repair(): array
    {
        $scan = $this->scan();
        $summary = $scan['summary'];

        if ($scan['collection_ids'] === []) {
            return [
                ...$summary,
                'source_collections_quarantined' => 0,
                'source_signals_deleted' => 0,
                'source_recommendations_invalidated' => 0,
            ];
        }

        $this->assertSafeToRepair();
        $result = DB::transaction(function () use ($scan): array {
            $collections = $this->candidateQuery()
                ->whereKey($scan['collection_ids'])
                ->orderBy('catalog_collections.id')
                ->lockForUpdate()
                ->get(['catalog_collections.id']);
            $collectionIds = $collections->modelKeys();

            if ($collectionIds === []) {
                return [
                    'collection_ids' => [],
                    'source_collections_quarantined' => 0,
                    'source_signals_deleted' => 0,
                    'source_recommendations_invalidated' => 0,
                ];
            }

            CatalogCollection::query()
                ->whereKey($collectionIds)
                ->update([
                    'visibility' => CatalogCollectionVisibility::Private->value,
                    'moderation_status' => CatalogCollectionModerationStatus::Archived->value,
                    'is_featured' => false,
                    'published_at' => null,
                    'content_version' => DB::raw('content_version + 1'),
                    'updated_at' => now(),
                ]);
            $signals = CatalogTitleRecommendationSignal::query()
                ->where('source', self::PROVIDER)
                ->where('signal_type', 'editorial_collection')
                ->whereIn('signal_key', $scan['source_keys'])
                ->lockForUpdate()
                ->get(['id', 'catalog_title_id']);
            $titleIds = $signals
                ->pluck('catalog_title_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->sort()
                ->values()
                ->all();
            $signalsDeleted = $signals->isEmpty()
                ? 0
                : CatalogTitleRecommendationSignal::query()->whereKey($signals->modelKeys())->delete();
            $recommendationsInvalidated = 0;

            foreach (array_chunk($titleIds, 1_000) as $titleChunk) {
                $recommendationsInvalidated += CatalogTitleRecommendation::query()
                    ->where(function (Builder $query) use ($titleChunk): void {
                        $query
                            ->whereIn('catalog_title_id', $titleChunk)
                            ->orWhereIn('recommended_title_id', $titleChunk);
                    })
                    ->delete();
            }

            if ($titleIds !== []) {
                $this->dirtyTitles->markMany($titleIds, 'collection-public-quality-repair');
            }

            return [
                'collection_ids' => array_map('intval', $collectionIds),
                'source_collections_quarantined' => count($collectionIds),
                'source_signals_deleted' => $signalsDeleted,
                'source_recommendations_invalidated' => $recommendationsInvalidated,
            ];
        }, attempts: 3);

        if ($result['collection_ids'] !== []) {
            $this->cache->changedMany(CatalogCollection::query()
                ->whereKey($result['collection_ids'])
                ->get());
        }

        unset($result['collection_ids']);

        return [...$summary, ...$result];
    }

    /**
     * @return array{
     *     summary: array<string, int>,
     *     collection_ids: list<int>,
     *     source_keys: list<string>
     * }
     */
    private function scan(): array
    {
        $collections = $this->candidateQuery()
            ->with('sourceRecord:id,catalog_collection_id,source_key')
            ->orderBy('catalog_collections.id')
            ->get(['catalog_collections.id']);
        $sourceKeys = $collections
            ->map(fn (CatalogCollection $collection): string => (string) $collection->sourceRecord?->source_key)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $signalQuery = CatalogTitleRecommendationSignal::query()
            ->where('source', self::PROVIDER)
            ->where('signal_type', 'editorial_collection')
            ->whereIn('signal_key', $sourceKeys);

        return [
            'summary' => [
                'source_quarantine_candidates' => $collections->count(),
                'source_signal_rows' => (clone $signalQuery)->count(),
                'source_signal_titles' => (clone $signalQuery)->distinct()->count('catalog_title_id'),
            ],
            'collection_ids' => $collections
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all(),
            'source_keys' => $sourceKeys,
        ];
    }

    /** @return Builder<CatalogCollection> */
    private function candidateQuery(): Builder
    {
        return CatalogCollection::query()
            ->whereNull('owner_id')
            ->whereNull('catalog_collection_category_id')
            ->where('type', CatalogCollectionType::Editorial->value)
            ->where('visibility', CatalogCollectionVisibility::Public->value)
            ->where('moderation_status', CatalogCollectionModerationStatus::Approved->value)
            ->whereNotNull('published_at')
            ->whereHas('sourceRecord', fn (Builder $source): Builder => $source
                ->where('provider', self::PROVIDER));
    }

    private function assertSafeToRepair(): void
    {
        if ($this->importActivity->active()) {
            throw new LogicException('Quarantine source collections запрещён во время активного импорта Seasonvar.');
        }

        if (CatalogCollectionSyncRun::query()
            ->where('status', CatalogCollectionSyncStatus::Running->value)
            ->exists()) {
            throw new LogicException('Quarantine source collections запрещён во время синхронизации source collections.');
        }

        if (CatalogRecommendationBuild::query()->whereIn('status', ['building', 'evaluated'])->exists()) {
            throw new LogicException('Quarantine source collections запрещён при незавершённой сборке рекомендаций.');
        }
    }
}
