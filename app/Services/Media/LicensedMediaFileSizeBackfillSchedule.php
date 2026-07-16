<?php

declare(strict_types=1);

namespace App\Services\Media;

final readonly class LicensedMediaFileSizeBackfillSchedule
{
    public const MAX_ITEMS = 100_000;

    private function __construct(
        public int $limit,
        public int $timeBudgetSeconds,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            limit: max(1, min(
                self::MAX_ITEMS,
                (int) config('seasonvar.media_file_size.scheduled_backfill_limit', 500),
            )),
            timeBudgetSeconds: max(1, min(
                LicensedMediaFileSizeBackfillBudget::MAX_SECONDS,
                (int) config('seasonvar.media_file_size.scheduled_backfill_time_budget_seconds', 480),
            )),
        );
    }
}
