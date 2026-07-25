<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Seasonvar\SeasonvarActiveRunReconciler;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ReconcileSeasonvarQueuedImportRun implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 0;

    public int $timeout = 120;

    public int $uniqueFor;

    private readonly int $retryUntilTimestamp;

    public function __construct(public readonly int $importRunId)
    {
        $this->uniqueFor = max(300, (int) config('seasonvar.queue.retry_window_seconds', 21_600));
        $this->retryUntilTimestamp = now()->addSeconds($this->uniqueFor)->getTimestamp();
        $this->onConnection((string) config('seasonvar.queue.connection', 'redis'));
        $this->onQueue((string) config('seasonvar.queue.queue', 'seasonvar-import'));
    }

    public function handle(SeasonvarActiveRunReconciler $reconciler): void
    {
        $reconciler->reconcile($this->importRunId);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function retryUntil(): DateTimeInterface
    {
        return Carbon::createFromTimestamp($this->retryUntilTimestamp);
    }

    public function uniqueId(): string
    {
        return 'seasonvar-active-run-reconciliation:'.$this->importRunId;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Восстановление транспорта активного импорта Seasonvar завершилось ошибкой.', [
            'job' => $this->uniqueId(),
            'import_run_id' => $this->importRunId,
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }
}
