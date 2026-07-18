<?php

namespace App\Services\Catalog;

use App\DTOs\CatalogDirectoryDefinition;
use App\DTOs\CatalogRecommendationItem;
use App\DTOs\CatalogRecommendationResult;
use App\Enums\CatalogRecommendationType;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Support\CatalogTitleDisplayName;
use App\Support\PlainText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

class CatalogSeoBuilder
{
    public function __construct(private readonly CatalogRecommendationPresenter $recommendationPresenter) {}

    /**
     * @param  Collection<int, CatalogRecommendationItem>  $items
     * @return array<string, mixed>
     */
    public function discovery(
        CatalogRecommendationType $type,
        CatalogRecommendationResult $result,
        Collection $items,
        bool $hasFilters,
    ): array {
        $presentation = $this->recommendationPresenter->type($result->displayType);
        $page = $result->page;
        $localized = request()->routeIs('localized.discover.*');
        $routeName = $localized ? 'localized.discover.index' : 'discover.index';
        $routeParameters = [
            ...($localized ? ['locale' => app()->currentLocale()] : []),
            'type' => $type->value,
        ];
        $baseUrl = route($routeName, $routeParameters);
        $canonical = $page > 1 && ! $hasFilters
            ? route($routeName, [...$routeParameters, 'page' => $page])
            : $baseUrl;
        $title = __('recommendations.seo.title', ['type' => $presentation['title']]);

        if ($page > 1) {
            $title .= ' — '.__('recommendations.page.page_number', ['page' => $page]);
        }

        $description = __('recommendations.seo.description', ['description' => $presentation['description']]);
        $indexable = $type->isIndexable()
            && request()->user() === null
            && ! $hasFilters
            && $items->isNotEmpty();
        $alternates = [];

        if ($indexable) {
            foreach (config('catalog-collections.supported_locales', ['ru']) as $locale) {
                $alternates[$locale] = route('localized.discover.index', [
                    'locale' => $locale,
                    'type' => $type->value,
                    ...($page > 1 ? ['page' => $page] : []),
                ]);
            }

            $alternates['x-default'] = route('discover.index', [
                'type' => $type->value,
                ...($page > 1 ? ['page' => $page] : []),
            ]);
        }

        $titles = $items->map(fn (CatalogRecommendationItem $item): CatalogTitle => $item->title);
        $breadcrumbs = [
            ['name' => __('catalog.navigation.home'), 'url' => route('home')],
            ['name' => __('recommendations.navigation.discover'), 'url' => $baseUrl],
            ['name' => $presentation['title'], 'url' => $canonical],
        ];

        return [
            'title' => $title,
            'description' => $this->seoDescription($description),
            'canonical' => $canonical,
            'robots' => $indexable ? $this->indexRobots() : 'noindex,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1',
            'prev' => $page > 1 && ! $hasFilters ? route($routeName, [...$routeParameters, ...($page > 2 ? ['page' => $page - 1] : [])]) : null,
            'next' => $result->hasMore && ! $hasFilters ? route($routeName, [...$routeParameters, 'page' => $page + 1]) : null,
            'type' => 'website',
            'section' => __('recommendations.navigation.discover'),
            'updated_time' => now()->toAtomString(),
            'alternates' => $alternates,
            'breadcrumbs' => $breadcrumbs,
            'jsonLd' => $indexable ? [
                $this->webPageJsonLd($title, $description, $canonical, 'CollectionPage', [$presentation['title']]),
                $this->collectionPageJsonLd($title, $description, $canonical),
                $this->itemListJsonLd($titles, (($page - 1) * $result->perPage) + 1, $presentation['title']),
                $this->breadcrumbJsonLd($breadcrumbs),
            ] : [],
        ];
    }

    /**
     * @param  array{titles: int, episodes: int, videos: int, genres: int, countries: int}  $stats
     * @param  Collection<int, CatalogTitle>  $latestTitles
     * @return array<string, mixed>
     */
    public function home(array $stats, Collection $latestTitles): array
    {
        $locale = app()->currentLocale();
        $titleCount = trans_choice('catalog.counts.results', $stats['titles'], [
            'count' => Number::format($stats['titles'], locale: $locale),
        ]);
        $episodeCount = trans_choice('catalog.counts.episodes', $stats['episodes'], [
            'count' => Number::format($stats['episodes'], locale: $locale),
        ]);
        $title = __('home.seo.title', ['site' => $this->siteName()]);
        $description = __('home.seo.description', [
            'titles' => $titleCount,
            'episodes' => $episodeCount,
        ]);
        $localized = request()->routeIs('localized.home')
            || $locale !== (string) config('catalog-collections.default_locale', 'ru');
        $canonical = $localized
            ? route('localized.home', ['locale' => $locale])
            : route('home');
        $alternates = collect((array) config('catalog-collections.supported_locales', []))
            ->filter(fn (mixed $supportedLocale): bool => is_string($supportedLocale) && $supportedLocale !== '')
            ->mapWithKeys(fn (string $supportedLocale): array => [
                $supportedLocale => route('localized.home', ['locale' => $supportedLocale]),
            ])
            ->put('x-default', route('home'))
            ->all();
        $breadcrumbs = [
            ['name' => __('catalog.navigation.home'), 'url' => $canonical],
        ];

        return [
            'title' => $title,
            'description' => $description,
            'keywords' => __('home.seo.keywords'),
            'news_keywords' => __('home.seo.news_keywords'),
            'canonical' => $canonical,
            'type' => 'website',
            'updated_time' => now()->toAtomString(),
            'section' => __('home.seo.section'),
            'tags' => __('home.seo.tags'),
            'seo_text' => __('home.seo.text'),
            'search_phrases' => __('home.seo.phrases'),
            'keyword_clusters' => __('home.seo.clusters'),
            'related_links' => [
                ['name' => __('home.seo.links.all_titles'), 'url' => route('titles.index')],
                ['name' => __('home.seo.links.year_titles', ['year' => now()->year]), 'url' => route('titles.year', ['year' => now()->year])],
                ['name' => __('home.seo.links.rss'), 'url' => route('feed')],
            ],
            'alternates' => $alternates,
            'breadcrumbs' => $breadcrumbs,
            'jsonLd' => [
                $this->organizationJsonLd(),
                $this->websiteJsonLd(),
                $this->siteNavigationJsonLd(),
                $this->webPageJsonLd($title, $description, $canonical, 'WebPage', __('home.seo.about')),
                $this->collectionPageJsonLd($title, $description, $canonical),
                $this->itemListJsonLd($latestTitles, 1, __('home.seo.new_titles_list')),
                $this->breadcrumbJsonLd($breadcrumbs),
            ],
        ];
    }

