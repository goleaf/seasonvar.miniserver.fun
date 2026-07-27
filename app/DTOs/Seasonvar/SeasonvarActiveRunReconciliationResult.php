<?php

declare(strict_types=1);

namespace App\DTOs\Seasonvar;

final readonly class SeasonvarActiveRunReconciliationResult
{
    public function __construct(
        public bool $eligible,
        public bool $dispatchRecovered,
        public int $pagesRegistered,
        public int $jobsDispatched,
        public bool $hasRemainingDueWork,
    ) {}

    public static function ineligible(): self
    {
        return new self(
            eligible: false,
            dispatchRecovered: false,
            pagesRegistered: 0,
            jobsDispatched: 0,
            hasRemainingDueWork: false,
        );
    }
}
