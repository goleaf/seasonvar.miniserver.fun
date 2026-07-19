<?php

declare(strict_types=1);

namespace App\Services\Comments;

use App\DTOs\Comments\CommentActivityData;
use App\DTOs\Comments\CommentNotificationData;
use App\DTOs\Comments\CommentRelationshipData;
use App\DTOs\Comments\PublicCommentActivityData;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionVisibility;
use App\Enums\CommentNotificationType;
use App\Enums\CommentStatus;
use App\Enums\CommentTargetType;
use App\Models\CatalogCollection;
use App\Models\Comment;
use App\Models\Episode;
use App\Models\Season;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\UserMute;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\UserPortal\UserPortalIdPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class CommentProfileQuery
{
    public function __construct(
        private readonly CatalogTitleQuery $titles,
        private readonly CommentPresenter $presenter,
        private readonly CommentRelationshipService $relationships,
        private readonly UserPortalIdPaginator $paginator,
    ) {}

    /** @return LengthAwarePaginator<int, PublicCommentActivityData> */
    public function publicActivity(int $authorId, ?User $viewer, int $perPage): LengthAwarePaginator
    {
        $paginator = $this->publicActivityQuery($authorId, $viewer)
            ->with(['catalogTitle:id,slug,title,original_title'])
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(
                max(1, $perPage),
                ['id', 'catalog_title_id', 'body', 'is_spoiler', 'created_at'],
                'commentsPage',
            );

        return $paginator->through(
            fn (Comment $comment): PublicCommentActivityData => $this->presenter->publicActivity($comment),
        );
    }

    public function publicCountForAuthor(int $authorId, ?User $viewer): int
    {
        return $this->publicActivityQuery($authorId, $viewer)->count();
    }

    /** @return LengthAwarePaginator<int, CommentActivityData> */
    public function activity(User $user, bool $refresh = false): LengthAwarePaginator
    {
        $query = Comment::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [
                CommentStatus::Published->value,
                CommentStatus::Pending->value,
                CommentStatus::Hidden->value,
                CommentStatus::Rejected->value,
                CommentStatus::Spam->value,
            ])
            ->where($this->accessibleTargets($user))
            ->select([
                'id', 'target_type', 'target_id', 'body', 'is_spoiler', 'status',
                'created_at', 'edited_at',
            ])
            ->latest('created_at')
            ->orderByDesc('id');
        $paginator = $this->paginator->paginate(
            $user,
            'profile-comment-activity',
            ['projection' => 'comment-activity-v1'],
            $query,
            max(1, (int) config('comments.pagination.profile_per_page', 15)),
            'activity_page',
            $refresh,
        );

        return $paginator->through(
            fn (Comment $comment): CommentActivityData => new CommentActivityData(
                id: (int) $comment->id,
                targetLabel: $comment->target_type->label(),
                excerpt: $comment->is_spoiler
                    ? null
                    : Str::limit((string) $comment->body, max(1, (int) config('comments.body.excerpt_length', 360))),
                isSpoiler: (bool) $comment->is_spoiler,
                statusLabel: $comment->status->label(),
                statusVariant: $comment->status === CommentStatus::Published ? 'success' : 'warning',
                createdAtIso: $comment->created_at?->toAtomString() ?? '',
                createdAtLabel: $comment->created_at?->diffForHumans() ?? '',
                editedAtLabel: $comment->edited_at?->diffForHumans(),
                directUrl: route('comments.show', $comment->id),
            ),
        );
    }

    /** @return LengthAwarePaginator<int, CommentNotificationData> */
    public function notifications(User $user): LengthAwarePaginator
    {
        $paginator = $user->notifications()
            ->where('type', 'comment.activity')
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(15, ['*'], 'notifications_page');
        $notifications = collect($paginator->items());
        $commentIds = $notifications
            ->map(fn (DatabaseNotification $notification): mixed => $notification->data['comment_id'] ?? null)
            ->filter(fn (mixed $id): bool => is_int($id) || (is_string($id) && ctype_digit($id)))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $comments = Comment::query()
            ->withTrashed()
            ->whereIn('id', $commentIds)
            ->where($this->accessibleTargets($user))
            ->get(['id', 'user_id', 'status', 'deleted_at'])
            ->keyBy('id');

        return $paginator->through(function (DatabaseNotification $notification) use ($comments, $user): CommentNotificationData {
            $data = $notification->data;
            $kind = is_string($data['kind'] ?? null)
                ? CommentNotificationType::tryFrom($data['kind'])
                : null;
            $commentId = is_numeric($data['comment_id'] ?? null) ? (int) $data['comment_id'] : null;
            $comment = $commentId !== null ? $comments->get($commentId) : null;
            $moderationStatus = is_string($data['moderation_status'] ?? null)
                ? CommentStatus::tryFrom($data['moderation_status'])
                : null;

            return new CommentNotificationData(
                id: (string) $notification->id,
                label: $kind !== null
                    ? __('comments.notifications.'.$kind->value)
                    : __('comments.notifications.unavailable'),
                detail: $kind === CommentNotificationType::Moderation && $moderationStatus !== null
                    ? __('comments.moderation.notice', ['status' => $moderationStatus->label()])
                    : null,
                url: $comment instanceof Comment && Gate::forUser($user)->allows('view', $comment)
                    ? route('comments.show', $comment->id)
                    : null,
                isRead: $notification->read_at !== null,
                createdAtIso: $notification->created_at?->toAtomString() ?? '',
                createdAtLabel: $notification->created_at?->diffForHumans() ?? '',
            );
        });
    }

    /** @return LengthAwarePaginator<int, CommentRelationshipData> */
    public function blocks(User $user): LengthAwarePaginator
    {
        $paginator = UserBlock::query()
            ->where('blocker_id', $user->id)
            ->with('blocked:id,name')
            ->orderByDesc('id')
            ->paginate(
                max(1, (int) config('comments.pagination.profile_per_page', 15)),
                ['id', 'blocked_id', 'created_at'],
                'blocks_page',
            );

        return $paginator->through(fn (UserBlock $block): CommentRelationshipData => new CommentRelationshipData(
            userId: (int) $block->blocked_id,
            name: $block->blocked->name ?? __('comments.author.unavailable'),
            createdAtLabel: $block->created_at?->diffForHumans() ?? '',
        ));
    }

    /** @return LengthAwarePaginator<int, CommentRelationshipData> */
    public function mutes(User $user): LengthAwarePaginator
    {
        $paginator = UserMute::query()
            ->where('muter_id', $user->id)
            ->with('muted:id,name')
            ->orderByDesc('id')
            ->paginate(
                max(1, (int) config('comments.pagination.profile_per_page', 15)),
                ['id', 'muted_id', 'created_at'],
                'mutes_page',
            );

        return $paginator->through(fn (UserMute $mute): CommentRelationshipData => new CommentRelationshipData(
            userId: (int) $mute->muted_id,
            name: $mute->muted->name ?? __('comments.author.unavailable'),
            createdAtLabel: $mute->created_at?->diffForHumans() ?? '',
        ));
    }

    /** @return Builder<Comment> */
    private function publicActivityQuery(int $authorId, ?User $viewer): Builder
    {
        $query = Comment::query()
            ->where('user_id', $authorId)
            ->published()
            ->whereNotNull('catalog_title_id')
            ->where($this->accessibleCatalogTargets($viewer));

        if ($viewer !== null
            && (int) $viewer->id !== $authorId
            && $this->relationships->context($viewer, [$authorId])->hides($authorId)) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private function accessibleCatalogTargets(?User $viewer): callable
    {
        return function (Builder $query) use ($viewer): void {
            $query->where(function (Builder $query) use ($viewer): void {
                $query
                    ->where(function (Builder $query) use ($viewer): void {
                        $query
                            ->where('target_type', CommentTargetType::Title->value)
                            ->whereIn('target_id', $this->titles->visibleTo($viewer)->select('id'));
                    })
                    ->orWhere(function (Builder $query) use ($viewer): void {
                        $query
                            ->where('target_type', CommentTargetType::Season->value)
                            ->whereIn('target_id', Season::query()
                                ->availableTo($viewer)
                                ->whereIn('catalog_title_id', $this->titles->visibleTo($viewer)->select('id'))
                                ->select('id'));
                    })
                    ->orWhere(function (Builder $query) use ($viewer): void {
                        $query
                            ->where('target_type', CommentTargetType::Episode->value)
                            ->whereIn('target_id', Episode::query()
                                ->availableTo($viewer)
                                ->whereIn('season_id', Season::query()
                                    ->availableTo($viewer)
                                    ->whereIn('catalog_title_id', $this->titles->visibleTo($viewer)->select('id'))
                                    ->select('id'))
                                ->select('id'));
                    });
            });
        };
    }

    private function accessibleTargets(User $user): callable
    {
        return function (Builder $query) use ($user): void {
            $query->where(function (Builder $query) use ($user): void {
                $query
                    ->where(function (Builder $query) use ($user): void {
                        $query
                            ->where('target_type', CommentTargetType::Title->value)
                            ->whereIn('target_id', $this->titles->visibleTo($user)->select('id'));
                    })
                    ->orWhere(function (Builder $query) use ($user): void {
                        $query
                            ->where('target_type', CommentTargetType::Season->value)
                            ->whereIn('target_id', Season::query()
                                ->availableTo($user)
                                ->whereIn('catalog_title_id', $this->titles->visibleTo($user)->select('id'))
                                ->select('id'));
                    })
                    ->orWhere(function (Builder $query) use ($user): void {
                        $query
                            ->where('target_type', CommentTargetType::Episode->value)
                            ->whereIn('target_id', Episode::query()
                                ->availableTo($user)
                                ->whereIn('season_id', Season::query()
                                    ->availableTo($user)
                                    ->whereIn('catalog_title_id', $this->titles->visibleTo($user)->select('id'))
                                    ->select('id'))
                                ->select('id'));
                    })
                    ->orWhere(function (Builder $query) use ($user): void {
                        $collections = CatalogCollection::query()->select('id');

                        if (! Gate::forUser($user)->allows('manage-catalog')) {
                            $collections->where(function (Builder $collections) use ($user): void {
                                $collections
                                    ->where('owner_id', $user->id)
                                    ->orWhere(function (Builder $collections): void {
                                        $collections
                                            ->whereIn('visibility', [
                                                CatalogCollectionVisibility::Unlisted->value,
                                                CatalogCollectionVisibility::Public->value,
                                            ])
                                            ->where('moderation_status', CatalogCollectionModerationStatus::Approved->value);
                                    });
                            });
                        }

                        $query
                            ->where('target_type', CommentTargetType::Collection->value)
                            ->whereIn('target_id', $collections);
                    });
            });
        };
    }
}
