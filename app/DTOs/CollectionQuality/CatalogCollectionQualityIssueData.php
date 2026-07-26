<?php

declare(strict_types=1);

namespace App\DTOs\CollectionQuality;

use App\Enums\CatalogCollectionQualityIssueSeverity;

final readonly class CatalogCollectionQualityIssueData
{
    /** @param array<string, int|float|string|bool|null> $evidence */
    public function __construct(
        public string $code,
        public CatalogCollectionQualityIssueSeverity $severity,
        public array $evidence = [],
    ) {}
}
