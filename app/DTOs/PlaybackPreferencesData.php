<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\PlaybackPreferenceMode;

final readonly class PlaybackPreferencesData
{
    /**
     * @param  list<string>  $hiddenVariantKeys
     */
    public function __construct(
        public ?string $variant = null,
        public ?string $audioLanguage = null,
        public ?string $quality = null,
        public ?string $format = null,
        public ?string $fallbackVariant = null,
        public PlaybackPreferenceMode $playbackMode = PlaybackPreferenceMode::Automatic,
        public ?string $subtitleLanguage = null,
        public array $hiddenVariantKeys = [],
    ) {}
}
