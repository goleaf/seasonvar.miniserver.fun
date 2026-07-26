<?php

declare(strict_types=1);

namespace App\DTOs\CatalogQuality;

final readonly class CatalogMetadataProvenanceViewData
{
    public function __construct(
        public string $key,
        public string $fieldLabel,
        public string $valueLabel,
        public string $sourceLabel,
        public string $confirmedAtLabel,
        public int $confidence,
        public string $status,
        public string $statusLabel,
    ) {}
}
