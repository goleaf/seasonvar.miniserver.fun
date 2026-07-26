<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionReadinessReason;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollection;
use App\Services\Catalog\CatalogWatchableTitleQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CatalogCollectionPublicationReadiness
{
    public const LOCAL_REQUIRED_ITEMS = 12;

    public const SOURCE_REQUIRED_ITEMS = 4;

    public function __construct(private readonly CatalogWatchableTitleQuery $watchableTitles) {}

    /**
     * @return array{
     *     ready: bool,
     *     visible_items: int,
     *     total_items: int,
     *     unavailable_items: int,
     *     required_items: int,
     *     source_managed: bool,
     *     reason_codes: list<string>
     * }
     */
    public function evaluate(CatalogCollection $collection): array
    {
        return $this->evaluateMany([$collection])[$collection->getKey()];
    }

    /**
     * @param  iterable<CatalogCollection>  $collections
     * @return array<int, array{
     *     ready: bool,
     *     visible_items: int,
     *     total_items: int,
     *     unavailable_items: int,
     *     required_items: int,
     *     source_managed: bool,
     *     reason_codes: list<string>
     * }>
     */
    public function evaluateMany(iterable $collections): array
    {
        $collectionById = collect($collections)
            ->keyBy(fn (CatalogCollection $collection): int => (int) $collection->getKey());
        $ids = $collectionById->keys()->map(static fn (mixed $id): int => (int) $id)->all();

        if ($ids === []) {
            return [];
        }

        $metricsById = $this->metricsQuery($ids)
            ->get()
            ->keyBy(static fn (object $row): int => (int) $row->collection_id);
        $results = [];

        foreach ($collectionById as $id => $collection) {
            $metrics = $metricsById->get((int) $id);
            $collectionMissing = $metrics === null;
            $sourceManaged = ! $collectionMissing && (bool) $metrics->source_managed;
            $sourceMissing = ! $collectionMissing && (bool) $metrics->source_missing;
            $categoryPresent = ! $collectionMissing && (bool) $metrics->category_present;
            $categoryActive = ! $collectionMissing && (bool) $metrics->category_active;
            $totalItems = $collectionMissing ? 0 : (int) $metrics->total_items;
            $visibleItems = $collectionMissing ? 0 : (int) $metrics->visible_items;
            $unavailableItems = max(0, $totalItems - $visibleItems);
            $requiredItems = $sourceManaged
                ? self::SOURCE_REQUIRED_ITEMS
                : self::LOCAL_REQUIRED_ITEMS;
            $reasonCodes = $this->structuralReasonCodes($collection);

            if ($collectionMissing && ! in_array(CatalogCollectionReadinessReason::Deleted->value, $reasonCodes, true)) {
                $reasonCodes[] = CatalogCollectionReadinessReason::Deleted->value;
            }

            if ($sourceMissing) {
                $reasonCodes[] = CatalogCollectionReadinessReason::SourceMissing->value;
            }

            if (! $collectionMissing && ! $categoryPresent) {
                $reasonCodes[] = CatalogCollectionReadinessReason::MissingCategory->value;
            } elseif (! $collectionMissing && ! $categoryActive) {
                $reasonCodes[] = CatalogCollectionReadinessReason::InactiveCategory->value;
            }

            if ($totalItems > $this->maximumPublicItems()) {
                $reasonCodes[] = CatalogCollectionReadinessReason::TooManyItems->value;
            }

            if ($visibleItems < $requiredItems) {
                $reasonCodes[] = CatalogCollectionReadinessReason::InsufficientVisibleItems->value;
            }

            if ($unavailableItems > 0) {
                $reasonCodes[] = CatalogCollectionReadinessReason::UnavailableItems->value;
            }

            $results[(int) $id] = [
                'ready' => $reasonCodes === [],
                'visible_items' => $visibleItems,
                'total_items' => $totalItems,
                'unavailable_items' => $unavailableItems,
                'required_items' => $requiredItems,
                'source_managed' => $sourceManaged,
                'reason_codes' => $reasonCodes,
            ];
        }

        return $results;
    }

    public function eligibleFeaturedCollectionIds(): Builder
    {
        return $this->baseQuery()
            ->select('readiness_collections.id')
            ->where('readiness_collections.type', CatalogCollectionType::Editorial->value)
            ->where('readiness_collections.visibility', CatalogCollectionVisibility::Public->value)
            ->where('readiness_collections.moderation_status', CatalogCollectionModerationStatus::Approved->value)
            ->where('readiness_collections.is_featured', true)
            ->whereNotNull('readiness_collections.published_at')
            ->whereNull('readiness_collections.deleted_at')
            ->whereNull('readiness_sources.missing_since_at')
            ->where('readiness_categories.is_active', true)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('readiness_categories.parent_id')
                ->orWhere('readiness_parent_categories.is_active', true))
            ->groupBy('readiness_collections.id')
            ->havingRaw('COUNT(readiness_items.id) = COUNT(readiness_titles.id)')
            ->havingRaw('COUNT(readiness_items.id) <= ?', [$this->maximumPublicItems()])
            ->havingRaw(
                'COUNT(readiness_titles.id) >= CASE WHEN MAX(CASE WHEN readiness_sources.id IS NULL THEN 0 ELSE 1 END) = 1 THEN ? ELSE ? END',
                [self::SOURCE_REQUIRED_ITEMS, self::LOCAL_REQUIRED_ITEMS],
            );
    }

    /**
     * @param  list<int>  $ids
     */
    private function metricsQuery(array $ids): Builder
    {
        return $this->baseQuery()
            ->selectRaw('readiness_collections.id as collection_id')
            ->selectRaw('COUNT(readiness_items.id) as total_items')
            ->selectRaw('COUNT(readiness_titles.id) as visible_items')
            ->selectRaw(
                'MAX(CASE WHEN readiness_sources.id IS NULL THEN 0 ELSE 1 END) as source_managed',
            )
            ->selectRaw(
                'MAX(CASE WHEN readiness_sources.missing_since_at IS NULL THEN 0 ELSE 1 END) as source_missing',
            )
            ->selectRaw(
                'MAX(CASE WHEN readiness_categories.id IS NULL THEN 0 ELSE 1 END) as category_present',
            )
            ->selectRaw(
                'MAX(CASE WHEN readiness_categories.is_active = 1 AND (readiness_categories.parent_id IS NULL OR readiness_parent_categories.is_active = 1) THEN 1 ELSE 0 END) as category_active',
            )
            ->whereIn('readiness_collections.id', $ids)
            ->groupBy('readiness_collections.id');
    }

    private function baseQuery(): Builder
    {
        $watchableTitles = $this->watchableTitles
            ->visibleTo(null)
            ->select('catalog_titles.id');

        return DB::table('catalog_collections as readiness_collections')
            ->leftJoin(
                'catalog_collection_sources as readiness_sources',
                'readiness_sources.catalog_collection_id',
                '=',
                'readiness_collections.id',
            )
            ->leftJoin(
                'catalog_collection_items as readiness_items',
                'readiness_items.catalog_collection_id',
                '=',
                'readiness_collections.id',
            )
            ->leftJoin(
                'catalog_collection_categories as readiness_categories',
                'readiness_categories.id',
                '=',
                'readiness_collections.catalog_collection_category_id',
            )
            ->leftJoin(
                'catalog_collection_categories as readiness_parent_categories',
                'readiness_parent_categories.id',
                '=',
                'readiness_categories.parent_id',
            )
            ->leftJoinSub(
                $watchableTitles,
                'readiness_titles',
                'readiness_titles.id',
                '=',
                'readiness_items.catalog_title_id',
            );
    }

    /** @return list<string> */
    private function structuralReasonCodes(CatalogCollection $collection): array
    {
        $reasonCodes = [];

        if ($collection->type !== CatalogCollectionType::Editorial) {
            $reasonCodes[] = CatalogCollectionReadinessReason::NotEditorial->value;
        }

        if ($collection->visibility !== CatalogCollectionVisibility::Public) {
            $reasonCodes[] = CatalogCollectionReadinessReason::NotPublic->value;
        }

        if ($collection->moderation_status !== CatalogCollectionModerationStatus::Approved) {
            $reasonCodes[] = CatalogCollectionReadinessReason::NotApproved->value;
        }

        if ($collection->published_at === null) {
            $reasonCodes[] = CatalogCollectionReadinessReason::NotPublished->value;
        }

        if ($collection->trashed()) {
            $reasonCodes[] = CatalogCollectionReadinessReason::Deleted->value;
        }

        return $reasonCodes;
    }

    private function maximumPublicItems(): int
    {
        return max(
            1,
            (int) config('catalog-collections.maximum_public_items_per_collection', 500),
        );
    }
}
