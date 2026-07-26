<?php

declare(strict_types=1);

namespace App\DTOs\CollectionQuality;

final readonly class CatalogCollectionQualityResult
{
    /**
     * @param  array{metadata: int, structure: int, theme: int, trust: int}  $components
     * @param  list<CatalogCollectionQualityIssueData>  $issues
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public int $score,
        public array $components,
        public array $issues,
        public array $details,
    ) {}

    /** @return list<string> */
    public function issueCodes(): array
    {
        return array_map(
            static fn (CatalogCollectionQualityIssueData $issue): string => $issue->code,
            $this->issues,
        );
    }
}
