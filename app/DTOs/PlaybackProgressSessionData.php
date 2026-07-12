<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PlaybackProgressSessionData
{
    public function __construct(
        public string $id,
        public int $userId,
        public int $catalogTitleId,
        public int $episodeId,
        public int $mediaId,
        public int $expiresAt,
    ) {}
}
