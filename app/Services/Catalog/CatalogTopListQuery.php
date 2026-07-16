<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationContext;
use App\DTOs\CatalogTopListItem;
use App\Enums\CatalogRecommendationType;
use App\Enums\CatalogTopListCategory;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRating;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\Season;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

final class CatalogTopListQuery
{
    private const ANIMATION_GENRE_SLUG = 'animacionnye';

    private const LIMIT = 100;

    private const PRIOR_RATING = 7.0;

    private const PRIOR_VOTES = 1_000;

    private const NON_ANIME_TYPES = ['serial', 'documentary', 'show'];

    public function __construct(
        private readonly CatalogRecommendationVisibilityService $visibility,
        private readonly CatalogRecommendationTitleLoader $titles,
    ) {}

    /** @return Collection<int, CatalogTopListItem> */
    public function items(CatalogTopListCategory $category, ?User $viewer): Collection
    {
        $rows = $this->rankedRows($category, self::LIMIT);
        $ids = $rows
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($ids === []) {
            return collect();
        }

        $context = new CatalogRecommendationContext(
            type: CatalogRecommendationType::TopRated,
            user: $viewer,
            locale: app()->currentLocale(),
            ratingSource: 'kinopoisk',
        );
        $rankedRows = $rows->keyBy(fn (CatalogTitle $title): int => (int) $title->id);

        return $this->titles
            ->load($context, $ids, watchable: false)
            ->values()
            ->map(function (CatalogTitle $title, int $index) use ($rankedRows): CatalogTopListItem {
                $row = $rankedRows->get((int) $title->id);
                $kinopoiskRating = $row instanceof CatalogTitle
                    ? $this->nullableFloat($row->getAttribute('top_kinopoisk_rating'))
                    : null;
                $provider = $kinopoiskRating !== null ? 'kinopoisk' : 'imdb';
                $rating = $kinopoiskRating
                    ?? ($row instanceof CatalogTitle ? $this->nullableFloat($row->getAttribute('top_imdb_rating')) : null)
                    ?? 0.0;
                $votes = $row instanceof CatalogTitle
                    ? (int) ($provider === 'kinopoisk'
                        ? $row->getAttribute('top_kinopoisk_votes')
                        : $row->getAttribute('top_imdb_votes'))
                    : 0;
                $weightedScore = $row instanceof CatalogTitle
                    ? (float) $row->getAttribute('top_weighted_score')
                    : 0.0;

                return new CatalogTopListItem(
                    title: $title,
                    rank: $index + 1,
                    ratingProvider: $provider,
                    rating: $rating,
                    votes: $votes,
                    weightedScore: $weightedScore,
                    reasonLabels: [
                        __('top_lists.rating', [
                            'provider' => __("top_lists.providers.{$provider}"),
                            'rating' => (string) Number::format(
                                $rating,
                                precision: 1,
                                locale: app()->currentLocale(),
                            ),
                        ]),
                        trans_choice('top_lists.votes', $votes, [
                            'count' => (string) Number::format($votes, locale: app()->currentLocale()),
                        ]),
                    ],
                );
            });
    }

    public function hasItems(CatalogTopListCategory $category): bool
    {
        return $this->rankedRows($category, 1)->isNotEmpty();
    }

