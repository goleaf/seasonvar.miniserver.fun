<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Exceptions\Comments\CommentActionException;
use App\Models\User;
use App\Services\Comments\CommentSchema;

final class MarkCommentNotificationRead
{
    public function __construct(private readonly CommentSchema $schema) {}

    public function one(User $user, string $notificationId): void
    {
        if (! $this->schema->notificationsAvailable()) {
            throw new CommentActionException('comments.errors.action_unavailable');
        }

        $notification = $user->notifications()
            ->where('type', 'comment.activity')
            ->findOrFail($notificationId);

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }
    }

    public function all(User $user): void
    {
        if (! $this->schema->notificationsAvailable()) {
            throw new CommentActionException('comments.errors.action_unavailable');
        }

        $user->unreadNotifications()
            ->where('type', 'comment.activity')
            ->update(['read_at' => now()]);
    }
}
