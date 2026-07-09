<?php

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\Tag;

class CatalogHomePageBuilder
{
    public function __construct(
        private readonly CatalogSeoBuilder $seo,
        private readonly CatalogTaxonomyRegistry $taxonomies,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $stats = [
            'titles' => CatalogTitle::query()->count(),
            'episodes' => Episode::query()->count(),
            'genres' => Genre::query()->count(),
            'countries' => Country::query()->count(),
        ];
        $latestTitles = CatalogTitle::query()
            ->with($this->taxonomies->cardRelations())
            ->withCount(['seasons', 'episodes'])
            ->latest('indexed_at')
            ->limit(64)
            ->get();

        return [
            'stats' => $stats,
            'latestTitles' => $latestTitles,
            'latestByDate' => $latestTitles->groupBy(fn (CatalogTitle $catalogTitle): string => $catalogTitle->indexed_at?->format('d.m.Y') ?? now()->format('d.m.Y')),
            'genres' => Genre::query()
                ->withCount('catalogTitles')
                ->orderByDesc('catalog_titles_count')
                ->limit(18)
                ->get(),
            'countries' => Country::query()
                ->withCount('catalogTitles')
                ->orderByDesc('catalog_titles_count')
                ->limit(10)
                ->get(),
            'subtitleTag' => Tag::query()
                ->where('slug', 'subtitry')
                ->withCount('catalogTitles')
                ->first(),
            'seo' => $this->seo->home($stats, $latestTitles),
        ];
    }
}
