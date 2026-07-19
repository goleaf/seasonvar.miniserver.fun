<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Enums\SeasonvarImportStatus;
use App\Models\SeasonvarImportRun;

final class SeasonvarImportActivity
{
    private const ACTIVE_STATUSES = [
        SeasonvarImportStatus::Queued->value,
        SeasonvarImportStatus::Running->value,
    ];

    public function active(): bool
    {
        return SeasonvarImportRun::query()
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->exists();
    }
}
