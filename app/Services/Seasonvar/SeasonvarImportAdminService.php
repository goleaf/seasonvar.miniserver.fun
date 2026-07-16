<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarImportStartResultData;
use App\Enums\SeasonvarImportStatus;
use App\Jobs\StartSeasonvarQueuedImport;
use App\Models\SeasonvarImportRun;
use App\Models\User;
use App\Support\HumanFileSizeFormatter;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
        return $this->staleRunsQuery()->update([
            'status' => SeasonvarImportStatus::Failed->value,
            'last_error' => 'Запуск остановлен автоматически: heartbeat давно не обновлялся и активных задач не осталось.',
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function markRetrying(SeasonvarImportRun $run, Throwable $exception): void
    {
        SeasonvarImportRun::query()
            ->whereKey($run->id)
            ->whereIn('status', [SeasonvarImportStatus::Queued->value, SeasonvarImportStatus::Running->value])
            ->update([
                'status' => SeasonvarImportStatus::Queued->value,
                'last_error' => $this->errors->fromException($exception),
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
                'last_heartbeat_at' => now(),
                'updated_at' => now(),
            ]);

        $this->claims->releaseForRun($run->id);
    }

    /**
     * @return array{runs: list<array<string, mixed>>, has_active_run: bool, stale_count: int, media_health: list<array<string, mixed>>, media_due_count: int}
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
            'stale_count' => $this->staleRunsQuery()->count(),
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
        ];
    }

    /** @return Builder<SeasonvarImportRun> */
    private function staleRunsQuery(): Builder
    {
        $cutoff = now()->subMinutes(max(5, (int) config('seasonvar.queue.stale_after_minutes', 120)));

        return SeasonvarImportRun::query()
            ->where('execution_mode', 'queue')
            ->where('status', SeasonvarImportStatus::Running->value)
            ->where(function (Builder $query) use ($cutoff): void {
                $query->where('last_heartbeat_at', '<=', $cutoff)
                    ->orWhere(function (Builder $query) use ($cutoff): void {
                        $query->whereNull('last_heartbeat_at')->where('updated_at', '<=', $cutoff);
                    });
            })
            ->whereDoesntHave('claimedSourcePages', function (Builder $query): void {
                $query->whereNotNull('import_claim_token')
                    ->where('import_claim_expires_at', '>', now());
            });
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
