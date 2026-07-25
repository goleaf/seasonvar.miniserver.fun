<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CatalogCollectionClassificationSummary
{
    public function __construct(
        public int $total,
        public int $categorized,
        public int $uncategorized,
        public int $publicUncategorized,
        public float $completionPercentage,
    ) {}
}
