<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogRecommendationContext;
use App\DTOs\CatalogRecommendationItem;
use App\DTOs\CatalogRecommendationListItem;
use App\Enums\CatalogRecommendationType;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\Tag;
use App\Models\User;
use App\Services\Auth\AccountDateTimeFormatter;
use App\Services\Auth\AccountSettingsService;
use App\Services\Auth\AuthenticationRedirectService;
use App\Services\Catalog\Queries\CatalogHomeFacetGroupsQuery;
use App\Services\Collections\CatalogCollectionQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class CatalogHomePageBuilder
{
    private const WEB_LATEST_TITLE_LIMIT = 12;

    private const WEB_GRID_TITLE_LIMIT = 6;

    private const WEB_TRENDING_TITLE_LIMIT = 5;

    private const API_GENRE_LIMIT = 18;

    private const API_YEAR_LIMIT = 12;

    public function __construct(
        private readonly CatalogSeoBuilder $seo,
        private readonly CatalogHomeFacetGroupsQuery $homeFacets,
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogTitleQuery $titles,
        private readonly CatalogHomeContentAdditionQuery $contentAdditions,
        private readonly CatalogHomeMetricsCache $metrics,
        private readonly CatalogHomeSnapshotCache $snapshot,
        private readonly CatalogTitleCardCountLoader $cardCounts,
        private readonly CatalogUserCardStateLoader $cardStates,
        private readonly CatalogCollectionQuery $collections,
        private readonly CatalogRecommendationService $recommendations,
        private readonly CatalogRecommendationPresenter $recommendationPresenter,
        private readonly CatalogViewingActivityQuery $viewingActivity,
        private readonly UserLibraryQuery $library,
        private readonly AccountSettingsService $accountSettings,
        private readonly AccountDateTimeFormatter $dates,
        private readonly AuthenticationRedirectService $authenticationRoutes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function data(?User $user = null): array
    {
        return $this->buildData($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function webData(?User $user = null): array
    {
        return $this->buildData(
            $user,
            self::WEB_LATEST_TITLE_LIMIT,
            includeApiOnlySections: false,
            includeWebOnlySections: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildData(
        ?User $user,
        ?int $latestTitleLimit = null,
        bool $includeApiOnlySections = true,
        bool $includeWebOnlySections = false,
    ): array {
        $accountSettings = $this->accountSettings->resolve($user);
        $locale = app()->currentLocale();
        $facetGroups = $this->homeFacets->handle();
        $genres = $facetGroups->get('genre', collect());
        $countries = $facetGroups->get('country', collect());
        $snapshot = $this->snapshot->snapshot();
        $allLatestTitleIds = $snapshot['latest_title_ids'];
        $latestTitleIds = $latestTitleLimit === null
            ? $allLatestTitleIds
            : array_slice($allLatestTitleIds, 0, $latestTitleLimit);
        $stats = [
            ...$this->metrics->metrics(),
            'genres' => $genres->count(),
            'countries' => $countries->count(),
        ];
        $titleGroups = $this->orderedTitleGroups([
            'latest' => $latestTitleIds,
            'featured' => $includeApiOnlySections ? $snapshot['featured_title_ids'] : [],
            'video' => $includeWebOnlySections
                ? array_slice($snapshot['video_title_ids'], 0, self::WEB_GRID_TITLE_LIMIT)
                : $snapshot['video_title_ids'],
        ], $this->titleSummaryQuery($user)->with($this->taxonomies->cardSummaryLoads()));
        $latestTitles = $titleGroups['latest'];
        $featuredTitles = $titleGroups['featured'];
        $videoTitles = $titleGroups['video'];
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
        $latestMedia = $includeApiOnlySections
            ? $this->orderedMedia(LicensedMedia::query()
                ->published()
                ->forAvailableReleases(null)
                ->select(['id', 'catalog_title_id', 'season_id', 'episode_id', 'title', 'quality', 'translation_name', 'format', 'published_at'])
                ->with([
                    'catalogTitle' => fn ($query) => $query
                        ->availableTo(null)
                        ->select(['id', 'slug', 'title', 'original_title', 'type', 'year', 'poster_url', 'indexed_at']),
                    'season:id,catalog_title_id,number,kind,sort_order,title',
                    'episode:id,season_id,number,kind,sort_order,title,released_at',
                ]), $snapshot['latest_media_ids'])
            : collect();
        $latestMediaTitles = $latestMedia
            ->map(fn (LicensedMedia $media): ?CatalogTitle => $media->catalogTitle)
            ->filter(fn (?CatalogTitle $title): bool => $title instanceof CatalogTitle)
            ->values();
        $this->cardCounts->load(
            $latestTitles
                ->concat($featuredTitles)
                ->concat($videoTitles)
                ->concat($latestMediaTitles),
            $user,
        );
        $latestReleaseGroups = $this->contentAdditions->latestReleaseGroups(
            $latestTitles,
            $latestTitleUpdates->all(),
            $includeWebOnlySections ? 6 : 12,
        );
        $featuredRecommendationIds = $includeApiOnlySections
            ? $featuredTitles->pluck('id')
            : collect($snapshot['featured_title_ids']);
        $excludedRecommendationIds = collect($allLatestTitleIds)
            ->concat($featuredRecommendationIds)
            ->concat($videoTitles->pluck('id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $recommendationType = $user !== null
            ? CatalogRecommendationType::Personalized
            : CatalogRecommendationType::RecentlyAdded;
        $recommendationResult = $this->recommendations->discover(new CatalogRecommendationContext(
            type: $recommendationType,
            user: $user,
            locale: app()->currentLocale(),
            excludedTitleIds: $includeWebOnlySections ? [] : $excludedRecommendationIds,
            perPage: $includeWebOnlySections ? self::WEB_GRID_TITLE_LIMIT : 8,
            seed: $user !== null ? 'home' : null,
        ));

        if ($user !== null && (! $includeWebOnlySections || $recommendationType === CatalogRecommendationType::Personalized)) {
            $this->recommendations->rememberShown($recommendationResult, $user);
        }

        $homeRecommendationItems = $this->presentRecommendationItems($recommendationResult->items);
        $homeRecommendationPresentation = $this->recommendationPresenter->type($recommendationResult->displayType);
        $trendingItems = collect();
        $trendingPresentation = $this->recommendationPresenter->type(CatalogRecommendationType::Trending);

        if ($includeWebOnlySections) {
            $trendingResult = $this->recommendations->discover(new CatalogRecommendationContext(
                type: CatalogRecommendationType::Trending,
                user: null,
                locale: $locale,
                perPage: self::WEB_TRENDING_TITLE_LIMIT,
                seed: 'home-trending',
            ));
            $trendingItems = $this->presentRecommendationItems($trendingResult->items);
            $usedTrendingTitleIds = $trendingItems
                ->map(fn (CatalogRecommendationListItem $item): int => (int) $item->title->id)
                ->all();
            $fallbackTrendingItems = $videoTitles
                ->concat($latestTitles)
                ->unique(fn (CatalogTitle $title): int => (int) $title->id)
                ->reject(fn (CatalogTitle $title): bool => in_array((int) $title->id, $usedTrendingTitleIds, true))
                ->take(max(0, self::WEB_TRENDING_TITLE_LIMIT - $trendingItems->count()))
                ->values()
                ->map(fn (CatalogTitle $title, int $index): CatalogRecommendationListItem => new CatalogRecommendationListItem(
                    title: $title,
                    rank: $trendingItems->count() + $index + 1,
                    reasonLabels: [__('home.trending.reason')],
                    type: CatalogRecommendationType::Trending,
                ));
            $trendingItems = $trendingItems->concat($fallbackTrendingItems)->values();
            $trendingPresentation = $this->recommendationPresenter->type($trendingResult->displayType);
        }

        $continueWatchingItems = $includeWebOnlySections && $user !== null
            ? $this->viewingActivity->continueWatching($user, 6)
            : collect();
        $libraryUpdateStates = $includeWebOnlySections && $user !== null
            ? $this->library->homeUpdates($user, 6)
            : new EloquentCollection;
        $this->cardStates->load(
            $latestTitles->concat($featuredTitles)->concat($videoTitles),
            $user,
        );
        $yearBuckets = collect($snapshot['year_buckets'])
            ->when(
                ! $includeWebOnlySections,
                fn (Collection $buckets): Collection => $buckets->take(self::API_YEAR_LIMIT),
            )
            ->map(fn (array $attributes): object => (object) $attributes)
            ->values();
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
            'genres' => $includeWebOnlySections
                ? $genres->values()
                : $genres->take(self::API_GENRE_LIMIT)->values(),
            'countries' => $countries,
            'subtitleTag' => $subtitleTag,
            'subtitleTagUrl' => $subtitleTag instanceof Tag
                ? route('titles.taxonomy', ['type' => 'tag', 'taxonomy' => $subtitleTag->slug])
                : null,
            'featuredCollections' => $includeWebOnlySections && $user !== null
                ? collect()
                : $this->collections->featured(),
            'homeRecommendationItems' => $homeRecommendationItems,
            'homeRecommendationPresentation' => $homeRecommendationPresentation,
            'trendingSpotlight' => $trendingItems->first(),
            'trendingCandidates' => $trendingItems->skip(1)->values(),
            'trendingPresentation' => $trendingPresentation,
            'continueWatchingItems' => $continueWatchingItems,
            'libraryUpdateStates' => $libraryUpdateStates,
            'isPersonalizedHome' => $includeWebOnlySections && $user !== null,
            'collectionsUrl' => $this->discoveryUrl(CatalogRecommendationType::Popular).'#collections',
            'discoveryUrl' => $this->discoveryUrl($recommendationResult->displayType),
            'trendingUrl' => $this->discoveryUrl(CatalogRecommendationType::Trending),
            'hasMoreLatestTitles' => count($allLatestTitleIds) > $latestTitles->count(),
            'topRatedUrl' => $this->discoveryUrl(CatalogRecommendationType::TopRated),
            'recentlyAddedUrl' => $this->discoveryUrl(CatalogRecommendationType::RecentlyAdded),
            'recentlyUpdatedUrl' => $this->discoveryUrl(CatalogRecommendationType::RecentlyUpdated),
            'upcomingUrl' => $this->localeRoute('calendar.upcoming'),
            'randomUrl' => $this->discoveryUrl(CatalogRecommendationType::Random),
            'continueWatchingUrl' => $user !== null
                ? route('library.section', ['section' => 'continue-watching'])
                : $this->authenticationRoutes->guestUrl('login', locale: $locale),
            'libraryUpdatesUrl' => $user !== null
                ? route('library.section', ['section' => 'with-updates'])
                : $this->authenticationRoutes->guestUrl('login', locale: $locale),
            'myCollectionsUrl' => $user !== null
                ? route('collections.mine')
                : $this->authenticationRoutes->guestUrl('login', locale: $locale),
            'myCalendarUrl' => $user !== null
                ? $this->localeRoute('calendar.mine')
                : $this->localeRoute('calendar.index'),
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
            ->select(['id', 'slug', 'title', 'original_title', 'type', 'year', 'description', 'poster_url', 'indexed_at']);
    }

    private function discoveryUrl(CatalogRecommendationType $type): string
    {
        return $this->localeRoute('discover.index', [
            'type' => $type->value,
        ]);
    }

    /**
     * @param  Collection<int, CatalogRecommendationItem>  $items
     * @return Collection<int, CatalogRecommendationListItem>
     */
    private function presentRecommendationItems(Collection $items): Collection
    {
        return $items->map(
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
     * @param  array{latest: list<int>, featured: list<int>, video: list<int>}  $groups
     * @param  Builder<CatalogTitle>  $query
     * @return array{
     *     latest: EloquentCollection<int, CatalogTitle>,
     *     featured: EloquentCollection<int, CatalogTitle>,
     *     video: EloquentCollection<int, CatalogTitle>
     * }
     */
    private function orderedTitleGroups(array $groups, Builder $query): array
    {
        $titleIds = collect($groups)
            ->flatten()
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $titlesById = $titleIds === []
            ? new EloquentCollection
            : $query
                ->whereKey($titleIds)
                ->get()
                ->keyBy(fn (CatalogTitle $title): int => (int) $title->getKey());
        $ordered = function (array $ids) use ($titlesById): EloquentCollection {
            $titles = collect($ids)
                ->map(function (mixed $id) use ($titlesById): ?CatalogTitle {
                    $title = $titlesById->get((int) $id);

                    return $title instanceof CatalogTitle ? clone $title : null;
                })
                ->filter(fn (?CatalogTitle $title): bool => $title instanceof CatalogTitle)
                ->values()
                ->all();

            return new EloquentCollection($titles);
        };

        return [
            'latest' => $ordered($groups['latest']),
            'featured' => $ordered($groups['featured']),
            'video' => $ordered($groups['video']),
        ];
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
