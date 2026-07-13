<?php

declare(strict_types=1);

namespace App\DTOs\Seasonvar;

use App\Enums\SeasonvarPageType;

final readonly class SeasonvarPageHandlerDefinition
{
    /**
     * @param  class-string|null  $parserClass
     * @param  class-string|null  $importerClass
     */
    public function __construct(
        public SeasonvarPageType $pageType,
        public bool $persistOnDiscovery,
        public bool $automaticParsing,
        public bool $metadataOnly,
        public ?string $parserClass,
        public ?string $importerClass,
        public string $retryBehavior,
        public string $expectedResultType,
        public bool $canGenerateLocalPublicPage,
    ) {}
}
