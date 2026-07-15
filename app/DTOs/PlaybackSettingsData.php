<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PlaybackSettingsData
{
    public function __construct(
        public bool $autoplay,
        public bool $rememberVolume,
        public int $volume,
        public bool $muted,
        public string $playbackSpeed,
        public ?string $preferredQuality,
        public ?string $preferredVariant,
        public bool $subtitlesEnabled,
        public bool $keyboardShortcutsEnabled,
    ) {}
}
