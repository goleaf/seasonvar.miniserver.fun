<?php

declare(strict_types=1);

namespace App\Services\Collections\Quality;

use App\Enums\CatalogWatchStatus;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogTitleUserState;
use App\Models\EpisodeViewProgress;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CatalogCollectionEngagementQuery
{
    /**
     * @param  list<int>  $collectionIds
     * @return array<int, array{
     *     save_count: int,
     *     completion_count: int,
     *     return_count: int
     * }>
     */
    public function forCollections(array $collectionIds): array
    {
        $collectionIds = array_values(array_unique(array_filter(
            array_map('intval', $collectionIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($collectionIds === []) {
            return [];
        }

        $result = array_fill_keys($collectionIds, [
            'save_count' => 0,
            'completion_count' => 0,
            'return_count' => 0,
        ]);

        $result = $this->mergeCounts(
            $result,
            'save_count',
            $this->savePairs($collectionIds),
        );
        $result = $this->mergeCounts(
            $result,
            'completion_count',
            $this->completionPairs($collectionIds),
        );
        $result = $this->mergeCounts(
            $result,
            'return_count',
            $this->returnPairs($collectionIds),
        );

        return $result;
    }

    /** @param list<int> $collectionIds */
    private function savePairs(array $collectionIds): Builder
    {
        $items = (new CatalogCollectionItem)->getTable();
        $states = (new CatalogTitleUserState)->getTable();

        return DB::table($items)
            ->join($states, "{$states}.catalog_title_id", '=', "{$items}.catalog_title_id")
            ->whereIn("{$items}.catalog_collection_id", $collectionIds)
            ->where("{$states}.in_watchlist", true)
            ->selectRaw(
                "{$items}.catalog_collection_id as collection_id, "
                ."{$states}.user_id as user_id, {$states}.catalog_title_id as catalog_title_id",
            )
            ->distinct();
    }

    /** @param list<int> $collectionIds */
    private function completionPairs(array $collectionIds): Builder
    {
        $items = (new CatalogCollectionItem)->getTable();
        $states = (new CatalogTitleUserState)->getTable();
        $progress = (new EpisodeViewProgress)->getTable();

        $statePairs = DB::table($items)
            ->join($states, "{$states}.catalog_title_id", '=', "{$items}.catalog_title_id")
            ->whereIn("{$items}.catalog_collection_id", $collectionIds)
            ->where("{$states}.watch_status", CatalogWatchStatus::Completed->value)
            ->selectRaw(
                "{$items}.catalog_collection_id as collection_id, "
                ."{$states}.user_id as user_id, {$states}.catalog_title_id as catalog_title_id",
            );
        $progressPairs = DB::table($items)
            ->join($progress, "{$progress}.catalog_title_id", '=', "{$items}.catalog_title_id")
            ->whereIn("{$items}.catalog_collection_id", $collectionIds)
            ->whereNotNull("{$progress}.completed_at")
            ->selectRaw(
                "{$items}.catalog_collection_id as collection_id, "
                ."{$progress}.user_id as user_id, {$progress}.catalog_title_id as catalog_title_id",
            );

        return $statePairs->union($progressPairs);
    }

    /** @param list<int> $collectionIds */
    private function returnPairs(array $collectionIds): Builder
    {
        $items = (new CatalogCollectionItem)->getTable();
        $progress = (new EpisodeViewProgress)->getTable();

        return DB::table($items)
            ->join($progress, "{$progress}.catalog_title_id", '=', "{$items}.catalog_title_id")
            ->whereIn("{$items}.catalog_collection_id", $collectionIds)
            ->selectRaw(
                "{$items}.catalog_collection_id as collection_id, "
                ."{$progress}.user_id as user_id, {$progress}.catalog_title_id as catalog_title_id",
            )
            ->groupBy(
                "{$items}.catalog_collection_id",
                "{$progress}.user_id",
                "{$progress}.catalog_title_id",
            )
            ->havingRaw("COUNT(DISTINCT {$progress}.episode_id) >= 2");
    }

    /**
     * @param  array<int, array{save_count: int, completion_count: int, return_count: int}>  $result
     * @param  'save_count'|'completion_count'|'return_count'  $key
     * @return array<int, array{save_count: int, completion_count: int, return_count: int}>
     */
    private function mergeCounts(array $result, string $key, Builder $pairs): array
    {
        $counts = DB::query()
            ->fromSub($pairs, 'collection_engagement_pairs')
            ->select('collection_id')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('collection_id')
            ->get();

        foreach ($counts as $count) {
            $collectionId = (int) $count->collection_id;

            if (isset($result[$collectionId])) {
                $result[$collectionId][$key] = (int) $count->aggregate;
            }
        }

        return $result;
    }
}
