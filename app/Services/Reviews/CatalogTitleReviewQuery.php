<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\DTOs\Reviews\AdminReviewItemData;
use App\DTOs\Reviews\PublicReviewActivityData;
use App\DTOs\Reviews\ReviewCriteria;
use App\DTOs\Reviews\ReviewItemData;
use App\DTOs\Reviews\ReviewViewerContext;
use App\Enums\ReviewReportStatus;
use App\Enums\ReviewSort;
use App\Enums\ReviewStatus;
use App\Enums\ReviewVoteType;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleReviewRestriction;
use App\Models\CatalogTitleReviewVote;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery as CatalogVisibilityQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class CatalogTitleReviewQuery
{
    public function __construct(
        private readonly ReviewSchema $schema,
        private readonly ReviewRelationshipService $relationships,
        private readonly ReviewPresenter $presenter,
        private readonly CatalogVisibilityQuery $titles,
    ) {}

    /**
     * @param  list<int>  $revealedReviewIds
     * @return LengthAwarePaginator<int, ReviewItemData>
     */
    public function forTitle(
        CatalogTitle $title,
        ?User $viewer,
        ReviewCriteria $criteria,
        array $revealedReviewIds = [],
        ?int $highlightedReviewId = null,
        bool $viewerRestricted = false,
        string $pageName = 'reviewPage',
    ): LengthAwarePaginator {
        $context = $this->relationships->context($viewer);
        $context = new ReviewViewerContext(
            userId: $context->userId,
            isModerator: $context->isModerator,
            isReviewRestricted: $viewerRestricted,
            blockedUserIds: $context->blockedUserIds,
            mutedUserIds: $context->mutedUserIds,
        );
        $query = $this->titleQuery($title, $context);

        if ($this->schema->communityAvailable()) {
            $this->applyFilters($query, $criteria);
            $this->applySort($query, $criteria->sort);
            $this->addPresentationRelations($query, $criteria->sort);
        } else {
            $query
                ->select(['id', 'catalog_title_id', 'author', 'body', 'published_at', 'created_at'])
                ->latest('published_at')
                ->latest('id');
        }

        $paginator = $query
            ->paginate(max(1, (int) config('reviews.per_page', 10)), pageName: $pageName)
            ->withQueryString();
        $paginator->setPath(route('titles.show', $title));
        $paginator->appends(array_filter([
            'review_sort' => $criteria->sort === ReviewSort::Newest ? null : $criteria->sort->value,
            'review_rating' => $criteria->rating,
            'review_spoiler' => match ($criteria->containsSpoiler) {
                true => 'contains',
                false => 'spoiler_free',
                null => null,
            },
            'review_verified' => match ($criteria->verifiedWatching) {
                true => 'verified',
                false => 'unverified',
                null => null,
            },
        ], static fn (mixed $value): bool => $value !== null));
        $reviews = $paginator->getCollection();
        $votes = $this->viewerVotes($reviews, $viewer);

        return $paginator->through(fn (CatalogTitleReview $review): ReviewItemData => $this->presenter->item(
            $review,
            $viewer,
            $context,
            $votes[(int) $review->id] ?? null,
            in_array((int) $review->id, $revealedReviewIds, true),
            $highlightedReviewId === (int) $review->id,
        ));
    }

    /**
     * @param  list<int>  $revealedReviewIds
     * @return LengthAwarePaginator<int, ReviewItemData>
     */
    public function forAuthor(
        User $author,
        ReviewSort $sort,
        ?ReviewStatus $status,
        array $revealedReviewIds = [],
        string $pageName = 'reviewPage',
    ): LengthAwarePaginator {
        $context = $this->relationships->context($author);
        $query = $this->communityQuery()
            ->where('catalog_title_reviews.user_id', $author->id)
            ->whereIn(
                'catalog_title_reviews.catalog_title_id',
                $this->titles->visibleTo($author)->select('catalog_titles.id'),
            )
            ->whereNull('catalog_title_reviews.merged_into_id')
            ->when($status !== null, fn (Builder $query): Builder => $query->where('catalog_title_reviews.status', $status->value));
        $this->applySort($query, $sort);
        $this->addPresentationRelations($query, $sort);
        $paginator = $query
            ->paginate(max(1, (int) config('reviews.profile_per_page', 12)), pageName: $pageName)
            ->withQueryString();
        $paginator->setPath(route('profile.reviews'));
        $paginator->appends(array_filter([
            'sort' => $sort === ReviewSort::Newest ? null : $sort->value,
            'status' => $status?->value,
        ], static fn (mixed $value): bool => $value !== null));

        return $paginator->through(fn (CatalogTitleReview $review): ReviewItemData => $this->presenter->item(
            $review,
            $author,
            $context,
            null,
            in_array((int) $review->id, $revealedReviewIds, true),
        ));
    }

    /** @return LengthAwarePaginator<int, PublicReviewActivityData> */
    public function forPublicAuthor(
        int $authorId,
        ?User $viewer,
        int $perPage,
        string $pageName = 'reviewsPage',
    ): LengthAwarePaginator {
        $perPage = max(1, $perPage);

        if ($authorId < 1 || ! $this->schema->communityAvailable()) {
            return new LengthAwarePaginator([], 0, $perPage, options: ['pageName' => $pageName]);
        }

        return CatalogTitleReview::query()
            ->where('user_id', $authorId)
            ->publiclyVisible()
            ->whereIn(
                'catalog_title_id',
                $this->titles->visibleTo($viewer)->select('catalog_titles.id'),
            )
            ->with([
                'catalogTitle' => fn ($query) => $query
                    ->select(['id', 'slug', 'title', 'original_title']),
            ])
            ->latest('published_at')
            ->orderByDesc('id')
            ->paginate(
                $perPage,
                [
                    'id',
                    'catalog_title_id',
                    'review_title',
                    'body',
                    'is_spoiler',
                    'published_at',
                    'created_at',
                ],
                $pageName,
            )
            ->withQueryString()
            ->through(fn (CatalogTitleReview $review): PublicReviewActivityData => $this->presenter->publicAuthorItem($review));
    }

    public function publicCountForAuthor(int $authorId, ?User $viewer): int
    {
        if ($authorId < 1 || ! $this->schema->communityAvailable()) {
            return 0;
        }

        return CatalogTitleReview::query()
            ->where('user_id', $authorId)
            ->publiclyVisible()
            ->whereIn(
                'catalog_title_id',
                $this->titles->visibleTo($viewer)->select('catalog_titles.id'),
            )
            ->count();
    }

    public function pageForPublicReview(
        CatalogTitleReview $review,
        ?User $viewer,
    ): int {
        if (! $this->schema->communityAvailable()) {
            $positionQuery = CatalogTitleReview::query()
                ->where('catalog_title_id', $review->catalog_title_id);
            $position = $review->published_at === null
                ? $positionQuery
                    ->where(function (Builder $query) use ($review): void {
                        $query
                            ->whereNotNull('published_at')
                            ->orWhere(function (Builder $query) use ($review): void {
                                $query->whereNull('published_at')->where('id', '>', $review->id);
                            });
                    })
                    ->count()
                : $positionQuery
                    ->where(function (Builder $query) use ($review): void {
                        $query
                            ->where('published_at', '>', $review->published_at)
                            ->orWhere(function (Builder $query) use ($review): void {
                                $query
                                    ->where('published_at', $review->published_at)
                                    ->where('id', '>', $review->id);
                            });
                    })
                    ->count();

            return intdiv($position, max(1, (int) config('reviews.per_page', 10))) + 1;
        }

        $context = $this->relationships->context($viewer);
        $query = $this->titleQuery($review->catalogTitle()->firstOrFail(), $context);
        $timestamp = ($review->published_at ?? $review->created_at)?->toDateTimeString();

        if ($timestamp === null) {
            return 1;
        }

        $position = $query
            ->where(function (Builder $query) use ($timestamp, $review): void {
                $query
                    ->whereRaw('COALESCE(catalog_title_reviews.published_at, catalog_title_reviews.created_at) > ?', [$timestamp])
                    ->orWhere(function (Builder $query) use ($timestamp, $review): void {
                        $query
                            ->whereRaw('COALESCE(catalog_title_reviews.published_at, catalog_title_reviews.created_at) = ?', [$timestamp])
                            ->where('catalog_title_reviews.id', '>', $review->id);
                    });
            })
            ->count();

        return intdiv($position, max(1, (int) config('reviews.per_page', 10))) + 1;
    }

    /** @return LengthAwarePaginator<int, AdminReviewItemData> */
    public function forModeration(
        User $moderator,
        ?ReviewStatus $status,
        bool $attentionOnly,
        ?int $reviewId,
        string $authorSearch,
        string $targetSearch,
        ?int $rating,
        string $pageName = 'reviewPage',
    ): LengthAwarePaginator {
        $context = $this->relationships->context($moderator);
        $query = $this->communityQuery(includePrivate: true)
            ->whereNull('catalog_title_reviews.merged_into_id')
            ->when($attentionOnly, fn (Builder $query): Builder => $query
                ->where(function (Builder $query): void {
                    $query
                        ->where('catalog_title_reviews.status', ReviewStatus::Pending->value)
                        ->orWhereHas('reports', fn (Builder $query): Builder => $query
                            ->whereIn('status', [
                                ReviewReportStatus::Open->value,
                                ReviewReportStatus::Reviewed->value,
                            ]));
                }))
            ->when($status !== null, fn (Builder $query): Builder => $query
                ->where('catalog_title_reviews.status', $status->value))
            ->when($reviewId !== null, fn (Builder $query): Builder => $query
                ->whereKey($reviewId))
            ->when($rating !== null, fn (Builder $query): Builder => $query
                ->where('review_rating_state.rating', $rating))
            ->when($authorSearch !== '', fn (Builder $query): Builder => $query
                ->whereHas('authorAccount', fn (Builder $query): Builder => $query
                    ->where('name', 'like', '%'.$authorSearch.'%')))
            ->when($targetSearch !== '', function (Builder $query) use ($targetSearch): Builder {
                if (ctype_digit($targetSearch)) {
                    return $query->where('catalog_title_reviews.catalog_title_id', (int) $targetSearch);
                }

                return $query->whereHas('catalogTitle', fn (Builder $query): Builder => $query
                    ->where('slug', $targetSearch));
            })
            ->with([
                'reports' => fn ($query) => $query
                    ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [ReviewReportStatus::Open->value])
                    ->latest('created_at')
                    ->latest('id')
                    ->select([
                        'id',
                        'catalog_title_review_id',
                        'category',
                        'details',
                        'status',
                        'private_note',
                        'resolved_at',
                        'created_at',
                    ]),
            ])
            ->withCount([
                'reports as open_reports_count' => fn (Builder $query): Builder => $query
                    ->whereIn('status', [
                        ReviewReportStatus::Open->value,
                        ReviewReportStatus::Reviewed->value,
                    ]),
            ])
            ->orderByDesc('open_reports_count')
            ->latest('catalog_title_reviews.created_at')
            ->orderByDesc('catalog_title_reviews.id');
        $this->addPresentationRelations($query);
        $paginator = $query
            ->paginate(max(1, (int) config('reviews.admin_per_page', 20)), pageName: $pageName)
            ->withQueryString();
        $paginator->setPath(route('admin.reviews'));
        $paginator->appends(array_filter([
            'status' => ! $attentionOnly && $status !== null ? $status->value : null,
            'author' => $authorSearch,
            'target' => $targetSearch,
            'rating' => $rating,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
        $reviewModels = $paginator->getCollection();
        $restrictions = CatalogTitleReviewRestriction::query()
            ->active()
            ->whereIn('user_id', $reviewModels->pluck('user_id')->filter()->unique())
            ->latest('starts_at')
            ->latest('id')
            ->get()
            ->unique('user_id')
            ->keyBy('user_id');

        return $paginator->through(function (CatalogTitleReview $review) use (
            $moderator,
            $context,
            $restrictions,
        ): AdminReviewItemData {
            $item = $this->presenter->item($review, $moderator, $context, null, true);
            $restriction = $review->user_id !== null ? $restrictions->get($review->user_id) : null;

            return new AdminReviewItemData(
                review: $item,
                authorUserId: $review->user_id !== null ? (int) $review->user_id : null,
                moderationReason: $review->moderation_reason?->value,
                moderatorNote: filled($review->moderator_note) ? (string) $review->moderator_note : null,
                openReportCount: (int) $review->getAttribute('open_reports_count'),
                reports: $review->reports->map(fn ($report): array => [
                    'id' => (int) $report->id,
                    'category' => $report->category->value,
                    'category_label' => $report->category->label(),
                    'details' => filled($report->details) ? (string) $report->details : null,
                    'status' => $report->status->value,
                    'status_label' => $report->status->label(),
                    'private_note' => filled($report->private_note) ? (string) $report->private_note : null,
                    'created_at' => $report->created_at?->translatedFormat('d.m.Y H:i') ?? '',
                    'resolved_at' => $report->resolved_at?->translatedFormat('d.m.Y H:i'),
                ])->all(),
                activeRestriction: $restriction instanceof CatalogTitleReviewRestriction ? [
                    'id' => (int) $restriction->id,
                    'type' => $restriction->type->label(),
                    'reason' => $restriction->reason_code->label(),
                    'expires_at' => $restriction->expires_at?->translatedFormat('d.m.Y H:i'),
                    'private_note' => filled($restriction->private_note)
                        ? (string) $restriction->private_note
                        : null,
                ] : null,
            );
        });
    }

    /** @return Builder<CatalogTitleReview> */
    public function publicReviewById(int $reviewId, ?User $viewer): Builder
    {
        $context = $this->relationships->context($viewer);

        return CatalogTitleReview::query()
            ->whereKey($reviewId)
            ->where('status', ReviewStatus::Published->value)
            ->whereNull('deleted_at')
            ->whereNull('merged_into_id')
            ->when(
                ! $context->isModerator && ($context->blockedUserIds !== [] || $context->mutedUserIds !== []),
                fn (Builder $query): Builder => $this->excludeHiddenAuthors($query, $context),
            );
    }

    /** @return Builder<CatalogTitleReview> */
    private function titleQuery(CatalogTitle $title, ReviewViewerContext $context): Builder
    {
        if (! $this->schema->communityAvailable()) {
            return CatalogTitleReview::query()->where('catalog_title_id', $title->id);
        }

        $query = $this->communityQuery()
            ->where('catalog_title_reviews.catalog_title_id', $title->id)
            ->whereNull('catalog_title_reviews.merged_into_id');

        if (! $context->isModerator) {
            $query->where(function (Builder $query) use ($context): void {
                $query
                    ->where(function (Builder $query): void {
                        $query
                            ->where('catalog_title_reviews.status', ReviewStatus::Published->value)
                            ->whereNull('catalog_title_reviews.deleted_at');
                    })
                    ->when($context->userId !== null, fn (Builder $query): Builder => $query
                        ->orWhere('catalog_title_reviews.user_id', $context->userId));
            });
            $this->excludeHiddenAuthors($query, $context);
        }

        return $query;
    }

    /** @return Builder<CatalogTitleReview> */
    private function communityQuery(bool $includePrivate = false): Builder
    {
        $query = CatalogTitleReview::query()
            ->select($includePrivate ? ['catalog_title_reviews.*'] : $this->presentationColumns())
            ->leftJoin('catalog_title_user_states as review_rating_state', function ($join): void {
                $join
                    ->on('review_rating_state.user_id', '=', 'catalog_title_reviews.user_id')
                    ->on('review_rating_state.catalog_title_id', '=', 'catalog_title_reviews.catalog_title_id');
            })
            ->addSelect('review_rating_state.rating as review_rating');

        return $query;
    }

    /** @return list<string> */
    private function presentationColumns(): array
    {
        return [
            'catalog_title_reviews.id',
            'catalog_title_reviews.catalog_title_id',
            'catalog_title_reviews.user_id',
            'catalog_title_reviews.origin',
            'catalog_title_reviews.author',
            'catalog_title_reviews.review_title',
            'catalog_title_reviews.body',
            'catalog_title_reviews.is_spoiler',
            'catalog_title_reviews.is_verified_watch',
            'catalog_title_reviews.status',
            'catalog_title_reviews.version',
            'catalog_title_reviews.edited_at',
            'catalog_title_reviews.deletion_reason',
            'catalog_title_reviews.ownership_key',
            'catalog_title_reviews.merged_into_id',
            'catalog_title_reviews.published_at',
            'catalog_title_reviews.created_at',
            'catalog_title_reviews.deleted_at',
        ];
    }

    /**
     * @param  Builder<CatalogTitleReview>  $query
     * @return Builder<CatalogTitleReview>
     */
    private function excludeHiddenAuthors(Builder $query, ReviewViewerContext $context): Builder
    {
        $hidden = collect([...$context->blockedUserIds, ...$context->mutedUserIds])->unique()->values()->all();

        if ($hidden === []) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($hidden, $context): void {
            $query
                ->whereNull('catalog_title_reviews.user_id')
                ->when($context->userId !== null, fn (Builder $query): Builder => $query
                    ->orWhere('catalog_title_reviews.user_id', $context->userId))
                ->orWhereNotIn('catalog_title_reviews.user_id', $hidden);
        });
    }

    /** @param Builder<CatalogTitleReview> $query */
    private function applyFilters(Builder $query, ReviewCriteria $criteria): void
    {
        $query
            ->when($criteria->rating !== null, fn (Builder $query): Builder => $query
                ->where('review_rating_state.rating', $criteria->rating))
            ->when($criteria->containsSpoiler !== null, fn (Builder $query): Builder => $query
                ->where('catalog_title_reviews.is_spoiler', $criteria->containsSpoiler))
            ->when($criteria->verifiedWatching !== null, fn (Builder $query): Builder => $query
                ->where('catalog_title_reviews.is_verified_watch', $criteria->verifiedWatching));
    }

    /** @param Builder<CatalogTitleReview> $query */
    private function addPresentationRelations(Builder $query, ?ReviewSort $sort = null): void
    {
        $query
            ->with([
                'authorAccount:id,name',
                'authorAccount.profile:user_id,username,profile_visibility,moderation_status',
                'catalogTitle' => fn ($query) => $query
                    ->withTrashed()
                    ->select(['id', 'slug', 'title', 'original_title']),
            ]);

        if (! $this->schema->writable() || $sort === ReviewSort::MostHelpful) {
            return;
        }

        $query->withCount([
            'votes as helpful_count' => fn (Builder $query): Builder => $query
                ->where('type', ReviewVoteType::Helpful->value),
            'votes as not_helpful_count' => fn (Builder $query): Builder => $query
                ->where('type', ReviewVoteType::NotHelpful->value),
        ]);
    }

    /** @param Builder<CatalogTitleReview> $query */
    private function applySort(Builder $query, ReviewSort $sort): void
    {
        if ($sort === ReviewSort::MostHelpful && ! $this->schema->writable()) {
            $sort = ReviewSort::Newest;
        }

        match ($sort) {
            ReviewSort::Oldest => $query
                ->orderByRaw('COALESCE(catalog_title_reviews.published_at, catalog_title_reviews.created_at)')
                ->orderBy('catalog_title_reviews.id'),
            ReviewSort::MostHelpful => $query
                ->withCount([
                    'votes as helpful_count' => fn (Builder $query): Builder => $query
                        ->where('type', ReviewVoteType::Helpful->value),
                    'votes as not_helpful_count' => fn (Builder $query): Builder => $query
                        ->where('type', ReviewVoteType::NotHelpful->value),
                ])
                ->orderByRaw('(helpful_count - not_helpful_count) DESC')
                ->orderByDesc('helpful_count')
                ->orderByDesc('catalog_title_reviews.id'),
            ReviewSort::HighestRated => $query
                ->orderByRaw('CASE WHEN review_rating_state.rating IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('review_rating_state.rating')
                ->orderByDesc('catalog_title_reviews.id'),
            ReviewSort::LowestRated => $query
                ->orderByRaw('CASE WHEN review_rating_state.rating IS NULL THEN 1 ELSE 0 END')
                ->orderBy('review_rating_state.rating')
                ->orderByDesc('catalog_title_reviews.id'),
            ReviewSort::Newest => $query
                ->orderByRaw('COALESCE(catalog_title_reviews.published_at, catalog_title_reviews.created_at) DESC')
                ->orderByDesc('catalog_title_reviews.id'),
        };
    }

    /**
     * @param  Collection<int, CatalogTitleReview>  $reviews
     * @return array<int, ReviewVoteType>
     */
    private function viewerVotes(Collection $reviews, ?User $viewer): array
    {
        if ($viewer === null || $reviews->isEmpty() || ! $this->schema->writable()) {
            return [];
        }

        return CatalogTitleReviewVote::query()
            ->where('user_id', $viewer->id)
            ->whereIn(
                'catalog_title_review_id',
                $reviews->map(fn (CatalogTitleReview $review): int => (int) $review->id)->all(),
            )
            ->get(['catalog_title_review_id', 'type'])
            ->mapWithKeys(fn (CatalogTitleReviewVote $vote): array => [
                (int) $vote->catalog_title_review_id => $vote->type,
            ])
            ->all();
    }
}
