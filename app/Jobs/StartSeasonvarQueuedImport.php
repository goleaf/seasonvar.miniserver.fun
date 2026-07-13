<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SeasonvarImportFailureType;
use App\Models\SeasonvarImportRun;
use App\Services\Seasonvar\SeasonvarImportAdminService;
use App\Services\Seasonvar\SeasonvarImportFailureClassifier;
use App\Services\Seasonvar\SeasonvarQueuedImportDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class StartSeasonvarQueuedImport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor;

    public function __construct(public readonly int $importRunId)
    {
        $this->uniqueFor = max(3600, (int) config('seasonvar.queue.retry_window_seconds', 21600));
        $this->onConnection((string) config('seasonvar.queue.connection', 'redis'));
        $this->onQueue((string) config('seasonvar.queue.queue', 'seasonvar-import'));
    }

    public function handle(
        SeasonvarQueuedImportDispatcher $dispatcher,
        SeasonvarImportAdminService $imports,
        SeasonvarImportFailureClassifier $failures,
    ): void {
        $run = SeasonvarImportRun::query()->find($this->importRunId);

        if ($run === null || $run->status !== 'queued' || $run->execution_mode !== 'queue') {
            return;
        }

        try {
            $dispatcher->dispatchRun($run);
        } catch (Throwable $exception) {
            if ($failures->classify($exception) === SeasonvarImportFailureType::Transient) {
                $imports->markRetrying($run, $exception);

                throw $exception;
            }

            $imports->markFailed($run, $exception);
        }
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function uniqueId(): string
    {
        return 'seasonvar-coordinator:'.$this->importRunId;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'));
    }

    public function failed(?Throwable $exception): void
    {
        $run = SeasonvarImportRun::query()->find($this->importRunId);

        if ($run !== null) {
            app(SeasonvarImportAdminService::class)->markFailed($run, $exception);
        }

        Log::error('Coordinator импорта Seasonvar завершился ошибкой.', [
            'provider' => 'seasonvar',
            'import_run_id' => $this->importRunId,
            'exception' => $exception ? $exception::class : null,
        ]);
    }
}
