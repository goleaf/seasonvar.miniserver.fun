<?php

declare(strict_types=1);

namespace App\Services\Collections\Import;

use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSourceMatchStatus;
use App\Enums\CatalogCollectionSyncStatus;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Models\CatalogCollectionSourceItem;
use App\Models\CatalogCollectionSyncRun;
use App\Models\CatalogTitleRecommendationSignal;
use Illuminate\Database\Eloquent\Builder;

final class HdRezkaCollectionSignalSynchronizer
{
    /** @return array{upserted: int, deleted: int, title_ids: list<int>} */
    public function synchronizeForRun(CatalogCollectionSyncRun $run): array
    {
        $activePairs = [];
        $affectedTitleIds = [];
        $timestamp = $run->completed_at ?? now();
        $weight = max(1, min(
            1000,
            (int) config('recommendations.similarity_v6.editorial_collection_signal_weight', 280),
        ));
        $query = CatalogCollectionSourceItem::query()
            ->select(['id', 'catalog_collection_source_id', 'catalog_title_id'])
            ->where('last_seen_run_id', $run->id)
            ->where('match_status', CatalogCollectionSourceMatchStatus::Matched->value)
            ->whereNotNull('catalog_title_id')
            ->whereHas('source', fn (Builder $source): Builder => $source
                ->where('provider', $run->provider)
                ->whereHas('collection', fn (Builder $collection): Builder => $collection
                    ->where('type', CatalogCollectionType::Editorial->value)
                    ->where('visibility', CatalogCollectionVisibility::Public->value)
                    ->where('moderation_status', CatalogCollectionModerationStatus::Approved->value)
                    ->whereNotNull('published_at')))
            ->with('source:id,source_key')
            ->orderBy('id');

        $query->chunkById(500, function ($items) use (&$activePairs, &$affectedTitleIds, $run, $timestamp, $weight): void {
            $rows = [];

            foreach ($items as $item) {
                $sourceKey = (string) $item->source->source_key;
                $catalogTitleId = (int) $item->catalog_title_id;
                $pair = $this->pairKey($catalogTitleId, $sourceKey);

                if (isset($activePairs[$pair])) {
                    continue;
                }

                $activePairs[$pair] = true;
                $affectedTitleIds[$catalogTitleId] = true;
                $rows[] = [
                    'catalog_title_id' => $catalogTitleId,
                    'source' => $run->provider,
                    'signal_type' => 'editorial_collection',
                    'signal_key' => $sourceKey,
                    'signal_value' => null,
                    'weight' => $weight,
                    'observed_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            if ($rows !== []) {
                CatalogTitleRecommendationSignal::query()->upsert(
                    $rows,
                    ['catalog_title_id', 'source', 'signal_type', 'signal_key'],
                    ['signal_value', 'weight', 'observed_at', 'updated_at'],
                );
            }
        });

        $stale = $run->status === CatalogCollectionSyncStatus::Completed
            ? $this->deleteStaleSignals((string) $run->provider, $activePairs)
            : ['deleted' => 0, 'title_ids' => []];

        foreach ($stale['title_ids'] as $catalogTitleId) {
            $affectedTitleIds[$catalogTitleId] = true;
        }

        $titleIds = array_map('intval', array_keys($affectedTitleIds));
        sort($titleIds, SORT_NUMERIC);

        return [
            'upserted' => count($activePairs),
            'deleted' => $stale['deleted'],
            'title_ids' => $titleIds,
        ];
    }

    /**
     * @param  array<string, true>  $activePairs
     * @return array{deleted: int, title_ids: list<int>}
     */
    private function deleteStaleSignals(string $provider, array $activePairs): array
    {
        $deleted = 0;
        $affectedTitleIds = [];

        CatalogTitleRecommendationSignal::query()
            ->select(['id', 'catalog_title_id', 'signal_key'])
            ->where('source', $provider)
            ->where('signal_type', 'editorial_collection')
            ->orderBy('id')
            ->chunkById(500, function ($signals) use (&$deleted, &$affectedTitleIds, $activePairs): void {
                $staleSignals = $signals
                    ->reject(fn (CatalogTitleRecommendationSignal $signal): bool => isset($activePairs[
                        $this->pairKey((int) $signal->catalog_title_id, (string) $signal->signal_key)
                    ]));
                $staleIds = $staleSignals->pluck('id')->all();

                if ($staleIds === []) {
                    return;
                }

                foreach ($staleSignals as $signal) {
                    $affectedTitleIds[(int) $signal->catalog_title_id] = true;
                }

                $deleted += CatalogTitleRecommendationSignal::query()->whereKey($staleIds)->delete();
            });

        $titleIds = array_map('intval', array_keys($affectedTitleIds));
        sort($titleIds, SORT_NUMERIC);

        return ['deleted' => $deleted, 'title_ids' => $titleIds];
    }

    private function pairKey(int $catalogTitleId, string $sourceKey): string
    {
        return $catalogTitleId."\0".$sourceKey;
    }
}
