<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CatalogRecommendationScoreRange
{
    public function __construct(
        public int $minimum,
        public int $median,
        public int $p95,
        public string $algorithmVersion,
        public string $featureVersion,
    ) {}
}
