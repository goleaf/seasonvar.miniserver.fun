<?php

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\Tag;
use App\Models\User;
use App\Services\Collections\CatalogCollectionQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CatalogHomePageBuilder
{
    public function __construct(
        private readonly CatalogSeoBuilder $seo,
        private readonly CatalogFacetQuery $facets,
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogTitleQuery $titles,
        private readonly CatalogHomeContentAdditionQuery $contentAdditions,
        private readonly CatalogHomeMetricsCache $metrics,
        private readonly CatalogHomeSnapshotCache $snapshot,
        private readonly CatalogUserCardStateLoader $cardStates,
        private readonly CatalogCollectionQuery $collections,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function data(?User $user = null): array
    {
        $genres = $this->facets->taxonomies('genre');
        $countries = $this->facets->taxonomies('country');
        $snapshot = $this->snapshot->snapshot();
        $stats = [
            ...$this->metrics->metrics(),
            'genres' => $genres->count(),
            'countries' => $countries->count(),
        ];
        $latestTitles = $this->orderedTitles($snapshot['latest_title_ids'], $this->titleSummaryQuery($user)
            ->with(array_merge([
                'latestSeason' => fn ($query) => $query->select(['seasons.id', 'seasons.catalog_title_id', 'seasons.number']),
            ], $this->taxonomies->cardSummaryLoads())));
        $latestTitleUpdates = collect($snapshot['latest_title_updates']);
        $latestUpdateTimes = $latestTitleUpdates
            ->mapWithKeys(fn (array $update): array => [
                (int) $update['id'] => CarbonImmutable::parse($update['added_at']),
            ]);
        $latestTitles->each(function (CatalogTitle $catalogTitle) use ($latestUpdateTimes): void {
            $catalogTitle->setAttribute(
                'content_added_at',
                $latestUpdateTimes->get((int) $catalogTitle->id),
            );
        });
        $latestReleaseGroups = $this->contentAdditions->latestReleaseGroups(
            $latestTitles,
            $latestTitleUpdates->all(),
        );
        $featuredTitles = $this->orderedTitles(
            $snapshot['featured_title_ids'],
            $this->titleSummaryQuery($user)->with($this->taxonomies->cardSummaryLoads()),
        );
        $videoTitles = $this->orderedTitles(
            $snapshot['video_title_ids'],
            $this->titleSummaryQuery($user)->with($this->taxonomies->cardSummaryLoads()),
        );
        $latestMedia = $this->orderedMedia(LicensedMedia::query()
            ->published()
            ->forAvailableReleases(null)
            ->select(['id', 'catalog_title_id', 'season_id', 'episode_id', 'title', 'quality', 'translation_name', 'format', 'published_at'])
            ->with([
                'catalogTitle' => fn ($query) => $query
                    ->availableTo(null)
                    ->select(['id', 'slug', 'title', 'original_title', 'type', 'year', 'poster_url', 'indexed_at'])
                    ->withCount([
                        'seasons' => fn (Builder $query): Builder => $query->whereIn(
                            'seasons.id',
                            Season::query()->published()->select('seasons.id'),
                        ),
                        'episodes' => fn (Builder $query): Builder => $query
                            ->whereIn(
                                'episodes.id',
                                Episode::query()
                                    ->published()
                                    ->whereIn('season_id', Season::query()->published()->select('seasons.id'))
                                    ->select('episodes.id'),
                            ),
                    ]),
                'season:id,catalog_title_id,number,kind,sort_order,title',
                'episode:id,season_id,number,kind,sort_order,title,released_at',
            ]), $snapshot['latest_media_ids']);
        $latestMedia->each(function (LicensedMedia $media): void {
            $media->setAttribute('card_meta', $this->latestMediaCardMeta($media));
        });
        $this->cardStates->load(
            $latestTitles->concat($featuredTitles)->concat($videoTitles),
            $user,
        );
        $yearBuckets = collect($snapshot['year_buckets'])->map(fn (array $attributes): object => (object) $attributes);
        $subtitleTag = null;

        if (is_array($snapshot['subtitle_tag'] ?? null)) {
            $subtitleTag = (new Tag)->newInstance([], true);
            $subtitleTag->setRawAttributes($snapshot['subtitle_tag'], true);
        }

        return [
            'stats' => $stats,
            'latestTitles' => $latestTitles,
            'latestByDate' => $latestTitles->groupBy(fn (CatalogTitle $catalogTitle): string => $catalogTitle->content_added_at->format('d.m.Y')),
            'featuredTitles' => $featuredTitles,
            'videoTitles' => $videoTitles,
            'latestMedia' => $latestMedia,
            'latestReleaseGroups' => $latestReleaseGroups,
            'yearBuckets' => $yearBuckets,
            'genres' => $genres->take(18)->values(),
            'countries' => $countries,
            'subtitleTag' => $subtitleTag,
            'featuredCollections' => $this->collections->featured(),
            'seo' => $this->seo->home($stats, $latestTitles),
        ];
    }

    /**
     * @return Builder<CatalogTitle>
     */
    private function titleSummaryQuery(?User $user): Builder
    {
        return $this->titles->visibleTo($user)
            ->select(['id', 'slug', 'title', 'original_title', 'type', 'year', 'description', 'poster_url', 'indexed_at'])
            ->withCount($this->titles->publicCardCounts($user));
    }

    private function latestMediaCardMeta(LicensedMedia $media): string
    {
        return collect([
            $media->translation_name,
            $media->format ? strtoupper($media->format) : null,
            $media->published_at?->format('d.m.Y'),
        ])->filter()->implode(' / ') ?: 'Видео сериала';
    }

    /**
     * @param  list<int>  $ids
     * @param  Builder<CatalogTitle>  $query
     * @return Collection<int, CatalogTitle>
     */
    private function orderedTitles(array $ids, Builder $query): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $positions = array_flip($ids);

        return $query
            ->whereKey($ids)
            ->get()
            ->sortBy(fn (CatalogTitle $model): int => (int) ($positions[(int) $model->getKey()] ?? PHP_INT_MAX))
            ->values();
    }

    /**
     * @param  list<int>  $ids
     * @param  Builder<LicensedMedia>  $query
     * @return Collection<int, LicensedMedia>
     */
    private function orderedMedia(Builder $query, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $positions = array_flip($ids);

        return $query
            ->whereKey($ids)
            ->get()
            ->sortBy(fn (LicensedMedia $model): int => (int) ($positions[(int) $model->getKey()] ?? PHP_INT_MAX))
            ->values();
    }
}
