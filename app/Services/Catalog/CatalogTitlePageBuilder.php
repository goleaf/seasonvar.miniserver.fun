<?php

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationListItem;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\User;
use App\Services\Media\ExternalMediaMetadata;
use App\Support\CatalogTitleDisplayName;
use App\View\ViewModels\CatalogShowViewModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CatalogTitlePageBuilder
{
    private ?bool $recommendationsTableExists = null;

    public function __construct(
        private readonly CatalogSeoBuilder $seo,
        private readonly CatalogTitleQuery $query,
        private readonly CatalogTitlePlaybackQuery $playback,
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly ExternalMediaMetadata $mediaMetadata,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function data(CatalogTitle $catalogTitle, ?User $user): array
    {
        $catalogTitle->load(array_merge([
            'aliases:id,catalog_title_id,name',
            'ratings:id,catalog_title_id,provider,rating,votes',
        ], $this->taxonomies->relationSummaryLoads()));
        $displayName = CatalogTitleDisplayName::from($catalogTitle->title, $catalogTitle->original_title);
        $aliases = $catalogTitle->aliases
            ->unique(fn ($alias): string => CatalogTitleDisplayName::comparisonKey($alias->name))
            ->reject(fn ($alias): bool => $displayName->contains($alias->name))
            ->values();
        $taxonomiesByType = collect($this->taxonomies->relations())
            ->mapWithKeys(fn (array $config, string $filterType): array => [$filterType => $catalogTitle->{$config['relation']}->values()]);
        $seasons = $this->playback->seasonSummaries($catalogTitle, $user);
        $episodeCount = (int) $seasons->sum('available_episodes_count');
        $taxonomyCount = $taxonomiesByType->sum(fn (Collection $items): int => $items->count());
        $parsedSeasonCount = $seasons->filter(fn ($season): bool => (int) $season->available_episodes_count > 0)->count();
        $mediaCount = $this->playback->availableMedia($catalogTitle, $user)->count();
        $genreIds = $taxonomiesByType->get('genre', collect())->pluck('id')->unique()->values();
        $recommendationItems = $this->recommendationItems($catalogTitle, $user, $genreIds);

        $showView = new CatalogShowViewModel(
            title: $catalogTitle,
            taxonomiesByType: $taxonomiesByType,
            seasons: $seasons,
            mediaItems: collect(),
            selectedEpisode: null,
            selectedMedia: null,
            mediaMetadata: $this->mediaMetadata,
            episodeCount: $episodeCount,
            taxonomyCount: $taxonomyCount,
            parsedSeasonCount: $parsedSeasonCount,
            mediaCount: $mediaCount,
        );

        return [
            'title' => $catalogTitle,
            'taxonomiesByType' => $taxonomiesByType,
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
            'taxonomyRows' => $showView->taxonomyRows,
            'seasons' => $seasons,
            'episodeCount' => $episodeCount,
            'taxonomyCount' => $taxonomyCount,
            'parsedSeasonCount' => $parsedSeasonCount,
            'mediaCount' => $mediaCount,
            'aliases' => $aliases,
            'ratings' => $catalogTitle->ratings,
            'topTaxonomies' => $showView->topTaxonomies,
            'showView' => $showView,
            'recommendationItems' => $recommendationItems,
            'seo' => $this->seo->title($catalogTitle, $taxonomiesByType, $seasons, $episodeCount, $mediaCount, null, null),
        ];
    }

    /**
     * @param  Collection<int, int>  $genreIds
     * @return Collection<int, CatalogRecommendationListItem>
     */
    private function recommendationItems(CatalogTitle $catalogTitle, ?User $user, Collection $genreIds): Collection
    {
        $precomputed = $this->recommendedTitleRecommendations($catalogTitle, $user);

        if ($precomputed->isNotEmpty()) {
            return $precomputed
                ->values()
                ->map(fn (CatalogTitleRecommendation $recommendation, int $index): CatalogRecommendationListItem => new CatalogRecommendationListItem(
                    title: $recommendation->recommendedTitle,
                    rank: $index + 1,
                    reasonLabels: $recommendation->reasonLabels(),
                    score: (int) $recommendation->score,
                ));
        }

        return $this->fallbackRecommendationItems($catalogTitle, $user, $genreIds);
    }

    /**
     * @param  Collection<int, int>  $genreIds
     * @return Collection<int, CatalogRecommendationListItem>
     */
    private function fallbackRecommendationItems(CatalogTitle $catalogTitle, ?User $user, Collection $genreIds): Collection
    {
        $genreRecommendations = $this->relatedTitleSummaryQuery($catalogTitle, $user)
            ->when($genreIds->isNotEmpty(), fn (Builder $query): Builder => $query->whereHas('genres', fn (Builder $query): Builder => $query->whereKey($genreIds)))
            ->when($genreIds->isEmpty(), fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->latest('indexed_at')
            ->limit(8)
            ->get();
        $yearRecommendations = $catalogTitle->year
            ? $this->relatedTitleSummaryQuery($catalogTitle, $user)
                ->where('year', $catalogTitle->year)
                ->latest('indexed_at')
                ->limit(8)
                ->get()
            : collect();
        $titles = [];
        $labels = [];

        foreach ([
            'Похожий жанр' => $genreRecommendations,
            'Тот же год' => $yearRecommendations,
        ] as $label => $recommendations) {
            foreach ($recommendations as $recommendedTitle) {
                $titles[$recommendedTitle->id] ??= $recommendedTitle;
                $labels[$recommendedTitle->id] ??= [];

                if (! in_array($label, $labels[$recommendedTitle->id], true)) {
                    $labels[$recommendedTitle->id][] = $label;
                }
            }
        }

        return collect(array_values($titles))
            ->take($this->recommendationDisplayLimit())
            ->values()
            ->map(fn (CatalogTitle $recommendedTitle, int $index): CatalogRecommendationListItem => new CatalogRecommendationListItem(
                title: $recommendedTitle,
                rank: $index + 1,
                reasonLabels: $labels[$recommendedTitle->id],
            ));
    }

    /** @return array<string, mixed> */
    public function seo(CatalogTitle $catalogTitle, ?User $user): array
    {
        $catalogTitle->load(array_merge([
            'aliases:id,catalog_title_id,name',
            'ratings:id,catalog_title_id,provider,rating,votes',
        ], $this->taxonomies->relationSummaryLoads()));
        $taxonomiesByType = collect($this->taxonomies->relations())
            ->mapWithKeys(fn (array $config, string $filterType): array => [$filterType => $catalogTitle->{$config['relation']}->values()]);
        $seasons = $this->playback->seasonSummaries($catalogTitle, $user);
        $episodeCount = (int) $seasons->sum('available_episodes_count');
        $mediaCount = $this->playback->availableMedia($catalogTitle, $user)->count();

        return $this->seo->title(
            $catalogTitle,
            $taxonomiesByType,
            $seasons,
            $episodeCount,
            $mediaCount,
            null,
            null,
        );
    }

    /**
     * @return Builder<CatalogTitle>
     */
    private function relatedTitleSummaryQuery(CatalogTitle $catalogTitle, ?User $user): Builder
    {
        return $this->titleSummaryQuery(CatalogTitle::query(), $user)
            ->whereKeyNot($catalogTitle->id);
    }

    /**
     * @return Collection<int, CatalogTitleRecommendation>
     */
    private function recommendedTitleRecommendations(CatalogTitle $catalogTitle, ?User $user): Collection
    {
        if (! $this->hasRecommendationsTable()) {
            return collect();
        }

        return $catalogTitle->recommendations()
            ->whereHas('recommendedTitle', fn (Builder $query): Builder => $this->query->constrainVisible($query, $user))
            ->orderBy('rank')
            ->orderByDesc('score')
            ->limit($this->recommendationDisplayLimit())
            ->with([
                'recommendedTitle' => function ($query) use ($user): void {
                    $this->titleSummaryQuery($query->getQuery(), $user);
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
    private function titleSummaryQuery(Builder $query, ?User $user): Builder
    {
        return $this->query
            ->constrainVisible($query, $user)
            ->select(['id', 'slug', 'title', 'original_title', 'type', 'year', 'description', 'poster_url', 'indexed_at'])
            ->with($this->taxonomies->cardSummaryLoads())
            ->withCount($this->query->publicCardCounts($user));
    }
}
