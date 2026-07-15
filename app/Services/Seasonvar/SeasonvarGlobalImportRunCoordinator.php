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
    ): SeasonvarImportStartResultData {
        $lock = $this->startLock();

        return $lock->block(5, function () use (
            $force,
            $discover,
            $pageTypes,
            $requestedByUserId,
            $retryOfRunId,
        ): SeasonvarImportStartResultData {
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
                ],
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
            ->where('execution_mode', 'queue')
            ->whereIn('status', [
                SeasonvarImportStatus::Queued->value,
                SeasonvarImportStatus::Running->value,
            ]);
    }
}
