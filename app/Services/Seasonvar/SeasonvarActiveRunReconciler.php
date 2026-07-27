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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

final class SeasonvarActiveRunReconciler
{
    public function __construct(
        private readonly SeasonvarImportRunRecorder $runs,
        private readonly SeasonvarImportFinalizationDispatcher $finalizers,
        private readonly SeasonvarImportGroupKey $groupKeys,
        private readonly SeasonvarImportDispatchBatcher $batcher,
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
        $pagesRegistered = 0;
        $jobsDispatched = 0;
        $dispatchHasMore = false;

        if (data_get($run->summary, 'dispatch_completed') === false) {
            $summary = $run->summary ?? [];
            $discoveryCompleted = array_key_exists(
                'discovery_completed',
                $summary,
            )
                ? $summary['discovery_completed'] === true
                : ! (bool) ($summary['discover'] ?? true);

            if (! $discoveryCompleted) {
                return SeasonvarActiveRunReconciliationResult::ineligible();
            }

            $batch = $this->batcher->dispatchNext($run->id);
            $pagesRegistered = $batch->registeredPages;
            $jobsDispatched = $batch->jobsDispatched;
            $dispatchHasMore = $batch->hasMore;
            $dispatchRecovered = $pagesRegistered > 0
                || $batch->dispatchCompleted;
            $run = $run->fresh();
        }

        $dueCutoff = now()->subSeconds($this->transportReplayAfterSeconds());
        $batchSize = max(0, $this->batchSize() - $jobsDispatched);
        $hasMoreStalePreparingWork = $this->resetStalePreparingPages(
            $run->id,
            $dueCutoff,
            $batchSize,
        );
        $preparedCandidates = $this->duePreparedPages(
            $run->id,
            $dueCutoff,
            $batchSize + 1,
        );
        $hasMorePreparedDueWork = $preparedCandidates->count() > $batchSize;
        $preparedPages = $preparedCandidates->take($batchSize);
        /** @var array<int, SeasonvarImportTitleGroup> $groups */
        $groups = [];
        $preparedDispatchFailed = false;
        $successfulPreparedIds = [];
        $attemptedAt = now();

        foreach ($preparedPages as $page) {
            try {
                PrepareSeasonvarImportTitlePage::dispatch((int) $page->id)
                    ->onConnection((string) config('seasonvar.queue.connection', 'redis'))
                    ->onQueue((string) $page->group->queue_name)
                    ->afterCommit();
                $jobsDispatched++;
                $successfulPreparedIds[] = (int) $page->id;
                $groups[(int) $page->group->id] = $page->group;
            } catch (Throwable $exception) {
                $preparedDispatchFailed = true;
                $this->reportDispatchFailure($run->id, $exception);
            }
        }

        if ($successfulPreparedIds !== []) {
            SeasonvarImportPreparedPage::query()
                ->whereIn('id', $successfulPreparedIds)
                ->where('status', SeasonvarPreparedPageStatus::Queued->value)
                ->update([
                    'last_enqueue_attempt_at' => $attemptedAt,
                    'enqueue_attempts' => DB::raw('enqueue_attempts + 1'),
                ]);
        }

        $preparedJobsDispatched = count($successfulPreparedIds);
        $remainingCapacity = max(0, $batchSize - $preparedJobsDispatched);

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

        $hasRemainingDueWork = $hasMoreStalePreparingWork
            || $hasMorePreparedDueWork
            || $preparedDispatchFailed
            || $this->hasRemainingLegacyDueWork($run->id, $dueCutoff);

        if ($hasRemainingDueWork && ! $dispatchHasMore) {
            ReconcileSeasonvarQueuedImportRun::dispatch($run->id)
                ->onConnection((string) config('seasonvar.queue.connection', 'redis'))
                ->onQueue((string) config('seasonvar.queue.queue', 'seasonvar-import'))
                ->delay(now()->addSecond())
                ->afterCommit();
        }

        return new SeasonvarActiveRunReconciliationResult(
            eligible: true,
            dispatchRecovered: $dispatchRecovered,
            pagesRegistered: $pagesRegistered,
            jobsDispatched: $jobsDispatched,
            hasRemainingDueWork: $hasRemainingDueWork,
        );
    }

    /** @return Collection<int, SeasonvarImportPreparedPage> */
    private function duePreparedPages(int $runId, Carbon $cutoff, int $limit): Collection
    {
        return SeasonvarImportPreparedPage::query()
            ->select([
                'id',
                'seasonvar_import_title_group_id',
                'status',
                'last_enqueue_attempt_at',
                'updated_at',
            ])
            ->with('group:id,seasonvar_import_run_id,queue_name')
            ->where('seasonvar_import_run_id', $runId)
            ->where('status', SeasonvarPreparedPageStatus::Queued->value)
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_enqueue_attempt_at')
                    ->orWhere('last_enqueue_attempt_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    private function resetStalePreparingPages(
        int $runId,
        Carbon $cutoff,
        int $limit,
    ): bool {
        if ($limit < 1) {
            return SeasonvarImportPreparedPage::query()
                ->where('seasonvar_import_run_id', $runId)
                ->where('status', SeasonvarPreparedPageStatus::Preparing->value)
                ->where('updated_at', '<=', $cutoff)
                ->exists();
        }

        $candidateIds = SeasonvarImportPreparedPage::query()
            ->where('seasonvar_import_run_id', $runId)
            ->where('status', SeasonvarPreparedPageStatus::Preparing->value)
            ->where('updated_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit + 1)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id);
        $hasMore = $candidateIds->count() > $limit;
        $resetIds = $candidateIds->take($limit)->all();

        if ($resetIds !== []) {
            SeasonvarImportPreparedPage::query()
                ->whereIn('id', $resetIds)
                ->where('status', SeasonvarPreparedPageStatus::Preparing->value)
                ->where('updated_at', '<=', $cutoff)
                ->update([
                    'status' => SeasonvarPreparedPageStatus::Queued->value,
                    'updated_at' => now(),
                ]);
        }

        return $hasMore;
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
