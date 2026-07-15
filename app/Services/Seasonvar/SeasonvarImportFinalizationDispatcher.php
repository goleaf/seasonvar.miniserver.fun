<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Enums\SeasonvarImportStatus;
use App\Jobs\FinalizeSeasonvarImportTitleGroup;
use App\Jobs\FinalizeSeasonvarQueuedImport;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;

final class SeasonvarImportFinalizationDispatcher
{
    public function titleGroup(SeasonvarImportTitleGroup $group, int $delaySeconds = 0): void
    {
        $dispatch = FinalizeSeasonvarImportTitleGroup::dispatch((int) $group->id)
            ->onConnection((string) config('seasonvar.queue.connection', 'redis'))
            ->onQueue((string) $group->queue_name)
            ->afterCommit();

        if ($delaySeconds > 0) {
            $dispatch->delay(now()->addSeconds($delaySeconds));
        }
    }

    public function globalRun(SeasonvarImportRun $run, int $delaySeconds = 0): void
    {
        if ($run->mode !== 'sitemap'
            || $run->execution_mode !== 'queue'
            || $run->statusValue() !== SeasonvarImportStatus::Running
        ) {
            return;
        }

        $dispatch = FinalizeSeasonvarQueuedImport::dispatch((int) $run->id)
            ->onConnection((string) config('seasonvar.queue.connection', 'redis'))
            ->onQueue((string) config('seasonvar.queue.queue', 'seasonvar-import'))
            ->afterCommit();

        if ($delaySeconds > 0) {
            $dispatch->delay(now()->addSeconds($delaySeconds));
        }
    }
}
