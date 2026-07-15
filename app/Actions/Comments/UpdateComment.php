<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Exceptions\Comments\CommentActionException;
use App\Models\Comment;
use App\Models\User;
use App\Services\Comments\CommentAntiSpamService;
use App\Services\Comments\CommentCacheInvalidator;
use App\Services\Comments\CommentRateLimiter;
use App\Services\Comments\CommentTargetResolver;
use App\ValueObjects\CommentBody;
use Illuminate\Support\Facades\Gate;

final class UpdateComment
{
    public function __construct(
        private readonly CommentTargetResolver $targets,
        private readonly CommentAntiSpamService $antiSpam,
        private readonly CommentRateLimiter $rateLimiter,
        private readonly CommentCacheInvalidator $cache,
    ) {}

    public function handle(
        User $user,
        int $commentId,
        int $expectedVersion,
        mixed $body,
        bool $isSpoiler,
    ): Comment {
        $comment = Comment::query()->withTrashed()->findOrFail($commentId);
        Gate::forUser($user)->authorize('update', $comment);
        $target = $this->targets->fromComment($comment, $user);
        $normalizedBody = CommentBody::from($body);
        $this->rateLimiter->hit('edit', $user, $target->key());
        $this->antiSpam->assertNotDuplicate(
            $user,
            $target->type,
            $target->id,
            $comment->parent_id,
            $normalizedBody,
            (int) $comment->id,
        );

        if ($expectedVersion < 1 || (int) $comment->version !== $expectedVersion) {
            throw new CommentActionException('comments.errors.stale_edit');
        }

        $updated = Comment::query()
            ->whereKey($comment->id)
            ->where('version', $expectedVersion)
            ->whereNull('deleted_at')
            ->update([
                'body' => $normalizedBody->value,
                'body_hash' => $normalizedBody->hash,
                'is_spoiler' => $isSpoiler,
                'version' => $expectedVersion + 1,
                'edited_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new CommentActionException('comments.errors.stale_edit');
        }

        $this->cache->targetChanged($target);

        return $comment->refresh();
    }
}
