<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use App\Services\Catalog\CatalogCacheInvalidator;
use App\Services\Seasonvar\SeasonvarImportErrorSanitizer;
use App\Services\Seasonvar\SeasonvarImportPipeline;
use App\Services\Seasonvar\SeasonvarImportRunRecorder;
use App\Services\Seasonvar\SeasonvarPageClaimManager;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

class FinalizeSeasonvarQueuedImport implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public const GLOBAL_LOCK_KEY = 'seasonvar-import-finalizer';

    public int $tries = 0;

    public int $timeout = 900;

    public readonly int $retryUntilTimestamp;

    public function __construct(public readonly int $importRunId)
    {
        $this->retryUntilTimestamp = now()->addDays(2)->getTimestamp();
        $this->timeout = max(60, (int) config('seasonvar.queue.worker_timeout', 900));
        $this->onConnection((string) config('seasonvar.queue.connection', 'redis'));
        $this->onQueue((string) config('seasonvar.queue.queue', 'seasonvar-import'));
    }

    public function handle(
        SeasonvarPageClaimManager $claims,
        SeasonvarImportPipeline $pipeline,
        SeasonvarImportRunRecorder $runs,
        CatalogCacheInvalidator $cacheInvalidator,
    ): void {
        $run = SeasonvarImportRun::query()->find($this->importRunId);

        if ($run === null
            || $run->mode !== 'sitemap'
            || $run->status !== 'running'
            || $run->execution_mode !== 'queue'
        ) {
            return;
        }

        if ($claims->outstandingForRun($run->id) > 0) {
            $runs->heartbeat($run->id);

            return;
        }

        if ($this->hasActiveTitleGroups($run->id)) {
            $runs->heartbeat($run->id);

            return;
        }

        $lock = $this->lockStore()->lock(self::GLOBAL_LOCK_KEY, $this->timeout + 300);

        if (! $lock->get()) {
            $runs->heartbeat($run->id);
            $this->release($this->releaseDelay());

            return;
        }

        try {
            $run->refresh();

            if ($run->mode !== 'sitemap'
                || $run->status !== 'running'
                || $run->execution_mode !== 'queue'
            ) {
                return;
            }

            if ($claims->outstandingForRun($run->id) > 0) {
                $runs->heartbeat($run->id);

                return;
            }

            if ($this->hasActiveTitleGroups($run->id)) {
                $runs->heartbeat($run->id);

                return;
            }

            $problemGroups = SeasonvarImportTitleGroup::query()
                ->where('seasonvar_import_run_id', $run->id)
                ->whereIn('status', ['partial', 'failed'])
                ->count();

            if ($problemGroups > 0 && (int) $run->failed === 0) {
                $run->update(['failed' => $problemGroups]);
            }

            $pipeline->finalizeQueuedRun($run);
            $cacheInvalidator->catalogChanged();
        } finally {
            $lock->release();
        }
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function retryUntil(): DateTimeInterface
    {
        return Carbon::createFromTimestamp($this->retryUntilTimestamp);
    }

    public function uniqueId(): string
    {
        return 'seasonvar-finalizer:'.$this->importRunId;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'));
    }

    private function releaseDelay(): int
    {
        return max(1, (int) config('seasonvar.queue.finalizer_delay_seconds', 60));
    }

    private function hasActiveTitleGroups(int $runId): bool
    {
        return SeasonvarImportTitleGroup::query()
            ->where('seasonvar_import_run_id', $runId)
            ->whereIn('status', ['discovering', 'running', 'finalizing'])
            ->exists();
    }

    private function lockStore(): Store&LockProvider
    {
        $repository = $this->uniqueVia();

        if (! $repository instanceof CacheRepository) {
            throw new LogicException('Seasonvar lock cache repository is unavailable.');
        }

        $store = $repository->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('Seasonvar lock cache store does not support atomic locks.');
        }

        return $store;
    }

    public function failed(?Throwable $exception): void
    {
        $message = app(SeasonvarImportErrorSanitizer::class)->fromException($exception);

        SeasonvarImportRun::query()
            ->whereKey($this->importRunId)
            ->where('execution_mode', 'queue')
            ->where('status', 'running')
            ->update([
                'status' => 'failed',
                'last_error' => $message,
                'finished_at' => now(),
                'last_heartbeat_at' => now(),
                'updated_at' => now(),
            ]);

        Log::error('Финализация очередного импорта Seasonvar завершилась ошибкой.', [
            'import_run_id' => $this->importRunId,
            'exception' => $exception ? get_class($exception) : null,
        ]);
    }
}
