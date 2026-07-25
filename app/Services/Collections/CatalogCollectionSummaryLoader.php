<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Models\CatalogCollection;
use App\Services\Catalog\CatalogTitleQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use LogicException;

final class CatalogCollectionSummaryLoader
{
    private const MAXIMUM_PAGE_SIZE = 36;

    public function __construct(
        private readonly CatalogTitleQuery $titles,
    ) {}

    /**
     * @param  Builder<CatalogCollection>  $summaryQuery
     * @param  list<int>  $collectionIds
     * @return EloquentCollection<int, CatalogCollection>
     */
    public function hydratePage(Builder $summaryQuery, array $collectionIds): EloquentCollection
    {
        $collectionIds = array_values(array_unique($collectionIds));

        if (collect($collectionIds)->contains(
            fn (int $collectionId): bool => $collectionId < 1,
        )) {
            throw new LogicException('Collection summary IDs must be positive integers.');
        }

        if (count($collectionIds) > self::MAXIMUM_PAGE_SIZE) {
            throw new LogicException('Collection summary page exceeds its bounded size.');
        }

        if ($collectionIds === []) {
            return new EloquentCollection;
        }

        $visibleTitleIds = $this->titles
            ->visibleTo(null)
            ->select('catalog_titles.id');
        $counts = DB::table('catalog_collection_items as items')
            ->leftJoinSub(
                $visibleTitleIds,
                'visible_titles',
                function (JoinClause $join): void {
                    $join->on('visible_titles.id', '=', 'items.catalog_title_id');
                },
            )
            ->select('items.catalog_collection_id')
            ->selectRaw('COUNT(*) AS total_items_count')
            ->selectRaw(
                'SUM(CASE WHEN visible_titles.id IS NULL THEN 0 ELSE 1 END) AS visible_items_count',
            )
            ->whereIn('items.catalog_collection_id', $collectionIds)
            ->groupBy('items.catalog_collection_id');

        return $summaryQuery
            ->leftJoinSub(
                $counts,
                'directory_counts',
                function (JoinClause $join): void {
                    $join->on(
                        'directory_counts.catalog_collection_id',
                        '=',
                        'catalog_collections.id',
                    );
                },
            )
            ->selectRaw('COALESCE(directory_counts.total_items_count, 0) AS total_items_count')
            ->selectRaw('COALESCE(directory_counts.visible_items_count, 0) AS visible_items_count')
            ->whereKey($collectionIds)
            ->get();
    }
}
