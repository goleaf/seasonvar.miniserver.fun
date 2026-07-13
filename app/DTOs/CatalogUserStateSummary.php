<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CatalogUserStateSummary
{
    public function __construct(
        public int $watchlistCount,
        public int $ratingCount,
        public ?float $ratingAverage,
    ) {}
}
