<?php

declare(strict_types=1);

namespace App\Services\UserPortal;

use App\DTOs\UserLibraryFilters;
use App\Enums\ReviewSort;
use App\Models\User;
use App\Services\Catalog\UserLibraryQuery;
use App\Services\Catalog\UserLibrarySummaryQuery;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Comments\CommentProfileQuery;
use App\Services\Reviews\CatalogTitleReviewQuery;
use App\Services\Tags\PersonalTagLibraryQuery;

final readonly class UserPortalCacheWarmer
{
    public function __construct(
        private UserLibrarySummaryQuery $librarySummaries,
        private UserLibraryQuery $library,
        private CatalogCollectionQuery $collections,
        private PersonalTagLibraryQuery $personalTags,
        private CommentProfileQuery $comments,
        private CatalogTitleReviewQuery $reviews,
    ) {}

    /** @return array{targets: int, duration_ms: int} */
    public function warm(User $user, bool $refresh = false): array
    {
        $startedAt = hrtime(true);
        $this->librarySummaries->get($user, $refresh);
        $this->library->watchlist($user, new UserLibraryFilters, 'watchlistPage', $refresh);
        $this->collections->ownerCounts($user, $refresh);
        $this->personalTags->active($user, '', $refresh);
        $this->personalTags->restorable($user, $refresh);
        $this->comments->activity($user, $refresh);
        $this->reviews->forAuthor($user, ReviewSort::Newest, null, refresh: $refresh);

        return [
            'targets' => 7,
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ];
    }
}
