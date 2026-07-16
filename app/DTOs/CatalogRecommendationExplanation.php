<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CatalogRecommendationReason;

final readonly class CatalogRecommendationExplanation
{
    /** @param array<string, scalar> $parameters */
    public function __construct(
        public CatalogRecommendationReason $reason,
        public array $parameters = [],
    ) {}
}
