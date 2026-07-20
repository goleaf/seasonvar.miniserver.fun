<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\Enums\SeasonvarImportTitleGroupStatus;
use App\Enums\SeasonvarPreparedPageStatus;
use App\Jobs\PrepareSeasonvarImportTitlePage;
use App\Models\SeasonvarImportPreparedPage;
use App\Models\SeasonvarImportTitleGroup;

final class SeasonvarPrematurelyFinalizedRunRecovery
{
    public function __construct(
        private readonly SeasonvarGlobalImportRunCoordinator $globalRuns,
        private readonly SeasonvarImportRunRecorder $runs,
        private readonly SeasonvarImportFinalizationDispatcher $finalizers,
    ) {}

    public function recover(int $runId): bool
    {
        $run = $this->globalRuns->resumePrematurelyFinalized($runId);

        if ($run === null) {
            return false;
        }

        $requeued = 0;

        SeasonvarImportPreparedPage::query()
            ->select(['id', 'seasonvar_import_title_group_id'])
            ->with('group:id,queue_name')
            ->where('seasonvar_import_run_id', $run->id)
            ->whereIn('status', [
                SeasonvarPreparedPageStatus::Queued->value,
                SeasonvarPreparedPageStatus::Preparing->value,
            ])
            ->orderBy('id')
            ->chunkById(100, function ($pages) use (&$requeued): void {
                foreach ($pages as $page) {
                    PrepareSeasonvarImportTitlePage::dispatch((int) $page->id)
                        ->onConnection((string) config('seasonvar.queue.connection', 'redis'))
                        ->onQueue((string) $page->group->queue_name)
                        ->afterCommit();
                    $requeued++;
                }
            });
        $groupSignals = 0;

        SeasonvarImportTitleGroup::query()
            ->where('seasonvar_import_run_id', $run->id)
            ->whereIn('status', [
                SeasonvarImportTitleGroupStatus::Discovering->value,
                SeasonvarImportTitleGroupStatus::Running->value,
                SeasonvarImportTitleGroupStatus::Finalizing->value,
            ])
            ->orderBy('id')
            ->chunkById(100, function ($groups) use (&$groupSignals): void {
                foreach ($groups as $group) {
                    $groupSignals += $this->finalizers->signalTitleGroup($group) ? 1 : 0;
                }
            });
        $storedRecovery = data_get($run->fresh()->summary, 'premature_finalization_recovery');
        $recovery = is_array($storedRecovery) ? $storedRecovery : [];
        $run = $this->runs->mergeSummary($run->id, [
            'dispatch_completed' => true,
            'premature_finalization_recovery' => array_merge($recovery, [
                'dispatch_completed_at' => now()->toIso8601String(),
                'prepared_pages_requeued' => $requeued,
                'title_groups_signalled' => $groupSignals,
            ]),
        ]);

        if ($run === null) {
            return false;
        }

        $this->finalizers->signalGlobalRun($run);

        return true;
    }
}
