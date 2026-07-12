<?php

declare(strict_types=1);

namespace App\DTOs\Seasonvar;

class SeasonvarQueueStatusData
{
    public function __construct(
        public readonly string $connection,
        public readonly string $queue,
        public readonly int $pending,
        public readonly int $delayed,
        public readonly int $reserved,
        public readonly ?int $oldestPendingTimestamp,
        public readonly int $liveClaims,
        public readonly ?int $runId,
        public readonly ?string $runStatus,
        public readonly int $selected,
        public readonly int $parsed,
        public readonly int $failed,
    ) {}

    public function oldestPendingAgeSeconds(): ?int
    {
        if ($this->oldestPendingTimestamp === null) {
            return null;
        }

        return max(0, now()->getTimestamp() - $this->oldestPendingTimestamp);
    }
}
