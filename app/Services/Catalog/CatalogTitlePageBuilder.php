<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationListItem;
use App\DTOs\CatalogRecommendationItem;
use App\Models\CatalogTitle;
use App\Models\Season;
use App\Models\User;
use App\Services\Media\ExternalMediaMetadata;
use App\Support\CatalogTitleDisplayName;
use App\View\ViewModels\CatalogShowViewModel;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Collection;

#[Scoped]
final class CatalogTitlePageBuilder
{
    /** @var array<string, array<string, mixed>> */
    private array $preparedPages = [];

    public function __construct(
        private readonly CatalogSeoBuilder $seo,
        private readonly CatalogTitleQuery $query,
        private readonly CatalogTitlePlaybackQuery $playback,
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly ExternalMediaMetadata $mediaMetadata,
        private readonly CatalogUserCardStateLoader $cardStates,
        private readonly CatalogRecommendationService $recommendations,
        private readonly CatalogRecommendationPresenter $recommendationPresenter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function data(CatalogTitle $catalogTitle, ?User $user): array
    {
        $key = $this->requestKey($catalogTitle->id, $user);

        if (isset($this->preparedPages[$key])) {
            return $this->preparedPages[$key];
        }

        $this->playback->rememberVisibleTitle($catalogTitle, $user);
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
        $parsedSeasonCount = $seasons
            ->filter(fn (Season $season): bool => (int) $season->getAttribute('available_episodes_count') > 0)
            ->count();
        $mediaCount = (int) $seasons->sum('available_media_count');
        $titleRecommendations = $this->recommendations->forTitle(
            $catalogTitle,
            $user,
            max(1, (int) config('seasonvar.recommendations.max_per_title', 12)),
        );
        $relatedRecommendationItems = $this->presentRecommendationItems($titleRecommendations['related'], $user !== null);
        $recommendationItems = $this->presentRecommendationItems($titleRecommendations['similar'], $user !== null);
        $this->cardStates->load(
            $relatedRecommendationItems->pluck('title')->concat($recommendationItems->pluck('title')),
            $user,
        );

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

        return $this->preparedPages[$key] = [
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
            'relatedRecommendationItems' => $relatedRecommendationItems,
            'seo' => $this->seo->title($catalogTitle, $taxonomiesByType, $seasons, $episodeCount, $mediaCount, null, null),
        ];
    }

    /** @return array<string, mixed> */
    public function dataForId(int $catalogTitleId, ?User $user): array
    {
        $key = $this->requestKey($catalogTitleId, $user);

        if (isset($this->preparedPages[$key])) {
            return $this->preparedPages[$key];
        }

        return $this->data(
            $this->query->visibleTo($user)->findOrFail($catalogTitleId),
            $user,
        );
    }

    public function forget(int $catalogTitleId, ?User $user): void
    {
        unset($this->preparedPages[$this->requestKey($catalogTitleId, $user)]);
        $this->playback->forget($catalogTitleId, $user);
    }

    /**
     * @param Collection<int, CatalogRecommendationItem> $items
     * @return Collection<int, CatalogRecommendationListItem>
     */
    private function presentRecommendationItems(Collection $items, bool $canDismiss): Collection
    {
        return $items->map(fn (CatalogRecommendationItem $item): CatalogRecommendationListItem => new CatalogRecommendationListItem(
            title: $item->title,
            rank: $item->rank,
            reasonLabels: $this->recommendationPresenter->explanations($item->explanations),
            score: $item->score,
            type: $item->type,
            source: $item->source,
            relationType: $item->relationType,
            canDismiss: $canDismiss,
        ));
    }

    /** @return array<string, mixed> */
    public function seo(CatalogTitle $catalogTitle, ?User $user): array
    {
        $seo = $this->data($catalogTitle, $user)['seo'];

        return is_array($seo) ? $seo : [];
    }

    private function requestKey(int $catalogTitleId, ?User $user): string
    {
        if ($user === null) {
            return $catalogTitleId.'|guest';
        }

        $userKey = $user->getAuthIdentifier();

        return $catalogTitleId.'|user:'.($userKey !== null ? (string) $userKey : 'object:'.spl_object_id($user));
    }

}