    /**
     * @param  Collection<string, Model>  $activeTaxonomies
     * @param  array<string, string>  $invalidFilterSlugs
     * @param  Collection<int, CatalogTitle>  $pageTitles
     * @return array<string, mixed>
     */
    public function titles(
        Request $request,
        int $total,
        string $search,
        ?int $year,
        Collection $activeTaxonomies,
        array $invalidFilterSlugs,
        bool $invalidYear,
        string $requestedYear,
        int $currentPage,
        ?string $previousPageUrl,
        ?string $nextPageUrl,
        Collection $pageTitles,
        int $firstItemPosition,
        ?CatalogTitle $titleContext,
    ): array {
        $filterTitle = $this->catalogFilteredTitle($activeTaxonomies);
        $title = match (true) {
            $search !== '' && $titleContext !== null => __('catalog.catalog.search_seo.title_query_context', ['query' => $search, 'title' => $titleContext->display_title]),
            $search !== '' => __('catalog.catalog.search_seo.title_query', ['query' => $search]),
            $filterTitle !== '' && $year !== null => __('catalog.catalog.search_seo.title_online', ['title' => $this->catalogFilteredTitle($activeTaxonomies, $year)]),
            $filterTitle !== '' => __('catalog.catalog.search_seo.title_online', ['title' => $filterTitle]),
            $year !== null => __('catalog.catalog.search_seo.title_year', ['year' => $year]),
            $titleContext !== null => __('catalog.catalog.search_seo.title_context', ['title' => $titleContext->display_title]),
            default => __('catalog.catalog.search_seo.title_all'),
        };

        if ($currentPage > 1) {
            $title = __('catalog.catalog.search_seo.title_page', ['title' => $title, 'page' => $currentPage]);
        }

        $totalLabel = trans_choice('catalog.counts.results_found', $total, [
            'count' => Number::format($total, locale: app()->currentLocale()),
        ]);
        $descriptionParts = collect([
            __('catalog.catalog.search_seo.description_count', ['count' => $totalLabel]),
            $titleContext !== null ? __('catalog.catalog.search_seo.description_context', ['title' => $titleContext->display_title]) : null,
            $activeTaxonomies->isNotEmpty() ? $this->catalogFilteredDescription($activeTaxonomies) : null,
            $year !== null ? __('catalog.catalog.search_seo.description_year', ['year' => $year]) : null,
            $search !== '' ? __('catalog.catalog.search_seo.description_query', ['query' => $search]) : null,
            $invalidYear ? __('catalog.catalog.search_seo.description_invalid_year', ['year' => $requestedYear]) : null,
            $invalidFilterSlugs !== [] ? __('catalog.catalog.search_seo.description_invalid_filters') : null,
        ])->filter()->implode(' ');
        $canonical = $this->catalogCanonicalUrl($request, $activeTaxonomies, $year, $currentPage, $titleContext);
        $hasIndexableLandingShape = ($activeTaxonomies->isEmpty() && $year !== null)
            || ($activeTaxonomies->count() <= 1 && $year === null);
        $robots = $search === '' && $invalidFilterSlugs === [] && ! $invalidYear && $hasIndexableLandingShape && ! $this->hasComplexCatalogQuery($request)
            ? $this->indexRobots()
            : 'noindex,nofollow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';
        $keywords = collect((array) __('catalog.catalog.search_seo.keywords'))
            ->when($titleContext !== null, fn (Collection $items): Collection => $items->push($titleContext->display_title))
            ->merge($activeTaxonomies->pluck('name'))
            ->merge($this->catalogSearchPhrases($search, $year, $activeTaxonomies))
            ->when($year !== null, fn (Collection $items): Collection => $items->push(__('catalog.catalog.search_seo.keyword_year', ['year' => $year])))
            ->filter()
            ->unique()
            ->values();
        $tags = $keywords->take(20)->all();

        return [
            'title' => $title,
            'h1' => $this->catalogSeoHeading($search, $year, $activeTaxonomies),
            'lead' => $this->catalogSeoLead($total, $search, $year, $activeTaxonomies),
            'description' => $this->seoDescription($descriptionParts),
            'keywords' => $keywords->implode(', '),
            'news_keywords' => $keywords->take(10)->implode(', '),
            'canonical' => $canonical,
            'robots' => $robots,
            'prev' => $previousPageUrl,
            'next' => $nextPageUrl,
            'type' => 'website',
            'updated_time' => now()->toAtomString(),
            'section' => __('catalog.navigation.all_titles'),
            'tags' => $tags,
            'seo_text' => $this->catalogSeoText($total, $search, $year, $activeTaxonomies),
            'search_phrases' => $this->catalogSearchPhrases($search, $year, $activeTaxonomies),
            'keyword_clusters' => $this->catalogKeywordClusters($search, $year, $activeTaxonomies),
            'related_links' => $this->catalogRelatedLinks($search, $year, $activeTaxonomies),
            'search_context' => $titleContext === null ? null : [
                'type' => 'title',
                'title' => $titleContext->display_title,
                'slug' => $titleContext->slug,
            ],
            'breadcrumbs' => [
                ['name' => __('catalog.navigation.home'), 'url' => route('home')],
                ['name' => __('catalog.navigation.all_titles'), 'url' => route('titles.index')],
            ],
            'jsonLd' => [
                $this->organizationJsonLd(),
                $this->websiteJsonLd(),
                $this->siteNavigationJsonLd(),
                $this->webPageJsonLd($title, $descriptionParts, $canonical, 'CollectionPage', $tags),
                $this->collectionPageJsonLd($title, $descriptionParts, $canonical),
                $this->itemListJsonLd($pageTitles, $firstItemPosition, $title),
                $this->breadcrumbJsonLd([
                    ['name' => __('catalog.navigation.home'), 'url' => route('home')],
                    ['name' => __('catalog.navigation.all_titles'), 'url' => route('titles.index')],
                ]),
            ],
        ];
    }

