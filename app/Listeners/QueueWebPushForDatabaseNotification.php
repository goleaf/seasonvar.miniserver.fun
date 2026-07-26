<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Jobs\FanOutWebPushNotification;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationSent;

final class QueueWebPushForDatabaseNotification
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof User) {
            return;
        }

        FanOutWebPushNotification::dispatch((int) $event->notifiable->getKey())
            ->afterCommit();
    }
}
