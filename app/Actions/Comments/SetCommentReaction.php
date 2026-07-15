<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Enums\CommentReactionType;
use App\Exceptions\Comments\CommentActionException;
use App\Models\Comment;
use App\Models\CommentReaction;
use App\Models\User;
use App\Services\Comments\CommentCacheInvalidator;
use App\Services\Comments\CommentNotificationService;
use App\Services\Comments\CommentRateLimiter;
use App\Services\Comments\CommentRelationshipService;
use App\Services\Comments\CommentTargetResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class SetCommentReaction
{
    public function __construct(
        private readonly CommentTargetResolver $targets,
        private readonly CommentRelationshipService $relationships,
        private readonly CommentRateLimiter $rateLimiter,
        private readonly CommentCacheInvalidator $cache,
        private readonly CommentNotificationService $notifications,
    ) {}

    public function handle(User $user, int $commentId, CommentReactionType|string|null $desired): ?CommentReactionType
    {
        $comment = Comment::query()->withTrashed()->findOrFail($commentId);
        Gate::forUser($user)->authorize('react', $comment);
        $target = $this->targets->fromComment($comment, $user);
        $this->relationships->assertCanInteract($user, $comment->user_id);
        $type = is_string($desired) ? CommentReactionType::tryFrom($desired) : $desired;

        if ($desired !== null && ! $type instanceof CommentReactionType) {
            throw new CommentActionException('comments.errors.invalid_reaction');
        }

        $this->rateLimiter->hit('reaction', $user, $target->key());
        /** @var array{0: CommentReaction|null, 1: bool, 2: Comment} $result */
        $result = DB::transaction(function () use ($comment, $user, $type, $target): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->targets->resolve($target->type, $target->id, $user, lock: true);
            $lockedComment = Comment::query()
                ->withTrashed()
                ->lockForUpdate()
                ->findOrFail($comment->id);

            if ($lockedComment->target_type !== $target->type
                || (int) $lockedComment->target_id !== $target->id) {
                throw new CommentActionException('comments.errors.target_unavailable');
            }

            Gate::forUser($user)->authorize('react', $lockedComment);
            $this->relationships->assertCanInteract($user, $lockedComment->user_id);

            if ($type === null) {
                $deleted = CommentReaction::query()
                    ->where('comment_id', $lockedComment->id)
                    ->where('user_id', $user->id)
                    ->delete();

                return [null, $deleted > 0, $lockedComment];
            }

            $current = CommentReaction::query()
                ->where('comment_id', $lockedComment->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($current?->type === $type) {
                return [$current, false, $lockedComment];
            }

            $timestamp = now();
            CommentReaction::query()->upsert(
                [[
                    'comment_id' => $lockedComment->id,
                    'user_id' => $user->id,
                    'type' => $type->value,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]],
                ['comment_id', 'user_id'],
                ['type', 'updated_at'],
            );

            $reaction = CommentReaction::query()
                ->where('comment_id', $lockedComment->id)
                ->where('user_id', $user->id)
                ->firstOrFail();

            return [$reaction, true, $lockedComment];
        }, attempts: 3);

        [$reaction, $changed, $comment] = $result;

        if (! $changed) {
            return $reaction?->type;
        }

        $this->cache->targetChanged($target);

        if ($reaction !== null) {
            $this->notifications->reactionSet($reaction, $comment, $user);
        }

        return $reaction?->type;
    }
}