    /**
     * @param  Collection<int, covariant object>  $pageItems
     * @return array<string, mixed>
     */
    public function directory(
        CatalogDirectoryDefinition $directory,
        int $totalValues,
        int $totalTitles,
        string $search,
        string $letter,
        string $sort,
        ?int $decade,
        int $currentPage,
        ?string $previousPageUrl,
        ?string $nextPageUrl,
        Collection $pageItems,
        int $firstItemPosition,
    ): array {
        $baseUrl = route($directory->indexRouteName);
        $hasInteractiveFilters = $search !== '' || $letter !== '' || $decade !== null || $sort !== 'name_asc';
        $canonical = ! $hasInteractiveFilters && $currentPage > 1
            ? route($directory->indexRouteName, ['page' => $currentPage])
            : $baseUrl;
        $title = $currentPage > 1
            ? __('catalog.catalog.search_seo.directory_page_title', ['title' => $directory->title, 'page' => $currentPage])
            : $directory->title;
        $description = __('catalog.catalog.search_seo.directory_description', [
            'description' => $directory->description,
            'values' => trans_choice('catalog.directories.counts.values', $totalValues, [
                'count' => Number::format($totalValues, locale: app()->currentLocale()),
            ]),
            'titles' => trans_choice('catalog.counts.results', $totalTitles, [
                'count' => Number::format($totalTitles, locale: app()->currentLocale()),
            ]),
        ]);
        $breadcrumbs = [
            ['name' => __('catalog.navigation.home'), 'url' => route('home')],
            ['name' => __('catalog.navigation.all_titles'), 'url' => route('titles.index')],
            ['name' => $directory->title, 'url' => $baseUrl],
        ];

        return [
            'title' => $title,
            'h1' => $directory->title,
            'lead' => $directory->description,
            'description' => $this->seoDescription($description),
            'canonical' => $canonical,
            'robots' => $hasInteractiveFilters
                ? 'noindex,nofollow,max-image-preview:large,max-snippet:-1,max-video-preview:-1'
                : $this->indexRobots(),
            'prev' => $previousPageUrl,
            'next' => $nextPageUrl,
            'type' => 'website',
            'updated_time' => now()->toAtomString(),
            'section' => __('catalog.directories.label'),
            'tags' => [$directory->title, __('catalog.catalog.search_seo.catalog_tag')],
            'breadcrumbs' => $breadcrumbs,
            'related_links' => [
                ['name' => __('catalog.navigation.all_titles'), 'url' => route('titles.index')],
            ],
            'jsonLd' => [
                $this->webPageJsonLd($title, $description, $canonical, 'CollectionPage', [$directory->title]),
                $this->collectionPageJsonLd($title, $description, $canonical),
                $this->directoryItemListJsonLd($pageItems, $firstItemPosition, $title),
                $this->breadcrumbJsonLd($breadcrumbs),
            ],
        ];
    }

    /**
     * @param  Collection<string, Collection<int, Model>>  $taxonomiesByType
     * @param  Collection<int, Season>  $seasons
     * @return array<string, mixed>
     */
    public function title(
        CatalogTitle $catalogTitle,
        Collection $taxonomiesByType,
        Collection $seasons,
        int $episodeCount,
        int $mediaCount,
        ?LicensedMedia $selectedMedia,
        ?string $selectedMediaUrl,
    ): array {
        $displayTitle = $catalogTitle->display_title ?: __('catalog.navigation.all_titles');
        $genres = $this->taxonomyNames($taxonomiesByType, 'genre');
        $countries = $this->taxonomyNames($taxonomiesByType, 'country');
        $actors = $this->taxonomyNames($taxonomiesByType, 'actor')->take(10);
        $directors = $this->taxonomyNames($taxonomiesByType, 'director')->take(10);
        $ageRatings = $this->taxonomyNames($taxonomiesByType, 'age_rating');
        $fallbackDescription = collect([
            __('catalog.seo.title_fallback_start', ['title' => $displayTitle]),
            $catalogTitle->year ? __('catalog.seo.year', ['year' => $catalogTitle->year]) : null,
            $genres->isNotEmpty() ? __('catalog.seo.genres', ['genres' => $genres->take(5)->implode(', ')]) : null,
            $countries->isNotEmpty() ? __('catalog.seo.countries', ['countries' => $countries->take(3)->implode(', ')]) : null,
            $seasons->count() > 0 ? trans_choice('catalog.counts.seasons', $seasons->count()).'.' : null,
            $episodeCount > 0 ? trans_choice('catalog.counts.episodes', $episodeCount).'.' : null,
            $mediaCount > 0 ? __('catalog.seo.player_available') : null,
        ])->filter()->implode(' ');
        $description = $this->seoDescription($catalogTitle->description ?: $fallbackDescription, 190);
        $pageTitle = __('catalog.seo.title_page', ['title' => $displayTitle]);

        if ($catalogTitle->year) {
            $pageTitle .= __('catalog.seo.title_year_suffix', ['year' => $catalogTitle->year]);
        }

        $displayName = CatalogTitleDisplayName::from($catalogTitle->title, $catalogTitle->original_title);
        $visibleAliases = $catalogTitle->relationLoaded('aliases')
            ? $catalogTitle->aliases
                ->pluck('name')
                ->reject(fn (mixed $name): bool => $displayName->contains($name))
            : collect();
        $alternateNames = collect([$displayName->original])
            ->merge($visibleAliases)
            ->map(fn (mixed $name): string => PlainText::clean($name))
            ->filter()
            ->map(fn (string $name): string => $name)
            ->unique(fn (string $name): string => CatalogTitleDisplayName::comparisonKey($name))
            ->values();
        $rating = $catalogTitle->relationLoaded('ratings')
            ? $catalogTitle->ratings->first(fn ($rating): bool => $rating->rating !== null)
            : null;
        $seriesSchema = $this->withoutEmpty([
            '@context' => 'https://schema.org',
            '@type' => 'TVSeries',
            'name' => $displayTitle,
            'alternateName' => $alternateNames->isNotEmpty() ? $alternateNames->all() : null,
            'description' => $description,
            'url' => route('titles.show', $catalogTitle),
            'mainEntityOfPage' => route('titles.show', $catalogTitle),
            'image' => $catalogTitle->poster_url,
            'isAccessibleForFree' => true,
            'publisher' => $this->organizationJsonLd(false),
            'datePublished' => $catalogTitle->year ? (string) $catalogTitle->year : null,
            'genre' => $genres->isNotEmpty() ? $genres->all() : null,
            'countryOfOrigin' => $countries->map(fn (string $name): array => ['@type' => 'Country', 'name' => $name])->values()->all(),
            'actor' => $actors->map(fn (string $name): array => ['@type' => 'Person', 'name' => $name])->values()->all(),
            'director' => $directors->map(fn (string $name): array => ['@type' => 'Person', 'name' => $name])->values()->all(),
            'contentRating' => $ageRatings->first(),
            'numberOfSeasons' => $seasons->count() > 0 ? $seasons->count() : null,
            'numberOfEpisodes' => $episodeCount > 0 ? $episodeCount : null,
            'containsSeason' => $this->seasonJsonLd($catalogTitle, $seasons),
            'aggregateRating' => $rating ? $this->withoutEmpty([
                '@type' => 'AggregateRating',
                'ratingValue' => (float) $rating->rating,
                'bestRating' => 10,
                'worstRating' => 0,
                'ratingCount' => $rating->votes,
            ]) : null,
        ]);
        $faqItems = $this->titleFaqItems($displayTitle, $catalogTitle->year, $genres, $countries, $seasons->count(), $episodeCount, $mediaCount);
        $usesRussianVocabulary = str_starts_with(app()->currentLocale(), 'ru');
        $keywordCollection = $usesRussianVocabulary
            ? $this->titleKeywordCollection($catalogTitle, $alternateNames, $genres, $countries, $actors, $directors, $seasons->count(), $episodeCount, $mediaCount)
            : collect([$displayTitle])->merge($alternateNames)->merge($genres)->merge($countries)->filter()->unique()->values();
        $searchPhrases = $usesRussianVocabulary
            ? $this->titleSearchPhrases($catalogTitle, $keywordCollection, $genres, $countries, $seasons->count(), $episodeCount, $mediaCount)
            : collect();
        $jsonLd = array_values(array_filter([
            $this->organizationJsonLd(),
            $this->websiteJsonLd(),
            $this->siteNavigationJsonLd(),
            $this->webPageJsonLd($pageTitle, $description, route('titles.show', $catalogTitle), 'VideoObject', $keywordCollection->take(25)->all()),
            $seriesSchema,
            $this->faqJsonLd($faqItems),
            $this->episodeItemListJsonLd($catalogTitle, $seasons),
            $this->breadcrumbJsonLd([
                ['name' => __('catalog.navigation.home'), 'url' => route('home')],
                ['name' => __('catalog.navigation.all_titles'), 'url' => route('titles.index')],
                ['name' => $displayTitle, 'url' => route('titles.show', $catalogTitle)],
            ]),
        ], fn (array $item): bool => $item !== []));

        return [
            'title' => $pageTitle,
            'description' => $description,
            'keywords' => $keywordCollection->take(45)->implode(', '),
            'news_keywords' => $keywordCollection->take(15)->implode(', '),
            'canonical' => route('titles.show', $catalogTitle),
            'image' => $catalogTitle->poster_url,
            'image_alt' => __('catalog.seo.poster_alt', ['title' => $displayTitle]),
            'video' => null,
            'published_time' => $this->sitemapDate($catalogTitle->created_at),
            'updated_time' => $this->sitemapDate($catalogTitle->indexed_at ?: $catalogTitle->updated_at),
            'section' => __('catalog.seo.section'),
            'tags' => $keywordCollection->take(25)->values()->all(),
            'seo_text' => $usesRussianVocabulary ? $this->titleSeoText($catalogTitle, $genres, $countries, $actors, $directors, $seasons->count(), $episodeCount, $mediaCount) : [],
            'search_phrases' => $searchPhrases,
            'keyword_clusters' => $usesRussianVocabulary ? $this->titleKeywordClusters($catalogTitle, $genres, $countries, $actors, $directors, $seasons->count(), $episodeCount, $mediaCount) : [],
            'related_links' => $usesRussianVocabulary ? $this->titleRelatedLinks($catalogTitle, $taxonomiesByType) : [],
            'search_context' => [
                'type' => 'title',
                'title' => $displayTitle,
                'slug' => $catalogTitle->slug,
            ],
            'breadcrumbs' => [
                ['name' => __('catalog.navigation.home'), 'url' => route('home')],
                ['name' => __('catalog.navigation.all_titles'), 'url' => route('titles.index')],
                ['name' => $displayTitle, 'url' => route('titles.show', $catalogTitle)],
            ],
            'faq' => $faqItems,
            'type' => 'video.tv_show',
            'jsonLd' => $jsonLd,
        ];
    }

