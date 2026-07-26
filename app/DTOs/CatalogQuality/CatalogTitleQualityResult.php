<?php

declare(strict_types=1);

namespace App\DTOs\CatalogQuality;

use App\Enums\CatalogQualityIssueCategory;
use App\Enums\CatalogQualitySeverity;
use Illuminate\Support\Collection;

final readonly class CatalogTitleQualityResult
{
    /**
     * @param  list<CatalogTitleQualityIssueData>  $issues
     */
    public function __construct(
        public int $score,
        public CatalogQualitySeverity $severity,
        public array $issues,
    ) {}

    /** @return list<string> */
    public function issueCodes(): array
    {
        return array_map(
            static fn (CatalogTitleQualityIssueData $issue): string => $issue->code,
            $this->issues,
        );
    }

    /** @return Collection<int, CatalogTitleQualityIssueData> */
    public function issuesFor(CatalogQualityIssueCategory $category): Collection
    {
        return collect($this->issues)
            ->filter(
                static fn (CatalogTitleQualityIssueData $issue): bool => $issue->category === $category,
            )
            ->values();
    }
}
