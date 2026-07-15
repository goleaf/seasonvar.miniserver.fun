<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Enums\CommentDeletionReason;
use App\Exceptions\Comments\CommentActionException;
use App\Models\Comment;
use App\Models\User;
use App\Services\Comments\CommentCacheInvalidator;
use App\Services\Comments\CommentTargetResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteComment
{
    public function __construct(
        private readonly CommentTargetResolver $targets,
        private readonly CommentCacheInvalidator $cache,
    ) {}

    public function handle(User $user, int $commentId): Comment
    {
        $comment = Comment::query()->withTrashed()->findOrFail($commentId);
        $target = $this->targets->fromComment($comment, $user);

        /** @var array{0: Comment, 1: bool} $result */
        $result = DB::transaction(function () use ($comment, $user, $target): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->targets->resolve($target->type, $target->id, $user, lock: true);
            $locked = Comment::query()
                ->withTrashed()
                ->lockForUpdate()
                ->findOrFail($comment->id);

            if ($locked->target_type !== $target->type || (int) $locked->target_id !== $target->id) {
                throw new CommentActionException('comments.errors.target_unavailable');
            }

            if ($locked->deleted_at !== null
                && $locked->user_id === $user->id
                && $locked->deletion_reason === CommentDeletionReason::Author) {
                return [$locked, false];
            }

            Gate::forUser($user)->authorize('delete', $locked);
            $locked->forceFill([
                'deletion_reason' => CommentDeletionReason::Author,
                'deleted_by_id' => $user->id,
                'version' => (int) $locked->version + 1,
            ])->save();
            $locked->delete();

            return [$locked, true];
        }, attempts: 3);

        [$comment, $changed] = $result;

        if ($changed) {
            $this->cache->targetChanged($target);
        }

        return $comment;
    }
}
