<?php

declare(strict_types=1);

namespace App\DTOs\Seasonvar;

final readonly class SeasonvarMediaSyncResult
{
    public function __construct(
        public int $attached = 0,
        public int $updated = 0,
        public int $skipped = 0,
        public int $failed = 0,
    ) {}

    /**
     * @return array{attached: int, updated: int, skipped: int, failed: int}
     */
    public function toArray(): array
    {
        return [
            'attached' => $this->attached,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
        ];
    }
}
