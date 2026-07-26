<?php

declare(strict_types=1);

namespace App\DTOs\CatalogQuality;

final readonly class CatalogQualityIssueViewData
{
    public function __construct(
        public string $code,
        public string $label,
        public string $detail,
        public string $severity,
        public string $severityLabel,
    ) {}
}
