<?php

declare(strict_types=1);

namespace App\Actions\TechnicalIssues;

use App\Exceptions\TechnicalIssues\TechnicalIssueActionException;
use App\Models\User;
use App\Services\TechnicalIssues\TechnicalIssueSchema;

final readonly class MarkTechnicalIssueNotificationRead
{
    public function __construct(private TechnicalIssueSchema $schema) {}

    public function one(User $user, string $notificationId): void
    {
        $this->assertAvailable();
        $notification = $user->notifications()->where('type', 'technical-issue.activity')->find($notificationId);

        if ($notification === null) {
            throw new TechnicalIssueActionException('issues.errors.notification_not_found');
        }

        $notification->markAsRead();
    }

    public function all(User $user): void
    {
        $this->assertAvailable();
        $user->unreadNotifications()->where('type', 'technical-issue.activity')->update(['read_at' => now()]);
    }

    private function assertAvailable(): void
    {
        if (! $this->schema->ready()) {
            throw new TechnicalIssueActionException('issues.errors.action_unavailable');
        }
    }
}
