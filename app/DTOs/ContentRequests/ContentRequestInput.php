<?php

declare(strict_types=1);

namespace App\DTOs\ContentRequests;

use App\Enums\ContentRequestType;

final readonly class ContentRequestInput
{
    /**
     * @param list<array{provider: string, identifier: string}> $externalIdentifiers
     * @param list<string> $sourceLinks
     */
    public function __construct(
        public ContentRequestType $type,
        public string $title,
        public ?string $originalTitle,
        public ?string $alternativeTitle,
        public ?int $releaseYear,
        public ?string $country,
        public ?string $contentLocale,
        public ?string $originalLanguage,
        public ?string $audioLanguage,
        public ?string $subtitleLanguage,
        public ?string $translationType,
        public ?string $translationStudio,
        public ?int $catalogTitleId,
        public ?int $seasonId,
        public ?int $episodeId,
        public ?int $seasonNumber,
        public ?string $seasonKind,
        public ?int $episodeNumber,
        public ?string $episodeReleaseDate,
        public ?string $currentQuality,
        public ?string $requestedQuality,
        public ?string $correctionField,
        public ?string $currentValue,
        public ?string $proposedValue,
        public ?string $explanation,
        public ?string $differentExplanation,
        public array $externalIdentifiers,
        public array $sourceLinks,
        public string $submissionToken,
    ) {}
}
