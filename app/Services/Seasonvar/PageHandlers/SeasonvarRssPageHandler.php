<?php

declare(strict_types=1);

namespace App\Services\Seasonvar\PageHandlers;

use App\DTOs\Seasonvar\SeasonvarPageHandlerDefinition;
use App\DTOs\Seasonvar\SeasonvarPageHandlerResult;
use App\Enums\SeasonvarPageType;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarRssFreshnessImporter;

final readonly class SeasonvarRssPageHandler implements SeasonvarPageHandler
{
    public function __construct(private SeasonvarRssFreshnessImporter $importer) {}

    public function definition(): SeasonvarPageHandlerDefinition
    {
        return new SeasonvarPageHandlerDefinition(
            pageType: SeasonvarPageType::Rss,
            persistOnDiscovery: true,
            automaticParsing: true,
            metadataOnly: true,
            parserClass: SeasonvarRssFreshnessImporter::class,
            importerClass: SeasonvarRssFreshnessImporter::class,
            retryBehavior: 'freshness',
            expectedResultType: 'freshness_signal',
            canGenerateLocalPublicPage: false,
            sourceAccess: 'public_freshness_signal',
            publicationAuthorized: false,
        );
    }

    public function handle(SourcePage $page, string $body, ?int $importRunId = null, ?callable $progress = null): SeasonvarPageHandlerResult
    {
        return $this->importer->import($page, $body, $importRunId, $progress);
    }
}
