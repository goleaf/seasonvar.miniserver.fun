<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarQueueStatusData;
use App\Enums\SeasonvarImportStatus;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use App\Models\SourcePage;
use App\Services\Operations\QueueWorkerHeartbeat;
use Carbon\CarbonImmutable;

class SeasonvarQueueStatus
{
    public function __construct(
        private readonly QueueWorkerHeartbeat $workers,
        private readonly SeasonvarGlobalImportRunCoordinator $globalRuns,
    ) {}

    public function read(): SeasonvarQueueStatusData
    {
        $activeRuns = SeasonvarImportRun::query()
            ->where('execution_mode', 'queue')
            ->whereIn('status', [SeasonvarImportStatus::Running->value, SeasonvarImportStatus::Queued->value])
            ->count();
        $run = $this->globalRuns->activeRun()
            ?? SeasonvarImportRun::query()
                ->where('execution_mode', 'queue')
                ->whereIn('status', [
                    SeasonvarImportStatus::Running->value,
                    SeasonvarImportStatus::Queued->value,
                ])
                ->latest('id')
                ->first()
            ?? SeasonvarImportRun::query()
                ->where('mode', 'sitemap')
                ->latest('id')
                ->first();
        $worker = $this->workers->statusFor($this->workerProfile($run));
        $claimQuery = SourcePage::query()
            ->whereNotNull('import_claim_token')
            ->where('import_claim_expires_at', '>', now());

        if ($run instanceof SeasonvarImportRun) {
            $claimQuery->where('import_claim_run_id', $run->id);
        } else {
            $claimQuery->whereRaw('1 = 0');
        }

        $claimState = $claimQuery
            ->selectRaw('COUNT(*) AS aggregate, MIN(import_claim_expires_at) AS earliest_expiry, MAX(import_claim_expires_at) AS latest_expiry')
            ->toBase()
            ->first();
        $groupState = $this->groupState($run);
        $dispatchCompleted = $this->dispatchCompleted($run, $groupState);
        $currentStage = data_get(
            $run?->summary,
            SeasonvarImportFinalizationCoordinator::STATE_KEY.'.current_stage',
        );
        $currentStage = is_string($currentStage) && $currentStage !== '' ? $currentStage : null;
        $phase = $this->phase($run, $dispatchCompleted, $currentStage, $groupState);
        $workerHeartbeat = $this->date($worker['last_heartbeat_at'] ?? null);
        $workerProcessed = $this->date($worker['last_processed_at'] ?? null);
        $progressStale = $this->progressIsStale($run);
        [$transportState, $staleReason] = $this->transportState(
            $run,
            $worker,
            $dispatchCompleted,
            $groupState,
            (int) ($claimState->aggregate ?? 0),
            $progressStale,
        );

        return new SeasonvarQueueStatusData(
            connection: (string) $worker['connection'],
            queue: (string) $worker['queue'],
            pending: (int) $worker['pending'],
            delayed: (int) $worker['delayed'],
            reserved: (int) $worker['reserved'],
            oldestPendingTimestamp: is_int($worker['oldest_pending_age_seconds'])
                ? now()->subSeconds($worker['oldest_pending_age_seconds'])->getTimestamp()
                : null,
            liveClaims: (int) ($claimState->aggregate ?? 0),
            activeRuns: $activeRuns,
            runId: $run?->id,
            runExecutionMode: $run?->execution_mode,
            runStatus: $run?->status,
            lastHeartbeatAt: $run?->last_heartbeat_at,
            selected: (int) $run?->selected,
            parsed: (int) $run?->parsed,
            failed: (int) $run?->failed,
            mediaSizesChecked: (int) $run?->media_sizes_checked,
            mediaSizesKnown: (int) $run?->media_sizes_known,
            mediaSizesUnknown: (int) $run?->media_sizes_unknown,
            mediaSizesUnsupported: (int) $run?->media_sizes_unsupported,
            mediaSizeChecksFailed: (int) $run?->media_size_checks_failed,
            mediaSizeKnownBytes: (int) $run?->media_size_known_bytes,
            phase: $phase,
            dispatchCompleted: $dispatchCompleted,
            dispatchCursor: max(0, (int) data_get($run?->summary, 'dispatch_batches', 0)),
            expectedPages: $groupState['expected'],
            preparedPages: $groupState['prepared'],
            appliedPages: $groupState['applied'],
            failedPages: $groupState['failed'],
            currentFinalizationStage: $currentStage,
            lastProgressAt: $run?->last_progress_at,
            workerHeartbeatAt: $workerHeartbeat,
            workerProcessedAt: $workerProcessed,
            workerStatus: (string) $worker['status'],
            workerMessage: is_string($worker['message'] ?? null) ? $worker['message'] : null,
            transportState: $transportState,
            staleReason: $staleReason,
            earliestClaimExpiryAt: $this->date($claimState->earliest_expiry ?? null),
            latestClaimExpiryAt: $this->date($claimState->latest_expiry ?? null),
            lastTerminalReasonCode: $this->lastTerminalReasonCode($run),
            retentionSnapshot: $this->retentionSnapshot($run),
        );
    }

    private function workerProfile(?SeasonvarImportRun $run): string
    {
        $queue = data_get($run?->summary, 'queue');

        if ($queue === (string) config('seasonvar.title_refresh.queue', 'seasonvar-title-refresh')
            || ($run?->mode === 'url' && ! is_string($queue))
        ) {
            return 'seasonvar_title_refresh';
        }

        return 'seasonvar_import';
    }

