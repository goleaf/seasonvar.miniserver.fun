<?php

declare(strict_types=1);

namespace App\Actions\Premium;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

final class MarkPremiumNotificationRead
{
    public function one(User $user, string $notificationId): void
    {
        if (! Str::isUuid($notificationId)) {
            throw new ModelNotFoundException;
        }

        $notification = $user->notifications()->where('type', 'premium.activity')->find($notificationId);

        if ($notification === null) {
            throw new ModelNotFoundException;
        }

        $notification->markAsRead();
    }

    public function all(User $user): void
    {
        $user->unreadNotifications()->where('type', 'premium.activity')->update(['read_at' => now()]);
    }
}
