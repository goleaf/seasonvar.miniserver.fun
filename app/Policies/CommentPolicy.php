<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CommentDeletionReason;
use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class CommentPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return Gate::forUser($user)->allows('manage-comments')
            && in_array($ability, ['view', 'moderate'], true)
                ? true
                : null;
    }

    public function view(?User $user, Comment $comment): bool
    {
        if ($comment->status === CommentStatus::Published) {
            return true;
        }

        return $user !== null && $comment->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return (bool) config('comments.enabled', true) && $user->hasVerifiedEmail();
    }

    public function reply(User $user, Comment $comment): bool
    {
        return $this->create($user)
            && $comment->status === CommentStatus::Published
            && $comment->deleted_at === null;
    }

    public function update(User $user, Comment $comment): bool
    {
        $window = max(1, (int) config('comments.editing.window_minutes', 30));

        return $comment->user_id === $user->id
            && $comment->deleted_at === null
            && in_array($comment->status, [CommentStatus::Published, CommentStatus::Pending], true)
            && $comment->created_at !== null
            && $comment->created_at->isAfter(now()->subMinutes($window));
    }

    public function delete(User $user, Comment $comment): bool
    {
        return $comment->user_id === $user->id
            && $comment->deleted_at === null;
    }

    public function restore(User $user, Comment $comment): bool
    {
        $days = max(1, (int) config('comments.editing.restoration_days', 7));

        return $comment->user_id === $user->id
            && $comment->deleted_at !== null
            && $comment->deletion_reason === CommentDeletionReason::Author
            && $comment->status !== CommentStatus::Removed
            && $comment->deleted_at->isAfter(now()->subDays($days));
    }

    public function react(User $user, Comment $comment): bool
    {
        return $user->hasVerifiedEmail()
            && $comment->user_id !== $user->id
            && $comment->status === CommentStatus::Published
            && $comment->deleted_at === null;
    }

    public function report(User $user, Comment $comment): bool
    {
        return $user->hasVerifiedEmail()
            && $comment->user_id !== $user->id
            && $comment->status === CommentStatus::Published
            && $comment->deleted_at === null;
    }

    public function moderate(User $user, Comment $comment): bool
    {
        return Gate::forUser($user)->allows('manage-comments');
    }
}
