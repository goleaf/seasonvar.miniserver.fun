<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;

final class CatalogRecommendationFeatureExtractor
{
    public function __construct(private readonly CatalogRecommendationThemeExtractor $themes) {}

    /**
     * @param  list<int>  $titleIds
     * @return array<int, list<string>>
     */
    public function forTitleIds(array $titleIds): array
    {
        $titleIds = collect($titleIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->take(500)
            ->values()
            ->all();

        if ($titleIds === []) {
            return [];
        }

        $features = array_fill_keys($titleIds, []);

        DB::table('catalog_title_genre')
            ->whereIn('catalog_title_id', $titleIds)
            ->get(['catalog_title_id', 'genre_id'])
            ->each(function (object $row) use (&$features): void {
                $features[(int) $row->catalog_title_id][] = 'genre:'.(int) $row->genre_id;
            });
        DB::table('catalog_title_tag')
            ->whereIn('catalog_title_id', $titleIds)
            ->whereIn('tag_id', Tag::query()->publiclyEligible()->select('tags.id'))
            ->get(['catalog_title_id', 'tag_id'])
            ->each(function (object $row) use (&$features): void {
                $features[(int) $row->catalog_title_id][] = 'tag:'.(int) $row->tag_id;
            });
        DB::table('catalog_title_country')
            ->whereIn('catalog_title_id', $titleIds)
            ->get(['catalog_title_id', 'country_id'])
            ->each(function (object $row) use (&$features): void {
                $features[(int) $row->catalog_title_id][] = 'country:'.(int) $row->country_id;
            });
        DB::table('catalog_title_actor')
            ->whereIn('catalog_title_id', $titleIds)
            ->get(['catalog_title_id', 'actor_id'])
            ->each(function (object $row) use (&$features): void {
                $features[(int) $row->catalog_title_id][] = 'actor:'.(int) $row->actor_id;
            });
        CatalogTitle::query()
            ->whereKey($titleIds)
            ->get(['id', 'title', 'original_title', 'description', 'year'])
            ->each(function (CatalogTitle $title) use (&$features): void {
                if (is_int($title->year)
                    && $title->year <= now()->year - max(1, (int) config('recommendations.feedback.classic_age_years', 15))) {
                    $features[(int) $title->id][] = 'era:classic';
                }

                foreach (array_keys($this->themes->extract(
                    $title->title,
                    $title->original_title,
                    $title->description,
                )) as $theme) {
                    $features[(int) $title->id][] = 'theme:'.$theme;
                }
            });
        DB::table('episodes')
            ->join('seasons', 'seasons.id', '=', 'episodes.season_id')
            ->whereIn('seasons.catalog_title_id', $titleIds)
            ->groupBy('seasons.catalog_title_id')
            ->havingRaw('COUNT(episodes.id) >= ?', [
                max(1, (int) config('recommendations.feedback.long_title_episode_count', 40)),
            ])
            ->pluck('seasons.catalog_title_id')
            ->each(function (mixed $titleId) use (&$features): void {
                $features[(int) $titleId][] = 'length:long';
            });
        DB::table('catalog_title_ratings')
            ->whereIn('catalog_title_id', $titleIds)
            ->where('provider', 'kinopoisk')
            ->whereNotNull('rating')
            ->where('rating', '<', (float) config('recommendations.feedback.low_rating_threshold', 6.0))
            ->pluck('catalog_title_id')
            ->each(function (mixed $titleId) use (&$features): void {
                $features[(int) $titleId][] = 'rating:low';
            });
        DB::table('catalog_status_catalog_title')
            ->join('catalog_statuses', 'catalog_statuses.id', '=', 'catalog_status_catalog_title.catalog_status_id')
            ->whereIn('catalog_status_catalog_title.catalog_title_id', $titleIds)
            ->whereNotIn('catalog_statuses.slug', (array) config(
                'recommendations.feedback.completed_status_slugs',
                ['zavershen', 'completed', 'finished'],
            ))
            ->pluck('catalog_status_catalog_title.catalog_title_id')
            ->each(function (mixed $titleId) use (&$features): void {
                $features[(int) $titleId][] = 'status:unfinished';
            });

        foreach ($features as &$titleFeatures) {
            $titleFeatures = array_values(array_unique($titleFeatures));
            sort($titleFeatures, SORT_STRING);
        }
        unset($titleFeatures);

        return $features;
    }
}