    private function siteName(): string
    {
        return (string) config('app.name', __('catalog.layout.site_name'));
    }

    private function currentHomeUrl(): string
    {
        $locale = app()->currentLocale();
        $localized = request()->routeIs('localized.*')
            || $locale !== (string) config('catalog-collections.default_locale', 'ru');

        if ($localized && Route::has('localized.home')) {
            return route('localized.home', ['locale' => $locale]);
        }

        return route('home');
    }

    private function indexRobots(): string
    {
        return 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';
    }

    private function seoDescription(?string $value, int $limit = 180): string
    {
        return PlainText::clean($value, $limit);
    }

    /** @param Collection<string, Model> $activeTaxonomies */
    private function catalogCanonicalUrl(Request $request, Collection $activeTaxonomies, ?int $year, int $currentPage, ?CatalogTitle $titleContext = null): string
    {
        $pageQuery = $currentPage > 1 ? ['page' => $currentPage] : [];
        $hasSearch = $this->hasSearchQuery($request);
        $hasComplexQuery = $this->hasComplexCatalogQuery($request);

        if ($activeTaxonomies->count() === 1
            && $year === null
            && $titleContext === null
            && ! $hasSearch
            && ! $hasComplexQuery) {
            $filterType = (string) $activeTaxonomies->keys()->first();
            $taxonomy = $activeTaxonomies->first();

            return route('titles.taxonomy', [
                'type' => $filterType,
                'taxonomy' => (string) $taxonomy->getAttribute('slug'),
                ...$pageQuery,
            ]);
        }

        if ($activeTaxonomies->isEmpty()
            && $year !== null
            && $titleContext === null
            && ! $hasSearch
            && ! $hasComplexQuery) {
            return route('titles.year', ['year' => $year, ...$pageQuery]);
        }

        if ($activeTaxonomies->isEmpty() && $year === null && $titleContext === null && ! $hasSearch && ! $hasComplexQuery) {
            return route('titles.index', $pageQuery);
        }

        return route('titles.index');
    }

    private function hasSearchQuery(Request $request): bool
    {
        $search = $request->query('q');

        return is_scalar($search) && trim((string) $search) !== '';
    }

    private function hasComplexCatalogQuery(Request $request): bool
    {
        $complexKeys = [
            'exclude_country',
            'exclude_genre',
            'year_from',
            'year_to',
            'seasons_min',
            'seasons_max',
            'episodes_min',
            'episodes_max',
            'rating_source',
            'rating_min',
            'votes_min',
            'video',
            'subtitles',
            'quality',
            'updated',
            'letter',
            'per_page',
            'sort',
            'publication_type',
            'decade',
        ];

        if (collect($complexKeys)->contains(fn (string $key): bool => $request->query->has($key))) {
            return true;
        }

        return collect($request->query())->contains(
            fn (mixed $value): bool => is_array($value) && count($value) > 1,
        );
    }

    /**
     * @param  Collection<string, Collection<int, Model>>  $taxonomiesByType
     * @return Collection<int, non-falsy-string>
     */
    private function taxonomyNames(Collection $taxonomiesByType, string $type): Collection
    {
        return $taxonomiesByType
            ->get($type, collect())
            ->pluck('name')
            ->map(fn (mixed $name): string => PlainText::clean($name))
            ->filter()
            ->map(fn (string $name): string => $name)
            ->unique()
            ->values();
    }

    private function taxonomyRecordName(Model $record): string
    {
        $name = $record->getAttribute('name');

        return PlainText::clean(is_scalar($name) ? (string) $name : '');
    }

    private function taxonomyContextPhrase(string $filterType, Model $record): string
    {
        $name = $this->taxonomyRecordName($record);

        if ($name === '') {
            return (string) __('catalog.catalog.filter_context.selected');
        }

        $key = in_array($filterType, [
            'genre', 'country', 'actor', 'director', 'age_rating',
            'translation', 'status', 'network', 'studio', 'tag',
        ], true) ? $filterType : 'default';

        return (string) __("catalog.catalog.filter_context.{$key}", ['name' => $name]);
    }

