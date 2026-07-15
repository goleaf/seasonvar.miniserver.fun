<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Exceptions\Comments\CommentActionException;
use App\Models\CommentNotificationPreference;
use App\Models\User;
use App\Services\Comments\CommentSchema;

final class UpdateCommentNotificationPreferences
{
    public function __construct(private readonly CommentSchema $schema) {}

    /** @param array{reply_notifications: bool, reaction_notifications: bool, moderation_notifications: bool, report_notifications: bool} $preferences */
    public function handle(User $user, array $preferences): CommentNotificationPreference
    {
        if (! $this->schema->notificationsAvailable()) {
            throw new CommentActionException('comments.errors.action_unavailable');
        }

        return CommentNotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->id],
            $preferences,
        );
    }
}
