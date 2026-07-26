<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\PlaybackPreferenceMode;

final readonly class PlaybackSettingsData
{
    /**
     * @param  list<string>  $hiddenVariantKeys
     */
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
        public ?string $fallbackVariant = null,
        public PlaybackPreferenceMode $playbackMode = PlaybackPreferenceMode::Automatic,
        public ?string $preferredSubtitleLanguage = null,
        public array $hiddenVariantKeys = [],
        public bool $notifyPreferredTranslation = false,
    ) {}
}
