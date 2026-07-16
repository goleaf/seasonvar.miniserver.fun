<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CatalogRecommendationSource;
use App\Enums\CatalogRecommendationType;
use App\Models\CatalogTitle;

final readonly class CatalogRecommendationListItem
{
    /**
     * @param  list<string>  $reasonLabels
     */
    public function __construct(
        public CatalogTitle $title,
        public int $rank,
        public array $reasonLabels,
        public ?int $score = null,
        public CatalogRecommendationType $type = CatalogRecommendationType::Similar,
        public CatalogRecommendationSource $source = CatalogRecommendationSource::ContentSimilarity,
        public ?string $relationType = null,
        public bool $canDismiss = false,
    ) {}
}
