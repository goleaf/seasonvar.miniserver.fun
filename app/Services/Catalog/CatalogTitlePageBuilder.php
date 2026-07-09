<?php

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\View\ViewModels\CatalogShowViewModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CatalogTitlePageBuilder
{
    public function __construct(
        private readonly CatalogSeoBuilder $seo,
        private readonly CatalogTitleQuery $query,
        private readonly CatalogTaxonomyRegistry $taxonomies,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function data(Request $request, CatalogTitle $catalogTitle): array
    {
        $catalogTitle->load(array_merge([
            'sourcePage',
            'aliases',
            'ratings',
            'seasons.episodes',
            'licensedMedia' => fn ($query) => $query->published()->with(['season', 'episode'])->latest('published_at')->latest(),
        ], $this->taxonomies->relationNames()));
        $taxonomiesByType = collect($this->taxonomies->relations())
            ->mapWithKeys(fn (array $config, string $filterType): array => [$filterType => $catalogTitle->{$config['relation']}->values()]);
        $seasons = $catalogTitle->seasons->sortBy('number')->values();
        $episodes = $seasons
            ->flatMap(fn ($season): Collection => $season->episodes->sortBy('number')->values())
            ->values();

        $mediaItems = $catalogTitle->licensedMedia
            ->sortBy(fn (LicensedMedia $media): string => sprintf(
                '%05d-%05d-%02d-%s',
                $media->season?->number ?? 99999,
                $media->episode?->number ?? 99999,
                $this->query->mediaQualityRank($media->quality),
                $media->title,
            ))
            ->values();
        $requestedEpisodeId = $request->integer('episode');
        $requestedMediaId = $request->integer('media');
        $selectedEpisode = $requestedEpisodeId > 0
            ? $episodes->firstWhere('id', $requestedEpisodeId)
            : null;
        $selectedMedia = $requestedMediaId > 0
            ? $mediaItems->firstWhere('id', $requestedMediaId)
            : null;

        if ($selectedMedia === null && $selectedEpisode !== null) {
            $selectedMedia = $mediaItems->firstWhere('episode_id', $selectedEpisode->id);
        }

        if ($selectedEpisode === null && $selectedMedia?->episode_id !== null) {
            $selectedEpisode = $episodes->firstWhere('id', $selectedMedia->episode_id)
                ?? $selectedMedia->episode;
        }

        if ($selectedMedia === null && $selectedEpisode === null) {
            $selectedMedia = $mediaItems->first();
        }

        if ($selectedEpisode === null && $selectedMedia?->episode_id !== null) {
            $selectedEpisode = $episodes->firstWhere('id', $selectedMedia->episode_id)
                ?? $selectedMedia->episode;
        }

        $selectedEpisode ??= $episodes->first();
        $selectedMediaUrl = $selectedMedia ? ($selectedMedia->playback_url ?: $selectedMedia->path) : null;
        $episodeCount = $seasons->sum(fn ($season): int => (int) $season->episodes->count());
        $taxonomyCount = $taxonomiesByType->sum(fn (Collection $items): int => $items->count());
        $parsedSeasonCount = $seasons->filter(fn ($season): bool => $season->episodes->isNotEmpty())->count();
        $mediaCount = $mediaItems->count();
        $relatedIdsByType = $taxonomiesByType
            ->map(fn (Collection $items): Collection => $items->pluck('id')->unique()->values())
            ->filter(fn (Collection $ids): bool => $ids->isNotEmpty());
        $recommendedTitlesQuery = CatalogTitle::query()
            ->select(['id', 'slug', 'title', 'description', 'poster_url', 'indexed_at'])
            ->whereKeyNot($catalogTitle->id);

        if ($relatedIdsByType->isNotEmpty()) {
            $recommendedTitlesQuery->where(function (Builder $query) use ($relatedIdsByType): void {
                foreach ($relatedIdsByType as $filterType => $ids) {
                    $relation = $this->taxonomies->relationName($filterType);
                    $query->orWhereHas($relation, function (Builder $query) use ($ids): void {
                        $query->whereKey($ids);
                    });
                }
            });
        }

        $showView = new CatalogShowViewModel(
            title: $catalogTitle,
            taxonomiesByType: $taxonomiesByType,
            seasons: $seasons,
            mediaItems: $mediaItems,
            selectedEpisode: $selectedEpisode,
            selectedMedia: $selectedMedia,
            episodeCount: $episodeCount,
            taxonomyCount: $taxonomyCount,
            parsedSeasonCount: $parsedSeasonCount,
            mediaCount: $mediaCount,
        );

        return [
            'title' => $catalogTitle,
            'taxonomiesByType' => $taxonomiesByType,
            'taxonomyGroups' => $showView->taxonomyGroups,
            'genres' => $showView->genres,
            'countries' => $showView->countries,
            'actors' => $showView->actors,
            'directors' => $showView->directors,
            'ageRatings' => $showView->ageRatings,
            'translations' => $showView->translations,
            'statuses' => $showView->statuses,
            'networks' => $showView->networks,
            'studios' => $showView->studios,
            'tags' => $showView->tags,
            'taxonomyLabels' => $showView->taxonomyLabels,
            'taxonomyIcons' => $showView->taxonomyIcons,
            'taxonomyRows' => $showView->taxonomyRows,
            'seasons' => $seasons,
            'episodeCount' => $episodeCount,
            'taxonomyCount' => $taxonomyCount,
            'parsedSeasonCount' => $parsedSeasonCount,
            'selectedEpisode' => $selectedEpisode,
            'selectedMedia' => $selectedMedia,
            'selectedMediaUrl' => $showView->selectedMediaUrl,
            'selectedMediaFormat' => $showView->selectedMediaFormat,
            'selectedMediaType' => $showView->selectedMediaType,
            'selectedEpisodeMediaItems' => $showView->selectedEpisodeMediaItems,
            'mediaItems' => $mediaItems,
            'mediaCount' => $mediaCount,
            'topTaxonomies' => $showView->topTaxonomies,
            'showView' => $showView,
            'recommendedTitles' => $recommendedTitlesQuery
                ->latest('indexed_at')
                ->limit(6)
                ->get(),
            'seo' => $this->seo->title($catalogTitle, $taxonomiesByType, $seasons, $episodeCount, $mediaCount, $selectedMedia, $selectedMediaUrl),
        ];
    }
}
