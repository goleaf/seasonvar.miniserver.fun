<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\PlaybackPreferenceMode;

final readonly class AccountSettingsData
{
    /**
     * @param  list<string>  $hiddenVariantKeys
     */
    public function __construct(
        public string $locale,
        public string $timezone,
        public bool $autoplay,
        public bool $rememberVolume,
        public int $volume,
        public bool $muted,
        public string $playbackSpeed,
        public ?string $preferredQuality,
        public ?string $preferredVariant,
        public ?string $fallbackVariant,
        public PlaybackPreferenceMode $playbackMode,
        public ?string $preferredSubtitleLanguage,
        public array $hiddenVariantKeys,
        public bool $notifyPreferredTranslation,
        public bool $subtitlesEnabled,
        public bool $keyboardShortcutsEnabled,
        public bool $reducedMotion,
        public string $collectionDefaultVisibility,
        public int $version,
    ) {}

    /** @return array<string, bool|int|string|list<string>|null> */
    public function toExportArray(): array
    {
        return [
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'autoplay' => $this->autoplay,
            'remember_volume' => $this->rememberVolume,
            'volume' => $this->volume,
            'muted' => $this->muted,
            'playback_speed' => $this->playbackSpeed,
            'preferred_quality' => $this->preferredQuality,
            'preferred_variant' => $this->preferredVariant,
            'fallback_variant' => $this->fallbackVariant,
            'preferred_playback_mode' => $this->playbackMode->value,
            'preferred_subtitle_language' => $this->preferredSubtitleLanguage,
            'hidden_variant_keys' => $this->hiddenVariantKeys,
            'notify_preferred_translation' => $this->notifyPreferredTranslation,
            'subtitles_enabled' => $this->subtitlesEnabled,
            'keyboard_shortcuts_enabled' => $this->keyboardShortcutsEnabled,
            'reduced_motion' => $this->reducedMotion,
            'collection_default_visibility' => $this->collectionDefaultVisibility,
            'settings_version' => $this->version,
        ];
    }
}
