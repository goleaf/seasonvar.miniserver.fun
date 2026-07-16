<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\DTOs\Profiles\PublicProfileCommentActivityData;
use App\DTOs\Profiles\PublicProfileWatchItemData;
use App\DTOs\Reviews\PublicReviewActivityData;
use App\Enums\CatalogWatchStatus;
use App\Models\CatalogCollection;
use App\Models\CatalogTitleUserState;
use App\Models\Comment;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Reviews\CatalogTitleReviewQuery;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class PublicUserProfileQuery
{
    public function __construct(
        private readonly CatalogTitleQuery $titles,
        private readonly CatalogCollectionQuery $collections,
        private readonly CatalogTitleReviewQuery $reviews,
    ) {}

    /** @return LengthAwarePaginator<int, PublicReviewActivityData> */
    public function reviews(UserProfile $profile, ?User $viewer): LengthAwarePaginator
    {
        return $this->reviews->forPublicAuthor(
            (int) $profile->user_id,
            $viewer,
            max(1, (int) config('user-profiles.pagination.reviews', 10)),
        );
    }

    /** @return LengthAwarePaginator<int, PublicProfileCommentActivityData> */
    public function comments(UserProfile $profile, ?User $viewer): LengthAwarePaginator
    {
        return Comment::query()
            ->where('user_id', $profile->user_id)
            ->published()
            ->whereNotNull('catalog_title_id')
            ->whereIn('catalog_title_id', $this->titles->visibleTo($viewer)->select('id'))
            ->with(['catalogTitle:id,slug,title'])
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(
                max(1, (int) config('user-profiles.pagination.comments', 12)),
                ['id', 'catalog_title_id', 'body', 'is_spoiler', 'created_at'],
                'commentsPage',
            )
            ->through(fn (Comment $comment): PublicProfileCommentActivityData => new PublicProfileCommentActivityData(
                id: (int) $comment->id,
                excerpt: $comment->is_spoiler ? null : Str::limit((string) $comment->body, 360),
                isSpoiler: (bool) $comment->is_spoiler,
                targetTitle: $comment->catalogTitle?->title,
                targetUrl: $comment->catalogTitle !== null ? route('titles.show', $comment->catalogTitle) : null,
                directUrl: route('comments.show', $comment->id),
                publishedAt: $comment->created_at?->diffForHumans() ?? '',
            ));
    }

    /** @return LengthAwarePaginator<int, CatalogCollection> */
    public function collections(UserProfile $profile): LengthAwarePaginator
    {
        return $this->collections->publicByOwner(
            $profile->user,
            max(1, (int) config('user-profiles.pagination.collections', 12)),
        );
    }

    /** @return LengthAwarePaginator<int, PublicProfileWatchItemData> */
    public function watchList(UserProfile $profile, ?User $viewer, CatalogWatchStatus $status): LengthAwarePaginator
    {
        return CatalogTitleUserState::query()
            ->where('user_id', $profile->user_id)
            ->where('watch_status', $status->value)
            ->whereIn('catalog_title_id', $this->titles->visibleTo($viewer)->select('id'))
            ->with(['catalogTitle:id,slug,title,original_title,year,poster_url'])
            ->latest('watch_status_updated_at')
            ->orderByDesc('id')
            ->paginate(
                max(1, (int) config('user-profiles.pagination.watch_lists', 18)),
                ['id', 'catalog_title_id', 'watch_status_updated_at'],
                $status->value.'Page',
            )
            ->through(fn (CatalogTitleUserState $state): PublicProfileWatchItemData => new PublicProfileWatchItemData(
                title: $state->catalogTitle?->title,
                originalTitle: $state->catalogTitle?->original_title,
                year: $state->catalogTitle?->year,
                url: $state->catalogTitle !== null ? route('titles.show', $state->catalogTitle) : null,
                posterUrl: $state->catalogTitle?->poster_url,
            ));
    }
}