    /**
     * @param  Collection<string, Model>  $activeTaxonomies
     * @return Collection<int, string>
     */
    private function taxonomyContextPhrases(Collection $activeTaxonomies): Collection
    {
        return $activeTaxonomies
            ->map(fn (Model $record, string $filterType): string => $this->taxonomyContextPhrase($filterType, $record))
            ->values();
    }

    /** @param Collection<string, Model> $activeTaxonomies */
    private function catalogFilteredTitle(Collection $activeTaxonomies, ?int $year = null): string
    {
        if ($activeTaxonomies->isEmpty()) {
            return '';
        }

        $contexts = $this->taxonomyContextPhrases($activeTaxonomies);

        if ($contexts->isEmpty()) {
            return $year === null
                ? (string) __('catalog.catalog.search_seo.filtered_default')
                : (string) __('catalog.catalog.search_seo.filtered_default_year', ['year' => $year]);
        }

        if ($contexts->count() === 1) {
            return $year === null
                ? (string) __('catalog.catalog.search_seo.filtered_single', ['context' => $contexts->first()])
                : (string) __('catalog.catalog.search_seo.filtered_single_year', ['year' => $year, 'context' => $contexts->first()]);
        }

        $suffix = $contexts->implode(', ');

        return $year === null
            ? (string) __('catalog.catalog.search_seo.filtered_multiple', ['context' => $suffix])
            : (string) __('catalog.catalog.search_seo.filtered_multiple_year', ['year' => $year, 'context' => $suffix]);
    }

    /** @param Collection<string, Model> $activeTaxonomies */
    private function catalogFilteredDescription(Collection $activeTaxonomies): string
    {
        $contexts = $this->taxonomyContextPhrases($activeTaxonomies);

        if ($contexts->isEmpty()) {
            return (string) __('catalog.catalog.search_seo.filtered_description_default');
        }

        return (string) __('catalog.catalog.search_seo.filtered_description', ['context' => $contexts->implode(', ')]);
    }

