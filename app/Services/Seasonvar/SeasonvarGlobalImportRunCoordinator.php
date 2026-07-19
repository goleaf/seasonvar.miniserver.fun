<?php

declare(strict_types=1);

namespace App\Services\Seasonvar;

use App\DTOs\Seasonvar\SeasonvarImportStartResultData;
use App\Enums\SeasonvarImportStatus;
use App\Models\SeasonvarImportRun;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;

final class SeasonvarGlobalImportRunCoordinator
{
    private const START_LOCK = 'seasonvar-global-import-start';

    /** @param list<string>|null $pageTypes */
    public function acquire(
        bool $force,
        bool $discover,
        ?array $pageTypes = null,
        ?int $requestedByUserId = null,
        ?int $retryOfRunId = null,
        ?int $sitemapTailLimit = null,
    ): SeasonvarImportStartResultData {
        $lock = $this->startLock();

        return $lock->block(5, function () use (
            $force,
            $discover,
            $pageTypes,
            $requestedByUserId,
            $retryOfRunId,
            $sitemapTailLimit,
        ): SeasonvarImportStartResultData {
            $this->recoverStale();
            $active = $this->activeRun();

            if ($active !== null) {
                return new SeasonvarImportStartResultData($active, false);
            }

            $run = DB::transaction(fn (): SeasonvarImportRun => SeasonvarImportRun::query()->create([
                'mode' => 'sitemap',
                'execution_mode' => 'queue',
                'status' => SeasonvarImportStatus::Queued->value,
                'force' => $force,
                'forever' => false,
                'requested_by_user_id' => $requestedByUserId,
                'retry_of_run_id' => $retryOfRunId,
                'last_heartbeat_at' => now(),
                'summary' => [
                    'discover' => $discover,
                    'provider' => 'seasonvar',
                    'page_types' => $pageTypes,
                    'sitemap_tail_limit' => $sitemapTailLimit,
                ],
            ]));

            return new SeasonvarImportStartResultData($run, true);
        });
    }

    public function acquireSync(
        bool $force,
        bool $forever,
        ?int $processId = null,
        ?string $processHost = null,
        ?string $processCommand = null,
    ): SeasonvarImportStartResultData {
        $lock = $this->startLock();

        return $lock->block(5, function () use (
            $force,
            $forever,
            $processId,
            $processHost,
            $processCommand,
        ): SeasonvarImportStartResultData {
            $this->recoverStale();
            $active = $this->activeRun();

            if ($active !== null) {
                return new SeasonvarImportStartResultData($active, false);
            }

            $run = DB::transaction(fn (): SeasonvarImportRun => SeasonvarImportRun::query()->create([
                'mode' => 'sitemap',
                'execution_mode' => 'sync',
                'status' => SeasonvarImportStatus::Running->value,
                'force' => $force,
                'forever' => $forever,
                'process_id' => $processId,
                'process_host' => $processHost,
                'process_command' => $processCommand,
                'started_at' => now(),
                'last_heartbeat_at' => now(),
            ]));

            return new SeasonvarImportStartResultData($run, true);
        });
    }

    public function activeRun(): ?SeasonvarImportRun
    {
        return $this->activeRuns()->latest('id')->first();
    }

    public function hasActiveRun(): bool
    {
        return $this->activeRuns()->exists();
    }

    public function recoverStale(): int
    {
        return $this->staleRuns()->update([
            'status' => SeasonvarImportStatus::Failed->value,
            'last_error' => 'Запуск остановлен автоматически: heartbeat давно не обновлялся и активных задач не осталось.',
            'finished_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function staleCount(): int
    {
        return $this->staleRuns()->count();
    }

    private function startLock(): Lock
    {
        $repository = Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'));

        if (! $repository instanceof CacheRepository) {
            throw new LogicException('Seasonvar lock cache repository is unavailable.');
        }

        $store = $repository->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('Seasonvar lock cache store does not support atomic locks.');
        }

        return $store->lock(self::START_LOCK, 30);
    }

    /** @return Builder<SeasonvarImportRun> */
    private function activeRuns(): Builder
    {
        return SeasonvarImportRun::query()
            ->where('mode', 'sitemap')
            ->whereIn('status', [
                SeasonvarImportStatus::Queued->value,
                SeasonvarImportStatus::Running->value,
            ]);
    }

    /** @return Builder<SeasonvarImportRun> */
    private function staleRuns(): Builder
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
}
