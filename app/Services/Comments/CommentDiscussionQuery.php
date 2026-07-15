<?php

declare(strict_types=1);

namespace App\Services\Comments;

use App\DTOs\Comments\CommentItemData;
use App\Enums\CommentReactionType;
use App\Enums\CommentSort;
use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\CommentReaction;
use App\Models\User;
use App\ValueObjects\CommentTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final class CommentDiscussionQuery
{
    public function __construct(
        private readonly CommentRelationshipService $relationships,
        private readonly CommentPresenter $presenter,
    ) {}

    /**
     * @param  list<int>  $revealedSpoilers
     * @param  list<int>  $expandedBodies
     * @return LengthAwarePaginator<int, CommentItemData>
     */
    public function comments(
        CommentTarget $target,
        ?User $viewer,
        CommentSort $sort,
        array $revealedSpoilers = [],
        array $expandedBodies = [],
        ?int $focusedCommentId = null,
        ?string $interfaceLocale = null,
    ): LengthAwarePaginator {
        $query = $this->topLevelVisibilityQuery($target, $viewer, $focusedCommentId);
        $this->addPresentationRelations($query, $viewer);
        $this->sort($query, $sort);
        $paginator = $query->paginate(
            max(1, (int) config('comments.pagination.comments_per_page', 15)),
            pageName: 'comments_page',
        );
        $comments = $paginator->getCollection();
        $context = $this->relationships->context($viewer, $this->relevantUserIds($comments));
        $viewerReactions = $this->viewerReactions($comments, $viewer);

        return $paginator->through(fn (Comment $comment): CommentItemData => $this->presenter->item(
            $comment,
            $viewer,
            $context,
            $revealedSpoilers,
            $expandedBodies,
            $viewerReactions,
            $focusedCommentId,
            $interfaceLocale,
        ));
    }

    /**
     * @param  list<int>  $revealedSpoilers
     * @param  list<int>  $expandedBodies
     * @return Collection<int, CommentItemData>
     */
    public function replies(
        CommentTarget $target,
        int $threadRootId,
        ?User $viewer,
        int $limit,
        array $revealedSpoilers = [],
        array $expandedBodies = [],
        ?int $focusedCommentId = null,
        ?string $interfaceLocale = null,
    ): Collection {
        $query = Comment::query()
            ->withTrashed()
            ->forTarget($target->type, $target->id)
            ->where('parent_id', $threadRootId)
            ->where(function (Builder $query) use ($viewer): void {
                $query->where(function (Builder $query): void {
                    $query
                        ->where('status', CommentStatus::Published->value)
                        ->whereNull('deleted_at');
                });

                if ($viewer !== null) {
                    $query->orWhere(function (Builder $query) use ($viewer): void {
                        $query
                            ->where('user_id', $viewer->id)
                            ->where('status', '!=', CommentStatus::Published->value)
                            ->where('status', '!=', CommentStatus::Removed->value)
                            ->whereNull('deleted_at');
                    });
                }
            })
            ->oldest('created_at')
            ->orderBy('id')
            ->limit(max(1, $limit));
        $this->addPresentationRelations($query, $viewer);
        $comments = $query->get();

        if ($focusedCommentId !== null && ! $comments->contains('id', $focusedCommentId)) {
            $isModerator = $viewer !== null && Gate::forUser($viewer)->allows('manage-comments');
            $focused = Comment::query()
                ->withTrashed()
                ->forTarget($target->type, $target->id)
                ->where('parent_id', $threadRootId)
                ->whereKey($focusedCommentId);

            if (! $isModerator) {
                $focused->where(function (Builder $query) use ($viewer): void {
                    $query->where('status', CommentStatus::Published->value);

                    if ($viewer !== null) {
                        $query->orWhere('user_id', $viewer->id);
                    }
                });
            }

            $this->addPresentationRelations($focused, $viewer);
            $focusedComment = $focused->first();

            if ($focusedComment !== null) {
                $comments->push($focusedComment);
                $comments = $comments
                    ->sortBy([
                        ['created_at', 'asc'],
                        ['id', 'asc'],
                    ])
                    ->values();
            }
        }

        $context = $this->relationships->context($viewer, $this->relevantUserIds($comments));
        $viewerReactions = $this->viewerReactions($comments, $viewer);

        return $comments->map(fn (Comment $comment): CommentItemData => $this->presenter->item(
            $comment,
            $viewer,
            $context,
            $revealedSpoilers,
            $expandedBodies,
            $viewerReactions,
            $focusedCommentId,
            $interfaceLocale,
        ));
    }

    public function publicCount(CommentTarget $target): int
    {
        return Comment::query()
            ->forTarget($target->type, $target->id)
            ->published()
            ->count();
    }

    public function rootFor(Comment $comment): Comment
    {
        if ($comment->parent_id === null) {
            return $comment;
        }

        $root = Comment::query()->withTrashed()->findOrFail($comment->parent_id);

        if ($root->parent_id !== null
            || $root->target_type !== $comment->target_type
            || (int) $root->target_id !== (int) $comment->target_id) {
            throw (new ModelNotFoundException)->setModel(Comment::class, [$comment->id]);
        }

        return $root;
    }

    public function oldestPageFor(CommentTarget $target, Comment $root, ?User $viewer): int
    {
        $before = $this->topLevelVisibilityQuery($target, $viewer, (int) $root->id)
            ->where(function (Builder $query) use ($root): void {
                $query
                    ->where('created_at', '<', $root->created_at)
                    ->orWhere(function (Builder $query) use ($root): void {
                        $query
                            ->where('created_at', $root->created_at)
                            ->where('id', '<', $root->id);
                    });
            })
            ->count();
        $perPage = max(1, (int) config('comments.pagination.comments_per_page', 15));

        return intdiv($before, $perPage) + 1;
    }

    /** @return Builder<Comment> */
    private function topLevelVisibilityQuery(
        CommentTarget $target,
        ?User $viewer,
        ?int $focusedCommentId = null,
    ): Builder {
        return Comment::query()
            ->withTrashed()
            ->forTarget($target->type, $target->id)
            ->whereNull('parent_id')
            ->where(function (Builder $query) use ($viewer, $focusedCommentId): void {
                $query->where(function (Builder $query): void {
                    $query
                        ->where('status', CommentStatus::Published->value)
                        ->where(function (Builder $query): void {
                            $query
                                ->whereNull('deleted_at')
                                ->orWhereHas('replies', fn (Builder $query): Builder => $query
                                    ->where('status', CommentStatus::Published->value)
                                    ->whereNull('deleted_at'));
                        });
                })->orWhere(function (Builder $query): void {
                    $query
                        ->where('status', '!=', CommentStatus::Published->value)
                        ->whereHas('replies', fn (Builder $query): Builder => $query
                            ->where('status', CommentStatus::Published->value)
                            ->whereNull('deleted_at'));
                });

                if ($viewer !== null) {
                    $query->orWhere(function (Builder $query) use ($viewer): void {
                        $query
                            ->where('user_id', $viewer->id)
                            ->where('status', '!=', CommentStatus::Published->value)
                            ->where('status', '!=', CommentStatus::Removed->value)
                            ->whereNull('deleted_at');
                    });
                }

                if ($focusedCommentId !== null) {
                    $query->orWhere(function (Builder $query) use ($focusedCommentId, $viewer): void {
                        $query->whereKey($focusedCommentId)->where(function (Builder $query) use ($viewer): void {
                            $query->where('status', CommentStatus::Published->value);

                            if ($viewer !== null) {
                                $query->orWhere('user_id', $viewer->id);
                            }
                        });
                    });

                    if ($viewer !== null && Gate::forUser($viewer)->allows('manage-comments')) {
                        $query->orWhere('id', $focusedCommentId);
                    }
                }
            });
    }

    /** @param Builder<Comment> $query */
    private function addPresentationRelations(Builder $query, ?User $viewer): void
    {
        $query
            ->select([
                'id',
                'user_id',
                'target_type',
                'target_id',
                'catalog_title_id',
                'parent_id',
                'reply_to_id',
                'body',
                'is_spoiler',
                'status',
                'version',
                'edited_at',
                'deletion_reason',
                'moderation_reason',
                'created_at',
                'updated_at',
                'deleted_at',
            ])
            ->with([
                'author:id,name',
                'parent:id,status,deletion_reason,deleted_at',
                'replyTo:id,user_id,status,deleted_at',
                'replyTo.author:id,name',
            ])
            ->withCount([
                'reactions as upvotes_count' => fn (Builder $query): Builder => $query
                    ->where('type', CommentReactionType::Up->value),
                'reactions as downvotes_count' => fn (Builder $query): Builder => $query
                    ->where('type', CommentReactionType::Down->value),
                'replies as replies_count' => fn (Builder $query): Builder => $query
                    ->where('status', CommentStatus::Published->value)
                    ->whereNull('deleted_at'),
            ]);

        if ($viewer !== null) {
            $query->withCount([
                'replies as viewer_private_replies_count' => fn (Builder $query): Builder => $query
                    ->where('user_id', $viewer->id)
                    ->where('status', '!=', CommentStatus::Published->value)
                    ->where('status', '!=', CommentStatus::Removed->value)
                    ->whereNull('deleted_at'),
            ]);
        }
    }

    /** @param Builder<Comment> $query */
    private function sort(Builder $query, CommentSort $sort): void
    {
        match ($sort) {
            CommentSort::Oldest => $query->oldest('created_at')->orderBy('id'),
            CommentSort::Popular => $query
                ->orderByDesc('upvotes_count')
                ->orderBy('downvotes_count')
                ->latest('created_at')
                ->orderByDesc('id'),
            CommentSort::Newest => $query->latest('created_at')->orderByDesc('id'),
        };
    }

    /**
     * @param  Collection<int, Comment>  $comments
     * @return array<int, CommentReactionType>
     */
    private function viewerReactions(Collection $comments, ?User $viewer): array
    {
        if ($viewer === null || $comments->isEmpty()) {
            return [];
        }

        return CommentReaction::query()
            ->where('user_id', $viewer->id)
            ->whereIn('comment_id', $comments->pluck('id')->all())
            ->get(['comment_id', 'type'])
            ->mapWithKeys(fn (CommentReaction $reaction): array => [
                (int) $reaction->comment_id => $reaction->type,
            ])
            ->all();
    }

    /**
     * @param  Collection<int, Comment>  $comments
     * @return list<int>
     */
    private function relevantUserIds(Collection $comments): array
    {
        return $comments
            ->flatMap(fn (Comment $comment): array => [
                $comment->user_id,
                $comment->replyTo?->user_id,
            ])
            ->filter(fn (mixed $id): bool => is_int($id) && $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
