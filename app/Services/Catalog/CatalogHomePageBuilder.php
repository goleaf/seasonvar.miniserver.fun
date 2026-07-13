<?php

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CatalogHomePageBuilder
{
    public function __construct(
        private readonly CatalogSeoBuilder $seo,
        private readonly CatalogFacetQuery $facets,
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogTitleQuery $titles,
        private readonly CatalogHomeMetricsCache $metrics,
        private readonly CatalogHomeSnapshotCache $snapshot,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $genres = $this->facets->taxonomies('genre');
        $countries = $this->facets->taxonomies('country');
        $snapshot = $this->snapshot->snapshot();
        $stats = [
            ...$this->metrics->metrics(),
            'genres' => $genres->count(),
            'countries' => $countries->count(),
        ];
        $latestTitles = $this->orderedTitles($snapshot['latest_title_ids'] ?? [], $this->titleSummaryQuery()
            ->with(array_merge([
                'latestSeason' => fn ($query) => $query->select(['seasons.id', 'seasons.catalog_title_id', 'seasons.number']),
            ], $this->taxonomies->cardSummaryLoads())));
        $featuredTitles = $this->orderedTitles(
            $snapshot['featured_title_ids'] ?? [],
            $this->titleSummaryQuery()->with($this->taxonomies->cardSummaryLoads()),
        );
        $videoTitles = $this->orderedTitles(
            $snapshot['video_title_ids'] ?? [],
            $this->titleSummaryQuery()->with($this->taxonomies->cardSummaryLoads()),
        );
        $latestMedia = $this->orderedModels(LicensedMedia::query()
            ->published()
            ->forAvailableReleases(null)
            ->select(['id', 'catalog_title_id', 'season_id', 'episode_id', 'title', 'quality', 'translation_name', 'format', 'published_at'])
            ->with([
                'catalogTitle' => fn ($query) => $query
                    ->availableTo(null)
                    ->select(['id', 'slug', 'title', 'original_title', 'type', 'year', 'poster_url', 'indexed_at'])
                    ->withCount([
                        'seasons' => fn (Builder $query): Builder => $query->published(),
                        'episodes' => fn (Builder $query): Builder => $query
                            ->published()
                            ->whereHas('season', fn (Builder $query): Builder => $query->published()),
                    ]),
                'season:id,catalog_title_id,number,kind,sort_order,title',
                'episode:id,season_id,number,kind,sort_order,title,released_at',
            ]), $snapshot['latest_media_ids'] ?? []);
        $yearBuckets = collect($snapshot['year_buckets'] ?? [])->map(fn (array $attributes): object => (object) $attributes);
        $subtitleTag = null;

        if (is_array($snapshot['subtitle_tag'] ?? null)) {
            $subtitleTag = (new Tag)->newInstance([], true);
            $subtitleTag->setRawAttributes($snapshot['subtitle_tag'], true);
        }

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
            'subtitleTag' => $subtitleTag,
            'seo' => $this->seo->home($stats, $latestTitles),
        ];
    }

    /**
     * @return Builder<CatalogTitle>
     */
    private function titleSummaryQuery(): Builder
    {
        return $this->titles->visibleTo(null)
            ->select(['id', 'slug', 'title', 'original_title', 'type', 'year', 'description', 'poster_url', 'indexed_at'])
            ->withCount($this->titles->publicCardCounts(null));
    }

    /**
     * @param  list<int>  $ids
     * @param  Builder<CatalogTitle>  $query
     * @return Collection<int, CatalogTitle>
     */
    private function orderedTitles(array $ids, Builder $query): Collection
    {
        return $this->orderedModels($query, $ids);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int>  $ids
     * @return Collection<int, TModel>
     */
    private function orderedModels(Builder $query, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $positions = array_flip($ids);

        return $query
            ->whereKey($ids)
            ->get()
            ->sortBy(fn ($model): int => (int) ($positions[(int) $model->getKey()] ?? PHP_INT_MAX))
            ->values();
    }
}
