<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\UserLibraryFilters;
use App\Enums\CatalogPublicationType;
use App\Enums\CatalogRecommendationFeedback;
use App\Enums\CatalogWatchStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\EpisodePlaybackMarker;
use App\Models\EpisodeViewProgress;
use App\Models\User;
use App\Services\Api\V1\Sync\ApiSyncReadiness;
use App\Services\UserPortal\UserPortalIdPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as ConcreteLengthAwarePaginator;
use Illuminate\Support\Facades\Schema;

final readonly class UserLibraryQuery
{
    public function __construct(
        private CatalogTitleQuery $titles,
        private CatalogTaxonomyRegistry $taxonomies,
        private ApiSyncReadiness $syncReadiness,
        private CatalogPersonalUpdateQuery $personalUpdates,
        private UserPortalIdPaginator $paginator,
        private CatalogTitleCardCountLoader $cardCounts,
    ) {}

    /** @return LengthAwarePaginator<int, CatalogTitleUserState> */
    public function watchlist(
        User $user,
        UserLibraryFilters $filters,
        string $pageName = 'page',
        bool $refresh = false,
    ): LengthAwarePaginator {
        $query = $this->applyFilters($this->base($user), $filters, $user)
            ->where('in_watchlist', true)
            ->tap(fn (Builder $query): Builder => $this->applyOrder($query, $filters, 'watchlist_updated_at'));

        return $this->hydrateCardCounts($this->paginator->paginate(
            $user,
            'library-watchlist',
            $this->dimensions($filters),
            $query,
            $filters->perPage,
            $pageName,
            $refresh,
        ), $user);
    }

    /** @return LengthAwarePaginator<int, CatalogTitleUserState> */
    public function ratings(
        User $user,
        UserLibraryFilters $filters,
        string $pageName = 'page',
    ): LengthAwarePaginator {
        $query = $this->applyFilters($this->base($user), $filters, $user)
            ->whereNotNull('rating')
            ->tap(fn (Builder $query): Builder => $this->applyOrder($query, $filters, 'rating_updated_at'));

        return $this->hydrateCardCounts(
            $this->paginator->paginate($user, 'library-ratings', $this->dimensions($filters), $query, $filters->perPage, $pageName),
            $user,
        );
    }

    /** @return LengthAwarePaginator<int, CatalogTitleUserState> */
    public function recommendationFeedback(User $user, string $pageName = 'page'): LengthAwarePaginator
    {
        $query = $this->base($user);

        if (! Schema::hasColumn('catalog_title_user_states', 'recommendation_feedback')) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereNotNull('recommendation_feedback');
        }

        $query->orderByDesc('recommendation_feedback_updated_at')->orderByDesc('id');

        return $this->hydrateCardCounts(
            $this->paginator->paginate($user, 'library-recommendation-feedback', [], $query, 24, $pageName),
            $user,
        );
    }

    /** @return LengthAwarePaginator<int, CatalogTitleUserState> */
    public function watchStatus(
        User $user,
        CatalogWatchStatus $status,
        UserLibraryFilters $filters,
        string $pageName = 'page',
    ): LengthAwarePaginator {
        $query = $this->applyFilters($this->base($user), $filters, $user)
            ->where('watch_status', $status->value)
            ->tap(fn (Builder $query): Builder => $this->applyOrder($query, $filters, 'watch_status_updated_at'));

        return $this->hydrateCardCounts($this->paginator->paginate(
            $user,
            'library-watch-status',
            [...$this->dimensions($filters), 'status' => $status->value],
            $query,
            $filters->perPage,
            $pageName,
        ), $user);
    }

    /** @return LengthAwarePaginator<int, CatalogTitleUserState> */
    public function recommendationFeedbackByType(
        User $user,
        CatalogRecommendationFeedback $feedback,
        string $pageName = 'page',
    ): LengthAwarePaginator {
        $query = $this->base($user);

        if (! Schema::hasColumn('catalog_title_user_states', 'recommendation_feedback')) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('recommendation_feedback', $feedback->value);
        }

        $query->orderByDesc('recommendation_feedback_updated_at')->orderByDesc('id');

        return $this->hydrateCardCounts($this->paginator->paginate(
            $user,
            'library-recommendation-feedback-type',
            ['feedback' => $feedback->value],
            $query,
            24,
            $pageName,
        ), $user);
    }

    /** @return LengthAwarePaginator<int, CatalogTitleUserState> */
    public function updates(
        User $user,
        bool $hasUpdates,
        UserLibraryFilters $filters,
        string $pageName = 'page',
    ): LengthAwarePaginator {
        $query = $this->applyFilters($this->base($user), $filters, $user)
            ->where(function (Builder $state): void {
                $state->where('in_watchlist', true)->orWhereNotNull('watch_status');
            })
            ->where(function (Builder $state): void {
                $state->whereNull('recommendation_feedback')
                    ->orWhereNotIn('recommendation_feedback', [
                        CatalogRecommendationFeedback::NotInterested->value,
                        CatalogRecommendationFeedback::Blacklisted->value,
                    ]);
            });
        $this->personalUpdates->constrain($query, $user, $hasUpdates);

        $query->tap(fn (Builder $query): Builder => $this->applyOrder($query, $filters));
        $paginator = $this->paginator->paginate(
            $user,
            'library-updates',
            [...$this->dimensions($filters), 'has_updates' => $hasUpdates],
            $query,
            $filters->perPage,
            $pageName,
        );
        $this->hydrateCardCounts($paginator, $user);
        $this->personalUpdates->hydrateIndicators($user, $paginator->getCollection());

        return $paginator;
    }

    /** @return LengthAwarePaginator<int, EpisodePlaybackMarker> */
    public function markers(User $user, UserLibraryFilters $filters, string $pageName = 'page'): LengthAwarePaginator
    {
        $query = EpisodePlaybackMarker::query()
            ->whereBelongsTo($user)
            ->whereIn('catalog_title_id', $this->titles->visibleTo($user)->select('id'))
            ->select([
                'id',
                'public_id',
                'user_id',
                'catalog_title_id',
                'episode_id',
                'position_seconds',
                'updated_at',
            ])
            ->with([
                'catalogTitle:id,slug,title,original_title,poster_url,type,year',
                'episode:id,season_id,number,kind,title',
                'episode.season:id,catalog_title_id,number,kind,title',
            ]);

        if ($filters->query !== '') {
            $search = '%'.$filters->query.'%';
            $query->whereHas('catalogTitle', fn (Builder $title): Builder => $title
                ->where(fn (Builder $matching): Builder => $matching
                    ->where('title', 'like', $search)
                    ->orWhere('original_title', 'like', $search)));
        }

        if ($filters->type !== null) {
            $types = CatalogPublicationType::from($filters->type)->databaseValues();
            $query->whereHas('catalogTitle', fn (Builder $title): Builder => $title->whereIn('type', $types));
        }

        if ($filters->year !== null) {
            $query->whereHas('catalogTitle', fn (Builder $title): Builder => $title->where('year', $filters->year));
        }

        if ($filters->personalTagPublicId !== null) {
            $query->whereHas('catalogTitle.personalTags', fn (Builder $tag): Builder => $tag
                ->where('user_tags.user_id', $user->id)
                ->where('user_tags.public_id', $filters->personalTagPublicId));
        }

        match ($filters->sort) {
            'title' => $query->orderBy(
                CatalogTitle::query()
                    ->select('title')
                    ->whereColumn('catalog_titles.id', 'episode_playback_markers.catalog_title_id')
                    ->limit(1),
                $filters->direction,
            ),
            'year' => $query->orderBy(
                CatalogTitle::query()
                    ->select('year')
                    ->whereColumn('catalog_titles.id', 'episode_playback_markers.catalog_title_id')
                    ->limit(1),
                $filters->direction,
            ),
            default => $query->orderBy('updated_at', $filters->direction),
        };

        $query->orderBy('id', $filters->direction);

        return $this->paginator->paginate(
            $user,
            'library-markers',
            $this->dimensions($filters),
            $query,
            $filters->perPage,
            $pageName,
        );
    }

    public function recommendationFeedbackCount(User $user): int
    {
        if (! Schema::hasColumn('catalog_title_user_states', 'recommendation_feedback')) {
            return 0;
        }

        return CatalogTitleUserState::query()
            ->whereBelongsTo($user)
            ->whereNotNull('recommendation_feedback')
            ->count();
    }

    /** @return Builder<CatalogTitleUserState> */
    private function base(User $user): Builder
    {
        $query = CatalogTitleUserState::query()
            ->whereBelongsTo($user)
            ->whereIn(
                'catalog_title_id',
                $this->titles->visibleTo($user)->select('id'),
            )
            ->select([
                'id',
                'user_id',
                'catalog_title_id',
                'in_watchlist',
                'rating',
                'created_at',
                'updated_at',
            ]);

        if ($this->syncReadiness->stateVersionsAvailable()) {
            $query->addSelect(['watchlist_version', 'rating_version']);
        } else {
            $query->selectRaw('0 AS watchlist_version, 0 AS rating_version');
        }

        if (Schema::hasColumns('catalog_title_user_states', ['watchlist_updated_at', 'rating_updated_at'])) {
            $query->addSelect(['watchlist_updated_at', 'rating_updated_at']);
        } else {
            $query->selectRaw('updated_at AS watchlist_updated_at, updated_at AS rating_updated_at');
        }

        if (Schema::hasColumns('catalog_title_user_states', [
            'recommendation_feedback',
            'recommendation_feedback_version',
            'recommendation_feedback_updated_at',
            'watch_status',
            'watch_status_version',
        ])) {
            $query->addSelect([
                'recommendation_feedback',
                'recommendation_feedback_version',
                'recommendation_feedback_updated_at',
                'watch_status',
                'watch_status_version',
                'watch_status_updated_at',
            ]);
        } else {
            $query->selectRaw('NULL AS recommendation_feedback, 0 AS recommendation_feedback_version, NULL AS recommendation_feedback_updated_at, NULL AS watch_status, 0 AS watch_status_version, updated_at AS watch_status_updated_at');
        }

        return $query->with([
            'catalogTitle' => function ($relation): void {
                $relation->getQuery()
                    ->select([
                        'id',
                        'slug',
                        'title',
                        'original_title',
                        'type',
                        'year',
                        'description',
                        'poster_url',
                        'indexed_at',
                    ])
                    ->with($this->taxonomies->cardSummaryLoads());
            },
        ]);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  ConcreteLengthAwarePaginator<int, TModel>  $paginator
     * @return ConcreteLengthAwarePaginator<int, TModel>
     */
    private function hydrateCardCounts(ConcreteLengthAwarePaginator $paginator, User $user): ConcreteLengthAwarePaginator
    {
        $titles = $paginator->getCollection()
            ->pluck('catalogTitle')
            ->filter(fn (mixed $title): bool => $title instanceof CatalogTitle)
            ->values();

        $this->cardCounts->load($titles, $user);

        return $paginator;
    }

    /**
     * @param  Builder<CatalogTitleUserState>  $query
     * @return Builder<CatalogTitleUserState>
     */
    private function applyFilters(Builder $query, UserLibraryFilters $filters, User $user): Builder
    {
        if ($filters->query !== '') {
            $search = '%'.$filters->query.'%';

            $query->whereHas('catalogTitle', fn (Builder $titleQuery): Builder => $titleQuery
                ->where(function (Builder $matchingTitle) use ($search): void {
                    $matchingTitle
                        ->where('title', 'like', $search)
                        ->orWhere('original_title', 'like', $search);
                }));
        }

        if ($filters->type !== null) {
            $types = CatalogPublicationType::from($filters->type)->databaseValues();
            $query->whereHas(
                'catalogTitle',
                fn (Builder $titleQuery): Builder => $titleQuery->whereIn('type', $types),
            );
        }

        if ($filters->year !== null) {
            $query->whereHas(
                'catalogTitle',
                fn (Builder $titleQuery): Builder => $titleQuery->where('year', $filters->year),
            );
        }

        if ($filters->personalTagPublicId !== null) {
            $query->whereHas('catalogTitle.personalTags', fn (Builder $tagQuery): Builder => $tagQuery
                ->where('user_tags.user_id', $user->id)
                ->where('user_tags.public_id', $filters->personalTagPublicId));
        }

        return $query;
    }

    /**
     * @param  Builder<CatalogTitleUserState>  $query
     * @return Builder<CatalogTitleUserState>
     */
    private function applyOrder(
        Builder $query,
        UserLibraryFilters $filters,
        string $defaultTimestamp = 'updated_at',
    ): Builder {
        $direction = $filters->direction;

        match ($filters->sort) {
            'rating' => $query->orderBy('rating', $direction),
            'recently-watched' => $query->orderBy($this->latestProgressColumn('last_watched_at'), $direction),
            'progress' => $query->orderBy($this->latestProgressColumn('progress_percent'), $direction),
            'status' => $query->orderBy('watch_status', $direction),
            'title' => $query->orderBy($this->titleOrderColumn('title'), $direction),
            'year' => $query->orderBy($this->titleOrderColumn('year'), $direction),
            default => $query->orderBy($defaultTimestamp, $direction),
        };

        return $query->orderBy('id', $direction);
    }

    /** @return Builder<CatalogTitle> */
    private function titleOrderColumn(string $column): Builder
    {
        return CatalogTitle::query()
            ->select($column)
            ->whereColumn('catalog_titles.id', 'catalog_title_user_states.catalog_title_id')
            ->limit(1);
    }

    /** @return Builder<EpisodeViewProgress> */
    private function latestProgressColumn(string $column): Builder
    {
        return EpisodeViewProgress::query()
            ->select($column)
            ->whereColumn(
                'episode_view_progress.catalog_title_id',
                'catalog_title_user_states.catalog_title_id',
            )
            ->whereColumn('episode_view_progress.user_id', 'catalog_title_user_states.user_id')
            ->orderByDesc('last_watched_at')
            ->orderByDesc('id')
            ->limit(1);
    }

    /** @return array<string, mixed> */
    private function dimensions(UserLibraryFilters $filters): array
    {
        return [
            'query' => $filters->query,
            'type' => $filters->type,
            'year' => $filters->year,
            'personal_tag' => $filters->personalTagPublicId,
            'sort' => $filters->sort,
            'direction' => $filters->direction,
        ];
    }
}
