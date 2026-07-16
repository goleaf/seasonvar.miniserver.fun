<?php

declare(strict_types=1);

namespace App\DTOs\ContentRequests;

final readonly class ContentRequestDetailData
{
    /**
     * @param list<array{status: string, label: string, reason: string|null, date: string}> $history
     * @param list<array{url: string, label: string}> $sourceLinks
     * @param list<array{provider: string, label: string, identifier: string}> $externalIdentifiers
     * @param list<array{role: string, role_label: string, body: string, date: string}> $clarifications
     */
    public function __construct(
        public ContentRequestCardData $card,
        public int $version,
        public ?string $alternativeTitle,
        public ?string $country,
        public ?string $originalLanguage,
        public ?string $audioLanguage,
        public ?string $subtitleLanguage,
        public ?string $translationType,
        public ?string $translationStudio,
        public ?int $seasonNumber,
        public ?int $episodeNumber,
        public ?string $currentQuality,
        public ?string $requestedQuality,
        public ?string $correctionField,
        public ?string $currentValue,
        public ?string $proposedValue,
        public ?string $explanation,
        public ?string $publicNote,
        public ?string $rejectionReason,
        public array $history,
        public array $sourceLinks,
        public array $externalIdentifiers,
        public array $clarifications,
        public bool $canEdit,
        public bool $canWithdraw,
        public bool $canClarify,
        public bool $canModerate,
        public ?string $completionUrl,
        public ?string $completionLabel,
    ) {}
}
