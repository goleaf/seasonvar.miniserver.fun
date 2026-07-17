<?php

declare(strict_types=1);

namespace App\Actions\ReleaseCalendar;

use App\Models\User;
use App\Services\ReleaseCalendar\ReleaseCalendarSchema;
use Illuminate\Validation\ValidationException;

final readonly class MarkReleaseCalendarNotificationRead
{
    public function __construct(private ReleaseCalendarSchema $schema) {}

    public function one(User $user, string $notificationId): void
    {
        $this->assertReady();
        $notification = $user->notifications()->where('type', 'release-calendar.activity')->find($notificationId);

        if ($notification === null) {
            throw ValidationException::withMessages(['notification' => [__('calendar.errors.notification_not_found')]]);
        }

        $notification->markAsRead();
    }

    public function all(User $user): void
    {
        $this->assertReady();
        $user->unreadNotifications()->where('type', 'release-calendar.activity')->update(['read_at' => now()]);
    }

    private function assertReady(): void
    {
        if (! $this->schema->ready()) {
            throw ValidationException::withMessages(['notification' => [__('calendar.unavailable')]]);
        }
    }
}
