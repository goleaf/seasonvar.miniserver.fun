<?php

namespace App\Services\Catalog;

use App\Enums\CatalogSort;
use App\Http\Requests\CatalogTitlesRequest;
use App\Models\CatalogTitle;
use App\Services\Catalog\Search\CatalogSearchQueryParser;
use App\Services\Catalog\Search\CatalogSearchState;
use App\Services\Catalog\Search\CatalogSearchSuggestion;
use App\Services\Catalog\Search\CatalogTitleSearch;
use App\View\ViewModels\CatalogTitlesViewModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class CatalogTitlesPageBuilder
{
    /**
     * @var array<string, int>
     */
    private const FILTER_LIMITS = [
        'genre' => 80,
        'country' => 80,
        'actor' => 24,
        'director' => 24,
        'age_rating' => 40,
        'translation' => 80,
        'status' => 40,
        'network' => 80,
        'studio' => 80,
        'tag' => 80,
    ];

    public function __construct(
        private readonly CatalogSeoBuilder $seo,
        private readonly CatalogTitleQuery $query,
        private readonly CatalogFacetQuery $facets,
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogDirectoryRegistry $directories,
        private readonly CatalogSearchQueryParser $searchParser,
        private readonly CatalogTitleSearch $titleSearch,
        private readonly CatalogSearchSuggestion $searchSuggestions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function data(
        CatalogTitlesRequest $request,
        ?string $type = null,
        ?string $taxonomy = null,
        bool $invalidInput = false,
        array $facetSearch = [],
        bool $includeFacets = true,
    ): array {
        $search = $request->normalizedSearch();
        $searchQuery = $this->searchParser->parse($search);
        $requestedYear = $request->requestedYear();
        $year = $request->year();
        $invalidYear = $request->invalidYear();
        $titleContextSlug = $request->titleContextSlug();
        $titleContext = $titleContextSlug === null || $titleContextSlug === ''
            ? null
            : $this->query->visibleTo($request->user())
                ->select(['id', 'slug', 'title', 'original_title'])
                ->where('slug', $titleContextSlug)
                ->first();
        $criteria = CatalogTitlesCriteria::fromRequest(
            $request,
            $searchQuery,
            $titleContext?->id,
            $titleContextSlug !== null && $titleContext === null,
        );
        $years = $criteria->years;
        $sortOption = $criteria->sort;
        $sort = $sortOption->value;
        $view = $criteria->view;
        $perPage = $criteria->perPage;
        $filterTypes = $this->taxonomies->filterTypes();
        $legacyType = $request->legacyType($filterTypes);
        $legacyTaxonomy = $request->legacyTaxonomy();
        $routeTaxonomyFilterType = null;
        $selectedFilterSlugs = [];
        $selectedExcludedFilterSlugs = [];

        $requestedFilterSlugs = $request->filterSlugs();

        if ($legacyType !== '' && $legacyTaxonomy !== null && $requestedFilterSlugs[$legacyType] === []) {
            $requestedFilterSlugs[$legacyType] = [$legacyTaxonomy];
        }

        if ($type !== null && in_array($type, $filterTypes, true) && $taxonomy !== null) {
            $requestedFilterSlugs[$type] = [$taxonomy];
            $routeTaxonomyFilterType = $type;
        }

        if ($taxonomy !== null && ! in_array($type, $filterTypes, true)) {
            abort(404);
        }

        $activeTaxonomies = collect();
        $selectedTaxonomies = collect();
        $selectedTaxonomyIds = [];

        foreach ($requestedFilterSlugs as $filterType => $slugs) {
            if (! in_array($filterType, $filterTypes, true) || $slugs === []) {
                continue;
            }

            $modelClass = $this->taxonomies->modelClass($filterType);
            $records = $modelClass::query()
                ->select(['id', 'name', 'slug'])
                ->whereIn('slug', $slugs)
                ->get()
                ->keyBy('slug');

            if ($records->isEmpty()) {
                if ($routeTaxonomyFilterType === $filterType) {
                    abort(404);
                }

                continue;
            }

            $orderedRecords = collect($slugs)
                ->map(fn (string $slug): ?Model => $records->get($slug))
                ->filter()
                ->values();

            $selectedFilterSlugs[$filterType] = $orderedRecords->pluck('slug')->map(fn (mixed $slug): string => (string) $slug)->values()->all();
            $selectedTaxonomyIds[$filterType] = $orderedRecords->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
            $selectedTaxonomies->put($filterType, $orderedRecords);
            $activeTaxonomies->put($filterType, $orderedRecords->first());
        }

        $excludedTaxonomyIds = [];
        $excludedTaxonomies = collect();
        foreach ($request->excludedFilterSlugs() as $filterType => $slugs) {
            if ($slugs === []) {
                continue;
            }

            $modelClass = $this->taxonomies->modelClass($filterType);
            $records = $modelClass::query()
                ->select(['id', 'name', 'slug'])
                ->whereIn('slug', $slugs)
                ->get();

            if ($records->isEmpty()) {
                continue;
            }

            $selectedExcludedFilterSlugs[$filterType] = $records->pluck('slug')->map(fn (mixed $slug): string => (string) $slug)->values()->all();
            $excludedTaxonomyIds[$filterType] = $records->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
            $excludedTaxonomies->put($filterType, $records->values());
        }

        $invalidFilterSlugs = [];
        $activeFilterSlugs = $activeTaxonomies
            ->mapWithKeys(fn (Model $record, string $filterType): array => [$filterType => $record->slug])
            ->all();

        $criteria = $criteria->withResolvedTaxonomies(
            selected: $selectedTaxonomyIds,
            excluded: $excludedTaxonomyIds,
            invalidYear: $invalidYear,
        );

        if ($invalidInput) {
            $criteria = $criteria->invalidated();
        }
        $catalogQueryState = $this->sanitizedCatalogQueryState($request->catalogQueryState(), $filterTypes, $selectedFilterSlugs, $selectedExcludedFilterSlugs, $years, $invalidYear);
        $paginationQuery = $this->paginationQuery($catalogQueryState, $search, $sort, $titleContext);

        $cardLoads = $this->taxonomies->cardSummaryLoads();
        $cardColumns = ['id', 'slug', 'title', 'original_title', 'type', 'year', 'poster_url', 'indexed_at'];

        if ($view === 'list') {
            $cardColumns[] = 'description';
        }

        $rankSearch = $searchQuery->isReady();
        $catalogTotal = $rankSearch
            ? $this->query->filteredTitles($criteria, $request->user())->count()
            : null;
        $cardCounts = $this->query->publicCardCounts($request->user());
        $cardRelations = $view === 'list'
            ? array_merge([
                'latestSeason' => fn ($query) => $query->select(['seasons.id', 'seasons.catalog_title_id', 'seasons.number']),
            ], $cardLoads)
            : $cardLoads;

        if ($rankSearch) {
            $catalogTitleIds = $this->query->filteredTitles(
                $criteria,
                $request->user(),
                rankSearch: true,
            )->select('catalog_titles.id');

            $sortCountKeys = match ($sortOption) {
                CatalogSort::EpisodesDesc => ['episodes'],
                CatalogSort::SeasonsDesc => ['seasons'],
                CatalogSort::VideoDesc => ['licensedMedia as published_media_count'],
                CatalogSort::Popularity => ['episodes', 'licensedMedia as published_media_count'],
                default => [],
            };

            if ($sortCountKeys !== []) {
                $catalogTitleIds->withCount(array_intersect_key($cardCounts, array_flip($sortCountKeys)));
            }

            if (in_array($sortOption, [CatalogSort::KinopoiskRating, CatalogSort::ImdbRating], true)) {
                $catalogTitleIds->withMax($this->query->ratingAggregates(), 'rating');
            }

            $this->query->sorted($catalogTitleIds, $sortOption);
            $catalogTitles = $catalogTitleIds
                ->paginate($perPage, total: $catalogTotal)
                ->appends($paginationQuery);
            $pageIds = $catalogTitles->getCollection()
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->values();
            $cardsById = $pageIds->isEmpty()
                ? collect()
                : CatalogTitle::query()
                    ->select($cardColumns)
                    ->whereKey($pageIds->all())
                    ->with($cardRelations)
                    ->withCount($cardCounts)
                    ->when(
                        in_array($sortOption, [CatalogSort::KinopoiskRating, CatalogSort::ImdbRating], true),
                        fn ($query) => $query->withMax($this->query->ratingAggregates(), 'rating'),
                    )
                    ->get()
                    ->keyBy('id');
            $catalogTitles->setCollection(
                $pageIds
                    ->map(fn (int $id): ?CatalogTitle => $cardsById->get($id))
                    ->filter()
                    ->values(),
            );
        } else {
            $catalogTitleQuery = $this->query->filteredTitles($criteria, $request->user())
                ->select($cardColumns)
                ->with($cardRelations)
                ->withCount($cardCounts);

            if (in_array($sortOption, [CatalogSort::KinopoiskRating, CatalogSort::ImdbRating], true)) {
                $catalogTitleQuery->withMax($this->query->ratingAggregates(), 'rating');
            }

            $this->query->sorted($catalogTitleQuery, $sortOption);
            $catalogTitles = $catalogTitleQuery->paginate($perPage)->appends($paginationQuery);
        }
        $suggestions = $catalogTitles->total() === 0 && ! $invalidInput && $titleContext === null
            ? $this->searchSuggestions->forQuery($searchQuery, $request->user())
            : collect();
        $seoRequest = $this->sanitizedSeoRequest($request, $paginationQuery, (int) $catalogTitles->currentPage());

        if ($includeFacets) {
            $searchMatches = $this->titleSearch->materializeMatches($searchQuery);
            $filterLimits = collect($filterTypes)
                ->mapWithKeys(fn (string $filterType): array => [$filterType => $this->filterLimit($filterType)])
                ->all();
            $facetSearches = collect($facetSearch)
                ->only(['actor', 'director'])
                ->filter(fn (mixed $search): bool => is_string($search))
                ->map(fn (string $search): string => $search)
                ->all();
            $filterTaxonomies = $this->facets->taxonomyGroups(
                $filterTypes,
                $filterLimits,
                $request->user(),
                $facetSearches,
                $criteria,
                $searchMatches,
            );

            $missingSelectedTaxonomies = collect();
            $filterTaxonomies = $filterTaxonomies->map(function (Collection $items, string $filterType) use ($selectedTaxonomies, $missingSelectedTaxonomies): Collection {
                $selected = $selectedTaxonomies->get($filterType, collect());
                $itemsById = $items->keyBy(fn (Model $record): int => (int) $record->id);
                $selectedWithContext = $selected->map(function (Model $record) use ($itemsById): Model {
                    return $itemsById->get((int) $record->id, $record);
                });
                $missingSelectedTaxonomies->put(
                    $filterType,
                    $selectedWithContext->filter(
                        fn (Model $record): bool => ! array_key_exists('context_titles_count', $record->getAttributes()),
                    )->values(),
                );
                $selectedIds = $selected->pluck('id')->map(fn (mixed $id): int => (int) $id);
                $remainingItems = $items
                    ->reject(fn (Model $record): bool => $selectedIds->contains((int) $record->id))
                    ->values();

                return $selectedWithContext->concat($remainingItems)->values();
            });

            $taxonomyContextCounts = $this->query->relationContextCounts($missingSelectedTaxonomies, $criteria, $request->user(), $searchMatches);
            $filterTaxonomies = $filterTaxonomies->map(function (Collection $items, string $filterType) use ($taxonomyContextCounts): Collection {
                return $items->map(function (Model $record) use ($filterType, $taxonomyContextCounts): Model {
                    if (! array_key_exists('context_titles_count', $record->getAttributes())) {
                        $record->context_titles_count = (int) ($taxonomyContextCounts->get($filterType.'|'.$record->id) ?? 0);
                    }

                    return $record;
                });
            });
            $yearBuckets = $this->facets->years($criteria, $years, 20, $request->user(), $searchMatches);
            $publicationTypeOptions = $this->facets->publicationTypes($criteria, $request->user(), $searchMatches);
            $subtitleOptions = $this->facets->subtitleAvailability($criteria, $request->user(), $searchMatches);
        } else {
            $filterTaxonomies = collect($filterTypes)
                ->mapWithKeys(fn (string $filterType): array => [$filterType => collect()]);
            $yearBuckets = collect();
            $publicationTypeOptions = collect();
            $subtitleOptions = collect();
        }

        $filterView = new CatalogTitlesViewModel(
            search: $search,
            sort: $sort,
            year: $year,
            requestedYear: $requestedYear,
            invalidYear: $invalidYear,
            activeTaxonomies: $activeTaxonomies,
            selectedTaxonomies: $selectedTaxonomies,
            activeFilterSlugs: $activeFilterSlugs,
            invalidFilterSlugs: $invalidFilterSlugs,
            titleContext: $titleContext,
            selectedFilterSlugs: $selectedFilterSlugs,
            view: $view,
            perPage: $perPage,
            catalogQueryState: $catalogQueryState,
            excludedTaxonomies: $excludedTaxonomies,
        );

        return [
            'titles' => $catalogTitles,
            'search' => $search,
            'sort' => $sort,
            'view' => $view,
            'perPage' => $perPage,
            'year' => $year,
            'requestedYear' => $requestedYear,
            'invalidYear' => $invalidYear,
            'searchState' => $searchQuery->state->value,
            'insufficientSearch' => $searchQuery->state === CatalogSearchState::Insufficient && $titleContext === null,
            'searchSuggestions' => $suggestions,
            'directorySuggestions' => $this->directories->suggestions($search),
            'titleContext' => $titleContext,
            'selectedTaxonomy' => $activeTaxonomies->first(),
            'activeTaxonomies' => $activeTaxonomies,
            'selectedTaxonomies' => $selectedTaxonomies,
            'excludedTaxonomies' => $excludedTaxonomies,
            'activeFilterSlugs' => $activeFilterSlugs,
            'invalidFilterSlugs' => $invalidFilterSlugs,
            'filterTaxonomies' => $filterTaxonomies,
            'filterTypes' => $filterTypes,
            'filterView' => $filterView,
            'yearBuckets' => $yearBuckets,
            'publicationTypeOptions' => $publicationTypeOptions,
            'subtitleOptions' => $subtitleOptions,
            'facetsLoaded' => $includeFacets,
            'seo' => $this->seo->titles(
                $seoRequest,
                (int) $catalogTitles->total(),
                $search,
                $year,
                $activeTaxonomies,
                $invalidFilterSlugs,
                $invalidYear,
                $requestedYear,
                (int) $catalogTitles->currentPage(),
                $catalogTitles->previousPageUrl(),
                $catalogTitles->nextPageUrl(),
                $catalogTitles->getCollection(),
                (int) ($catalogTitles->firstItem() ?? 1),
                $titleContext,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $catalogQueryState
     * @param  list<string>  $filterTypes
     * @param  array<string, list<string>>  $selectedFilterSlugs
     * @param  array<string, list<string>>  $selectedExcludedFilterSlugs
     * @param  list<int>  $years
     * @return array<string, mixed>
     */
    private function sanitizedCatalogQueryState(array $catalogQueryState, array $filterTypes, array $selectedFilterSlugs, array $selectedExcludedFilterSlugs, array $years, bool $invalidYear): array
    {
        foreach ($filterTypes as $filterType) {
            unset($catalogQueryState[$filterType]);
        }

        unset($catalogQueryState['exclude_country'], $catalogQueryState['exclude_genre']);

        foreach ($selectedFilterSlugs as $filterType => $slugs) {
            if ($slugs !== []) {
                $catalogQueryState[$filterType] = $slugs;
            }
        }

        foreach ($selectedExcludedFilterSlugs as $filterType => $slugs) {
            if ($slugs !== []) {
                $catalogQueryState['exclude_'.$filterType] = $slugs;
            }
        }

        if ($years !== []) {
            $catalogQueryState['year'] = $years;
        } elseif (! $invalidYear) {
            unset($catalogQueryState['year']);
        }

        return $catalogQueryState;
    }

    /**
     * @param  array<string, mixed>  $catalogQueryState
     * @return array<string, mixed>
     */
    private function paginationQuery(array $catalogQueryState, string $search, string $sort, ?CatalogTitle $titleContext): array
    {
        $query = $catalogQueryState;

        if ($search !== '') {
            $query['q'] = $search;
        }

        if ($titleContext !== null) {
            $query['title'] = $titleContext->slug;
        }

        if ($sort !== CatalogSort::Updated->value) {
            $query['sort'] = $sort;
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $paginationQuery
     */
    private function sanitizedSeoRequest(CatalogTitlesRequest $request, array $paginationQuery, int $currentPage): CatalogTitlesRequest
    {
        $seoRequest = clone $request;
        $query = $paginationQuery;

        if ($currentPage > 1) {
            $query['page'] = $currentPage;
        } else {
            unset($query['page']);
        }

        $seoRequest->query->replace($query);

        return $seoRequest;
    }

    private function filterLimit(string $filterType): int
    {
        return self::FILTER_LIMITS[$filterType] ?? 24;
    }
}
