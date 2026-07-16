<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\CatalogTitle;

final readonly class CatalogTopListItem
{
    /**
     * @param  list<string>  $reasonLabels
     */
    public function __construct(
        public CatalogTitle $title,
        public int $rank,
        public string $ratingProvider,
        public float $rating,
        public int $votes,
        public float $weightedScore,
        public array $reasonLabels,
    ) {}
}
