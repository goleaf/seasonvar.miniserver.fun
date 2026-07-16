<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Enums\SeasonvarImportStatus;
use App\Enums\SeasonvarImportTitleGroupStatus;
use App\Jobs\FinalizeSeasonvarImportTitleGroup;
use App\Jobs\FinalizeSeasonvarQueuedImport;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SeasonvarImportFinalizationDispatcher
{
    public function __construct(
        private readonly SeasonvarImportTitleGroupReconciler $groups,
    ) {}

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

    public function signalTitleGroup(SeasonvarImportTitleGroup $group): bool
    {
        try {
            $this->titleGroup($group);

            return true;
        } catch (Throwable $exception) {
            Log::warning('Не удалось поставить сигнал финализации группы Seasonvar в очередь.', [
                'group_id' => $group->id,
                'import_run_id' => $group->seasonvar_import_run_id,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    public function signalGlobalRun(SeasonvarImportRun $run): bool
    {
        try {
            $this->globalRun($run);

            return true;
        } catch (Throwable $exception) {
            Log::warning('Не удалось поставить сигнал глобальной финализации Seasonvar в очередь.', [
                'import_run_id' => $run->id,
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    /** @return array{title_groups: int, global_runs: int} */
    public function wakeReady(): array
    {
        $batchSize = max(1, (int) config('seasonvar.queue.finalizer_watchdog_batch_size', 250));
        $staleBefore = $this->groups->staleBefore();
        $groups = SeasonvarImportTitleGroup::query()
            ->whereIn('status', [
                SeasonvarImportTitleGroupStatus::Discovering->value,
                SeasonvarImportTitleGroupStatus::Running->value,
                SeasonvarImportTitleGroupStatus::Finalizing->value,
            ])
            ->where(function ($query) use ($staleBefore): void {
                $query->where(function ($query): void {
                    $query->where('expected_pages', '>', 0)
                        ->whereRaw('expected_pages <= prepared_pages + failed_pages');
                })->orWhere('updated_at', '<=', $staleBefore);
            })
            ->whereHas('run', fn ($query) => $query
                ->where('execution_mode', 'queue')
                ->where('status', SeasonvarImportStatus::Running->value))
            ->orderBy('id')
            ->limit($batchSize)
            ->get();
        $groupSignals = 0;

        foreach ($groups as $group) {
            $groupSignals += $this->signalTitleGroup($group) ? 1 : 0;
        }

        $runs = SeasonvarImportRun::query()
            ->where('mode', 'sitemap')
            ->where('execution_mode', 'queue')
            ->where('status', SeasonvarImportStatus::Running->value)
            ->orderBy('id')
            ->limit($batchSize)
            ->get();
        $runSignals = 0;

        foreach ($runs as $run) {
            $runSignals += $this->signalGlobalRun($run) ? 1 : 0;
        }

        return [
            'title_groups' => $groupSignals,
            'global_runs' => $runSignals,
        ];
    }
}