    /** @return array<string, mixed> */
    private function websiteJsonLd(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $this->siteName(),
            'url' => $this->currentHomeUrl(),
            'inLanguage' => str_replace('_', '-', app()->currentLocale()),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => route('search.index').'?q={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ],
            'sameAs' => [
                route('sitemap.index'),
                route('feed'),
            ],
            'keywords' => __('catalog.seo.website_keywords'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function siteNavigationJsonLd(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => __('catalog.navigation.site_navigation'),
            'itemListElement' => [
                [
                    '@type' => 'SiteNavigationElement',
                    'position' => 1,
                    'name' => __('catalog.navigation.home'),
                    'url' => $this->currentHomeUrl(),
                ],
                [
                    '@type' => 'SiteNavigationElement',
                    'position' => 2,
                    'name' => __('catalog.navigation.all_titles'),
                    'url' => route('titles.index'),
                ],
                [
                    '@type' => 'SiteNavigationElement',
                    'position' => 3,
                    'name' => __('catalog.navigation.search'),
                    'url' => route('search.index').'?q={search_term_string}',
                ],
                [
                    '@type' => 'SiteNavigationElement',
                    'position' => 4,
                    'name' => __('catalog.layout.rss_feed'),
                    'url' => route('feed'),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function organizationJsonLd(bool $withContext = true): array
    {
        return $this->withoutEmpty([
            '@context' => $withContext ? 'https://schema.org' : null,
            '@type' => 'Organization',
            'name' => $this->siteName(),
            'url' => $this->currentHomeUrl(),
            'logo' => null,
        ]);
    }

    /**
     * @param  list<string>  $about
     * @return array<string, mixed>
     */
    private function webPageJsonLd(string $name, string $description, string $url, string $type = 'WebPage', array $about = []): array
    {
        return $this->withoutEmpty([
            '@context' => 'https://schema.org',
            '@type' => $type === 'VideoObject' ? 'WebPage' : $type,
            'name' => $name,
            'description' => $this->seoDescription($description),
            'url' => $url,
            'inLanguage' => str_replace('_', '-', app()->currentLocale()),
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => $this->siteName(),
                'url' => $this->currentHomeUrl(),
            ],
            'publisher' => $this->organizationJsonLd(false),
            'mainEntity' => $url,
            'speakable' => [
                '@type' => 'SpeakableSpecification',
                'cssSelector' => ['h1'],
            ],
            'about' => collect($about)
                ->filter()
                ->unique()
                ->take(20)
                ->map(fn (string $name): array => ['@type' => 'Thing', 'name' => $name])
                ->values()
                ->all(),
            'keywords' => collect($about)->filter()->unique()->take(30)->implode(', '),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionPageJsonLd(string $name, string $description, string $url): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $name,
            'description' => $this->seoDescription($description),
            'url' => $url,
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => $this->siteName(),
                'url' => $this->currentHomeUrl(),
            ],
        ];
    }

    /**
     * @param  Collection<int, CatalogTitle>  $titles
     * @return array<string, mixed>
     */
    private function itemListJsonLd(Collection $titles, int $startPosition, string $name): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $name,
            'numberOfItems' => $titles->count(),
            'itemListElement' => $titles
                ->values()
                ->map(fn (CatalogTitle $title, int $index): array => $this->withoutEmpty([
                    '@type' => 'ListItem',
                    'position' => $startPosition + $index,
                    'url' => route('titles.show', $title),
                    'name' => filled($title->display_title)
                        ? (string) $title->display_title
                        : __('catalog.title.untitled'),
                    'image' => $title->poster_url,
                ]))
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, covariant object>  $items
     * @return array<string, mixed>
     */
    private function directoryItemListJsonLd(Collection $items, int $startPosition, string $name): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $name,
            'numberOfItems' => $items->count(),
            'itemListElement' => $items
                ->values()
                ->map(fn (object $item, int $index): array => [
                    '@type' => 'ListItem',
                    'position' => $startPosition + $index,
                    'url' => (string) $item->detail_url,
                    'name' => (string) $item->name,
                ])
                ->all(),
        ];
    }

    /**
     * @param  Collection<string, Model>  $activeTaxonomies
     * @return list<string>
     */
    private function catalogSeoText(int $total, string $search, ?int $year, Collection $activeTaxonomies): array
    {
        $count = trans_choice('catalog.counts.results_found', $total, [
            'count' => Number::format($total, locale: app()->currentLocale()),
        ]);
        $parts = collect([
            __('catalog.catalog.search_seo.text_count', ['count' => $count]),
            $year !== null ? __('catalog.catalog.search_seo.text_year', ['year' => $year]) : null,
            $search !== '' ? __('catalog.catalog.search_seo.text_query') : null,
            $activeTaxonomies->isNotEmpty() ? $this->catalogFilteredDescription($activeTaxonomies) : null,
        ])->filter()->implode(' ');

        return [
            $parts !== '' ? $parts : __('catalog.catalog.search_seo.text_default'),
            __('catalog.catalog.search_seo.text_freshness'),
        ];
    }

    /**
     * @param  Collection<string, Model>  $activeTaxonomies
     * @return list<array{name: string, url: string}>
     */
    private function catalogRelatedLinks(string $search, ?int $year, Collection $activeTaxonomies): array
    {
        $links = collect([
            ['name' => __('catalog.navigation.all_titles'), 'url' => route('titles.index')],
            ['name' => __('catalog.catalog.search_seo.link_year', ['year' => now()->year]), 'url' => route('titles.year', ['year' => now()->year])],
        ]);

        if ($year !== null) {
            $links->push(['name' => __('catalog.catalog.search_seo.link_year', ['year' => $year - 1]), 'url' => route('titles.year', ['year' => $year - 1])]);
            $links->push(['name' => __('catalog.catalog.search_seo.link_year', ['year' => $year + 1]), 'url' => route('titles.year', ['year' => $year + 1])]);
        }

        foreach ($activeTaxonomies as $filterType => $taxonomy) {
            $links->push([
                'name' => $this->catalogFilteredTitle(collect([$filterType => $taxonomy])),
                'url' => route('titles.taxonomy', ['type' => $filterType, 'taxonomy' => (string) $taxonomy->getAttribute('slug')]),
            ]);
        }

        if ($search !== '') {
            $links->push(['name' => __('catalog.catalog.search_seo.link_query', ['query' => $search]), 'url' => route('titles.index', ['q' => $search])]);
        }

        return $links
            ->unique('url')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, covariant string>  $genres
     * @param  Collection<int, covariant string>  $countries
     * @param  Collection<int, covariant string>  $actors
     * @param  Collection<int, covariant string>  $directors
     * @return list<string>
     */
    private function titleSeoText(
        CatalogTitle $catalogTitle,
        Collection $genres,
        Collection $countries,
        Collection $actors,
        Collection $directors,
        int $seasonCount,
        int $episodeCount,
        int $mediaCount,
    ): array {
        $facts = collect([
            $catalogTitle->year ? 'год выхода '.$catalogTitle->year : null,
            $genres->isNotEmpty() ? 'жанры '.$genres->take(5)->implode(', ') : null,
            $countries->isNotEmpty() ? 'страна производства '.$countries->take(3)->implode(', ') : null,
            $directors->isNotEmpty() ? 'режиссеры '.$directors->take(4)->implode(', ') : null,
            $actors->isNotEmpty() ? 'в ролях '.$actors->take(8)->implode(', ') : null,
        ])->filter()->implode('; ');

        return [
            'Страница сериала '.$catalogTitle->display_title.' автоматически собирает описание, постер, сезоны, серии, связи каталога и доступные видео для просмотра онлайн.',
            ($facts !== '' ? 'Основная информация о сериале — '.$facts.'. ' : '').'Сейчас в базе указано сезонов: '.$seasonCount.', серий: '.$episodeCount.', видео-файлов: '.$mediaCount.'.',
            'SEO-данные этой страницы обновляются из импортированной информации: алиасы, оригинальное название, рейтинги, актеры, жанры, страны, сезоны и серии используются для формирования поисковых фраз.',
        ];
    }

    /**
     * @param  Collection<string, Collection<int, Model>>  $taxonomiesByType
     * @return list<array{name: string, url: string}>
     */
    private function titleRelatedLinks(CatalogTitle $catalogTitle, Collection $taxonomiesByType): array
    {
        $links = collect([
            ['name' => 'Все сериалы', 'url' => route('titles.index')],
        ]);

        if ($catalogTitle->year) {
            $links->push(['name' => 'Сериалы '.$catalogTitle->year.' года', 'url' => route('titles.year', ['year' => $catalogTitle->year])]);
        }

        foreach (['genre', 'country', 'actor', 'director', 'age_rating', 'translation'] as $type) {
            foreach ($taxonomiesByType->get($type, collect())->take(3) as $taxonomy) {
                $links->push([
                    'name' => $this->catalogFilteredTitle(collect([$type => $taxonomy])),
                    'url' => route('titles.taxonomy', ['type' => $type, 'taxonomy' => (string) $taxonomy->getAttribute('slug')]),
                ]);
            }
        }

        return $links
            ->unique('url')
            ->take(14)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<string, Model>  $activeTaxonomies
     * @return list<string>
     */
    private function catalogSearchPhrases(string $search, ?int $year, Collection $activeTaxonomies): array
    {
        $base = collect((array) __('catalog.catalog.search_seo.phrases.base'));

        if ($search !== '') {
            $base = $base->merge(collect((array) __('catalog.catalog.search_seo.phrases.query'))
                ->map(fn (string $phrase): string => str_replace(':query', $search, $phrase)));
        }

        if ($year !== null) {
            $base = $base->merge(collect((array) __('catalog.catalog.search_seo.phrases.year'))
                ->map(fn (string $phrase): string => str_replace(':year', (string) $year, $phrase)));
        }

        foreach ($activeTaxonomies as $filterType => $taxonomy) {
            $context = $this->taxonomyContextPhrase($filterType, $taxonomy);

            $base = $base->merge(collect((array) __('catalog.catalog.search_seo.phrases.context'))
                ->map(fn (string $phrase): string => str_replace(':context', $context, $phrase)));
        }

        return $base
            ->filter()
            ->map(fn (string $phrase): string => Str::lower($phrase))
            ->unique()
            ->take(16)
            ->values()
            ->all();
    }

    /** @param Collection<string, Model> $activeTaxonomies */
    private function catalogSeoHeading(string $search, ?int $year, Collection $activeTaxonomies): string
    {
        if ($search !== '') {
            return (string) __('catalog.catalog.search_seo.heading_query', ['query' => $search]);
        }

        if ($activeTaxonomies->isNotEmpty() && $year !== null) {
            return $this->catalogFilteredTitle($activeTaxonomies, $year);
        }

        if ($activeTaxonomies->isNotEmpty()) {
            return $this->catalogFilteredTitle($activeTaxonomies);
        }

        if ($year !== null) {
            return (string) __('catalog.catalog.search_seo.heading_year', ['year' => $year]);
        }

        return (string) __('catalog.catalog.search_seo.heading_all');
    }

    /** @param Collection<string, Model> $activeTaxonomies */
    private function catalogSeoLead(int $total, string $search, ?int $year, Collection $activeTaxonomies): string
    {
        $count = trans_choice('catalog.counts.results', $total, [
            'count' => Number::format($total, locale: app()->currentLocale()),
        ]);
        $scope = collect([
            $year !== null ? __('catalog.catalog.search_seo.lead_year', ['year' => $year]) : null,
            $search !== '' ? __('catalog.catalog.search_seo.lead_query', ['query' => $search]) : null,
            $activeTaxonomies->isNotEmpty() ? $this->taxonomyContextPhrases($activeTaxonomies)->implode(', ') : null,
        ])->filter()->implode(', ');

        return $scope === ''
            ? (string) __('catalog.catalog.search_seo.lead', ['count' => $count])
            : (string) __('catalog.catalog.search_seo.lead_scoped', ['count' => $count, 'scope' => $scope]);
    }

    /**
     * @param  Collection<int, covariant string>  $alternateNames
     * @param  Collection<int, covariant string>  $genres
     * @param  Collection<int, covariant string>  $countries
     * @param  Collection<int, covariant string>  $actors
     * @param  Collection<int, covariant string>  $directors
     * @return Collection<int, non-empty-string>
     */
    private function titleKeywordCollection(
        CatalogTitle $catalogTitle,
        Collection $alternateNames,
        Collection $genres,
        Collection $countries,
        Collection $actors,
        Collection $directors,
        int $seasonCount,
        int $episodeCount,
        int $mediaCount,
    ): Collection {
        $displayTitle = $catalogTitle->display_title;
        $baseNames = collect([$displayTitle, $catalogTitle->original_title])
            ->merge($alternateNames)
            ->filter()
            ->unique()
            ->values();
        $keywords = collect([
            $displayTitle,
            $catalogTitle->original_title,
            'сериал '.$displayTitle,
            'сериал '.$displayTitle.' смотреть онлайн',
            $displayTitle.' онлайн',
            $displayTitle.' все серии',
            $displayTitle.' все сезоны',
            $displayTitle.' в хорошем качестве',
            $displayTitle.' плеер',
            $catalogTitle->year ? $displayTitle.' '.$catalogTitle->year : null,
            $catalogTitle->year ? 'сериал '.$displayTitle.' '.$catalogTitle->year.' смотреть онлайн' : null,
            $seasonCount > 0 ? $displayTitle.' '.$seasonCount.' сезон' : null,
            $episodeCount > 0 ? $displayTitle.' '.$episodeCount.' серий' : null,
            $mediaCount > 0 ? $displayTitle.' смотреть в плеере' : null,
        ])
            ->merge($baseNames)
            ->merge($genres)
            ->merge($countries)
            ->merge($actors)
            ->merge($directors);

        foreach ($baseNames->take(5) as $name) {
            $keywords = $keywords->merge([
                $name.' смотреть онлайн',
                $name.' все серии онлайн',
                $name.' сезоны и серии',
            ]);
        }

        foreach ($genres->take(5) as $genre) {
            $keywords = $keywords->merge([
                $displayTitle.' '.$genre,
                'сериалы '.$genre.' онлайн',
            ]);
        }

        foreach ($countries->take(3) as $country) {
            $keywords = $keywords->merge([
                $displayTitle.' '.$country,
                'сериалы '.$country.' смотреть онлайн',
            ]);
        }

        foreach ($actors->take(5) as $actor) {
            $keywords = $keywords->push($displayTitle.' '.$actor);
        }

        return $keywords
            ->filter()
            ->map(fn (string $keyword): string => trim(preg_replace('/\s+/u', ' ', $keyword) ?: $keyword))
            ->filter(fn (string $keyword): bool => $keyword !== '' && mb_strlen($keyword) <= 120)
            ->map(fn (string $keyword): string => $keyword)
            ->unique(fn (string $keyword): string => Str::lower($keyword))
            ->values();
    }

    /**
     * @param  Collection<int, covariant string>  $keywords
     * @param  Collection<int, covariant string>  $genres
     * @param  Collection<int, covariant string>  $countries
     * @return list<string>
     */
    private function titleSearchPhrases(
        CatalogTitle $catalogTitle,
        Collection $keywords,
        Collection $genres,
        Collection $countries,
        int $seasonCount,
        int $episodeCount,
        int $mediaCount,
    ): array {
        $displayTitle = $catalogTitle->display_title;
        $phrases = collect([
            'смотреть '.$displayTitle.' онлайн',
            $displayTitle.' все серии подряд',
            $displayTitle.' все сезоны онлайн',
            $displayTitle.' бесплатно в плеере',
            $displayTitle.' описание сериала',
            $displayTitle.' актеры и роли',
            $displayTitle.' жанр и страна',
            $catalogTitle->year ? $displayTitle.' '.$catalogTitle->year.' онлайн' : null,
            $seasonCount > 0 ? $displayTitle.' '.$seasonCount.' сезон смотреть' : null,
            $episodeCount > 0 ? $displayTitle.' '.$episodeCount.' серий смотреть' : null,
            $mediaCount > 0 ? $displayTitle.' видео онлайн' : null,
        ])
            ->merge($genres->take(3)->map(fn (string $genre): string => $displayTitle.' '.$genre.' сериал'))
            ->merge($countries->take(2)->map(fn (string $country): string => $displayTitle.' '.$country.' сериал'))
            ->merge($keywords->take(8));

        return $phrases
            ->filter()
            ->map(fn (string $phrase): string => trim(preg_replace('/\s+/u', ' ', $phrase) ?: $phrase))
            ->filter(fn (string $phrase): bool => $phrase !== '' && mb_strlen($phrase) <= 140)
            ->unique(fn (string $phrase): string => Str::lower($phrase))
            ->take(18)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, covariant string>  $genres
     * @param  Collection<int, covariant string>  $countries
     * @return list<array{question: string, answer: string}>
     */
    private function titleFaqItems(
        string $title,
        ?int $year,
        Collection $genres,
        Collection $countries,
        int $seasonCount,
        int $episodeCount,
        int $mediaCount,
    ): array {
        $genreText = $genres->take(5)->implode(', ');
        $countryText = $countries->take(3)->implode(', ');
        $onlineWord = Str::lower(__('catalog.seo.faq.online_word'));
        $watchQuestion = Str::endsWith(Str::lower($title), $onlineWord)
            ? __('catalog.seo.faq.watch_question_without_suffix', ['title' => $title])
            : __('catalog.seo.faq.watch_question', ['title' => $title]);

        return collect([
            [
                'question' => $watchQuestion,
                'answer' => $mediaCount > 0
                    ? __('catalog.seo.faq.watch_available', ['title' => $title])
                    : __('catalog.seo.faq.watch_unavailable', ['title' => $title]),
            ],
            [
                'question' => __('catalog.seo.faq.counts_question', ['title' => $title]),
                'answer' => __('catalog.seo.faq.counts_answer', [
                    'seasons' => trans_choice('catalog.counts.seasons', $seasonCount),
                    'episodes' => trans_choice('catalog.counts.episodes', $episodeCount),
                ]),
            ],
            [
                'question' => __('catalog.seo.faq.facts_question', ['title' => $title]),
                'answer' => collect([
                    $year ? __('catalog.seo.faq.year_fact', ['year' => $year]) : null,
                    $genreText !== '' ? __('catalog.seo.faq.genres_fact', ['genres' => $genreText]) : null,
                    $countryText !== '' ? __('catalog.seo.faq.countries_fact', ['countries' => $countryText]) : null,
                ])->filter()->implode('; ').'.',
            ],
            [
                'question' => __('catalog.seo.faq.information_question', ['title' => $title]),
                'answer' => __('catalog.seo.faq.information_answer'),
            ],
        ])
            ->filter(fn (array $item): bool => trim($item['answer'], " .\t\n\r\0\x0B") !== '')
            ->values()
            ->all();
    }

    /**
     * @param  list<array{question: string, answer: string}>  $items
     * @return array<string, mixed>
     */
    private function faqJsonLd(array $items): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => collect($items)
                ->map(fn (array $item): array => [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $item['answer'],
                    ],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, Season>  $seasons
     * @return list<array<string, mixed>>
     */
    private function seasonJsonLd(CatalogTitle $catalogTitle, Collection $seasons): array
    {
        return $seasons
            ->values()
            ->map(fn (Season $season): array => $this->withoutEmpty([
                '@type' => 'CreativeWorkSeason',
                'name' => __('catalog.release.season', ['number' => $season->number]),
                'seasonNumber' => (int) $season->number,
                'numberOfEpisodes' => $season->relationLoaded('episodes')
                    ? $season->episodes->count()
                    : (int) ($season->available_episodes_count ?? 0),
                'url' => route('titles.show', $catalogTitle).'#season-'.$season->id,
            ]))
            ->all();
    }

    /**
     * @param  Collection<int, Season>  $seasons
     * @return array<string, mixed>
     */
    private function episodeItemListJsonLd(CatalogTitle $catalogTitle, Collection $seasons): array
    {
        $episodes = $seasons
            ->filter(fn (Season $season): bool => $season->relationLoaded('episodes'))
            ->flatMap(fn (Season $season): Collection => $season->episodes
                ->sortBy('number')
                ->values()
                ->map(fn (Episode $episode): array => [
                    'season' => $season,
                    'episode' => $episode,
                ]))
            ->values()
            ->take(100);

        if ($episodes->isEmpty()) {
            return [];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => 'Серии '.$catalogTitle->display_title,
            'numberOfItems' => $episodes->count(),
            'itemListElement' => $episodes
                ->values()
                ->map(fn (array $item, int $index): array => $this->withoutEmpty([
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => $this->withoutEmpty([
                        '@type' => 'TVEpisode',
                        'name' => PlainText::clean($item['episode']->title) ?: __('catalog.release.episode', ['number' => $item['episode']->number]),
                        'episodeNumber' => $item['episode']->number,
                        'partOfSeason' => [
                            '@type' => 'CreativeWorkSeason',
                            'seasonNumber' => (int) $item['season']->number,
                        ],
                        'partOfSeries' => [
                            '@type' => 'TVSeries',
                            'name' => $catalogTitle->display_title,
                        ],
                        'url' => route('titles.show', ['catalogTitle' => $catalogTitle, 'episode' => $item['episode']->id]).'#player',
                    ]),
                ]))
                ->all(),
        ];
    }

    /**
     * @param  list<array{name: string, url: string}>  $items
     * @return array<string, mixed>
     */
    private function breadcrumbJsonLd(array $items): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($items)
                ->values()
                ->map(fn (array $item, int $index): array => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $item['name'],
                    'item' => $item['url'],
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutEmpty(array $data): array
    {
        return array_filter($data, fn (mixed $value): bool => ! ($value === null || $value === '' || $value === []));
    }

    /**
     * @param  Collection<string, Model>  $activeTaxonomies
     * @return list<array{title: string, items: list<string>}>
     */
    private function catalogKeywordClusters(string $search, ?int $year, Collection $activeTaxonomies): array
    {
        $taxonomyContexts = $this->taxonomyContextPhrases($activeTaxonomies);

        return [
            [
                'title' => __('catalog.catalog.search_seo.clusters.watch_title'),
                'items' => collect((array) __('catalog.catalog.search_seo.clusters.watch_items'))
                    ->when($search !== '', fn (Collection $items): Collection => $items->push(__('catalog.catalog.search_seo.clusters.query_item', ['query' => $search])))
                    ->values()
                    ->all(),
            ],
            [
                'title' => __('catalog.catalog.search_seo.clusters.collections_title'),
                'items' => collect((array) __('catalog.catalog.search_seo.clusters.collections_items'))
                    ->merge($taxonomyContexts->map(fn (string $context): string => __('catalog.catalog.search_seo.clusters.context_item', ['context' => $context])))
                    ->values()
                    ->all(),
            ],
            [
                'title' => __('catalog.catalog.search_seo.clusters.years_title'),
                'items' => collect((array) __('catalog.catalog.search_seo.clusters.years_items'))
                    ->when($year !== null, fn (Collection $items): Collection => $items
                        ->push(__('catalog.catalog.search_seo.clusters.year_item', ['year' => $year]))
                        ->push(__('catalog.catalog.search_seo.clusters.year_watch_item', ['year' => $year])))
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * @param  Collection<int, covariant string>  $genres
     * @param  Collection<int, covariant string>  $countries
     * @param  Collection<int, covariant string>  $actors
     * @param  Collection<int, covariant string>  $directors
     * @return list<array{title: string, items: list<string>}>
     */
    private function titleKeywordClusters(
        CatalogTitle $catalogTitle,
        Collection $genres,
        Collection $countries,
        Collection $actors,
        Collection $directors,
        int $seasonCount,
        int $episodeCount,
        int $mediaCount,
    ): array {
        $displayTitle = $catalogTitle->display_title;

        return [
            [
                'title' => 'Смотреть онлайн',
                'items' => collect([
                    $displayTitle.' смотреть онлайн',
                    $displayTitle.' все серии',
                    $displayTitle.' все сезоны',
                    $mediaCount > 0 ? $displayTitle.' смотреть в плеере' : $displayTitle.' видео скоро появится',
                ])->filter()->values()->all(),
            ],
            [
                'title' => 'Информация о сериале',
                'items' => collect([
                    $catalogTitle->year ? $displayTitle.' '.$catalogTitle->year : null,
                    $seasonCount > 0 ? $displayTitle.' '.$seasonCount.' сезон' : null,
                    $episodeCount > 0 ? $displayTitle.' '.$episodeCount.' серий' : null,
                    $genres->isNotEmpty() ? $displayTitle.' '.$genres->first() : null,
                    $countries->isNotEmpty() ? $displayTitle.' '.$countries->first() : null,
                ])->filter()->values()->all(),
            ],
            [
                'title' => 'Люди и связи',
                'items' => collect()
                    ->merge($actors->take(5)->map(fn (string $actor): string => $displayTitle.' '.$actor))
                    ->merge($directors->take(3)->map(fn (string $director): string => $displayTitle.' режиссер '.$director))
                    ->values()
                    ->all(),
            ],
        ];
    }

    private function sitemapDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->toAtomString();
            } catch (\Throwable) {
                return now()->toAtomString();
            }
        }

        return now()->toAtomString();
    }
}
