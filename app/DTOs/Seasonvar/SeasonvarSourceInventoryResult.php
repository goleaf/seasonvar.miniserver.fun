<?php

declare(strict_types=1);

namespace App\DTOs\Seasonvar;

use Illuminate\Support\Carbon;

final readonly class SeasonvarSourceInventoryResult
{
    /**
     * @param  array<string, int>  $countsByPageType
     * @param  array<string, list<string>>  $sampleUrlsByPageType
     * @param  list<string>  $locallySupportedImportTypes
     * @param  list<string>  $locallySupportedPublicPageTypes
     * @param  list<string>  $discoveredButUnsupportedTypes
     * @param  list<string>  $warnings
     * @param  list<string>  $failureDetails
     */
    public function __construct(
        public int $importRunId,
        public Carbon $startedAt,
        public Carbon $completedAt,
        public int $sitemapCount,
        public int $totalUrlCount,
        public int $storedUrlCount,
        public array $countsByPageType,
        public int $unknownUrlCount,
        public int $malformedUrlCount,
        public int $blockedUrlCount,
        public int $duplicateUrlCount,
        public array $sampleUrlsByPageType,
        public array $locallySupportedImportTypes,
        public array $locallySupportedPublicPageTypes,
        public array $discoveredButUnsupportedTypes,
        public array $warnings,
        public array $failureDetails,
    ) {}

    public function successful(): bool
    {
        return $this->failureDetails === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'import_run_id' => $this->importRunId,
            'started_at' => $this->startedAt->toIso8601String(),
            'completed_at' => $this->completedAt->toIso8601String(),
            'sitemap_count' => $this->sitemapCount,
            'total_url_count' => $this->totalUrlCount,
            'stored_url_count' => $this->storedUrlCount,
            'counts_by_page_type' => $this->countsByPageType,
            'unknown_url_count' => $this->unknownUrlCount,
            'malformed_url_count' => $this->malformedUrlCount,
            'blocked_url_count' => $this->blockedUrlCount,
            'duplicate_url_count' => $this->duplicateUrlCount,
            'sample_urls_by_page_type' => $this->sampleUrlsByPageType,
            'locally_supported_import_types' => $this->locallySupportedImportTypes,
            'locally_supported_public_page_types' => $this->locallySupportedPublicPageTypes,
            'discovered_but_unsupported_types' => $this->discoveredButUnsupportedTypes,
            'warnings' => $this->warnings,
            'failure_details' => $this->failureDetails,
        ];
    }
}
