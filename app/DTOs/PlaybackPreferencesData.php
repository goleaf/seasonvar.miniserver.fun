<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PlaybackPreferencesData
{
    public function __construct(
        public ?string $variant = null,
        public ?string $audioLanguage = null,
        public ?string $quality = null,
        public ?string $format = null,
    ) {}
}
