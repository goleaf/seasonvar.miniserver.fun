<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CatalogRecommendationBuildEvaluation
{
    public function __construct(
        public bool $gatePassed,
        public bool $goldenAvailable,
        public CatalogRecommendationQualityReport $baseline,
        public CatalogRecommendationQualityReport $candidate,
        public float $rowChurn,
    ) {}
}
