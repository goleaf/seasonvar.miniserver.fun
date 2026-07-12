<?php

namespace App\Jobs;

use App\Models\SeasonvarImportRun;
use App\Services\Catalog\CatalogStatsSnapshotCache;
use App\Services\Seasonvar\SeasonvarImportPipeline;
use App\Services\Seasonvar\SeasonvarPageClaimManager;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class FinalizeSeasonvarQueuedImport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 0;

    public int $timeout = 900;

    public readonly int $retryUntilTimestamp;

    public function __construct(public readonly int $importRunId)
    {
        $this->retryUntilTimestamp = now()->addDays(2)->getTimestamp();
        $this->onConnection((string) config('seasonvar.queue.connection', 'redis'));
        $this->onQueue((string) config('seasonvar.queue.queue', 'seasonvar-import'));
    }

    public function handle(
        SeasonvarPageClaimManager $claims,
        SeasonvarImportPipeline $pipeline,
        CatalogStatsSnapshotCache $statsSnapshots,
    ): void {
        $run = SeasonvarImportRun::query()->find($this->importRunId);

        if ($run === null || $run->status !== 'running' || $run->execution_mode !== 'queue') {
            return;
        }

        if ($claims->outstandingForRun($run->id) > 0) {
            $this->release(max(1, (int) config('seasonvar.queue.finalizer_delay_seconds', 60)));

            return;
        }

        $pipeline->finalizeQueuedRun($run);
        $statsSnapshots->refresh();
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
        return Cache::store((string) config('seasonvar.queue.lock_store', 'redis'));
    }

    public function failed(?Throwable $exception): void
    {
        SeasonvarImportRun::query()
            ->whereKey($this->importRunId)
            ->where('execution_mode', 'queue')
            ->where('status', 'running')
            ->update([
                'status' => 'failed',
                'last_error' => $exception?->getMessage() ?? 'Queue finalizer завершился ошибкой.',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        Log::error('Финализация очередного импорта Seasonvar завершилась ошибкой.', [
            'import_run_id' => $this->importRunId,
            'exception' => $exception ? get_class($exception) : null,
            'message' => $exception?->getMessage(),
        ]);
    }
}
