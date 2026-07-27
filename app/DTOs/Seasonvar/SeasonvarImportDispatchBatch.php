<?php

declare(strict_types=1);

namespace App\DTOs\Seasonvar;

final readonly class SeasonvarImportDispatchBatch
{
    public function __construct(
        public int $registeredPages,
        public int $jobsDispatched,
        public bool $hasMore,
        public bool $dispatchCompleted,
    ) {}
}
