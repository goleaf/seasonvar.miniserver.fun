<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CatalogRecommendationSource;
use App\Enums\CatalogRecommendationType;
use App\Models\CatalogTitle;

final readonly class CatalogRecommendationItem
{
    /** @param list<CatalogRecommendationExplanation> $explanations */
    public function __construct(
        public CatalogTitle $title,
        public CatalogRecommendationType $type,
        public CatalogRecommendationSource $source,
        public array $explanations,
        public int $rank,
        public int $score,
        public ?string $relationType = null,
    ) {}
}
