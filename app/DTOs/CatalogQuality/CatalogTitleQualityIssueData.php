<?php

declare(strict_types=1);

namespace App\DTOs\CatalogQuality;

use App\Enums\CatalogQualityIssueCategory;
use App\Enums\CatalogQualitySeverity;

final readonly class CatalogTitleQualityIssueData
{
    /**
     * @param  array<string, bool|float|int|string|list<int|string>|null>  $evidence
     */
    public function __construct(
        public string $code,
        public CatalogQualityIssueCategory $category,
        public CatalogQualitySeverity $severity,
        public int $penalty,
        public array $evidence = [],
    ) {}
}
