<?php

namespace App\Services\Catalog;

use App\Http\Requests\CatalogShowRequest;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendation;
use App\Models\User;
use App\Services\Media\ExternalMediaMetadata;
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
    public function data(CatalogShowRequest $request, CatalogTitle $catalogTitle): array
    {
        $catalogTitle->load(array_merge([
            'aliases:id,catalog_title_id,name',
            'ratings:id,catalog_title_id,provider,rating,votes',
        ], $this->taxonomies->relationSummaryLoads()));
        $taxonomiesByType = collect($this->taxonomies->relations())
            ->mapWithKeys(fn (array $config, string $filterType): array => [$filterType => $catalogTitle->{$config['relation']}->values()]);
        $seasons = $this->playback->seasonSummaries($catalogTitle, $request->user());
        $episodeCount = (int) $seasons->sum('available_episodes_count');
        $taxonomyCount = $taxonomiesByType->sum(fn (Collection $items): int => $items->count());
        $parsedSeasonCount = $seasons->filter(fn ($season): bool => (int) $season->available_episodes_count > 0)->count();
        $mediaCount = $this->playback->availableMedia($catalogTitle, $request->user())->count();
        $genreIds = $taxonomiesByType->get('genre', collect())->pluck('id')->unique()->values();
        $genreRecommendations = $this->relatedTitleSummaryQuery($catalogTitle, $request->user())
            ->when($genreIds->isNotEmpty(), fn (Builder $query): Builder => $query->whereHas('genres', fn (Builder $query): Builder => $query->whereKey($genreIds)))
            ->when($genreIds->isEmpty(), fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->latest('indexed_at')
            ->limit(8)
            ->get();
        $yearRecommendations = $catalogTitle->year
            ? $this->relatedTitleSummaryQuery($catalogTitle, $request->user())
                ->where('year', $catalogTitle->year)
                ->latest('indexed_at')
                ->limit(8)
                ->get()
            : collect();

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
        $recommendedTitleRecommendations = $this->recommendedTitleRecommendations($catalogTitle, $request->user());

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
            'aliases' => $catalogTitle->aliases,
            'ratings' => $catalogTitle->ratings,
            'topTaxonomies' => $showView->topTaxonomies,
            'showView' => $showView,
            'recommendedTitleRecommendations' => $recommendedTitleRecommendations,
            'genreRecommendations' => $genreRecommendations,
            'yearRecommendations' => $yearRecommendations,
            'seo' => $this->seo->title($catalogTitle, $taxonomiesByType, $seasons, $episodeCount, $mediaCount, null, null),
        ];
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
