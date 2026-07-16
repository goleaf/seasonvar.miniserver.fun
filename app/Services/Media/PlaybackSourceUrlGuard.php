<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\DTOs\VerifiedExternalUrlData;

class PlaybackSourceUrlGuard
{
    public function __construct(
        private readonly ExternalMediaUrlGuard $urls,
    ) {}

    public function safeExternalUrl(mixed $url): ?string
    {
        return $this->verifiedExternalUrl($url)?->url;
    }

    public function verifiedExternalUrl(mixed $url): ?VerifiedExternalUrlData
    {
        return $this->urls->verifiedExternalUrl(
            $url,
            ['https'],
            array_values(array_map('strval', (array) config('playback.allowed_hosts', []))),
        );
    }
}
