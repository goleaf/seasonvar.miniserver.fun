<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationContext;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use Illuminate\Support\Collection;

final class CatalogRecommendationTitleLoader
{
    public function __construct(
        private readonly CatalogRecommendationVisibilityService $visibility,
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogUserCardStateLoader $cardStates,
    ) {}

    /**
     * @param  list<int>  $ids
     * @return Collection<int, CatalogTitle>
     */
    public function load(CatalogRecommendationContext $context, array $ids, bool $watchable = true): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $positions = array_flip($ids);
        $titles = $this->visibility
            ->eligible($context, $watchable)
            ->whereKey($ids)
            ->select(['catalog_titles.id', 'slug', 'title', 'original_title', 'type', 'year', 'description', 'poster_url', 'indexed_at'])
            ->with($this->taxonomies->cardSummaryLoads())
            ->get()
            ->sortBy(fn (CatalogTitle $title): int => (int) ($positions[(int) $title->id] ?? PHP_INT_MAX))
            ->values();
        $this->loadCounts($titles, $context);
        $this->cardStates->load($titles, $context->user);

        return $titles;
    }

    /** @param Collection<int, CatalogTitle> $titles */
    private function loadCounts(Collection $titles, CatalogRecommendationContext $context): void
    {
        $titleIds = $titles
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($titleIds === []) {
            return;
        }

        $seasonCounts = Season::query()
            ->availableTo($context->user)
            ->whereIn('catalog_title_id', $titleIds)
            ->selectRaw('catalog_title_id, COUNT(*) AS aggregate_count')
            ->groupBy('catalog_title_id')
            ->pluck('aggregate_count', 'catalog_title_id');
        $availableSeasons = Season::query()
            ->availableTo($context->user)
            ->whereIn('catalog_title_id', $titleIds)
            ->select(['id', 'catalog_title_id']);
        $episodeCounts = Episode::query()
            ->availableTo($context->user)
            ->joinSub($availableSeasons, 'available_seasons', 'available_seasons.id', '=', 'episodes.season_id')
            ->selectRaw('available_seasons.catalog_title_id, COUNT(*) AS aggregate_count')
            ->groupBy('available_seasons.catalog_title_id')
            ->pluck('aggregate_count', 'available_seasons.catalog_title_id');
        $mediaCounts = LicensedMedia::query()
            ->availableTo($context->user)
            ->forAvailableReleases($context->user)
            ->whereIn('licensed_media.catalog_title_id', $titleIds)
            ->selectRaw('licensed_media.catalog_title_id, COUNT(*) AS aggregate_count')
            ->groupBy('licensed_media.catalog_title_id')
            ->pluck('aggregate_count', 'licensed_media.catalog_title_id');

        $titles->each(function (CatalogTitle $title) use ($episodeCounts, $mediaCounts, $seasonCounts): void {
            $title->setAttribute('seasons_count', (int) $seasonCounts->get($title->id, 0));
            $title->setAttribute('episodes_count', (int) $episodeCounts->get($title->id, 0));
            $title->setAttribute('published_media_count', (int) $mediaCounts->get($title->id, 0));
        });
    }
}
