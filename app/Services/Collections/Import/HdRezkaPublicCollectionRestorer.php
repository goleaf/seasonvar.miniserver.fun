<?php

declare(strict_types=1);

namespace App\Services\Collections\Import;

use App\Enums\CatalogCollectionMode;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSyncStatus;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogCollectionSource;
use App\Models\CatalogCollectionSyncRun;
use App\Models\CatalogRecommendationBuild;
use App\Services\Collections\CatalogCollectionCacheInvalidator;
use App\Services\Collections\CatalogCollectionSchema;
use App\Services\Collections\Quality\CatalogCollectionQualityAssessor;
use App\Services\Seasonvar\SeasonvarImportActivity;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class HdRezkaPublicCollectionRestorer
{
    private const PROVIDER = 'hdrezka';

    /** @var array<string, string> */
    private const REVIEWED_CATEGORY_BY_SOURCE_KEY = [
        '9a9356bc09b43aaddf98917f48d675ae004777da5769deef60a12456f34cec09' => 'new-and-premieres',
        'f649faa975cd16579a2169bfc7c07746d6394bfa6f94e35636ed8278ed2c9965' => 'animation-and-anime',
        '5d711ad5dc05d5745f51e8426b138d7a32cedcd9139e62eb6099b4689aeb8084' => 'netflix',
        'cebb994667363f919eed224b034bb53d172518df6e44038bfc4ebaced66cce80' => 'amazon',
        '12e9fe670d2581e6279561ddec7ee00d0a860ee0c4265973b1d12faeee9284a1' => 'apple-tv-plus',
        '56a95e80ee35465bc2b0fd4be91edba955056b8e2a13c1955bda4c47562ca7ef' => 'disney-plus',
        '4c1f446d603d670ad0c9007de71a7aa92600b383cc0d984ca17dfa4ad5707863' => 'hbo-and-max',
        '672f1d2bff0740e49e7beddf966f689018fe31e6c7234c9af15639ceebb609f9' => 'other-platforms',
        '3c610372f56a8d421fd38c7a4f9217962e539e53b159319aa830b96fe734174a' => 'tense-stories',
        '8b7982ee8d68818128ffae593139a7527a85f56f2755fb4e899bbab4b9d49da4' => 'animation-and-anime',
    ];

    public function __construct(
        private SeasonvarImportActivity $importActivity,
        private CatalogCollectionCacheInvalidator $cache,
        private CatalogCollectionSchema $schema,
        private CatalogCollectionQualityAssessor $quality,
    ) {}

    /** @return array<string, int> */
    public function inspect(): array
    {
        return $this->scan()['summary'];
    }

    /**
     * @return array{
     *     before: array<string, int>,
     *     after: array<string, int>,
     *     counters: array{records_restored: int, quality_refreshed: int}
     * }
     */
    public function repair(): array
    {
        if (! $this->schema->qualityAvailable()) {
            throw new LogicException('Для восстановления исходных подборок нужна развёрнутая схема качества.');
        }

        $this->assertSafeToRepair();
        $before = $this->inspect();
        $mutation = DB::transaction(function (): array {
            $scan = $this->scan(lockForUpdate: true);
            $changed = [];
            $qualityRefreshIds = [];

            foreach ($scan['restorable'] as $candidate) {
                $collection = $candidate['collection'];
                $category = $candidate['category'];
                $attributesChanged = $collection->catalog_collection_category_id !== $category->id
                    || $collection->visibility !== CatalogCollectionVisibility::Public
                    || $collection->moderation_status !== CatalogCollectionModerationStatus::Approved
                    || $collection->published_at === null
                    || $collection->is_featured;

                if ($attributesChanged) {
                    $collection->forceFill([
                        'catalog_collection_category_id' => $category->id,
                        'visibility' => CatalogCollectionVisibility::Public,
                        'moderation_status' => CatalogCollectionModerationStatus::Approved,
                        'is_featured' => false,
                        'published_at' => $collection->published_at ?? now(),
                        'content_version' => max(1, $collection->content_version) + 1,
                        'quality_content_version' => 0,
                        'quality_evaluated_at' => null,
                        'editorially_verified_at' => null,
                        'editorially_verified_by_id' => null,
                        'editorially_verified_content_version' => null,
                    ])->save();
                    $changed[] = $collection;
                }

                $qualityRefreshIds[] = (int) $collection->id;
            }

            return [
                'changed' => new EloquentCollection($changed),
                'quality_refresh_ids' => array_values(array_unique($qualityRefreshIds)),
            ];
        }, attempts: 3);

        /** @var EloquentCollection<int, CatalogCollection> $changed */
        $changed = $mutation['changed'];

        if ($changed->isNotEmpty()) {
            $this->cache->changedMany($changed);
        }

        $qualityRefreshed = 0;

        foreach ($mutation['quality_refresh_ids'] as $collectionId) {
            if (! $this->quality->refreshCollection($collectionId)) {
                throw new LogicException('Не удалось пересчитать качество восстановленной подборки.');
            }

            $qualityRefreshed++;
        }

        return [
            'before' => $before,
            'after' => $this->inspect(),
            'counters' => [
                'records_restored' => $changed->count(),
                'quality_refreshed' => $qualityRefreshed,
            ],
        ];
    }

    /**
     * @return array{
     *     summary: array<string, int>,
     *     restorable: list<array{
     *         collection: CatalogCollection,
     *         category: CatalogCollectionCategory
     *     }>
     * }
     */
    private function scan(bool $lockForUpdate = false): array
    {
        $sourceQuery = CatalogCollectionSource::query()
            ->select([
                'id',
                'provider',
                'source_key',
                'catalog_collection_id',
                'missing_since_at',
            ])
            ->where('provider', self::PROVIDER)
            ->whereIn('source_key', array_keys(self::REVIEWED_CATEGORY_BY_SOURCE_KEY))
            ->orderBy('source_key');

        if ($lockForUpdate) {
            $sourceQuery->lockForUpdate();
        }

        $sources = $sourceQuery->get()->keyBy('source_key');
        $collectionIds = $sources
            ->pluck('catalog_collection_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $collectionQuery = CatalogCollection::query()
            ->withTrashed()
            ->select([
                'id',
                'owner_id',
                'catalog_collection_category_id',
                'type',
                'mode',
                'visibility',
                'moderation_status',
                'is_featured',
                'content_version',
                'published_at',
                'deleted_at',
            ])
            ->whereKey($collectionIds)
            ->orderBy('id');

        if ($lockForUpdate) {
            $collectionQuery->lockForUpdate();
        }

        $collections = $collectionQuery->get()->keyBy('id');
        $categorySlugs = array_values(array_unique(self::REVIEWED_CATEGORY_BY_SOURCE_KEY));
        $categoryQuery = CatalogCollectionCategory::query()
            ->select(['id', 'parent_id', 'slug', 'is_active'])
            ->whereIn('slug', $categorySlugs)
            ->orderBy('id');

        if ($lockForUpdate) {
            $categoryQuery->lockForUpdate();
        }

        $categories = $categoryQuery->get()->keyBy('slug');
        $parentIds = $categories
            ->pluck('parent_id')
            ->filter()
            ->unique()
            ->values();
        $parentQuery = CatalogCollectionCategory::query()
            ->select(['id', 'is_active'])
            ->whereKey($parentIds)
            ->orderBy('id');

        if ($lockForUpdate) {
            $parentQuery->lockForUpdate();
        }

        $parents = $parentQuery->get()->keyBy('id');
        $itemCounts = $collectionIds === []
            ? collect()
            : CatalogCollectionItem::query()
                ->select('catalog_collection_id')
                ->selectRaw('COUNT(*) AS aggregate')
                ->whereIntegerInRaw('catalog_collection_id', $collectionIds)
                ->groupBy('catalog_collection_id')
                ->pluck('aggregate', 'catalog_collection_id');
        $publiclyListedIds = $collectionIds === []
            ? []
            : CatalogCollection::query()
                ->publiclyListed()
                ->whereIntegerInRaw('catalog_collections.id', $collectionIds)
                ->pluck('catalog_collections.id')
                ->mapWithKeys(static fn (mixed $id): array => [(int) $id => true])
                ->all();
        $maximumItems = max(
            1,
            (int) config('catalog-collections.maximum_public_items_per_collection', 500),
        );
        $summary = [
            'reviewed_source_keys' => count(self::REVIEWED_CATEGORY_BY_SOURCE_KEY),
            'matched_records' => $sources->count(),
            'missing_records' => count(self::REVIEWED_CATEGORY_BY_SOURCE_KEY) - $sources->count(),
            'restorable_records' => 0,
            'already_restored_records' => 0,
            'category_conflicts' => 0,
            'ineligible_records' => 0,
            'publicly_listed_records' => count($publiclyListedIds),
        ];
        $restorable = [];

        foreach (self::REVIEWED_CATEGORY_BY_SOURCE_KEY as $sourceKey => $categorySlug) {
            $source = $sources->get($sourceKey);

            if (! $source instanceof CatalogCollectionSource) {
                continue;
            }

            $collection = $collections->get((int) $source->catalog_collection_id);
            $category = $categories->get($categorySlug);

            if (! $collection instanceof CatalogCollection
                || ! $category instanceof CatalogCollectionCategory
                || ! $category->is_active
                || ($category->parent_id !== null
                    && $parents->get($category->parent_id)?->is_active !== true)
                || $collection->deleted_at !== null
                || $collection->owner_id !== null
                || $collection->type !== CatalogCollectionType::Editorial
                || $collection->mode !== CatalogCollectionMode::Manual
                || $source->missing_since_at !== null
                || (int) ($itemCounts[$collection->id] ?? 0) < 1
                || (int) ($itemCounts[$collection->id] ?? 0) > $maximumItems) {
                $summary['ineligible_records']++;

                continue;
            }

            if ($collection->catalog_collection_category_id !== null
                && $collection->catalog_collection_category_id !== $category->id) {
                $summary['category_conflicts']++;

                continue;
            }

            if (isset($publiclyListedIds[(int) $collection->id])
                && $collection->catalog_collection_category_id === $category->id) {
                $summary['already_restored_records']++;

                continue;
            }

            $summary['restorable_records']++;
            $restorable[] = compact('collection', 'category');
        }

        return compact('summary', 'restorable');
    }

    private function assertSafeToRepair(): void
    {
        if ($this->importActivity->active()) {
            throw new LogicException('Восстановление исходных подборок запрещено во время активного импорта Seasonvar.');
        }

        if (CatalogCollectionSyncRun::query()
            ->where('status', CatalogCollectionSyncStatus::Running->value)
            ->exists()) {
            throw new LogicException('Восстановление исходных подборок запрещено во время их синхронизации.');
        }

        if (CatalogRecommendationBuild::query()->whereIn('status', ['building', 'evaluated'])->exists()) {
            throw new LogicException('Восстановление исходных подборок запрещено при незавершённой сборке рекомендаций.');
        }
    }
}
