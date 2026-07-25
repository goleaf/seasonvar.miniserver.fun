<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarActiveRunReconciliationResult;
use App\Enums\SeasonvarImportStatus;
use App\Enums\SeasonvarPreparedPageStatus;
use App\Jobs\ImportSeasonvarSourcePage;
use App\Jobs\PrepareSeasonvarImportTitlePage;
use App\Jobs\ReconcileSeasonvarQueuedImportRun;
use App\Models\SeasonvarImportPreparedPage;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use App\Models\SourcePage;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

final class SeasonvarActiveRunReconciler
{
    public function __construct(
        private readonly SeasonvarImportRunRecorder $runs,
        private readonly SeasonvarImportFinalizationDispatcher $finalizers,
        private readonly SeasonvarImportGroupKey $groupKeys,
    ) {}

    public function reconcile(int $runId): SeasonvarActiveRunReconciliationResult
    {
        if ($runId < 1) {
            return SeasonvarActiveRunReconciliationResult::ineligible();
        }

        $lock = $this->lockStore()->lock(
            'seasonvar-active-run-reconciliation:'.$runId,
            $this->lockSeconds(),
        );

        if (! $lock->get()) {
            return SeasonvarActiveRunReconciliationResult::ineligible();
        }

        try {
            return $this->reconcileUnderLock($runId);
        } finally {
            $lock->release();
        }
    }

    private function reconcileUnderLock(int $runId): SeasonvarActiveRunReconciliationResult
    {
        $run = SeasonvarImportRun::query()
            ->select([
                'id',
                'mode',
                'execution_mode',
                'status',
                'force',
                'summary',
                'started_at',
                'created_at',
            ])
            ->find($runId);

        if ($run === null
            || $run->mode !== 'sitemap'
            || $run->execution_mode !== 'queue'
            || $run->statusValue() !== SeasonvarImportStatus::Running
        ) {
            return SeasonvarActiveRunReconciliationResult::ineligible();
        }

        $dispatchRecovered = false;

        if (data_get($run->summary, 'dispatch_completed') === false) {
            $durableProgressAt = $this->durableDispatchProgressAt($run);

            if ($durableProgressAt->greaterThan($this->staleCutoff())) {
                return SeasonvarActiveRunReconciliationResult::ineligible();
            }

            $ledgerRows = $run->preparedPages()->count();
            $run = $this->runs->mergeSummary($run->id, [
                'dispatch_completed' => true,
                'queued_pages' => $ledgerRows,
                'active_run_reconciliation' => [
                    'reason' => 'redis_transport_reconciled',
                    'durable_progress_at' => $durableProgressAt->toIso8601String(),
                    'recovered_at' => now()->toIso8601String(),
                    'prepared_page_ledger_rows' => $ledgerRows,
                ],
            ]) ?? $run;
            $dispatchRecovered = true;
        }

        $dueCutoff = now()->subSeconds($this->transportReplayAfterSeconds());
        $batchSize = $this->batchSize();
        $preparedCandidates = $this->duePreparedPages(
            $run->id,
            $dueCutoff,
            $batchSize + 1,
        );
        $hasMorePreparedDueWork = $preparedCandidates->count() > $batchSize;
        $preparedPages = $preparedCandidates->take($batchSize);
        $jobsDispatched = 0;
        /** @var array<int, SeasonvarImportTitleGroup> $groups */
        $groups = [];
        $restoredPreparedDispatchFailure = false;

        foreach ($preparedPages as $page) {
            $attemptedAt = now();
            $claimed = SeasonvarImportPreparedPage::query()
                ->whereKey($page->id)
                ->whereIn('status', [
                    SeasonvarPreparedPageStatus::Queued->value,
                    SeasonvarPreparedPageStatus::Preparing->value,
                ])
                ->where('updated_at', '<=', $dueCutoff)
                ->update(['updated_at' => $attemptedAt]);

            if ($claimed !== 1) {
                continue;
            }

            try {
                PrepareSeasonvarImportTitlePage::dispatch((int) $page->id)
                    ->onConnection((string) config('seasonvar.queue.connection', 'redis'))
                    ->onQueue((string) $page->group->queue_name)
                    ->afterCommit();
                $jobsDispatched++;
                $groups[(int) $page->group->id] = $page->group;
            } catch (Throwable $exception) {
                $restored = SeasonvarImportPreparedPage::query()
                    ->whereKey($page->id)
                    ->where('updated_at', $attemptedAt)
                    ->update(['updated_at' => $page->updated_at]);
                $restoredPreparedDispatchFailure =
                    $restoredPreparedDispatchFailure || $restored === 1;
                $this->reportDispatchFailure($run->id, $exception);
            }
        }

        $remainingCapacity = max(0, $batchSize - $jobsDispatched);

        if ($remainingCapacity > 0) {
            $jobsDispatched += $this->requeueLegacyClaimedPages(
                $run,
                $dueCutoff,
                $remainingCapacity,
            );
        }

        if ($jobsDispatched > 0) {
            $this->runs->heartbeat($run->id);
        }

        if ($dispatchRecovered || $jobsDispatched > 0) {
            foreach ($groups as $group) {
                $this->finalizers->signalTitleGroup($group);
            }

            $this->finalizers->signalGlobalRun($run);
        }

        $hasRemainingDueWork = $hasMorePreparedDueWork
            || $restoredPreparedDispatchFailure
            || $this->hasRemainingLegacyDueWork($run->id, $dueCutoff);

        if ($hasRemainingDueWork) {
            ReconcileSeasonvarQueuedImportRun::dispatch($run->id)
                ->onConnection((string) config('seasonvar.queue.connection', 'redis'))
                ->onQueue((string) config('seasonvar.queue.queue', 'seasonvar-import'))
                ->delay(now()->addSecond())
                ->afterCommit();
        }

        return new SeasonvarActiveRunReconciliationResult(
            eligible: true,
            dispatchRecovered: $dispatchRecovered,
            jobsDispatched: $jobsDispatched,
            hasRemainingDueWork: $hasRemainingDueWork,
        );
    }

