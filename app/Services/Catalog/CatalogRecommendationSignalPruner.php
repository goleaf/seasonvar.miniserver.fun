<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogTitleRecommendationSignal;
use Illuminate\Database\Eloquent\Builder;

final class CatalogRecommendationSignalPruner
{
    private const CHUNK_SIZE = 1_000;

    /** @var list<string> */
    private const GENERIC_SIGNAL_TYPES = [
        'taxonomy_genre',
        'taxonomy_country',
        'taxonomy_actor',
        'taxonomy_director',
        'taxonomy_age_rating',
        'taxonomy_translation',
        'taxonomy_status',
        'taxonomy_network',
        'taxonomy_studio',
        'taxonomy_tag',
        'rating',
        'release_year',
        'page_quality',
    ];

    /**
     * @param  (callable(string, array<string, int>): void)|null  $progress
     * @return array{checked: int, deleted: int}
     */
    public function prune(?callable $progress = null): array
    {
        $checked = 0;
        $deleted = 0;

        $this->genericSignals()
            ->select('id')
            ->chunkById(self::CHUNK_SIZE, function ($signals) use (&$checked, &$deleted): void {
                $ids = $signals->modelKeys();
                $checked += count($ids);
                $deleted += $this->genericSignals()
                    ->whereKey($ids)
                    ->delete();
            });

        $result = [
            'checked' => $checked,
            'deleted' => $deleted,
        ];
        $progress?->__invoke('catalog-recommendation-signals-pruned', $result);

        return $result;
    }

    /** @return Builder<CatalogTitleRecommendationSignal> */
    private function genericSignals(): Builder
    {
        return CatalogTitleRecommendationSignal::query()
            ->where('source', 'seasonvar_info')
            ->whereIn('signal_type', self::GENERIC_SIGNAL_TYPES);
    }
}
