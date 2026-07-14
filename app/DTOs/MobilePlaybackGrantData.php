<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class MobilePlaybackGrantData
{
    public function __construct(
        public ?int $userId,
        public int $mediaId,
        public int $expiresAt,
    ) {}
}
