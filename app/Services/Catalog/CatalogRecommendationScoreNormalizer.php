<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationScoreRange;
use App\Models\CatalogRecommendationBuild;
use Illuminate\Support\Facades\Schema;

final class CatalogRecommendationScoreNormalizer
{
    public function forActiveBuild(): ?CatalogRecommendationScoreRange
    {
        if (! Schema::hasTable('catalog_recommendation_builds')) {
            return null;
        }

        return CatalogRecommendationBuild::query()
            ->where('status', 'active')
            ->latest('activated_at')
            ->latest('id')
            ->first()
            ?->personalizationScoreRange();
    }

    public function normalize(int $score, CatalogRecommendationScoreRange $range): float
    {
        return max(
            0.0,
            min(1.0, ($score - $range->minimum) / max(1, $range->p95 - $range->minimum)),
        );
    }
}
