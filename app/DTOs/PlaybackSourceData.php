<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\PlaybackAvailability;

final readonly class PlaybackSourceData
{
    public function __construct(
        public PlaybackAvailability $status,
        public string $message,
        public ?int $mediaId = null,
        public ?string $url = null,
        public ?string $mimeType = null,
        public ?string $format = null,
        public ?string $quality = null,
        public ?string $variant = null,
        public ?string $expiresAt = null,
    ) {}

    public static function blocked(PlaybackAvailability $status): self
    {
        return new self(status: $status, message: $status->message());
    }

    public function isPlayable(): bool
    {
        return $this->status === PlaybackAvailability::Ready && $this->url !== null;
    }
}
