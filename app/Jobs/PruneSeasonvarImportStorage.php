<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Seasonvar\SeasonvarImportStorageMaintenance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PruneSeasonvarImportStorage implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public int $uniqueFor = 900;

    public function __construct()
    {
        $timeBudgetSeconds = max(
            1,
            min(600, (int) config('seasonvar.import.maintenance_time_budget_seconds', 30)),
        );
        $this->timeout = max(60, min(900, $timeBudgetSeconds + 30));
        $this->onConnection((string) config('seasonvar.queue.connection', 'redis'));
        $this->onQueue((string) config('seasonvar.queue.queue', 'seasonvar-import'));
    }

    public function handle(SeasonvarImportStorageMaintenance $maintenance): void
    {
        $result = $maintenance->prune();

        Log::info('Завершён ограниченный проход очистки служебного хранилища импорта Seasonvar.', [
            'enabled' => $result['enabled'],
            'rows_deleted' => $result['rows_deleted'],
            'events_deleted' => $result['events_deleted'],
            'snapshots_deleted' => $result['snapshots_deleted'],
            'title_groups_deleted' => $result['title_groups_deleted'],
            'prepared_pages_deleted' => $result['prepared_pages_deleted'],
            'chunks_processed' => $result['chunks_processed'],
            'elapsed_milliseconds' => $result['elapsed_milliseconds'],
            'stopped_reason' => $result['stopped_reason'],
        ]);
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->expireAfter($this->timeout + 60)
                ->releaseAfter(60),
        ];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function uniqueId(): string
    {
        return 'seasonvar-import-storage-prune-v1';
    }

    public function uniqueVia(): Repository
    {
        return Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Очистка служебного хранилища импорта Seasonvar завершилась ошибкой.', [
            'job' => $this->uniqueId(),
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }
}
