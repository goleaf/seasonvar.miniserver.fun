<?php

declare(strict_types=1);

namespace App\Services\Seasonvar\PageHandlers;

use App\DTOs\Seasonvar\SeasonvarPageHandlerDefinition;
use App\DTOs\Seasonvar\SeasonvarPageHandlerResult;
use App\Enums\SeasonvarPageType;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarTaxonomyPageImporter;
use App\Services\Seasonvar\SeasonvarTaxonomyPageParser;

final readonly class SeasonvarTaxonomyPageHandler implements SeasonvarPageHandler
{
    public function __construct(
        private SeasonvarPageType $pageType,
        private SeasonvarTaxonomyPageParser $parser,
        private SeasonvarTaxonomyPageImporter $importer,
    ) {}

    public function definition(): SeasonvarPageHandlerDefinition
    {
        return new SeasonvarPageHandlerDefinition(
            pageType: $this->pageType,
            persistOnDiscovery: true,
            automaticParsing: false,
            metadataOnly: true,
            parserClass: SeasonvarTaxonomyPageParser::class,
            importerClass: SeasonvarTaxonomyPageImporter::class,
            retryBehavior: 'metadata',
            expectedResultType: 'taxonomy',
            canGenerateLocalPublicPage: true,
            sourceAccess: 'public_metadata',
            publicationAuthorized: false,
        );
    }

    public function handle(SourcePage $page, string $body, ?int $importRunId = null, ?callable $progress = null): SeasonvarPageHandlerResult
    {
        $data = $this->parser->parse($body, $page->url, $this->pageType);

        return $this->importer->import($page, $data, $importRunId, $progress);
    }
}
