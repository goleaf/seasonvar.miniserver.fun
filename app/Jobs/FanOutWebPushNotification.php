<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\WebPushSubscription;
use App\Services\Pwa\WebPushSubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class FanOutWebPushNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $userId)
    {
        $this->afterCommit();
    }

    public function handle(WebPushSubscriptionService $subscriptions): void
    {
        if (! $subscriptions->configured()) {
            return;
        }

        WebPushSubscription::query()
            ->where('user_id', $this->userId)
            ->whereNull('disabled_at')
            ->select('id')
            ->chunkById(100, static function ($records): void {
                foreach ($records as $record) {
                    DeliverWebPushNotification::dispatch((int) $record->id);
                }
            });
    }
}
