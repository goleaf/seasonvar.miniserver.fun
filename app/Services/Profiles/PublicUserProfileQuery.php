<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\DTOs\Comments\PublicCommentActivityData;
use App\DTOs\Profiles\PublicProfileWatchItemData;
use App\DTOs\Reviews\PublicReviewActivityData;
use App\Enums\CatalogWatchStatus;
use App\Models\CatalogCollection;
use App\Models\CatalogTitleUserState;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Comments\CommentProfileQuery;
use App\Services\Reviews\CatalogTitleReviewQuery;
use Illuminate\Pagination\LengthAwarePaginator;

final class PublicUserProfileQuery
{
    public function __construct(
        private readonly CatalogTitleQuery $titles,
        private readonly CatalogCollectionQuery $collections,
        private readonly CatalogTitleReviewQuery $reviews,
        private readonly CommentProfileQuery $comments,
    ) {}

    /** @return LengthAwarePaginator<int, PublicReviewActivityData> */
    public function reviews(UserProfile $profile, ?User $viewer): LengthAwarePaginator
    {
        return $this->reviews->forPublicAuthor(
            (int) $profile->user_id,
            $viewer,
            max(1, (int) config('user-profiles.pagination.reviews', 10)),
            interfaceLocale: app()->getLocale(),
        );
    }

    /** @return LengthAwarePaginator<int, PublicCommentActivityData> */
    public function comments(UserProfile $profile, ?User $viewer): LengthAwarePaginator
    {
        return $this->comments->publicActivity(
            (int) $profile->user_id,
            $viewer,
            max(1, (int) config('user-profiles.pagination.comments', 12)),
        );
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
