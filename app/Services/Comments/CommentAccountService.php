<?php

declare(strict_types=1);

namespace App\Services\Comments;

use App\Enums\CommentTargetType;
use App\Models\Comment;
use App\Models\CommentNotificationPreference;
use App\Models\CommentReaction;
use App\Models\CommentReport;
use App\Models\CommentRestriction;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\UserMute;
use Illuminate\Database\Eloquent\Builder;

final class CommentAccountService
{
    public function __construct(
        private readonly CommentCacheInvalidator $cache,
        private readonly CommentSchema $schema,
    ) {}

    /** @return array{comments: list<array<string, mixed>>, reactions: list<array<string, mixed>>, notification_preferences: array<string, mixed>|null} */
    public function export(User $user): array
    {
        if (! $this->schema->available()) {
            return ['comments' => [], 'reactions' => [], 'notification_preferences' => null];
        }

        $comments = Comment::query()
            ->withTrashed()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get([
                'id',
                'target_type',
                'target_id',
                'parent_id',
                'reply_to_id',
                'body',
                'is_spoiler',
                'status',
                'version',
                'edited_at',
                'created_at',
                'updated_at',
                'deleted_at',
            ])
            ->map(fn (Comment $comment): array => [
                'comment_id' => (int) $comment->id,
                'target_type' => $comment->target_type->value,
                'target_id' => (int) $comment->target_id,
                'parent_comment_id' => $comment->parent_id,
                'reply_to_comment_id' => $comment->reply_to_id,
                'body' => $comment->body,
                'contains_spoiler' => $comment->is_spoiler,
                'public_status' => $comment->status->value,
                'version' => $comment->version,
                'edited_at' => $comment->edited_at?->toAtomString(),
                'created_at' => $comment->created_at?->toAtomString(),
                'updated_at' => $comment->updated_at?->toAtomString(),
                'deleted_at' => $comment->deleted_at?->toAtomString(),
            ])->all();
        $reactions = $this->schema->engagementAvailable()
            ? CommentReaction::query()
                ->where('user_id', $user->id)
                ->with(['comment' => fn ($query) => $query
                    ->withTrashed()
                    ->select(['id', 'target_type', 'target_id'])])
                ->orderBy('id')
                ->get(['id', 'comment_id', 'type', 'created_at', 'updated_at'])
                ->map(fn (CommentReaction $reaction): array => [
                    'comment_id' => (int) $reaction->comment_id,
                    'target_type' => $reaction->comment?->target_type->value,
                    'target_id' => $reaction->comment?->target_id,
                    'reaction' => $reaction->type->value,
                    'created_at' => $reaction->created_at?->toAtomString(),
                    'updated_at' => $reaction->updated_at?->toAtomString(),
                ])->all()
            : [];

        return [
            'comments' => $comments,
            'reactions' => $reactions,
            'notification_preferences' => $this->schema->notificationsAvailable()
                ? CommentNotificationPreference::query()->find($user->id)?->only([
                    'reply_notifications',
                    'reaction_notifications',
                    'moderation_notifications',
                    'report_notifications',
                ])
                : null,
        ];
    }

    public function prepareForDeletion(User $user): void
    {
        if (! $this->schema->available()) {
            return;
        }

        $affectedComments = Comment::query()
            ->withTrashed()
            ->where(function (Builder $query) use ($user): void {
                $query->where('user_id', $user->id);

                if ($this->schema->engagementAvailable()) {
                    $query->orWhereHas('reactions', fn (Builder $reactions): Builder => $reactions
                        ->where('user_id', $user->id));
                }
            });
        $catalogTitleIds = (clone $affectedComments)
            ->whereNotNull('catalog_title_id')
            ->distinct()
            ->limit(1_001)
            ->pluck('catalog_title_id')
            ->all();
        $hasCollections = (clone $affectedComments)
            ->where('target_type', CommentTargetType::Collection->value)
            ->exists();

        Comment::query()
            ->withTrashed()
            ->where('user_id', $user->id)
            ->update([
                'user_id' => null,
                'submission_key' => null,
                'updated_at' => now(),
            ]);
        if ($this->schema->engagementAvailable()) {
            CommentReaction::query()->where('user_id', $user->id)->delete();
            CommentReport::query()->where('reporter_id', $user->id)->update([
                'reporter_id' => null,
                'deduplication_key' => null,
                'updated_at' => now(),
            ]);
            CommentRestriction::query()->where('user_id', $user->id)->delete();
        }

        if ($this->schema->relationshipsAvailable()) {
            UserBlock::query()
                ->where('blocker_id', $user->id)
                ->orWhere('blocked_id', $user->id)
                ->delete();
            UserMute::query()
                ->where('muter_id', $user->id)
                ->orWhere('muted_id', $user->id)
                ->delete();
        }

        if ($this->schema->notificationsAvailable()) {
            CommentNotificationPreference::query()->where('user_id', $user->id)->delete();
            $user->notifications()->where('type', 'comment.activity')->delete();
        }

        $this->cache->identitiesChanged(
            $catalogTitleIds,
            $hasCollections,
            recommendationsChanged: false,
        );
    }

    public function authorIdentityChanged(User $user): void
    {
        if ($this->schema->available()) {
            $this->cache->authorChanged($user);
        }
    }
}
