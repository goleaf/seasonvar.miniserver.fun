<?php

declare(strict_types=1);

namespace App\DTOs\ReleaseCalendar;

final readonly class ReleaseScheduleCardData
{
    public function __construct(
        public string $publicId,
        public int $catalogTitleId,
        public string $title,
        public ?string $originalTitle,
        public ?string $posterUrl,
        public string $type,
        public string $typeLabel,
        public string $status,
        public string $statusLabel,
        public string $precisionLabel,
        public string $dateLabel,
        public string $groupLabel,
        public ?string $dateTimeIso,
        public ?string $countdownIso,
        public ?string $seasonLabel,
        public ?string $episodeLabel,
        public ?string $contextLabel,
        public ?string $availabilityLabel,
        public string $url,
        public bool $isSubscribed,
        public bool $canSubscribe,
        public bool $isCancelled,
        public bool $isDelayed,
    ) {}
}
