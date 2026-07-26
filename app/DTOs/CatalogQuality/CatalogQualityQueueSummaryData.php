<?php

declare(strict_types=1);

namespace App\DTOs\CatalogQuality;

final readonly class CatalogQualityQueueSummaryData
{
    public function __construct(
        public string $code,
        public string $label,
        public int $count,
    ) {}
}
