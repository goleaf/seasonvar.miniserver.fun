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
        CatalogTitle::query()
            ->whereKey($titleIds)
            ->get(['id', 'title', 'original_title', 'description'])
            ->each(function (CatalogTitle $title) use (&$features): void {
                foreach (array_keys($this->themes->extract(
                    $title->title,
                    $title->original_title,
                    $title->description,
                )) as $theme) {
                    $features[(int) $title->id][] = 'theme:'.$theme;
                }
            });

        foreach ($features as &$titleFeatures) {
            $titleFeatures = array_values(array_unique($titleFeatures));
            sort($titleFeatures, SORT_STRING);
        }
        unset($titleFeatures);

        return $features;
    }
}
