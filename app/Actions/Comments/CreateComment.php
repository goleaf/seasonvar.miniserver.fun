<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Enums\CommentAntiSpamDecision;
use App\Enums\CommentDeletionReason;
use App\Enums\CommentStatus;
use App\Enums\CommentTargetType;
use App\Exceptions\Comments\CommentActionException;
use App\Models\Comment;
use App\Models\User;
use App\Services\Comments\CommentAntiSpamService;
use App\Services\Comments\CommentCacheInvalidator;
use App\Services\Comments\CommentNotificationService;
use App\Services\Comments\CommentRateLimiter;
use App\Services\Comments\CommentRelationshipService;
use App\Services\Comments\CommentRestrictionService;
use App\Services\Comments\CommentTargetResolver;
use App\ValueObjects\CommentBody;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class CreateComment
{
    public function __construct(
        private readonly CommentTargetResolver $targets,
        private readonly CommentRestrictionService $restrictions,
        private readonly CommentRelationshipService $relationships,
        private readonly CommentAntiSpamService $antiSpam,
        private readonly CommentRateLimiter $rateLimiter,
        private readonly CommentCacheInvalidator $cache,
        private readonly CommentNotificationService $notifications,
    ) {}

    public function handle(
        User $user,
        CommentTargetType|string $targetType,
        int $targetId,
        mixed $body,
        bool $isSpoiler,
        string $submissionToken,
        ?int $replyToId = null,
    ): Comment {
        Gate::forUser($user)->authorize('create', Comment::class);
        $target = $this->targets->resolve($targetType, $targetId, $user);
        $normalizedBody = CommentBody::from($body);
        $submissionKey = $this->submissionKey($user, $target->key(), $submissionToken);
        $existing = Comment::query()->withTrashed()->where('submission_key', $submissionKey)->first();

        if ($existing !== null) {
            return $this->assertMatchingSubmission(
                $existing,
                $target->type,
                $target->id,
                $normalizedBody,
                $isSpoiler,
                $replyToId,
            );
        }

        $replyTo = $this->replyTo($replyToId, $target->type, $target->id, $user);
        $parentId = $replyTo === null ? null : ($replyTo->parent_id ?? $replyTo->id);
        $this->restrictions->assertCanComment($user);
        $this->rateLimiter->hit($replyTo === null ? 'create' : 'reply', $user, $target->key());
        $this->antiSpam->assertNotDuplicate(
            $user,
            $target->type,
            $target->id,
            $parentId,
            $normalizedBody,
        );
        $status = $this->antiSpam->decision($user, $normalizedBody) === CommentAntiSpamDecision::Review
            ? CommentStatus::Pending
            : CommentStatus::Published;

        /** @var array{0: Comment, 1: bool} $result */
        $result = DB::transaction(function () use (
            $user,
            $target,
            $normalizedBody,
            $isSpoiler,
            $submissionKey,
            $replyTo,
            $status,
        ): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->restrictions->assertCanComment($user);
            $existing = Comment::query()->withTrashed()->where('submission_key', $submissionKey)->first();

            if ($existing !== null) {
                return [
                    $this->assertMatchingSubmission(
                        $existing,
                        $target->type,
                        $target->id,
                        $normalizedBody,
                        $isSpoiler,
                        $replyTo?->id,
                    ),
                    false,
                ];
            }

            $lockedTarget = $this->targets->resolve(
                $target->type,
                $target->id,
                $user,
                lock: true,
            );

            $lockedReplyTo = $this->replyTo(
                $replyTo?->id,
                $lockedTarget->type,
                $lockedTarget->id,
                $user,
                lock: true,
            );
            $lockedParentId = $lockedReplyTo === null
                ? null
                : ($lockedReplyTo->parent_id ?? $lockedReplyTo->id);

            $this->antiSpam->assertNotDuplicate(
                $user,
                $lockedTarget->type,
                $lockedTarget->id,
                $lockedParentId,
                $normalizedBody,
            );

            $comment = Comment::query()->firstOrCreate(
                ['submission_key' => $submissionKey],
                [
                    'user_id' => $user->id,
                    'target_type' => $lockedTarget->type,
                    'target_id' => $lockedTarget->id,
                    'catalog_title_id' => $lockedTarget->catalogTitleId,
                    'parent_id' => $lockedParentId,
                    'reply_to_id' => $lockedReplyTo?->id,
                    'body' => $normalizedBody->value,
                    'body_hash' => $normalizedBody->hash,
                    'is_spoiler' => $isSpoiler,
                    'status' => $status,
                ],
            );

            return [$comment, $comment->wasRecentlyCreated];
        }, attempts: 3);

        [$comment, $created] = $result;

        if (! $created) {
            return $comment;
        }

        $this->cache->targetChanged(
            $target,
            recommendationsChanged: $comment->status === CommentStatus::Published
                && $comment->deleted_at === null,
        );

        if ($comment->isReply() && $comment->status === CommentStatus::Published) {
            $this->notifications->replyCreated($comment, $user);
        }

        return $comment;
    }

    private function replyTo(
        ?int $replyToId,
        CommentTargetType $targetType,
        int $targetId,
        User $user,
        bool $lock = false,
    ): ?Comment {
        if ($replyToId === null) {
            return null;
        }

        $replyToQuery = Comment::query()->withTrashed();

        if ($lock) {
            $replyToQuery->lockForUpdate();
        }

        $replyTo = $replyToQuery->findOrFail($replyToId);

        if ($replyTo->target_type !== $targetType
            || (int) $replyTo->target_id !== $targetId
            || $replyTo->deleted_at !== null
            || $replyTo->status !== CommentStatus::Published) {
            throw new CommentActionException('comments.errors.invalid_parent');
        }

        Gate::forUser($user)->authorize('reply', $replyTo);
        $this->relationships->assertCanInteract($user, $replyTo->user_id);

        if ($replyTo->parent_id !== null) {
            $rootQuery = Comment::query()->withTrashed();

            if ($lock) {
                $rootQuery->lockForUpdate();
            }

            $root = $rootQuery->find($replyTo->parent_id);

            if ($root === null
                || $root->parent_id !== null
                || $root->target_type !== $targetType
                || (int) $root->target_id !== $targetId
                || $root->status !== CommentStatus::Published
                || ($root->deleted_at !== null && $root->deletion_reason !== CommentDeletionReason::Author)) {
                throw new CommentActionException('comments.errors.invalid_parent');
            }
        }

        return $replyTo;
    }

    private function submissionKey(User $user, string $targetKey, string $submissionToken): string
    {
        if (! Str::isUuid($submissionToken)) {
            throw new CommentActionException('comments.errors.invalid_submission');
        }

        return hash('sha256', $user->id.':'.$targetKey.':'.Str::lower($submissionToken));
    }

    private function assertMatchingSubmission(
        Comment $comment,
        CommentTargetType $targetType,
        int $targetId,
        CommentBody $body,
        bool $isSpoiler,
        ?int $replyToId,
    ): Comment {
        if ($comment->target_type !== $targetType
            || (int) $comment->target_id !== $targetId
            || $comment->body !== $body->value
            || $comment->body_hash !== $body->hash
            || $comment->is_spoiler !== $isSpoiler
            || $comment->reply_to_id !== $replyToId) {
            throw new CommentActionException('comments.errors.invalid_submission');
        }

        return $comment;
    }
}
