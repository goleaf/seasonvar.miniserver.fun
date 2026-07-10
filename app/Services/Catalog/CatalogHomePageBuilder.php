<?php

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;

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
            'videos' => LicensedMedia::query()->published()->count(),
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
            ->whereHas('licensedMedia', fn (Builder $query): Builder => $query->published())
            ->orderByDesc('published_media_count')
            ->latest('indexed_at')
            ->limit(8)
            ->get();
        $latestMedia = LicensedMedia::query()
            ->published()
            ->select(['id', 'catalog_title_id', 'season_id', 'episode_id', 'title', 'quality', 'translation_name', 'format', 'published_at'])
            ->with([
                'catalogTitle' => fn ($query) => $query
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
            'genres' => Genre::query()
                ->withCount('catalogTitles')
                ->orderByDesc('catalog_titles_count')
                ->limit(18)
                ->get(),
            'countries' => Country::query()
                ->withCount('catalogTitles')
                ->orderByDesc('catalog_titles_count')
                ->get(),
            'subtitleTag' => Tag::query()
                ->where('slug', 'subtitry')
                ->withCount('catalogTitles')
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
            ->select(['id', 'slug', 'title', 'original_title', 'type', 'year', 'description', 'poster_url', 'indexed_at'])
            ->withCount([
                'seasons',
                'episodes',
                'licensedMedia as published_media_count' => fn (Builder $query): Builder => $query->published(),
            ]);
    }
}
