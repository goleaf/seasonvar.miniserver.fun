<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogRecommendationBuild;
use Illuminate\Support\Facades\Schema;

final class CatalogRecommendationBuildPruner
{
    public function prune(): int
    {
        if (! Schema::hasTable('catalog_recommendation_builds')) {
            return 0;
        }

        $historyLimit = max(
            1,
            min(20, (int) config('recommendations.similarity_v6.build_history_limit', 5)),
        );
        $keepIds = CatalogRecommendationBuild::query()
            ->where('status', 'active')
            ->pluck('id')
            ->merge(CatalogRecommendationBuild::query()
                ->whereIn('status', ['evaluated', 'rejected', 'failed'])
                ->latest('id')
                ->limit($historyLimit)
                ->pluck('id'))
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return CatalogRecommendationBuild::query()
            ->whereIn('status', ['evaluated', 'rejected', 'failed'])
            ->when($keepIds !== [], fn ($query) => $query->whereNotIn('id', $keepIds))
            ->delete();
    }
}