    /** @return Collection<int, CatalogTitle> */
    private function rankedRows(CatalogTopListCategory $category, int $limit): Collection
    {
        $context = new CatalogRecommendationContext(
            type: CatalogRecommendationType::TopRated,
            user: null,
            locale: (string) config('catalog-collections.default_locale', 'ru'),
            ratingSource: 'kinopoisk',
        );
        $ratingTable = (new CatalogTitleRating)->getTable();
        $ratingExpression = 'CASE WHEN top_kinopoisk_rating.rating IS NOT NULL '
            .'THEN top_kinopoisk_rating.rating ELSE top_imdb_rating.rating END';
        $votesExpression = 'CASE WHEN top_kinopoisk_rating.rating IS NOT NULL '
            .'THEN COALESCE(top_kinopoisk_rating.votes, 0) ELSE COALESCE(top_imdb_rating.votes, 0) END';
        $weightedScoreExpression = "(({$ratingExpression} * {$votesExpression}) + ".
            (self::PRIOR_RATING * self::PRIOR_VOTES).') / ('.$votesExpression.' + '.self::PRIOR_VOTES.'.0)';
        $query = $this->visibility
            ->eligible($context, watchable: true)
            ->leftJoin($ratingTable.' as top_kinopoisk_rating', function (JoinClause $join): void {
                $join
                    ->on('top_kinopoisk_rating.catalog_title_id', '=', 'catalog_titles.id')
                    ->where('top_kinopoisk_rating.provider', '=', 'kinopoisk');
            })
            ->leftJoin($ratingTable.' as top_imdb_rating', function (JoinClause $join): void {
                $join
                    ->on('top_imdb_rating.catalog_title_id', '=', 'catalog_titles.id')
                    ->where('top_imdb_rating.provider', '=', 'imdb');
            })
            ->whereRaw("{$ratingExpression} IS NOT NULL")
            ->whereRaw("{$votesExpression} > 0");

        $this->applyCategory($query, $category);

        return $query
            ->select([
                'catalog_titles.id',
                'top_kinopoisk_rating.rating as top_kinopoisk_rating',
                'top_kinopoisk_rating.votes as top_kinopoisk_votes',
                'top_imdb_rating.rating as top_imdb_rating',
                'top_imdb_rating.votes as top_imdb_votes',
            ])
            ->selectRaw("{$weightedScoreExpression} AS top_weighted_score")
            ->orderByRaw("{$weightedScoreExpression} DESC")
            ->orderByRaw("{$votesExpression} DESC")
            ->orderByRaw("{$ratingExpression} DESC")
            ->orderByDesc('catalog_titles.id')
            ->limit(max(1, min(self::LIMIT, $limit)))
            ->get();
    }

    /** @param Builder<CatalogTitle> $query */
    private function applyCategory(Builder $query, CatalogTopListCategory $category): void
    {
        match ($category) {
            CatalogTopListCategory::Movies => $query
                ->whereIn('catalog_titles.type', self::NON_ANIME_TYPES)
                ->whereDoesntHave('genres', $this->animationGenre(...))
                ->whereIn('catalog_titles.id', $this->availableEpisodeTitleIds(multiple: false)),
            CatalogTopListCategory::Series => $query
                ->whereIn('catalog_titles.type', self::NON_ANIME_TYPES)
                ->whereDoesntHave('genres', $this->animationGenre(...))
                ->whereIn('catalog_titles.id', $this->availableEpisodeTitleIds(multiple: true)),
            CatalogTopListCategory::Anime => $query->where('catalog_titles.type', 'anime'),
            CatalogTopListCategory::Cartoons => $query
                ->where('catalog_titles.type', '!=', 'anime')
                ->whereHas('genres', $this->animationGenre(...)),
        };
    }

    /** @param Builder<Genre> $query */
    private function animationGenre(Builder $query): void
    {
        $query->where('genres.slug', self::ANIMATION_GENRE_SLUG);
    }

    /** @return Builder<Episode> */
    private function availableEpisodeTitleIds(bool $multiple): Builder
    {
        $query = Episode::query()
            ->availableTo(null)
            ->join('seasons', 'seasons.id', '=', 'episodes.season_id')
            ->whereIn('seasons.id', Season::query()->availableTo(null)->select('seasons.id'));

        return $query
            ->select('seasons.catalog_title_id')
            ->groupBy('seasons.catalog_title_id')
            ->havingRaw($multiple ? 'COUNT(DISTINCT episodes.id) >= 2' : 'COUNT(DISTINCT episodes.id) = 1');
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
