<?php

declare(strict_types=1);

namespace App\DTOs\Seasonvar;

use App\Enums\SeasonvarPageType;

final readonly class SeasonvarMetadataPageData
{
    /**
     * @param  list<string>  $linkedSerialUrls
     * @param  list<string>  $sourceAliases
     * @param  list<string>  $missingDataFlags
     */
    public function __construct(
        public SeasonvarPageType $pageType,
        public string $displayName,
        public string $normalizedName,
        public string $sourceSlug,
        public string $sourceUrl,
        public string $canonicalSourceUrl,
        public ?string $pageTitle,
        public ?string $alphabetPosition,
        public ?int $sourceProvidedCount,
        public array $linkedSerialUrls,
        public array $sourceAliases,
        public array $missingDataFlags,
    ) {}
}
