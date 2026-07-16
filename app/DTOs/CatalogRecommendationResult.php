<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CatalogPersonalizationConfidence;
use App\Enums\CatalogRecommendationType;
use Illuminate\Support\Collection;

final readonly class CatalogRecommendationResult
{
    /**
     * @param  Collection<int, CatalogRecommendationItem>  $items
     */
    public function __construct(
        public CatalogRecommendationType $requestedType,
        public CatalogRecommendationType $displayType,
        public Collection $items,
        public int $page,
        public int $perPage,
        public bool $hasMore,
        public bool $personalized,
        public bool $coldStart,
        public ?CatalogPersonalizationConfidence $personalizationConfidence = null,
    ) {}
}
