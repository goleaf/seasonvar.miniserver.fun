<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Enums\SeasonvarImportStatus;
use App\Jobs\FinalizeSeasonvarImportTitleGroup;
use App\Jobs\FinalizeSeasonvarQueuedImport;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use App\Services\Seasonvar\SeasonvarImportTitleGroupReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @phpstan-type AuditSample array{failed_job_id: int, kind: string, target_id: int|null, reason: string, age: string, state: string, disposition: string}
 * @phpstan-type AuditReport array{
 *     status: 'complete',
 *     read_only: true,
 *     failed_jobs: array{total: int, jobs: array<string, int>, categories: array<string, int>, ages: array<string, int>, reasons: array<string, int>},
 *     finalizers: array{total: int, parsed: int, unresolved: int, kinds: array<string, int>, states: array<string, int>, dispositions: array<string, int>, reasons: array<string, int>, ages: array<string, int>, samples: list<AuditSample>},
 *     mutations: array{retried: 0, forgotten: 0, cleared: 0, dispatched: 0}
 * }
 */
final class FailedFinalizerAuditBuilder
{
    private const CHUNK_SIZE = 200;

    private const MAX_SAMPLES_PER_STATE = 10;

    private const FINALIZER_CLASSES = [
        FinalizeSeasonvarImportTitleGroup::class,
        FinalizeSeasonvarQueuedImport::class,
    ];

    private const ACTIVE_GROUP_STATUSES = [
        'discovering',
        'running',
        'finalizing',
    ];

    public function __construct(
        private readonly FailedJobSummaryBuilder $summary,
        private readonly FailedJobMetadataClassifier $metadata,
        private readonly FailedFinalizerPayloadInspector $payloads,
        private readonly SeasonvarImportTitleGroupReconciler $groups,
    ) {}

    /** @return AuditReport */
    public function build(int $sampleLimitPerState = 3): array
    {
        $failedJobs = $this->summary->build();
        $finalizers = [
            'total' => 0,
            'parsed' => 0,
            'unresolved' => 0,
            'kinds' => [],
            'states' => [],
            'dispositions' => [],
            'reasons' => [],
            'ages' => [],
            'samples' => [],
        ];
        $table = (string) config('queue.failed.table', 'failed_jobs');

        if (! Schema::hasTable($table)) {
            return $this->report($failedJobs, $finalizers);
        }

        $sampleLimitPerState = min(
            self::MAX_SAMPLES_PER_STATE,
            max(0, $sampleLimitPerState),
        );
        $sampleBuckets = [];
        $now = CarbonImmutable::now();
        $staleBefore = $this->groups->staleBefore();

        DB::table($table)
            ->select(['id', 'failed_at'])
            ->selectRaw("json_extract(payload, '$.displayName') AS display_name")
            ->selectRaw('substr(payload, 1, ?) AS payload_prefix', [FailedFinalizerPayloadInspector::MAX_PAYLOAD_BYTES + 1])
            ->selectRaw('substr(exception, 1, ?) AS exception_prefix', [FailedJobMetadataClassifier::EXCEPTION_PREFIX_BYTES])
            ->whereRaw('json_valid(payload)')
            ->whereIn(DB::raw("json_extract(payload, '$.displayName')"), self::FINALIZER_CLASSES)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($rows) use (
                &$finalizers,
                &$sampleBuckets,
                $sampleLimitPerState,
                $now,
                $staleBefore,
            ): void {
                $items = [];

                foreach ($rows as $row) {
                    $kind = $row->display_name === FinalizeSeasonvarImportTitleGroup::class
                        ? 'title_group'
                        : 'global_run';
                    $payload = is_string($row->payload_prefix) ? $row->payload_prefix : '';
                    $target = $this->payloads->inspect($payload);
                    $reason = $this->metadata->reasonLabel(
                        is_string($row->exception_prefix) ? $row->exception_prefix : null,
                    );
                    $age = $this->metadata->ageLabel((string) $row->failed_at, $now);
                    $finalizers['total']++;
                    $this->increment($finalizers['kinds'], $kind);
                    $this->increment($finalizers['reasons'], $reason);
                    $this->increment($finalizers['ages'], $age);

                    if ($target === null || $target['type'] !== $kind) {
                        $finalizers['unresolved']++;
                        $this->record(
                            finalizers: $finalizers,
                            sampleBuckets: $sampleBuckets,
                            sampleLimitPerState: $sampleLimitPerState,
                            failedJobId: (int) $row->id,
                            kind: $kind,
                            targetId: null,
                            reason: $reason,
                            age: $age,
                            state: 'payload_unresolved',
                        );

                        continue;
                    }

                    $finalizers['parsed']++;
                    $items[] = [
                        'failed_job_id' => (int) $row->id,
                        'kind' => $kind,
                        'target_id' => $target['target_id'],
                        'reason' => $reason,
                        'age' => $age,
                    ];
                }

                $this->mapCurrentState(
                    $items,
                    $finalizers,
                    $sampleBuckets,
                    $sampleLimitPerState,
                    $staleBefore,
                );
            }, 'id');

