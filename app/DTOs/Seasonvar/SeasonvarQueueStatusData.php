<?php

declare(strict_types=1);

namespace App\DTOs\Seasonvar;

use Carbon\CarbonInterface;

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
        public readonly int $activeRuns,
        public readonly ?int $runId,
        public readonly ?string $runExecutionMode,
        public readonly ?string $runStatus,
        public readonly ?CarbonInterface $lastHeartbeatAt,
        public readonly int $selected,
        public readonly int $parsed,
        public readonly int $failed,
        public readonly int $mediaSizesChecked,
        public readonly int $mediaSizesKnown,
        public readonly int $mediaSizesUnknown,
        public readonly int $mediaSizesUnsupported,
        public readonly int $mediaSizeChecksFailed,
        public readonly int $mediaSizeKnownBytes,
    ) {}

    public function oldestPendingAgeSeconds(): ?int
    {
        if ($this->oldestPendingTimestamp === null) {
            return null;
        }

        return max(0, now()->getTimestamp() - $this->oldestPendingTimestamp);
    }
}
