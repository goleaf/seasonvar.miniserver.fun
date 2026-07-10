<?php

namespace App\Services\Catalog;

use App\Http\Requests\CatalogShowRequest;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Services\Media\ExternalMediaMetadata;
use App\View\ViewModels\CatalogShowViewModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class CatalogTitlePageBuilder
{
    private ?bool $recommendationsTableExists = null;

    public function __construct(
        private readonly CatalogSeoBuilder $seo,
        private readonly CatalogTitleQuery $query,
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly ExternalMediaMetadata $mediaMetadata,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function data(CatalogShowRequest $request, CatalogTitle $catalogTitle): array
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
        $requestedEpisodeId = $request->episodeId();
        $requestedMediaId = $request->mediaId();
        $requestedVariantKey = $request->variantKey();
        $requestedQuality = $request->quality();
        $requestedFormat = $request->mediaFormat();
        $selectedEpisode = $requestedEpisodeId > 0
            ? $episodes->firstWhere('id', $requestedEpisodeId)
            : null;
        $selectedMedia = $requestedMediaId > 0
            ? $mediaItems->firstWhere('id', $requestedMediaId)
            : null;

        if ($selectedMedia !== null
            && $selectedEpisode !== null
            && $selectedMedia->episode_id !== null
            && (int) $selectedMedia->episode_id !== (int) $selectedEpisode->id
        ) {
            $selectedMedia = null;
        }

        if ($selectedMedia === null && $selectedEpisode !== null) {
            $selectedMedia = $this->bestMediaForEpisode(
                $mediaItems,
                $selectedEpisode,
                $requestedVariantKey,
                $requestedQuality,
                $requestedFormat,
            );
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
        $genreIds = $taxonomiesByType->get('genre', collect())->pluck('id')->unique()->values();
        $genreRecommendations = $this->relatedTitleSummaryQuery($catalogTitle)
            ->when($genreIds->isNotEmpty(), fn (Builder $query): Builder => $query->whereHas('genres', fn (Builder $query): Builder => $query->whereKey($genreIds)))
            ->when($genreIds->isEmpty(), fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->latest('indexed_at')
            ->limit(8)
            ->get();
        $yearRecommendations = $catalogTitle->year
            ? $this->relatedTitleSummaryQuery($catalogTitle)
                ->where('year', $catalogTitle->year)
                ->latest('indexed_at')
                ->limit(8)
                ->get()
            : collect();

        $showView = new CatalogShowViewModel(
            title: $catalogTitle,
            taxonomiesByType: $taxonomiesByType,
            seasons: $seasons,
            mediaItems: $mediaItems,
            selectedEpisode: $selectedEpisode,
            selectedMedia: $selectedMedia,
            mediaMetadata: $this->mediaMetadata,
            episodeCount: $episodeCount,
            taxonomyCount: $taxonomyCount,
            parsedSeasonCount: $parsedSeasonCount,
            mediaCount: $mediaCount,
        );
        $recommendedTitleRecommendations = $this->recommendedTitleRecommendations($catalogTitle);

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
            'recommendedTitleRecommendations' => $recommendedTitleRecommendations,
            'genreRecommendations' => $genreRecommendations,
            'yearRecommendations' => $yearRecommendations,
            'seo' => $this->seo->title($catalogTitle, $taxonomiesByType, $seasons, $episodeCount, $mediaCount, $selectedMedia, $selectedMediaUrl),
        ];
    }

    /**
     * @return Builder<CatalogTitle>
     */
    private function relatedTitleSummaryQuery(CatalogTitle $catalogTitle): Builder
    {
        return $this->titleSummaryQuery(CatalogTitle::query())
            ->whereKeyNot($catalogTitle->id);
    }

    /**
     * @return Collection<int, CatalogTitleRecommendation>
     */
    private function recommendedTitleRecommendations(CatalogTitle $catalogTitle): Collection
    {
        if (! $this->hasRecommendationsTable()) {
            return collect();
        }

        return $catalogTitle->recommendations()
            ->orderBy('rank')
            ->orderByDesc('score')
            ->limit($this->recommendationDisplayLimit())
            ->with([
                'recommendedTitle' => function ($query): void {
                    $this->titleSummaryQuery($query->getQuery())
                        ->where('is_published', true);
                },
            ])
            ->get()
            ->filter(fn ($recommendation): bool => $recommendation->recommendedTitle !== null)
            ->values();
    }

    private function recommendationDisplayLimit(): int
    {
        return max(1, (int) config('seasonvar.recommendations.max_per_title', 12));
    }

    private function hasRecommendationsTable(): bool
    {
        return $this->recommendationsTableExists ??= Schema::hasTable('catalog_title_recommendations');
    }

    /**
     * @param  Builder<CatalogTitle>  $query
     * @return Builder<CatalogTitle>
     */
    private function titleSummaryQuery(Builder $query): Builder
    {
        return $query
            ->select(['id', 'slug', 'title', 'original_title', 'type', 'year', 'description', 'poster_url', 'indexed_at'])
            ->with($this->taxonomies->cardRelations())
            ->withCount($this->cardCounts());
    }

    /**
     * @return array<int|string, string|\Closure(Builder): Builder>
     */
    private function cardCounts(): array
    {
        return [
            'seasons',
            'episodes',
            'licensedMedia as published_media_count' => fn (Builder $query): Builder => $query->published(),
        ];
    }

    /**
     * @param  Collection<int, LicensedMedia>  $mediaItems
     */
    private function bestMediaForEpisode(
        Collection $mediaItems,
        Episode $episode,
        ?string $variantKey,
        ?string $quality,
        ?string $format,
    ): ?LicensedMedia {
        $episodeMediaItems = $mediaItems
            ->filter(fn (LicensedMedia $media): bool => (int) $media->episode_id === (int) $episode->id)
            ->values();

        if ($episodeMediaItems->isEmpty()) {
            return null;
        }

        $candidates = $episodeMediaItems;
        $variantMatches = $variantKey !== null
            ? $candidates->filter(fn (LicensedMedia $media): bool => $this->mediaVariantKey($media) === $variantKey)->values()
            : collect();

        if ($variantMatches->isNotEmpty()) {
            $candidates = $variantMatches;
        }

        $qualityMatches = $quality !== null
            ? $candidates->filter(fn (LicensedMedia $media): bool => $this->sameNormalizedValue($this->mediaQuality($media), $quality))->values()
            : collect();

        if ($qualityMatches->isNotEmpty()) {
            $candidates = $qualityMatches;
        }

        $formatMatches = $format !== null
            ? $candidates->filter(fn (LicensedMedia $media): bool => $this->sameNormalizedValue($this->mediaFormat($media), $format))->values()
            : collect();

        if ($formatMatches->isNotEmpty()) {
            $candidates = $formatMatches;
        }

        return $candidates->first();
    }

    private function mediaVariantKey(LicensedMedia $media): ?string
    {
        if (is_string($media->variant_key) && $media->variant_key !== '') {
            return $media->variant_key;
        }

        $url = $this->mediaUrl($media);

        if ($url === null) {
            return null;
        }

        return $this->mediaMetadata->playbackVariant($media->title, $media->source_url, $url)['variant_key'];
    }

    private function mediaQuality(LicensedMedia $media): ?string
    {
        if (is_string($media->quality) && $media->quality !== '') {
            return $media->quality;
        }

        $url = $this->mediaUrl($media);

        return $url !== null ? $this->mediaMetadata->quality($media->title, $url) : null;
    }

    private function mediaFormat(LicensedMedia $media): ?string
    {
        if (is_string($media->format) && $media->format !== '') {
            return $media->format;
        }

        $url = $this->mediaUrl($media);

        return $url !== null ? $this->mediaMetadata->format($url) : null;
    }

    private function mediaUrl(LicensedMedia $media): ?string
    {
        $url = $media->playback_url ?: $media->path;

        return is_string($url) && trim($url) !== '' ? $url : null;
    }

    private function sameNormalizedValue(?string $actual, string $expected): bool
    {
        return $actual !== null && Str::lower($actual) === Str::lower($expected);
    }
}
