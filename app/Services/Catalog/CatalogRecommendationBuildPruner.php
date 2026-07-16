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

        $staleBuildMinutes = max(
            5,
            min(1_440, (int) config('recommendations.similarity_v6.stale_build_minutes', 20)),
        );
        CatalogRecommendationBuild::query()
            ->where('status', 'building')
            ->where('updated_at', '<', now()->subMinutes($staleBuildMinutes))
            ->update([
                'status' => 'failed',
                'failure_message' => 'Shadow build остановлен: heartbeat не обновлялся в допустимом интервале.',
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

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
