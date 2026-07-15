<?php

declare(strict_types=1);

namespace App\Actions\Reviews;

use App\Exceptions\Reviews\ReviewActionException;
use App\Models\User;
use App\Services\Reviews\ReviewSchema;

final class MarkReviewNotificationRead
{
    public function __construct(private readonly ReviewSchema $schema) {}

    public function one(User $user, string $notificationId): void
    {
        $this->assertAvailable();
        $notification = $user->notifications()
            ->where('type', 'review.activity')
            ->find($notificationId);

        if ($notification === null) {
            throw new ReviewActionException('reviews.errors.notification_not_found');
        }

        $notification->markAsRead();
    }

    public function all(User $user): void
    {
        $this->assertAvailable();
        $user->unreadNotifications()
            ->where('type', 'review.activity')
            ->update(['read_at' => now()]);
    }

    private function assertAvailable(): void
    {
        if (! $this->schema->notificationsAvailable()) {
            throw new ReviewActionException('reviews.errors.action_unavailable');
        }
    }
}
