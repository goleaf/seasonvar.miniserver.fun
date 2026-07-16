<?php

declare(strict_types=1);

namespace App\Actions\ContentRequests;

use App\Exceptions\ContentRequests\ContentRequestActionException;
use App\Models\User;
use App\Services\ContentRequests\ContentRequestSchema;

final readonly class MarkContentRequestNotificationRead
{
    public function __construct(private ContentRequestSchema $schema) {}

    public function one(User $user, string $notificationId): void
    {
        $this->assertAvailable();
        $notification = $user->notifications()->where('type', 'content-request.activity')->find($notificationId);

        if ($notification === null) {
            throw new ContentRequestActionException('requests.errors.notification_not_found');
        }

        $notification->markAsRead();
    }

    public function all(User $user): void
    {
        $this->assertAvailable();
        $user->unreadNotifications()->where('type', 'content-request.activity')->update(['read_at' => now()]);
    }

    private function assertAvailable(): void
    {
        if (! $this->schema->ready()) {
            throw new ContentRequestActionException('requests.errors.action_unavailable');
        }
    }
}
