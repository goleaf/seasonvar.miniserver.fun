<?php

declare(strict_types=1);

namespace App\DTOs\CatalogQuality;

final readonly class CatalogQualityQueueItemData
{
    /**
     * @param  list<CatalogQualityIssueViewData>  $issues
     * @param  list<CatalogMetadataProvenanceViewData>  $provenance
     */
    public function __construct(
        public int $catalogTitleId,
        public string $title,
        public ?string $originalTitle,
        public string $yearLabel,
        public int $score,
        public string $severity,
        public string $severityLabel,
        public int $issueCount,
        public int $criticalCount,
        public string $sourceCheckedAtLabel,
        public string $evaluatedAtLabel,
        public bool $needsRefresh,
        public array $issues,
        public array $provenance,
        public string $editUrl,
    ) {}
}
