<?php

declare(strict_types=1);

namespace App\Services\Seasonvar\PageHandlers;

use App\DTOs\Seasonvar\SeasonvarPageHandlerDefinition;
use App\DTOs\Seasonvar\SeasonvarPageHandlerResult;
use App\Enums\SeasonvarPageType;
use App\Models\SourcePage;

final readonly class SeasonvarPassivePageHandler implements SeasonvarPageHandler
{
    public function __construct(private SeasonvarPageType $pageType) {}

    public function definition(): SeasonvarPageHandlerDefinition
    {
        return new SeasonvarPageHandlerDefinition(
            pageType: $this->pageType,
            persistOnDiscovery: true,
            automaticParsing: false,
            metadataOnly: true,
            parserClass: null,
            importerClass: null,
            retryBehavior: 'none',
            expectedResultType: 'stored_source_page',
            canGenerateLocalPublicPage: false,
        );
    }

    public function handle(SourcePage $page, string $body, ?int $importRunId = null, ?callable $progress = null): SeasonvarPageHandlerResult
    {
        return new SeasonvarPageHandlerResult;
    }
}
