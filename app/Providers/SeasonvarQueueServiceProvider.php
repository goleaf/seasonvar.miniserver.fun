<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Operations\QueueWorkerHeartbeat;
use App\Services\Seasonvar\SeasonvarQueueMonitor;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class SeasonvarQueueServiceProvider extends ServiceProvider
{
    public function boot(SeasonvarQueueMonitor $monitor, QueueWorkerHeartbeat $heartbeat): void
    {
        Queue::looping(function (): void {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        });
        Queue::exceptionOccurred($monitor->exceptionOccurred(...));
        Queue::failing($monitor->failed(...));
        Queue::failing($heartbeat->failed(...));
        Queue::before($heartbeat->processing(...));
        Queue::looping($heartbeat->looping(...));
        Event::listen(QueueBusy::class, $monitor->busy(...));
    }
}
