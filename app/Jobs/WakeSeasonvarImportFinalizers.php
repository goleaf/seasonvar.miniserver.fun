<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Seasonvar\SeasonvarImportFinalizationDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

final class WakeSeasonvarImportFinalizers implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 900;

    public function __construct()
    {
        $this->onConnection((string) config('seasonvar.queue.connection', 'redis'));
        $this->onQueue((string) config('seasonvar.queue.queue', 'seasonvar-import'));
    }

    public function handle(SeasonvarImportFinalizationDispatcher $finalizers): void
    {
        $finalizers->wakeReady();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function uniqueId(): string
    {
        return 'seasonvar-import-finalization-watchdog';
    }

    public function uniqueVia(): Repository
    {
        return Cache::store((string) config('seasonvar.queue.lock_store', 'redis-locks'));
    }
}
