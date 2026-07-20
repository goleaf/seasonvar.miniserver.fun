<?php

namespace App\Services\Catalog;

use App\DTOs\CatalogTitlesPageContext;
use App\Enums\CatalogSort;
use App\Http\Requests\CatalogTitlesRequest;
use App\Models\CatalogTitle;
use App\Models\Tag;
use App\Models\TagTranslation;
use App\Services\Catalog\Search\CatalogSearchMatchSet;
use App\Services\Catalog\Search\CatalogSearchQueryParser;
use App\Services\Catalog\Search\CatalogSearchState;
use App\Services\Catalog\Search\CatalogSearchSuggestion;
use App\Services\Catalog\Search\CatalogTitleSearch;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Tags\TagPagePresenter;
use App\Services\Tags\TagSeoPresenter;
use App\View\ViewModels\CatalogTitlesViewModel;
use Illuminate\Database\Eloquent\Builder;
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
        private readonly CatalogTitleCardCountLoader $cardCounts,
        private readonly CatalogUserCardStateLoader $cardStates,
        private readonly CatalogCollectionQuery $collections,
        private readonly TagPagePresenter $tagPages,
        private readonly TagSeoPresenter $tagSeo,
    ) {}

    /**
     * @param  array<string, mixed>  $facetSearch
     * @return array<string, mixed>
     */
    public function data(
        CatalogTitlesRequest $request,
        ?string $type = null,
        ?string $taxonomy = null,
        bool $invalidInput = false,
        array $facetSearch = [],
        bool $includeFacets = true,
        bool $includeDescription = true,
    ): array {
        $context = $this->context($request, $type, $taxonomy, $invalidInput);
        $search = $context->search;
        $searchQuery = $context->searchQuery;
        $requestedYear = $context->requestedYear;
        $year = $context->year;
        $invalidYear = $context->invalidYear;
        $titleContext = $context->titleContext;
        $criteria = $context->criteria;
        $sortOption = $context->sortOption;
        $sort = $context->sort;
        $perPage = $context->perPage;
        $filterTypes = $context->filterTypes;
        $activeTaxonomies = $context->activeTaxonomies;
        $selectedTaxonomies = $context->selectedTaxonomies;
        $excludedTaxonomies = $context->excludedTaxonomies;
        $activeFilterSlugs = $context->activeFilterSlugs;
        $invalidFilterSlugs = $context->invalidFilterSlugs;
        $catalogQueryState = $context->catalogQueryState;
        $paginationQuery = $context->paginationQuery;
        $filterView = $context->filterView;

        $cardLoads = $this->taxonomies->cardSummaryLoads();
        $cardColumns = ['id', 'slug', 'title', 'original_title', 'type', 'year', 'poster_url', 'indexed_at'];

        if ($includeDescription) {
            $cardColumns[] = 'description';
        }

        $rankSearch = $searchQuery->isReady();
        $catalogTotal = $rankSearch
            ? $this->query->filteredTitles($criteria, $request->user())->count()
            : null;
        $cardCountQueries = $this->query->publicCardCounts($request->user());
        $cardRelations = array_merge([
            'latestSeason' => fn ($query) => $query->select(['seasons.id', 'seasons.catalog_title_id', 'seasons.number']),
        ], $cardLoads);
        $sortCountKeys = match ($sortOption) {
            CatalogSort::EpisodesDesc => ['episodes'],
            CatalogSort::SeasonsDesc => ['seasons'],
            CatalogSort::VideoDesc => ['licensedMedia as published_media_count'],
            default => [],
        };
        $sortCardCountQueries = array_intersect_key($cardCountQueries, array_flip($sortCountKeys));

        if ($rankSearch) {
            $catalogTitleIds = $this->query->filteredTitles(
                $criteria,
                $request->user(),
                rankSearch: true,
            )->select('catalog_titles.id');

            if ($sortCardCountQueries !== []) {
                $catalogTitleIds->withCount($sortCardCountQueries);
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
                ->with($cardRelations);

            if ($sortCardCountQueries !== []) {
                $catalogTitleQuery->withCount($sortCardCountQueries);
            }

            if (in_array($sortOption, [CatalogSort::KinopoiskRating, CatalogSort::ImdbRating], true)) {
                $catalogTitleQuery->withMax($this->query->ratingAggregates(), 'rating');
            }

            $this->query->sorted($catalogTitleQuery, $sortOption);
            $catalogTitles = $catalogTitleQuery->paginate($perPage)->appends($paginationQuery);
        }
        $catalogTitles->setCollection(
            $this->cardCounts->load($catalogTitles->getCollection(), $request->user()),
        );
        $catalogTitles->setCollection(
            $this->cardStates->load($catalogTitles->getCollection(), $request->user()),
        );
        $suggestions = $catalogTitles->total() === 0 && ! $invalidInput && $titleContext === null
            ? $this->searchSuggestions->forQuery($searchQuery, $request->user())
            : collect();
        $seoRequest = $this->sanitizedSeoRequest($request, $paginationQuery, (int) $catalogTitles->currentPage());

        if ($includeFacets) {
            $facetData = $this->buildFacetData(
                $context,
                $facetSearch,
                $this->titleSearch->materializeMatches($searchQuery),
            );
            $filterTaxonomies = $facetData['filterTaxonomies'];
            $yearBuckets = $facetData['yearBuckets'];
            $publicationTypeOptions = $facetData['publicationTypeOptions'];
            $subtitleOptions = $facetData['subtitleOptions'];
        } else {
            $filterTaxonomies = $this->emptyTaxonomyGroups($filterTypes);
            $yearBuckets = collect();
            $publicationTypeOptions = collect();
            $subtitleOptions = collect();
        }

        $tagPage = $this->tagPages->present(
            $context->routeTaxonomyFilterType,
            $activeTaxonomies,
            (int) $catalogTitles->total(),
        );
        $seo = $this->seo->titles(
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
        );

        if ($tagPage !== null) {
            $seo = $this->tagSeo->present($seo, $tagPage, (int) $catalogTitles->currentPage());
        }

        return [
            'titles' => $catalogTitles,
            'search' => $search,
            'sort' => $sort,
            'perPage' => $perPage,
            'year' => $year,
            'requestedYear' => $requestedYear,
            'invalidYear' => $invalidYear,
            'searchState' => $searchQuery->state->value,
            'insufficientSearch' => $searchQuery->state === CatalogSearchState::Insufficient && $titleContext === null,
            'searchSuggestions' => $suggestions,
            'directorySuggestions' => $this->directories->suggestions($search),
            'collectionSuggestions' => $searchQuery->isReady()
                ? $this->collections->publicSearch($search)
                : collect(),
            'tagPage' => $tagPage,
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
            'seo' => $seo,
        ];
    }

    /**
     * @param  array<string, mixed>  $facetSearch
     * @return array<string, mixed>
     */
    public function facets(
        CatalogTitlesRequest $request,
        ?string $type = null,
        ?string $taxonomy = null,
        bool $invalidInput = false,
        array $facetSearch = [],
    ): array {
        $context = $this->context($request, $type, $taxonomy, $invalidInput);

        return $this->buildFacetData(
            $context,
            $facetSearch,
            $this->titleSearch->materializeMatches($context->searchQuery),
        );
    }

    private function context(
        CatalogTitlesRequest $request,
        ?string $type,
        ?string $taxonomy,
        bool $invalidInput,
    ): CatalogTitlesPageContext {
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
            $requestedFilterSlugs[$type] = collect([
                $taxonomy,
                ...($requestedFilterSlugs[$type] ?? []),
            ])->unique()->values()->all();
            $routeTaxonomyFilterType = $type;
        }

        if ($taxonomy !== null && ! in_array($type, $filterTypes, true)) {
            abort(404);
        }

        $activeTaxonomies = $this->emptyTaxonomyMap();
        $selectedTaxonomies = $this->emptyTaxonomyGroups();
        $selectedTaxonomyIds = [];

        foreach ($requestedFilterSlugs as $filterType => $slugs) {
            if (! in_array($filterType, $filterTypes, true) || $slugs === []) {
                continue;
            }

            $records = $this->taxonomyRecordsQuery($filterType)
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
        $excludedTaxonomies = $this->emptyTaxonomyGroups();

        foreach ($request->excludedFilterSlugs() as $filterType => $slugs) {
            if ($slugs === []) {
                continue;
            }

            $records = $this->taxonomyRecordsQuery($filterType)
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
            ->mapWithKeys(fn (Model $record, string $filterType): array => [
                $filterType => (string) $record->getAttribute('slug'),
            ])
            ->all();
        $criteria = $criteria->withResolvedTaxonomies(
            selected: $selectedTaxonomyIds,
            excluded: $excludedTaxonomyIds,
            invalidYear: $invalidYear,
        );

        if ($invalidInput) {
            $criteria = $criteria->invalidated();
        }

        $catalogQueryState = $this->sanitizedCatalogQueryState(
            $request->catalogQueryState(),
            $filterTypes,
            $selectedFilterSlugs,
            $selectedExcludedFilterSlugs,
            $years,
            $invalidYear,
        );
        $paginationQuery = $this->paginationQuery($catalogQueryState, $search, $sort, $titleContext);
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
            perPage: $perPage,
            catalogQueryState: $catalogQueryState,
            excludedTaxonomies: $excludedTaxonomies,
        );

        return new CatalogTitlesPageContext(
            request: $request,
            search: $search,
            searchQuery: $searchQuery,
            requestedYear: $requestedYear,
            year: $year,
            invalidYear: $invalidYear,
            titleContext: $titleContext,
            criteria: $criteria,
            years: $years,
            sortOption: $sortOption,
            sort: $sort,
            perPage: $perPage,
            filterTypes: $filterTypes,
            routeTaxonomyFilterType: $routeTaxonomyFilterType,
            activeTaxonomies: $activeTaxonomies,
            selectedTaxonomies: $selectedTaxonomies,
            excludedTaxonomies: $excludedTaxonomies,
            activeFilterSlugs: $activeFilterSlugs,
            invalidFilterSlugs: $invalidFilterSlugs,
            catalogQueryState: $catalogQueryState,
            paginationQuery: $paginationQuery,
            filterView: $filterView,
        );
    }

    /**
     * @param  array<string, mixed>  $facetSearch
     * @return array<string, mixed>
     */
    private function buildFacetData(
        CatalogTitlesPageContext $context,
        array $facetSearch,
        ?CatalogSearchMatchSet $searchMatches,
    ): array {
        $request = $context->request;
        $criteria = $context->criteria;
        $filterTypes = $context->filterTypes;
        $selectedTaxonomies = $context->selectedTaxonomies;
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

        $missingSelectedTaxonomies = $this->emptyTaxonomyGroups();
        $filterTaxonomies = $filterTaxonomies->map(function (Collection $items, string $filterType) use ($selectedTaxonomies, $missingSelectedTaxonomies): Collection {
            $selected = $selectedTaxonomies->get($filterType, $this->emptyTaxonomyCollection());
            $itemsById = $items->keyBy(fn (Model $record): int => (int) $record->getKey());
            $selectedWithContext = $selected->map(function (Model $record) use ($itemsById): Model {
                return $itemsById->get((int) $record->getKey(), $record);
            });
            $missingSelectedTaxonomies->put(
                $filterType,
                $selectedWithContext->filter(
                    fn (Model $record): bool => ! array_key_exists('context_titles_count', $record->getAttributes()),
                )->values(),
            );
            $selectedIds = $selected->map(fn (Model $record): int => (int) $record->getKey());
            $remainingItems = $items
                ->reject(fn (Model $record): bool => $selectedIds->contains((int) $record->getKey()))
                ->values();

            return $selectedWithContext->concat($remainingItems)->values();
        });

        $taxonomyContextCounts = $this->query->relationContextCounts(
            $missingSelectedTaxonomies,
            $criteria,
            $request->user(),
            $searchMatches,
        );
        $filterTaxonomies = $filterTaxonomies->map(function (Collection $items, string $filterType) use ($taxonomyContextCounts): Collection {
            return $items->map(function (Model $record) use ($filterType, $taxonomyContextCounts): Model {
                if (! array_key_exists('context_titles_count', $record->getAttributes())) {
                    $record->setAttribute(
                        'context_titles_count',
                        (int) ($taxonomyContextCounts->get($filterType.'|'.$record->getKey()) ?? 0),
                    );
                }

                return $record;
            });
        });

        return [
            'filterView' => $context->filterView,
            'filterTypes' => $filterTypes,
            'selectedTaxonomies' => $selectedTaxonomies,
            'filterTaxonomies' => $filterTaxonomies,
            'yearBuckets' => $this->facets->years($criteria, $context->years, 20, $request->user(), $searchMatches),
            'publicationTypeOptions' => $this->facets->publicationTypes($criteria, $request->user(), $searchMatches),
            'subtitleOptions' => $this->facets->subtitleAvailability($criteria, $request->user(), $searchMatches),
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

    /** @return Builder<Model> */
    private function taxonomyRecordsQuery(string $filterType): Builder
    {
        $modelClass = $this->taxonomies->modelClass($filterType);
        $model = new $modelClass;
        $query = $modelClass::query()->select(['id', 'name', 'slug']);

        if ($model instanceof Tag && Tag::usesCanonicalSchema()) {
            $query
                ->whereIn($model->qualifyColumn('id'), Tag::query()->publiclyEligible()->select('tags.id'))
                ->addSelect([
                    'localized_label' => TagTranslation::query()
                        ->select('label')
                        ->whereColumn('tag_id', $model->qualifyColumn('id'))
                        ->where('locale', app()->getLocale())
                        ->limit(1),
                    'fallback_label' => TagTranslation::query()
                        ->select('label')
                        ->whereColumn('tag_id', $model->qualifyColumn('id'))
                        ->where('locale', (string) config('app.fallback_locale', 'ru'))
                        ->limit(1),
                ]);
        }

        return $query;
    }

    /** @return Collection<string, Model> */
    private function emptyTaxonomyMap(): Collection
    {
        return collect();
    }

    /** @return Collection<int, Model> */
    private function emptyTaxonomyCollection(): Collection
    {
        return collect();
    }

    /**
     * @param  list<string>  $filterTypes
     * @return Collection<string, Collection<int, Model>>
     */
    private function emptyTaxonomyGroups(array $filterTypes = []): Collection
    {
        return collect($filterTypes)->mapWithKeys(
            fn (string $filterType): array => [$filterType => $this->emptyTaxonomyCollection()],
        );
    }
}