        foreach (['kinds', 'states', 'dispositions', 'reasons', 'ages'] as $key) {
            ksort($finalizers[$key]);
        }

        ksort($sampleBuckets);
        $finalizers['samples'] = array_merge(...array_values($sampleBuckets ?: [[]]));

        return $this->report($failedJobs, $finalizers);
    }

    /**
     * @param  list<array{failed_job_id: int, kind: string, target_id: int, reason: string, age: string}>  $items
     * @param  array{total: int, parsed: int, unresolved: int, kinds: array<string, int>, states: array<string, int>, dispositions: array<string, int>, reasons: array<string, int>, ages: array<string, int>, samples: list<AuditSample>}  $finalizers
     * @param  array<string, list<AuditSample>>  $sampleBuckets
     */
    private function mapCurrentState(
        array $items,
        array &$finalizers,
        array &$sampleBuckets,
        int $sampleLimitPerState,
        Carbon $staleBefore,
    ): void {
        if ($items === []) {
            return;
        }

        $groupIds = [];
        $globalRunIds = [];

        foreach ($items as $item) {
            if ($item['kind'] === 'title_group') {
                $groupIds[] = $item['target_id'];
            } else {
                $globalRunIds[] = $item['target_id'];
            }
        }

        $groups = $this->groupsById($groupIds);
        $runIds = array_merge(
            $globalRunIds,
            array_map(
                fn (SeasonvarImportTitleGroup $group): int => (int) $group->seasonvar_import_run_id,
                array_values($groups),
            ),
        );
        $runs = $this->runsById($runIds);
        $groupClaims = $this->liveClaimGroupIds($groupIds);
        $runClaims = $this->liveClaimRunIds($globalRunIds);
        $activeGroupRuns = $this->activeGroupRunIds($globalRunIds);

        foreach ($items as $item) {
            $state = $item['kind'] === 'title_group'
                ? $this->groupState($item['target_id'], $groups, $runs, $groupClaims, $staleBefore)
                : $this->runState($item['target_id'], $runs, $runClaims, $activeGroupRuns);
            $this->record(
                finalizers: $finalizers,
                sampleBuckets: $sampleBuckets,
                sampleLimitPerState: $sampleLimitPerState,
                failedJobId: $item['failed_job_id'],
                kind: $item['kind'],
                targetId: $item['target_id'],
                reason: $item['reason'],
                age: $item['age'],
                state: $state,
            );
        }
    }

    /**
     * @param  list<int>  $groupIds
     * @return array<int, SeasonvarImportTitleGroup>
     */
    private function groupsById(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $groups = [];

        foreach (SeasonvarImportTitleGroup::query()
            ->select([
                'id',
                'seasonvar_import_run_id',
                'status',
                'expected_pages',
                'prepared_pages',
                'failed_pages',
                'updated_at',
            ])
            ->whereKey(array_values(array_unique($groupIds)))
            ->get() as $group) {
            $groups[(int) $group->id] = $group;
        }

        return $groups;
    }

    /**
     * @param  list<int>  $runIds
     * @return array<int, SeasonvarImportRun>
     */
    private function runsById(array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }

        $runs = [];

        foreach (SeasonvarImportRun::query()
            ->select(['id', 'mode', 'execution_mode', 'status'])
            ->whereKey(array_values(array_unique($runIds)))
            ->get() as $run) {
            $runs[(int) $run->id] = $run;
        }

        return $runs;
    }

    /**
     * @param  list<int>  $groupIds
     * @return array<int, true>
     */
    private function liveClaimGroupIds(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $ids = DB::table('seasonvar_import_prepared_pages as prepared')
            ->join('source_pages as source', 'source.id', '=', 'prepared.source_page_id')
            ->whereIn('prepared.seasonvar_import_title_group_id', array_values(array_unique($groupIds)))
            ->whereNotNull('source.import_claim_token')
            ->where('source.import_claim_expires_at', '>', now())
            ->distinct()
            ->pluck('prepared.seasonvar_import_title_group_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_fill_keys($ids, true);
    }

    /**
     * @param  list<int>  $runIds
     * @return array<int, true>
     */
    private function liveClaimRunIds(array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }

        $ids = DB::table('source_pages')
            ->whereIn('import_claim_run_id', array_values(array_unique($runIds)))
            ->whereNotNull('import_claim_token')
            ->where('import_claim_expires_at', '>', now())
            ->distinct()
            ->pluck('import_claim_run_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_fill_keys($ids, true);
    }

    /**
     * @param  list<int>  $runIds
     * @return array<int, true>
     */
    private function activeGroupRunIds(array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }

        $ids = SeasonvarImportTitleGroup::query()
            ->whereIn('seasonvar_import_run_id', array_values(array_unique($runIds)))
            ->whereIn('status', self::ACTIVE_GROUP_STATUSES)
            ->distinct()
            ->pluck('seasonvar_import_run_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_fill_keys($ids, true);
    }

    /**
     * @param  array<int, SeasonvarImportTitleGroup>  $groups
     * @param  array<int, SeasonvarImportRun>  $runs
     * @param  array<int, true>  $liveClaims
     */
    private function groupState(
        int $groupId,
        array $groups,
        array $runs,
        array $liveClaims,
        Carbon $staleBefore,
    ): string {
        $group = $groups[$groupId] ?? null;

        if ($group === null) {
            return 'target_missing';
        }

        if ($group->status->isTerminal()) {
            return 'target_terminal';
        }

        $run = $runs[(int) $group->seasonvar_import_run_id] ?? null;

        if ($run === null
            || $run->execution_mode !== 'queue'
            || $run->statusValue() !== SeasonvarImportStatus::Running
        ) {
            return 'parent_inconsistent';
        }

        if (isset($liveClaims[$groupId])) {
            return 'active_work';
        }

        $counterReady = $group->expected_pages > 0
            && $group->expected_pages <= $group->prepared_pages + $group->failed_pages;
        $stale = $group->updated_at !== null && $group->updated_at->lessThanOrEqualTo($staleBefore);

        return $counterReady || $stale
            ? 'canonical_signal_candidate'
            : 'active_work';
    }

    /**
     * @param  array<int, SeasonvarImportRun>  $runs
     * @param  array<int, true>  $liveClaims
     * @param  array<int, true>  $activeGroupRuns
     */
    private function runState(int $runId, array $runs, array $liveClaims, array $activeGroupRuns): string
    {
        $run = $runs[$runId] ?? null;

        if ($run === null) {
            return 'target_missing';
        }

        if (! $run->statusValue()->isActive()) {
            return 'target_terminal';
        }

        if ($run->mode !== 'sitemap' || $run->execution_mode !== 'queue') {
            return 'parent_inconsistent';
        }

        if ($run->statusValue() !== SeasonvarImportStatus::Running
            || isset($liveClaims[$runId])
            || isset($activeGroupRuns[$runId])
        ) {
            return 'active_work';
        }

        return 'canonical_signal_candidate';
    }

    /**
     * @param  array{total: int, parsed: int, unresolved: int, kinds: array<string, int>, states: array<string, int>, dispositions: array<string, int>, reasons: array<string, int>, ages: array<string, int>, samples: list<AuditSample>}  $finalizers
     * @param  array<string, list<AuditSample>>  $sampleBuckets
     */
    private function record(
        array &$finalizers,
        array &$sampleBuckets,
        int $sampleLimitPerState,
        int $failedJobId,
        string $kind,
        ?int $targetId,
        string $reason,
        string $age,
        string $state,
    ): void {
        $disposition = self::dispositionFor($state);
        $this->increment($finalizers['states'], $state);
        $this->increment($finalizers['dispositions'], $disposition);

        if (count($sampleBuckets[$state] ?? []) >= $sampleLimitPerState) {
            return;
        }

        $sampleBuckets[$state][] = [
            'failed_job_id' => $failedJobId,
            'kind' => $kind,
            'target_id' => $targetId,
            'reason' => $reason,
            'age' => $age,
            'state' => $state,
            'disposition' => $disposition,
        ];
    }

    public static function dispositionFor(string $state): string
    {
        return match ($state) {
            'target_missing', 'target_terminal' => 'forget_candidate',
            'active_work' => 'retain',
            'canonical_signal_candidate' => 'canonical_signal_candidate',
            default => 'manual_review',
        };
    }

    /** @param array<string, int> $counts */
    private function increment(array &$counts, string $label): void
    {
        $counts[$label] = ($counts[$label] ?? 0) + 1;
    }

    /**
     * @param  array{total: int, jobs: array<string, int>, categories: array<string, int>, ages: array<string, int>, reasons: array<string, int>}  $failedJobs
     * @param  array{total: int, parsed: int, unresolved: int, kinds: array<string, int>, states: array<string, int>, dispositions: array<string, int>, reasons: array<string, int>, ages: array<string, int>, samples: list<AuditSample>}  $finalizers
     * @return AuditReport
     */
    private function report(array $failedJobs, array $finalizers): array
    {
        return [
            'status' => 'complete',
            'read_only' => true,
            'failed_jobs' => $failedJobs,
            'finalizers' => $finalizers,
            'mutations' => [
                'retried' => 0,
                'forgotten' => 0,
                'cleared' => 0,
                'dispatched' => 0,
            ],
        ];
    }
}
