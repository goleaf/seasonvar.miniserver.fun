<?php

declare(strict_types=1);

namespace App\Services\Seasonvar\PageHandlers;

use App\DTOs\Seasonvar\SeasonvarPageHandlerDefinition;
use App\DTOs\Seasonvar\SeasonvarPageHandlerResult;
use App\Enums\SeasonvarPageType;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarCatalogImporter;
use App\Services\Seasonvar\SeasonvarCatalogParser;
use LogicException;

final class SeasonvarSerialPageHandler implements SeasonvarPageHandler
{
    public function definition(): SeasonvarPageHandlerDefinition
    {
        return new SeasonvarPageHandlerDefinition(
            pageType: SeasonvarPageType::Serial,
            persistOnDiscovery: true,
            automaticParsing: true,
            metadataOnly: false,
            parserClass: SeasonvarCatalogParser::class,
            importerClass: SeasonvarCatalogImporter::class,
            retryBehavior: 'catalog',
            expectedResultType: 'catalog_title',
            canGenerateLocalPublicPage: true,
        );
    }

    public function handle(SourcePage $page, string $body, ?int $importRunId = null, ?callable $progress = null): SeasonvarPageHandlerResult
    {
        throw new LogicException('Serial handler выполняется совместимым serial pipeline импортера.');
    }
}
