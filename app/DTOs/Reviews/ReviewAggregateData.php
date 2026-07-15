<?php

declare(strict_types=1);

namespace App\DTOs\Reviews;

final readonly class ReviewAggregateData
{
    public function __construct(
        public int $publicCount,
        public int $ratedCount,
        public ?float $ratingAverage,
    ) {}
}
