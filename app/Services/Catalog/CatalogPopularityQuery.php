<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\ReviewStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRating;
use App\Models\CatalogTitleReview;
use App\Models\CatalogTitleUserState;
use App\Models\EpisodeViewProgress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class CatalogPopularityQuery
{
    /**
     * @param  Builder<CatalogTitle>  $query
     * @return Builder<CatalogTitle>
     */
    public function apply(Builder $query, string $ratingProvider = 'kinopoisk'): Builder
    {
        $ratingProvider = in_array($ratingProvider, ['imdb', 'kinopoisk'], true)
            ? $ratingProvider
            : 'kinopoisk';

        $watchlists = CatalogTitleUserState::query()->select('catalog_title_id')
            ->selectRaw('COUNT(*) AS popularity_watchlist_count')->where('in_watchlist', true)->groupBy('catalog_title_id');
        $watchers = EpisodeViewProgress::query()->select('catalog_title_id')
            ->selectRaw('COUNT(DISTINCT user_id) AS popularity_watcher_count')
            ->where(function (Builder $query): void {
                $query->where('position_seconds', '>=', max(1, (int) config('recommendations.meaningful_progress_seconds', 180)))
                    ->orWhere('progress_percent', '>=', max(1, (int) config('recommendations.meaningful_progress_percent', 10)))
                    ->orWhereNotNull('completed_at');
            })->groupBy('catalog_title_id');
        $reviews = CatalogTitleReview::query()->select('catalog_title_id')
            ->selectRaw('COUNT(*) AS popularity_review_count')->where('status', ReviewStatus::Published->value)
            ->whereNull('deleted_at')->whereNull('merged_into_id')->groupBy('catalog_title_id');
        $ratings = CatalogTitleRating::query()->select('catalog_title_id')
            ->selectRaw('MAX(votes) AS popularity_provider_votes')->where('provider', $ratingProvider)->groupBy('catalog_title_id');

        return $query
            ->leftJoinSub($watchlists, 'popularity_watchlists', fn (JoinClause $join): JoinClause => $join->on('popularity_watchlists.catalog_title_id', '=', 'catalog_titles.id'))
            ->leftJoinSub($watchers, 'popularity_watchers', fn (JoinClause $join): JoinClause => $join->on('popularity_watchers.catalog_title_id', '=', 'catalog_titles.id'))
            ->leftJoinSub($reviews, 'popularity_reviews', fn (JoinClause $join): JoinClause => $join->on('popularity_reviews.catalog_title_id', '=', 'catalog_titles.id'))
            ->leftJoinSub($ratings, 'popularity_ratings', fn (JoinClause $join): JoinClause => $join->on('popularity_ratings.catalog_title_id', '=', 'catalog_titles.id'))
            ->addSelect('catalog_titles.*')
            ->addSelect([
                DB::raw('COALESCE(popularity_watchlists.popularity_watchlist_count, 0) AS popularity_watchlist_count'),
                DB::raw('COALESCE(popularity_watchers.popularity_watcher_count, 0) AS popularity_watcher_count'),
                DB::raw('COALESCE(popularity_reviews.popularity_review_count, 0) AS popularity_review_count'),
                DB::raw('COALESCE(popularity_ratings.popularity_provider_votes, 0) AS popularity_provider_votes'),
            ])
            ->orderByRaw('(popularity_watchlist_count * 35 + popularity_watcher_count * 45 + popularity_review_count * 8 + CASE WHEN popularity_provider_votes >= 100000 THEN 80 WHEN popularity_provider_votes >= 10000 THEN 60 WHEN popularity_provider_votes >= 1000 THEN 40 WHEN popularity_provider_votes >= 100 THEN 20 ELSE 0 END) DESC')
            ->orderByDesc('popularity_watcher_count')
            ->orderByDesc('popularity_provider_votes');
    }
}
