<?php

declare(strict_types=1);

namespace App\Services\Comments;

use App\Enums\CommentNotificationType;
use App\Models\Comment;
use App\Models\CommentNotificationPreference;
use App\Models\CommentReaction;
use App\Models\CommentReport;
use App\Models\User;
use App\Notifications\CommentActivityNotification;
use App\Support\DeterministicUuid;
use Illuminate\Database\QueryException;
use Throwable;

final class CommentNotificationService
{
    public function __construct(
        private readonly CommentRelationshipService $relationships,
        private readonly CommentSchema $schema,
    ) {}

    public function replyCreated(Comment $reply, User $actor): void
    {
        $this->safely(function () use ($reply, $actor): void {
            if (! $this->schema->notificationsAvailable()) {
                return;
            }

            $reply->loadMissing(['parent.author:id,name', 'replyTo.author:id,name']);
            $recipient = $reply->reply_to_id !== null
                ? $reply->replyTo?->author
                : $reply->parent?->author;

            if (! $recipient instanceof User || ! $this->allows($recipient, $actor, 'reply_notifications')) {
                return;
            }

            $this->deliver(
                $recipient,
                'reply:'.$reply->id,
                new CommentActivityNotification(
                    CommentNotificationType::Reply,
                    commentId: (int) $reply->id,
                ),
            );
        });
    }

    public function reactionSet(CommentReaction $reaction, Comment $comment, User $actor): void
    {
        $this->safely(function () use ($reaction, $comment, $actor): void {
            if (! $this->schema->notificationsAvailable()) {
                return;
            }

            $comment->loadMissing('author:id,name');
            $recipient = $comment->author;

            if (! $recipient instanceof User || ! $this->allows($recipient, $actor, 'reaction_notifications')) {
                return;
            }

            $this->deliver(
                $recipient,
                'reaction:'.$comment->id.':'.$actor->id,
                new CommentActivityNotification(
                    CommentNotificationType::Reaction,
                    commentId: (int) $comment->id,
                    reactionId: (int) $reaction->id,
                ),
            );
        });
    }

    public function moderationChanged(Comment $comment): void
    {
        $this->safely(function () use ($comment): void {
            if (! $this->schema->notificationsAvailable()) {
                return;
            }

            $comment->loadMissing('author:id,name');
            $recipient = $comment->author;

            if (! $recipient instanceof User || ! $this->preference($recipient)->moderation_notifications) {
                return;
            }

            $this->deliver(
                $recipient,
                'moderation:'.$comment->id.':'.$comment->status->value.':'.$comment->version,
                new CommentActivityNotification(
                    CommentNotificationType::Moderation,
                    commentId: (int) $comment->id,
                    moderationStatus: $comment->status->value,
                ),
            );
        });
    }

    public function reportResolved(CommentReport $report): void
    {
        $this->safely(function () use ($report): void {
            if (! $this->schema->notificationsAvailable()) {
                return;
            }

            $report->loadMissing('reporter:id,name');
            $recipient = $report->reporter;

            if (! $recipient instanceof User || ! $this->preference($recipient)->report_notifications) {
                return;
            }

            $this->deliver(
                $recipient,
                'report:'.$report->id.':'.$report->status->value,
                new CommentActivityNotification(
                    CommentNotificationType::ReportResolved,
                    commentId: (int) $report->comment_id,
                    reportId: (int) $report->id,
                ),
            );
        });
    }

    private function allows(User $recipient, User $actor, string $preference): bool
    {
        return $this->relationships->shouldNotify($recipient, $actor)
            && (bool) $this->preference($recipient)->getAttribute($preference);
    }

    private function preference(User $user): CommentNotificationPreference
    {
        return CommentNotificationPreference::query()->firstOrCreate(['user_id' => $user->id]);
    }

    private function deliver(User $recipient, string $deduplicationKey, CommentActivityNotification $notification): void
    {
        $notification->id = DeterministicUuid::from(
            'seasonvar.comment.notification',
            $recipient->id.':'.$deduplicationKey,
        );

        if ($recipient->notifications()->whereKey($notification->id)->exists()) {
            return;
        }

        try {
            $recipient->notify($notification);
        } catch (QueryException $exception) {
            if (! $recipient->notifications()->whereKey($notification->id)->exists()) {
                throw $exception;
            }
        }
    }

    private function safely(callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
