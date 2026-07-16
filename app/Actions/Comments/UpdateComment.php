<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Enums\CommentAntiSpamDecision;
use App\Enums\CommentStatus;
use App\Exceptions\Comments\CommentActionException;
use App\Models\Comment;
use App\Models\User;
use App\Services\Comments\CommentAntiSpamService;
use App\Services\Comments\CommentCacheInvalidator;
use App\Services\Comments\CommentRateLimiter;
use App\Services\Comments\CommentTargetResolver;
use App\ValueObjects\CommentBody;
use Illuminate\Support\Facades\DB;
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

        /** @var array{0: Comment, 1: bool, 2: bool} $result */
        $result = DB::transaction(function () use (
            $user,
            $target,
            $comment,
            $normalizedBody,
            $isSpoiler,
            $expectedVersion,
        ): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->targets->resolve($target->type, $target->id, $user, lock: true);
            $locked = Comment::query()
                ->withTrashed()
                ->lockForUpdate()
                ->findOrFail($comment->id);
            $this->assertSameTarget($locked, $target->type->value, $target->id);
            Gate::forUser($user)->authorize('update', $locked);

            if ($locked->body === $normalizedBody->value
                && $locked->body_hash === $normalizedBody->hash
                && $locked->is_spoiler === $isSpoiler) {
                return [$locked, false, false];
            }

            if ($expectedVersion < 1 || (int) $locked->version !== $expectedVersion) {
                throw new CommentActionException('comments.errors.stale_edit');
            }

            $this->antiSpam->assertNotDuplicate(
                $user,
                $target->type,
                $target->id,
                $locked->parent_id,
                $normalizedBody,
                (int) $locked->id,
            );
            $wasPublic = $locked->status === CommentStatus::Published
                && $locked->deleted_at === null;
            $status = $locked->status === CommentStatus::Published
                && $this->antiSpam->decision($user, $normalizedBody) === CommentAntiSpamDecision::Review
                    ? CommentStatus::Pending
                    : $locked->status;

            $locked->forceFill([
                'body' => $normalizedBody->value,
                'body_hash' => $normalizedBody->hash,
                'is_spoiler' => $isSpoiler,
                'status' => $status,
                'version' => $expectedVersion + 1,
                'edited_at' => now(),
            ])->save();

            $isPublic = $locked->status === CommentStatus::Published
                && $locked->deleted_at === null;

            return [$locked, true, $wasPublic !== $isPublic];
        }, attempts: 3);

        [$comment, $changed, $recommendationsChanged] = $result;

        if ($changed) {
            $this->cache->targetChanged($target, $recommendationsChanged);
        }

        return $comment;
    }

    private function assertSameTarget(Comment $comment, string $targetType, int $targetId): void
    {
        if ($comment->target_type->value !== $targetType || (int) $comment->target_id !== $targetId) {
            throw new CommentActionException('comments.errors.target_unavailable');
        }
    }
}
