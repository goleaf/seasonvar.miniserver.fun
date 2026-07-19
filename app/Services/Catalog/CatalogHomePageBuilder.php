<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationContext;
use App\DTOs\CatalogRecommendationItem;
use App\DTOs\CatalogRecommendationListItem;
use App\Enums\CatalogRecommendationType;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\Tag;
use App\Models\User;
use App\Services\Auth\AccountDateTimeFormatter;
use App\Services\Auth\AccountSettingsService;
use App\Services\Auth\AuthenticationRedirectService;
use App\Services\Collections\CatalogCollectionQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
        private readonly CatalogRecommendationService $recommendations,
        private readonly CatalogRecommendationPresenter $recommendationPresenter,
        private readonly AccountSettingsService $accountSettings,
        private readonly AccountDateTimeFormatter $dates,
        private readonly AuthenticationRedirectService $authenticationRoutes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function data(?User $user = null): array
    {
        $accountSettings = $this->accountSettings->resolve($user);
        $locale = app()->currentLocale();
        $genres = $this->facets->taxonomies('genre');
        $countries = $this->facets->taxonomies('country');
        $countries->each(function (Model $country): void {
            $country->setAttribute('detail_url', route('titles.taxonomy', [
                'type' => 'country',
                'taxonomy' => $country->getAttribute('slug'),
            ]));
        });
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
        $excludedRecommendationIds = $latestTitles
            ->concat($featuredTitles)
            ->concat($videoTitles)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $recommendationType = $user !== null
            ? CatalogRecommendationType::Personalized
            : CatalogRecommendationType::Trending;
        $recommendationResult = $this->recommendations->discover(new CatalogRecommendationContext(
            type: $recommendationType,
            user: $user,
            locale: app()->currentLocale(),
            excludedTitleIds: $excludedRecommendationIds,
            perPage: 8,
            seed: $user !== null ? 'home' : null,
        ));

        if ($recommendationResult->items->isEmpty() && $recommendationType === CatalogRecommendationType::Trending) {
            $recommendationResult = $this->recommendations->discover(new CatalogRecommendationContext(
                type: CatalogRecommendationType::Popular,
                user: null,
                locale: app()->currentLocale(),
                excludedTitleIds: $excludedRecommendationIds,
                perPage: 8,
            ));
        }

        if ($user !== null) {
            $this->recommendations->rememberShown($recommendationResult, $user);
        }

        $homeRecommendationItems = $recommendationResult->items->map(
            fn (CatalogRecommendationItem $item): CatalogRecommendationListItem => new CatalogRecommendationListItem(
                title: $item->title,
                rank: $item->rank,
                reasonLabels: $this->recommendationPresenter->explanations($item->explanations),
                score: $item->score,
                type: $item->type,
                source: $item->source,
                relationType: $item->relationType,
                canDismiss: false,
            ),
        );
        $homeRecommendationPresentation = $this->recommendationPresenter->type($recommendationResult->displayType);
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
            'latestByDate' => $latestTitles->groupBy(fn (CatalogTitle $catalogTitle): string => $this->dates->dateGroup(
                $catalogTitle->content_added_at,
                $locale,
                $accountSettings->timezone,
            )),
            'featuredTitles' => $featuredTitles,
            'videoTitles' => $videoTitles,
            'latestMedia' => $latestMedia,
            'latestReleaseGroups' => $latestReleaseGroups,
            'yearBuckets' => $yearBuckets,
            'genres' => $genres->take(18)->values(),
            'countries' => $countries,
            'subtitleTag' => $subtitleTag,
            'subtitleTagUrl' => $subtitleTag instanceof Tag
                ? route('titles.taxonomy', ['type' => 'tag', 'taxonomy' => $subtitleTag->slug])
                : null,
            'featuredCollections' => $this->collections->featured(),
            'homeRecommendationItems' => $homeRecommendationItems,
            'homeRecommendationPresentation' => $homeRecommendationPresentation,
            'collectionsUrl' => $this->discoveryUrl(CatalogRecommendationType::Popular).'#collections',
            'discoveryUrl' => $this->discoveryUrl($recommendationResult->displayType),
            'topRatedUrl' => $this->discoveryUrl(CatalogRecommendationType::TopRated),
            'recentlyAddedUrl' => $this->discoveryUrl(CatalogRecommendationType::RecentlyAdded),
            'upcomingUrl' => $this->localeRoute('calendar.upcoming'),
            'randomUrl' => $this->discoveryUrl(CatalogRecommendationType::Random),
            'continueWatchingUrl' => $user !== null
                ? route('library.section', ['section' => 'continue-watching'])
                : $this->authenticationRoutes->guestUrl('login', locale: $locale),
            'noveltiesUrl' => route('titles.year', ['year' => now()->year]),
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

    private function discoveryUrl(CatalogRecommendationType $type): string
    {
        return $this->localeRoute('discover.index', [
            'type' => $type->value,
        ]);
    }

    /** @param array<string, scalar> $parameters */
    private function localeRoute(string $name, array $parameters = []): string
    {
        $locale = app()->currentLocale();
        $localizedName = 'localized.'.$name;
        $shouldLocalize = request()->routeIs('localized.*')
            || $locale !== (string) config('catalog-collections.default_locale', 'ru');

        return $shouldLocalize
            ? route($localizedName, ['locale' => $locale, ...$parameters])
            : route($name, $parameters);
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
