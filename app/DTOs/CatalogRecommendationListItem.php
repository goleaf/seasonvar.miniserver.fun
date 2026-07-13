<?php

declare(strict_types=1);

namespace App\DTOs;

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
    ) {}
}
