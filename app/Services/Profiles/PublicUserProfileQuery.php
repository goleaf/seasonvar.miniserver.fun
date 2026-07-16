<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\Enums\CatalogWatchStatus;
use App\Models\CatalogCollection;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleUserState;
use App\Models\Comment;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Collections\CatalogCollectionQuery;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class PublicUserProfileQuery
{
    public function __construct(
        private readonly CatalogTitleQuery $titles,
        private readonly CatalogCollectionQuery $collections,
    ) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function reviews(UserProfile $profile, ?User $viewer): LengthAwarePaginator
    {
        return CatalogTitleReview::query()
            ->where('user_id', $profile->user_id)
            ->publiclyVisible()
            ->whereIn('catalog_title_id', $this->titles->visibleTo($viewer)->select('id'))
            ->with(['catalogTitle:id,slug,title'])
            ->latest('published_at')
            ->orderByDesc('id')
            ->paginate(
                max(1, (int) config('user-profiles.pagination.reviews', 10)),
                ['id', 'catalog_title_id', 'review_title', 'body', 'is_spoiler', 'published_at', 'created_at'],
                'reviewsPage',
            )
            ->through(fn (CatalogTitleReview $review): array => [
                'id' => (int) $review->id,
                'title' => $review->review_title,
                'excerpt' => $review->is_spoiler ? null : Str::limit((string) $review->body, 420),
                'is_spoiler' => (bool) $review->is_spoiler,
                'target_title' => $review->catalogTitle?->title,
                'target_url' => $review->catalogTitle !== null ? route('titles.show', $review->catalogTitle) : null,
                'direct_url' => route('reviews.show', $review->id),
                'published_at' => ($review->published_at ?? $review->created_at)?->diffForHumans() ?? '',
            ]);
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
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
            ->through(fn (Comment $comment): array => [
                'id' => (int) $comment->id,
                'excerpt' => $comment->is_spoiler ? null : Str::limit((string) $comment->body, 360),
                'is_spoiler' => (bool) $comment->is_spoiler,
                'target_title' => $comment->catalogTitle?->title,
                'target_url' => $comment->catalogTitle !== null ? route('titles.show', $comment->catalogTitle) : null,
                'direct_url' => route('comments.show', $comment->id),
                'published_at' => $comment->created_at?->diffForHumans() ?? '',
            ]);
    }

    /** @return LengthAwarePaginator<int, CatalogCollection> */
    public function collections(UserProfile $profile): LengthAwarePaginator
    {
        return $this->collections->publicByOwner(
            $profile->user,
            max(1, (int) config('user-profiles.pagination.collections', 12)),
        );
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
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
            ->through(fn (CatalogTitleUserState $state): array => [
                'title' => $state->catalogTitle?->title,
                'original_title' => $state->catalogTitle?->original_title,
                'year' => $state->catalogTitle?->year,
                'url' => $state->catalogTitle !== null ? route('titles.show', $state->catalogTitle) : null,
                'poster_url' => $state->catalogTitle?->poster_url,
            ]);
    }
}
