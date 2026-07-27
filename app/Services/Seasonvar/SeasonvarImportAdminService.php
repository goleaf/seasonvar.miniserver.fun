<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\LicensedMediaFileSizeBacklogStatusData;
use App\DTOs\Seasonvar\SeasonvarImportStartResultData;
use App\DTOs\Seasonvar\SeasonvarQueueStatusData;
use App\Enums\SeasonvarImportStatus;
use App\Jobs\StartSeasonvarQueuedImport;
use App\Models\SeasonvarImportRun;
use App\Models\User;
use App\Services\Media\LicensedMediaFileSizeBackfillSchedule;
use App\Services\Media\LicensedMediaFileSizeBacklog;
use App\Support\HumanFileSizeFormatter;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SeasonvarImportAdminService
{
    public function __construct(
        private readonly SeasonvarPageClaimManager $claims,
        private readonly SeasonvarImportErrorSanitizer $errors,
        private readonly SeasonvarGlobalImportRunCoordinator $globalRuns,
        private readonly BusDispatcher $bus,
        private readonly HumanFileSizeFormatter $fileSizes,
        private readonly LicensedMediaFileSizeBacklog $fileSizeBacklog,
        private readonly SeasonvarQueueStatus $queueStatus,
    ) {}

    public function start(
        User $user,
        bool $force = false,
        bool $discover = true,
        ?SeasonvarImportRun $retryOf = null,
    ): SeasonvarImportStartResultData {
        Gate::forUser($user)->authorize('manage-seasonvar-imports');
        $this->recoverStale();

        $result = $this->globalRuns->acquire(
            force: $force,
            discover: $discover,
            requestedByUserId: (int) $user->id,
            retryOfRunId: $retryOf?->id,
        );

        if ($result->created) {
            try {
                $this->bus->dispatch(new StartSeasonvarQueuedImport($result->run->id));
            } catch (Throwable $exception) {
                $this->markFailed($result->run, $exception);

                throw $exception;
            }
        }

        return $result;
    }

    public function retry(User $user, int $runId): SeasonvarImportStartResultData
    {
        Gate::forUser($user)->authorize('manage-seasonvar-imports');
        $run = SeasonvarImportRun::query()->findOrFail($runId);

        if (! $this->effectiveStatus($run)->isRetryable()) {
            throw ValidationException::withMessages([
                'run' => 'Повторить можно только неудачный или частично завершённый запуск.',
            ]);
        }

        $discover = (bool) data_get($run->summary, 'discover', true);

        return $this->start($user, (bool) $run->force, $discover, $run);
    }

    public function cancel(User $user, int $runId): SeasonvarImportRun
    {
        Gate::forUser($user)->authorize('manage-seasonvar-imports');
        $run = SeasonvarImportRun::query()->findOrFail($runId);

        SeasonvarImportRun::query()
            ->whereKey($run->id)
            ->whereIn('status', [SeasonvarImportStatus::Queued->value, SeasonvarImportStatus::Running->value])
            ->update([
                'status' => SeasonvarImportStatus::Cancelled->value,
                'cancel_requested_at' => now(),
                'finished_at' => now(),
                'last_heartbeat_at' => now(),
                'updated_at' => now(),
            ]);

        $this->claims->releaseForRun($run->id);

        return $run->fresh();
    }

    public function recoverStale(): int
    {
        return $this->globalRuns->recoverStale();
    }

    public function markRetrying(SeasonvarImportRun $run, Throwable $exception): void
    {
        SeasonvarImportRun::query()
            ->whereKey($run->id)
            ->whereIn('status', [SeasonvarImportStatus::Queued->value, SeasonvarImportStatus::Running->value])
            ->update([
                'status' => SeasonvarImportStatus::Queued->value,
                'last_error' => $this->errors->fromException($exception),
                'last_progress_at' => now(),
                'last_heartbeat_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function markFailed(SeasonvarImportRun $run, ?Throwable $exception): void
    {
        SeasonvarImportRun::query()
            ->whereKey($run->id)
            ->whereIn('status', [SeasonvarImportStatus::Queued->value, SeasonvarImportStatus::Running->value])
            ->update([
                'status' => SeasonvarImportStatus::Failed->value,
                'last_error' => $this->errors->fromException($exception),
                'finished_at' => now(),
                'last_progress_at' => now(),
                'last_heartbeat_at' => now(),
                'updated_at' => now(),
            ]);

        $this->claims->releaseForRun($run->id);
    }

    /**
     * @return array{runs: list<array<string, mixed>>, has_active_run: bool, stale_count: int, queue_status: array<string, mixed>, media_health: list<array<string, mixed>>, media_due_count: int, media_size_backlog: array<string, mixed>}
     */
    public function dashboard(): array
    {
        $runs = SeasonvarImportRun::query()
            ->select([
                'id',
                'status',
                'force',
                'selected',
                'parsed',
                'failed',
                'stored',
                'media_attached',
                'media_updated',
                'media_skipped',
                'media_failed',
                'media_sizes_checked',
                'media_sizes_known',
                'media_sizes_unknown',
                'media_sizes_unsupported',
                'media_size_checks_failed',
                'media_size_known_bytes',
                'summary',
                'last_error',
                'requested_by_user_id',
                'retry_of_run_id',
                'last_heartbeat_at',
                'started_at',
                'finished_at',
                'updated_at',
            ])
            ->with('requestedBy:id,name')
            ->latest('id')
            ->limit(20)
            ->get();
        $healthCounts = DB::table('licensed_media')
            ->selectRaw("'health' AS metric, health_status AS value, COUNT(*) AS aggregate")
            ->groupBy('health_status');
        $dueCount = DB::table('licensed_media')
            ->selectRaw("'due' AS metric, NULL AS value, COUNT(*) AS aggregate")
            ->whereIn('health_status', ['active', 'degraded', 'unavailable'])
            ->where(function (QueryBuilder $query): void {
                $query->whereNull('next_check_at')->orWhere('next_check_at', '<=', now());
            });
        $healthMetrics = $healthCounts
            ->unionAll($dueCount)
            ->get();
        $healthByStatus = $healthMetrics
            ->where('metric', 'health')
            ->mapWithKeys(fn (object $row): array => [(string) $row->value => (int) $row->aggregate]);
        $dueMetric = $healthMetrics->firstWhere('metric', 'due');
        $mediaDueCount = is_object($dueMetric) ? (int) $dueMetric->aggregate : 0;

        return [
            'runs' => $runs->map(fn (SeasonvarImportRun $run): array => $this->present($run))->all(),
            'has_active_run' => $this->hasActiveRun(),
            'stale_count' => $this->globalRuns->staleCount(),
            'queue_status' => $this->presentQueueStatus($this->queueStatus->read()),
            'media_health' => collect([
                ['status' => 'active', 'label' => 'Активно', 'icon' => 'fa-solid fa-circle-check', 'tone' => 'text-emerald-700'],
                ['status' => 'degraded', 'label' => 'Нестабильно', 'icon' => 'fa-solid fa-triangle-exclamation', 'tone' => 'text-amber-700'],
                ['status' => 'unavailable', 'label' => 'Недоступно', 'icon' => 'fa-solid fa-circle-xmark', 'tone' => 'text-rose-700'],
                ['status' => 'disabled', 'label' => 'Отключено', 'icon' => 'fa-solid fa-ban', 'tone' => 'text-slate-500'],
            ])->map(function (array $item) use ($healthByStatus): array {
                $item['count'] = (int) ($healthByStatus[$item['status']] ?? 0);

                return $item;
            })->all(),
            'media_due_count' => $mediaDueCount,
            'media_size_backlog' => $this->presentFileSizeBacklog($this->fileSizeBacklog->status()),
        ];
    }

    /** @return array<string, mixed> */
    private function presentQueueStatus(SeasonvarQueueStatusData $status): array
    {
        $locale = app()->currentLocale();
        $number = static fn (int $value): string => Number::format($value, locale: $locale);
        $date = static fn (?CarbonInterface $value): string => $value?->format('d.m.Y H:i:s') ?? '—';
        $phase = $this->queuePhasePresentation($status->phase);
        $transport = $this->queueTransportPresentation($status->transportState);
        $worker = $this->queueWorkerPresentation($status->workerStatus);

        return [
            'phase' => $phase,
            'transport' => $transport,
            'worker' => $worker,
            'metrics' => [
                [
                    'key' => 'pending',
                    'label' => __('catalog.importer.queue_pending'),
                    'value' => $number($status->pending),
                    'icon' => 'fa-solid fa-inbox',
                    'tone' => 'text-sky-700',
                ],
                [
                    'key' => 'delayed',
                    'label' => __('catalog.importer.queue_delayed'),
                    'value' => $number($status->delayed),
                    'icon' => 'fa-solid fa-clock',
                    'tone' => 'text-amber-700',
                ],
                [
                    'key' => 'reserved',
                    'label' => __('catalog.importer.queue_reserved'),
                    'value' => $number($status->reserved),
                    'icon' => 'fa-solid fa-gears',
                    'tone' => 'text-violet-700',
                ],
                [
                    'key' => 'claims',
                    'label' => __('catalog.importer.queue_claims'),
                    'value' => $number($status->liveClaims),
                    'icon' => 'fa-solid fa-lock',
                    'tone' => 'text-emerald-700',
                ],
            ],
            'durable_progress' => __('catalog.importer.queue_durable_progress', [
                'expected' => $number($status->expectedPages),
                'prepared' => $number($status->preparedPages),
                'applied' => $number($status->appliedPages),
                'failed' => $number($status->failedPages),
            ]),
            'dispatch' => __('catalog.importer.queue_dispatch', [
                'state' => match ($status->dispatchCompleted) {
                    true => __('catalog.importer.queue_dispatch_complete'),
                    false => __('catalog.importer.queue_dispatch_in_progress'),
                    null => __('catalog.importer.queue_dispatch_not_applicable'),
                },
                'cursor' => $number($status->dispatchCursor),
            ]),
            'last_progress' => __('catalog.importer.queue_last_progress', [
                'value' => $date($status->lastProgressAt),
            ]),
            'worker_heartbeat' => __('catalog.importer.queue_worker_heartbeat', [
                'value' => $date($status->workerHeartbeatAt),
            ]),
            'claim_expiry' => __('catalog.importer.queue_claim_expiry', [
                'value' => $date($status->earliestClaimExpiryAt),
            ]),
            'finalization_stage' => $status->currentFinalizationStage === null
                ? null
                : __('catalog.importer.queue_finalization_stage', [
                    'value' => $status->currentFinalizationStage,
                ]),
            'stale_reason' => $status->staleReason === null
                ? null
                : __('catalog.importer.queue_stale_reason_'.$status->staleReason),
            'terminal_reason' => $status->lastTerminalReasonCode === null
                ? null
                : __('catalog.importer.queue_terminal_reason', [
                    'value' => $status->lastTerminalReasonCode,
                ]),
        ];
    }

    /** @return array{code: string, label: string, tone: string, icon: string} */
    private function queuePhasePresentation(string $phase): array
    {
        $normalized = in_array($phase, [
            'idle',
            'terminal',
            'dispatching',
            'preparing',
            'applying',
            'finalizing',
        ], true) ? $phase : 'idle';

        return [
            'code' => $normalized,
            'label' => __('catalog.importer.queue_phase_'.$normalized),
            'tone' => in_array($normalized, ['dispatching', 'preparing', 'applying', 'finalizing'], true)
                ? 'text-sky-700'
                : 'text-slate-600',
            'icon' => $normalized === 'terminal'
                ? 'fa-solid fa-circle-check'
                : ($normalized === 'idle' ? 'fa-solid fa-circle-pause' : 'fa-solid fa-spinner fa-spin'),
        ];
    }

    /** @return array{code: string, label: string, tone: string, icon: string} */
    private function queueTransportPresentation(string $transport): array
    {
        $normalized = in_array($transport, [
            'idle',
            'observed',
            'worker_missing',
            'stalled_no_progress',
            'reconciliation_required',
        ], true) ? $transport : 'idle';

        return [
            'code' => $normalized,
            'label' => __('catalog.importer.queue_transport_'.$normalized),
            'tone' => match ($normalized) {
                'observed' => 'text-emerald-700',
                'stalled_no_progress' => 'text-amber-700',
                'worker_missing', 'reconciliation_required' => 'text-red-700',
                default => 'text-slate-600',
            },
            'icon' => match ($normalized) {
                'observed' => 'fa-solid fa-circle-check',
                'stalled_no_progress' => 'fa-solid fa-triangle-exclamation',
                'worker_missing', 'reconciliation_required' => 'fa-solid fa-circle-xmark',
                default => 'fa-solid fa-circle-pause',
            },
        ];
    }

    /** @return array{code: string, label: string, tone: string, icon: string} */
    private function queueWorkerPresentation(string $worker): array
    {
        $normalized = in_array($worker, ['idle', 'ok', 'degraded', 'failed'], true)
            ? $worker
            : 'failed';

        return [
            'code' => $normalized,
            'label' => __('catalog.importer.queue_worker_'.$normalized),
            'tone' => match ($normalized) {
                'ok' => 'text-emerald-700',
                'degraded' => 'text-amber-700',
                'failed' => 'text-red-700',
                default => 'text-slate-600',
            },
            'icon' => match ($normalized) {
                'ok' => 'fa-solid fa-circle-check',
                'degraded' => 'fa-solid fa-triangle-exclamation',
                'failed' => 'fa-solid fa-circle-xmark',
                default => 'fa-solid fa-circle-pause',
            },
        ];
    }

    /** @return array<string, mixed> */
    private function presentFileSizeBacklog(LicensedMediaFileSizeBacklogStatusData $backlog): array
    {
        $locale = app()->currentLocale();
        $backfillSchedule = LicensedMediaFileSizeBackfillSchedule::fromConfig();
        $number = fn (int|float $value, ?int $maxPrecision = null): string => Number::format(
            $value,
            maxPrecision: $maxPrecision,
            locale: $locale,
        );

        return [
            'metrics' => [
                [
                    'key' => 'eligible',
                    'label' => __('catalog.importer.file_size_backlog_eligible'),
                    'value' => $number($backlog->eligible),
                    'icon' => 'fa-solid fa-file-video',
                    'tone' => 'text-sky-700',
                ],
                [
                    'key' => 'known',
                    'label' => __('catalog.importer.file_size_backlog_known'),
                    'value' => $number($backlog->known),
                    'icon' => 'fa-solid fa-circle-check',
                    'tone' => 'text-emerald-700',
                ],
                [
                    'key' => 'pending',
                    'label' => __('catalog.importer.file_size_backlog_pending'),
                    'value' => $number($backlog->pending),
                    'icon' => 'fa-solid fa-hourglass-half',
                    'tone' => 'text-amber-700',
                ],
                [
                    'key' => 'due',
                    'label' => __('catalog.importer.file_size_backlog_due'),
                    'value' => $number($backlog->due),
                    'icon' => 'fa-solid fa-clock-rotate-left',
                    'tone' => 'text-rose-700',
                ],
            ],
            'coverage' => __('catalog.importer.file_size_backlog_coverage', [
                'percentage' => $number($backlog->inspectionCoveragePercentage(), 2),
            ]),
            'states' => __('catalog.importer.file_size_backlog_states', [
                'checked' => $number($backlog->checked),
                'unknown' => $number($backlog->unknown),
                'unsupported' => $number($backlog->unsupported),
                'failed' => $number($backlog->failed),
            ]),
            'known_bytes' => __('catalog.importer.file_size_backlog_bytes', [
                'size' => $this->fileSizes->format($backlog->knownBytes, $locale) ?? '0 B',
                'bytes' => $number($backlog->knownBytes),
            ]),
            'captured_at' => __('catalog.importer.file_size_backlog_captured_at', [
                'value' => $backlog->capturedAt->format('d.m.Y H:i:s'),
            ]),
            'stale' => $backlog->isStale(),
            'stale_notice' => __('catalog.importer.file_size_backlog_stale'),
            'scheduled_batch' => __('catalog.importer.file_size_backlog_scheduled_batch', [
                'count' => $number($backfillSchedule->limit),
                'seconds' => $number($backfillSchedule->timeBudgetSeconds),
            ]),
        ];
    }

    private function hasActiveRun(): bool
    {
        return $this->globalRuns->hasActiveRun();
    }

    private function effectiveStatus(SeasonvarImportRun $run): SeasonvarImportStatus
    {
        $status = $run->statusValue();

        if ($status === SeasonvarImportStatus::Completed && $run->completionStatus() === SeasonvarImportStatus::Partial->value) {
            return SeasonvarImportStatus::Partial;
        }

        return $status;
    }

    private function isStale(SeasonvarImportRun $run): bool
    {
        if ($run->statusValue() !== SeasonvarImportStatus::Running) {
            return false;
        }

        $heartbeat = $run->last_heartbeat_at ?? $run->updated_at;
        $minutes = max(5, (int) config('seasonvar.queue.stale_after_minutes', 120));

        return $heartbeat !== null && $heartbeat->lessThanOrEqualTo(now()->subMinutes($minutes));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SeasonvarImportRun $run): array
    {
        $status = $this->effectiveStatus($run);
        $processed = min((int) $run->selected, (int) $run->parsed + (int) $run->failed);
        $progress = (int) $run->selected > 0
            ? min(100, (int) round(($processed / (int) $run->selected) * 100))
            : ($status->isActive() ? 0 : 100);

        return [
            'id' => (int) $run->id,
            'status' => $status->value,
            'status_label' => $status->label(),
            'tone' => $status->tone(),
            'force' => (bool) $run->force,
            'discover' => (bool) data_get($run->summary, 'discover', true),
            'requested_by' => $run->requestedBy?->name,
            'retry_of_run_id' => $run->retry_of_run_id,
            'selected' => (int) $run->selected,
            'parsed' => (int) $run->parsed,
            'failed' => (int) $run->failed,
            'stored' => (int) $run->stored,
            'media_attached' => (int) $run->media_attached,
            'media_updated' => (int) $run->media_updated,
            'media_skipped' => (int) $run->media_skipped,
            'media_failed' => (int) $run->media_failed,
            'media_sizes_checked' => (int) $run->media_sizes_checked,
            'media_sizes_known' => (int) $run->media_sizes_known,
            'media_sizes_unknown' => (int) $run->media_sizes_unknown,
            'media_sizes_unsupported' => (int) $run->media_sizes_unsupported,
            'media_size_checks_failed' => (int) $run->media_size_checks_failed,
            'media_size_known_bytes' => (int) $run->media_size_known_bytes,
            'media_size_known_label' => $this->fileSizes->format((int) $run->media_size_known_bytes) ?? __('catalog.download.size_unknown'),
            'created' => (int) $run->stored + (int) $run->media_attached,
            'updated' => (int) $run->parsed + (int) $run->media_updated,
            'skipped' => max(0, (int) $run->selected - (int) $run->parsed - (int) $run->failed) + (int) $run->media_skipped,
            'failed_total' => (int) $run->failed + (int) $run->media_failed,
            'progress' => $progress,
            'error' => $run->last_error ? $this->errors->sanitize($run->last_error) : null,
            'started_at' => $run->started_at?->format('d.m.Y H:i:s') ?? '—',
            'finished_at' => $run->finished_at?->format('d.m.Y H:i:s') ?? '—',
            'heartbeat_at' => $run->last_heartbeat_at?->format('d.m.Y H:i:s') ?? '—',
            'can_cancel' => $status->isActive(),
            'can_retry' => $status->isRetryable(),
            'is_stale' => $this->isStale($run),
        ];
    }
}
