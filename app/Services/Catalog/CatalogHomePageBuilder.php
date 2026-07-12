<?php

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;

class CatalogHomePageBuilder
{
    public function __construct(
        private readonly CatalogSeoBuilder $seo,
        private readonly CatalogFacetQuery $facets,
        private readonly CatalogTaxonomyRegistry $taxonomies,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $genres = $this->facets->taxonomies('genre');
        $countries = $this->facets->taxonomies('country');
        $stats = [
            'titles' => CatalogTitle::query()->published()->count(),
            'episodes' => Episode::query()
                ->whereIn('season_id', Season::query()
                    ->select('id')
                    ->whereIn('catalog_title_id', CatalogTitle::query()->published()->select('id')))
                ->count(),
            'genres' => $genres->count(),
            'countries' => $countries->count(),
            'videos' => LicensedMedia::query()
                ->published()
                ->whereIn('catalog_title_id', CatalogTitle::query()->published()->select('id'))
                ->count(),
        ];
        $latestTitles = $this->titleSummaryQuery()
            ->with($this->taxonomies->listRowRelations())
            ->latest('indexed_at')
            ->limit(48)
            ->get();
        $featuredTitles = $this->titleSummaryQuery()
            ->with($this->taxonomies->cardRelations())
            ->whereNotNull('poster_url')
            ->latest('indexed_at')
            ->limit(12)
            ->get();
        $videoTitles = $this->titleSummaryQuery()
            ->with($this->taxonomies->cardRelations())
            ->whereIn('id', LicensedMedia::query()
                ->published()
                ->whereNotNull('catalog_title_id')
                ->select('catalog_title_id'))
            ->orderByDesc('published_media_count')
            ->latest('indexed_at')
            ->limit(8)
            ->get();
        $latestMedia = LicensedMedia::query()
            ->published()
            ->whereIn('catalog_title_id', CatalogTitle::query()->published()->select('id'))
            ->select(['id', 'catalog_title_id', 'season_id', 'episode_id', 'title', 'quality', 'translation_name', 'format', 'published_at'])
            ->with([
                'catalogTitle' => fn ($query) => $query
                    ->published()
                    ->select(['id', 'slug', 'title', 'original_title', 'type', 'year', 'poster_url', 'indexed_at'])
                    ->withCount(['seasons', 'episodes']),
                'season:id,catalog_title_id,number,title',
                'episode:id,season_id,number,title,released_at',
            ])
            ->latest('published_at')
            ->latest()
            ->limit(12)
            ->get();
        $yearBuckets = CatalogTitle::query()
            ->published()
            ->select('year')
            ->selectRaw('count(*) as titles_count')
            ->whereNotNull('year')
            ->where('year', '>=', 1900)
            ->where('year', '<=', (int) now()->format('Y') + 1)
            ->groupBy('year')
            ->orderByDesc('year')
            ->limit(12)
            ->get();

        return [
            'stats' => $stats,
            'latestTitles' => $latestTitles,
            'latestByDate' => $latestTitles->groupBy(fn (CatalogTitle $catalogTitle): string => $catalogTitle->indexed_at?->format('d.m.Y') ?? now()->format('d.m.Y')),
            'featuredTitles' => $featuredTitles,
            'videoTitles' => $videoTitles,
            'latestMedia' => $latestMedia,
            'yearBuckets' => $yearBuckets,
            'genres' => $genres->take(18)->values(),
            'countries' => $countries,
            'subtitleTag' => Tag::query()
                ->where('slug', 'subtitry')
                ->withCount(['catalogTitles' => fn (Builder $query): Builder => $query->published()])
                ->first(),
            'seo' => $this->seo->home($stats, $latestTitles),
        ];
    }

    /**
     * @return Builder<CatalogTitle>
     */
    private function titleSummaryQuery(): Builder
    {
        return CatalogTitle::query()
            ->published()
            ->select(['id', 'slug', 'title', 'original_title', 'type', 'year', 'description', 'poster_url', 'indexed_at'])
            ->withCount([
                'seasons',
                'episodes',
                'licensedMedia as published_media_count' => fn (Builder $query): Builder => $query->published(),
            ]);
    }
}