    /**
     * @param  array{expected: int, prepared: int, applied: int, failed: int, finalizing: int}  $groups
     */
    private function dispatchCompleted(?SeasonvarImportRun $run, array $groups): ?bool
    {
        if (! $run instanceof SeasonvarImportRun) {
            return null;
        }

        if ($run->execution_mode !== 'queue') {
            return null;
        }

        if ($run->mode !== 'sitemap' && $groups['expected'] > 0) {
            return true;
        }

        return data_get($run->summary, 'dispatch_completed') === true;
    }

    /**
     * @return array{expected: int, prepared: int, applied: int, failed: int, finalizing: int}
     */
    private function groupState(?SeasonvarImportRun $run): array
    {
        if (! $run instanceof SeasonvarImportRun) {
            return [
                'expected' => 0,
                'prepared' => 0,
                'applied' => 0,
                'failed' => 0,
                'finalizing' => 0,
            ];
        }

        $row = SeasonvarImportTitleGroup::query()
            ->where('seasonvar_import_run_id', $run->id)
            ->selectRaw(<<<'SQL'
                COALESCE(SUM(expected_pages), 0) AS expected,
                COALESCE(SUM(prepared_pages), 0) AS prepared,
                COALESCE(SUM(applied_pages), 0) AS applied,
                COALESCE(SUM(failed_pages), 0) AS failed,
                COALESCE(SUM(CASE WHEN status = 'finalizing' THEN 1 ELSE 0 END), 0) AS finalizing
                SQL)
            ->toBase()
            ->first();

        return [
            'expected' => (int) ($row->expected ?? 0),
            'prepared' => (int) ($row->prepared ?? 0),
            'applied' => (int) ($row->applied ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'finalizing' => (int) ($row->finalizing ?? 0),
        ];
    }

    /**
     * @param  array{expected: int, prepared: int, applied: int, failed: int, finalizing: int}  $groups
     */
    private function phase(
        ?SeasonvarImportRun $run,
        ?bool $dispatchCompleted,
        ?string $currentStage,
        array $groups,
    ): string {
        if (! $run instanceof SeasonvarImportRun) {
            return 'idle';
        }

        if (! in_array($run->status, [
            SeasonvarImportStatus::Queued->value,
            SeasonvarImportStatus::Running->value,
        ], true)) {
            return 'terminal';
        }

        if ($dispatchCompleted !== true) {
            return 'dispatching';
        }

        if ($currentStage !== null) {
            return 'finalizing';
        }

        if ($groups['prepared'] < $groups['expected']) {
            return 'preparing';
        }

        if ($groups['finalizing'] > 0 || $groups['expected'] > $groups['applied'] + $groups['failed']) {
            return 'applying';
        }

        return 'finalizing';
    }

    private function progressIsStale(?SeasonvarImportRun $run): bool
    {
        if (! $run instanceof SeasonvarImportRun
            || ! in_array($run->status, [
                SeasonvarImportStatus::Queued->value,
                SeasonvarImportStatus::Running->value,
            ], true)
            || $run->last_progress_at === null
        ) {
            return false;
        }

        return $run->last_progress_at->lessThanOrEqualTo(now()->subMinutes(
            max(5, (int) config('seasonvar.queue.stale_after_minutes', 120)),
        ));
    }

    /**
     * @param  array<string, mixed>  $worker
     * @param  array{expected: int, prepared: int, applied: int, failed: int, finalizing: int}  $groups
     * @return array{string, string|null}
     */
    private function transportState(
        ?SeasonvarImportRun $run,
        array $worker,
        ?bool $dispatchCompleted,
        array $groups,
        int $liveClaims,
        bool $progressStale,
    ): array {
        if (! $run instanceof SeasonvarImportRun
            || ! in_array($run->status, [
                SeasonvarImportStatus::Queued->value,
                SeasonvarImportStatus::Running->value,
            ], true)
        ) {
            return ['idle', null];
        }

        $queued = (int) ($worker['pending'] ?? 0)
            + (int) ($worker['delayed'] ?? 0)
            + (int) ($worker['reserved'] ?? 0);
        $incomplete = max(0, $groups['expected'] - $groups['applied'] - $groups['failed']);

        if ($dispatchCompleted === true && $incomplete > 0 && $queued === 0 && $liveClaims === 0) {
            return ['reconciliation_required', 'nonterminal_staging_without_transport'];
        }

        if ($queued > 0 && ($worker['status'] ?? null) === 'failed') {
            return ['worker_missing', 'worker_heartbeat_missing'];
        }

        if ($progressStale) {
            return ['stalled_no_progress', 'durable_progress_stale'];
        }

        return ['observed', null];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    private function lastTerminalReasonCode(?SeasonvarImportRun $run): ?string
    {
        if (! $run instanceof SeasonvarImportRun) {
            return null;
        }

        $reason = SeasonvarImportTitleGroup::query()
            ->where('seasonvar_import_run_id', $run->id)
            ->whereNotNull('terminal_reason_code')
            ->latest('id')
            ->value('terminal_reason_code');

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /** @return array<string, mixed>|null */
    private function retentionSnapshot(?SeasonvarImportRun $run): ?array
    {
        $snapshot = data_get(
            $run?->summary,
            SeasonvarImportFinalizationCoordinator::STATE_KEY.'.stages.storage_maintenance',
        );

        return is_array($snapshot) ? $snapshot : null;
    }
}
