<?php

declare(strict_types=1);

namespace App\Actions\ReleaseCalendar;

use App\Models\User;
use App\Services\ReleaseCalendar\ReleaseCalendarSchema;
use Illuminate\Validation\ValidationException;

final readonly class MarkReleaseCalendarNotificationRead
{
    private const TYPES = [
        'release-calendar.activity',
        'playback-preference.translation-available',
    ];

    public function __construct(private ReleaseCalendarSchema $schema) {}

    public function one(User $user, string $notificationId): void
    {
        $this->assertReady();
        $notification = $user->notifications()->whereIn('type', self::TYPES)->find($notificationId);

        if ($notification === null) {
            throw ValidationException::withMessages(['notification' => [__('calendar.errors.notification_not_found')]]);
        }

        $notification->markAsRead();
    }

    public function all(User $user): void
    {
        $this->assertReady();
        $user->unreadNotifications()->whereIn('type', self::TYPES)->update(['read_at' => now()]);
    }

    private function assertReady(): void
    {
        if (! $this->schema->ready()) {
            throw ValidationException::withMessages(['notification' => [__('calendar.unavailable')]]);
        }
    }
}
