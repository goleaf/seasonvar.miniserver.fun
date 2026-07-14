<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\PlaybackAvailability;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use Carbon\CarbonInterface;

final readonly class MobilePlaybackSessionData
{
    public function __construct(
        public PlaybackAvailability $status,
        public string $message,
        public ?CatalogTitle $title = null,
        public ?Episode $episode = null,
        public ?LicensedMedia $media = null,
        public ?string $playbackUrl = null,
        public ?string $mimeType = null,
        public ?string $format = null,
        public ?string $quality = null,
        public ?string $variant = null,
        public ?CarbonInterface $expiresAt = null,
        public ?CatalogEpisodeNavigation $navigation = null,
        public ?string $progressSessionToken = null,
    ) {}

    public static function blocked(PlaybackAvailability $status): self
    {
        return new self(status: $status, message: $status->message());
    }

    public function isReady(): bool
    {
        return $this->status === PlaybackAvailability::Ready;
    }
}