    private function durableDispatchProgressAt(SeasonvarImportRun $run): Carbon
    {
        $latestPreparedAt = $run->preparedPages()->max('updated_at');
        $progressAt = $run->started_at ?? $run->created_at ?? now();

        if ($latestPreparedAt !== null) {
            $preparedAt = Carbon::parse((string) $latestPreparedAt);

            if ($preparedAt->greaterThan($progressAt)) {
                $progressAt = $preparedAt;
            }
        }

        return $progressAt;
    }

    /** @return Collection<int, SeasonvarImportPreparedPage> */
    private function duePreparedPages(int $runId, Carbon $cutoff, int $limit): Collection
    {
        return SeasonvarImportPreparedPage::query()
            ->select([
                'id',
                'seasonvar_import_title_group_id',
                'status',
                'updated_at',
            ])
            ->with('group:id,seasonvar_import_run_id,queue_name')
            ->where('seasonvar_import_run_id', $runId)
            ->whereIn('status', [
                SeasonvarPreparedPageStatus::Queued->value,
                SeasonvarPreparedPageStatus::Preparing->value,
            ])
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    private function requeueLegacyClaimedPages(
        SeasonvarImportRun $run,
        Carbon $cutoff,
        int $limit,
    ): int {
        $pages = SourcePage::query()
            ->select([
                'id',
                'url',
                'url_hash',
                'page_type',
                'import_claim_token',
                'import_claim_run_id',
                'import_claim_expires_at',
                'updated_at',
            ])
            ->where('import_claim_run_id', $run->id)
            ->whereNotNull('import_claim_token')
            ->where('import_claim_expires_at', '>', now())
            ->where('page_type', '!=', 'serial')
            ->whereNotIn(
                'id',
                SeasonvarImportPreparedPage::query()
                    ->where('seasonvar_import_run_id', $run->id)
                    ->select('source_page_id'),
            )
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->get();
        $dispatched = 0;

        foreach ($pages as $page) {
            $token = is_string($page->import_claim_token) ? $page->import_claim_token : null;

            if ($token === null) {
                continue;
            }

            $attemptedAt = now();
            $claimed = SourcePage::query()
                ->whereKey($page->id)
                ->where('import_claim_run_id', $run->id)
                ->where('import_claim_token', $token)
                ->where('import_claim_expires_at', '>', $attemptedAt)
                ->where('updated_at', '<=', $cutoff)
                ->update(['updated_at' => $attemptedAt]);

            if ($claimed !== 1) {
                continue;
            }

            try {
                ImportSeasonvarSourcePage::dispatch(
                    sourcePageId: (int) $page->id,
                    importRunId: (int) $run->id,
                    claimToken: $token,
                    groupKey: $this->groupKeys->forUrl((string) $page->url, (string) $page->url_hash),
                    force: (bool) $run->force,
                )
                    ->onConnection((string) config('seasonvar.queue.connection', 'redis'))
                    ->onQueue((string) config('seasonvar.queue.queue', 'seasonvar-import'))
                    ->afterCommit();
                $dispatched++;
            } catch (Throwable $exception) {
                SourcePage::query()
                    ->whereKey($page->id)
                    ->where('import_claim_run_id', $run->id)
                    ->where('import_claim_token', $token)
                    ->where('updated_at', $attemptedAt)
                    ->update(['updated_at' => $page->updated_at]);
                $this->reportDispatchFailure($run->id, $exception);
            }
        }

        return $dispatched;
    }

    private function hasRemainingLegacyDueWork(int $runId, Carbon $cutoff): bool
    {
        return SourcePage::query()
            ->where('import_claim_run_id', $runId)
            ->whereNotNull('import_claim_token')
            ->where('import_claim_expires_at', '>', now())
            ->where('page_type', '!=', 'serial')
            ->whereNotIn(
                'id',
                SeasonvarImportPreparedPage::query()
                    ->where('seasonvar_import_run_id', $runId)
                    ->select('source_page_id'),
            )
            ->where('updated_at', '<=', $cutoff)
            ->exists();
    }

    private function staleCutoff(): Carbon
    {
        return now()->subMinutes(max(5, (int) config('seasonvar.queue.stale_after_minutes', 120)));
    }

    private function transportReplayAfterSeconds(): int
    {
        $connection = (string) config('seasonvar.queue.connection', 'redis');

        return max(
            60,
            (int) config('queue.connections.'.$connection.'.retry_after', 1200),
            (int) config('seasonvar.queue.worker_timeout', 900) + 60,
        );
    }

    private function batchSize(): int
    {
        return max(
            1,
            min(1000, (int) config('seasonvar.queue.finalizer_watchdog_batch_size', 250)),
        );
    }

    private function lockSeconds(): int
    {
        return max(60, min(300, $this->transportReplayAfterSeconds()));
    }

    private function lockStore(): Store&LockProvider
    {
        $repository = Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'));

        if (! $repository instanceof CacheRepository) {
            throw new LogicException('Seasonvar reconciliation cache repository is unavailable.');
        }

        $store = $repository->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('Seasonvar reconciliation cache store does not support atomic locks.');
        }

        return $store;
    }

    private function reportDispatchFailure(int $runId, Throwable $exception): void
    {
        Log::warning('Не удалось повторно поставить работу импорта Seasonvar в очередь.', [
            'import_run_id' => $runId,
            'exception' => $exception::class,
        ]);
    }
}
