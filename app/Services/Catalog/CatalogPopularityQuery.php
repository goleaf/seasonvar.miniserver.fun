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

        return $query
            ->selectSub(CatalogTitleUserState::query()
                ->selectRaw('COUNT(*)')
                ->whereColumn('catalog_title_id', 'catalog_titles.id')
                ->where('in_watchlist', true), 'popularity_watchlist_count')
            ->selectSub(EpisodeViewProgress::query()
                ->selectRaw('COUNT(DISTINCT user_id)')
                ->whereColumn('catalog_title_id', 'catalog_titles.id')
                ->where(function (Builder $query): void {
                    $query
                        ->where('position_seconds', '>=', max(1, (int) config('recommendations.meaningful_progress_seconds', 180)))
                        ->orWhere('progress_percent', '>=', max(1, (int) config('recommendations.meaningful_progress_percent', 10)))
                        ->orWhereNotNull('completed_at');
                }), 'popularity_watcher_count')
            ->selectSub(CatalogTitleReview::query()
                ->selectRaw('COUNT(*)')
                ->whereColumn('catalog_title_id', 'catalog_titles.id')
                ->where('status', ReviewStatus::Published->value)
                ->whereNull('deleted_at')
                ->whereNull('merged_into_id'), 'popularity_review_count')
            ->selectSub(CatalogTitleRating::query()
                ->select('votes')
                ->whereColumn('catalog_title_id', 'catalog_titles.id')
                ->where('provider', $ratingProvider)
                ->limit(1), 'popularity_provider_votes')
            ->orderByRaw('(popularity_watchlist_count * 35 + popularity_watcher_count * 45 + popularity_review_count * 8 + CASE WHEN popularity_provider_votes >= 100000 THEN 80 WHEN popularity_provider_votes >= 10000 THEN 60 WHEN popularity_provider_votes >= 1000 THEN 40 WHEN popularity_provider_votes >= 100 THEN 20 ELSE 0 END) DESC')
            ->orderByDesc('popularity_watcher_count')
            ->orderByDesc('popularity_provider_votes');
    }
}
