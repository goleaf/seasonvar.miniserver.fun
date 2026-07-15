<?php

declare(strict_types=1);

namespace App\Services\Comments;

use App\Enums\CommentAntiSpamDecision;
use App\Enums\CommentTargetType;
use App\Exceptions\Comments\CommentActionException;
use App\Models\Comment;
use App\Models\User;
use App\ValueObjects\CommentBody;

final class CommentAntiSpamService
{
    public function assertNotDuplicate(
        User $user,
        CommentTargetType $targetType,
        int $targetId,
        ?int $parentId,
        CommentBody $body,
        ?int $exceptCommentId = null,
    ): void {
        $window = max(1, (int) config('comments.anti_spam.duplicate_window_seconds', 90));
        $duplicate = Comment::query()
            ->withTrashed()
            ->where('user_id', $user->id)
            ->where('target_type', $targetType->value)
            ->where('target_id', $targetId)
            ->where('parent_id', $parentId)
            ->where('body_hash', $body->hash)
            ->where('created_at', '>=', now()->subSeconds($window))
            ->when($exceptCommentId !== null, fn ($query) => $query->whereKeyNot($exceptCommentId))
            ->exists();

        if ($duplicate) {
            throw new CommentActionException('comments.errors.duplicate_comment');
        }
    }

    public function decision(User $user, CommentBody $body): CommentAntiSpamDecision
    {
        $reviewMinutes = max(0, (int) config('comments.anti_spam.new_account_review_minutes', 15));
        $isNewAccount = $reviewMinutes > 0
            && $user->created_at !== null
            && $user->created_at->isAfter(now()->subMinutes($reviewMinutes));

        return $isNewAccount && $body->linkCount > 0
            ? CommentAntiSpamDecision::Review
            : CommentAntiSpamDecision::Allow;
    }
}
