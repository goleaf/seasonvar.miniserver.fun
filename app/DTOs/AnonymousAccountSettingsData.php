<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class AnonymousAccountSettingsData
{
    public function __construct(
        public ?string $locale = null,
        public ?string $timezone = null,
        public ?bool $autoplay = null,
        public ?bool $rememberVolume = null,
        public ?int $volume = null,
        public ?bool $muted = null,
        public ?string $playbackSpeed = null,
        public ?string $preferredQuality = null,
        public ?string $preferredVariant = null,
        public ?bool $subtitlesEnabled = null,
        public ?bool $keyboardShortcutsEnabled = null,
        public ?bool $reducedMotion = null,
    ) {}
}
