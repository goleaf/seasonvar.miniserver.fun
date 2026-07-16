<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class PlaybackProgressInput
{
    public function __construct(
        public string $playbackSessionToken,
        public int $eventSequence,
        public int $positionSeconds,
        public int $reportedDurationSeconds,
        public bool $ended,
    ) {}
}
