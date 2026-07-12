<?php

namespace App\Services\Catalog;

use App\Enums\CatalogSort;
use App\Http\Requests\CatalogTitlesRequest;
use App\Models\CatalogTitle;
use App\Services\Catalog\Search\CatalogSearchQueryParser;
use App\Services\Catalog\Search\CatalogSearchState;
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
        private readonly CatalogSearchQueryParser $searchParser,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function data(CatalogTitlesRequest $request, ?string $type = null, ?string $taxonomy = null): array
    {
        $search = $request->normalizedSearch();
        $searchQuery = $this->searchParser->parse($search);
        $requestedYear = $request->requestedYear();
        $year = $request->year();
        $invalidYear = $request->invalidYear();
        $titleContextSlug = $request->titleContextSlug();
        $titleContext = $titleContextSlug === null || $titleContextSlug === ''
            ? null
            : CatalogTitle::query()->select(['id', 'slug', 'title'])->where('slug', $titleContextSlug)->first();
        $criteria = CatalogTitlesCriteria::fromRequest(
            $request,
            $searchQuery,
            $titleContext?->id,
            false,
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
                ->whereIn('slug', $slugs)
                ->withCount(['catalogTitles' => fn (Builder $query): Builder => $query->published()])
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
            $records = $modelClass::query()->whereIn('slug', $slugs)->get();

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

        $advancedFilters = $criteria->queryFilters();
        $catalogQueryState = $this->sanitizedCatalogQueryState($request->catalogQueryState(), $filterTypes, $selectedFilterSlugs, $selectedExcludedFilterSlugs, $years, $invalidYear);
        $paginationQuery = $this->paginationQuery($catalogQueryState, $search, $sort, $titleContext);

        $catalogTitles = $this->query->filteredTitles($activeTaxonomies, $invalidFilterSlugs, $searchQuery, $year, null, $invalidYear, $titleContext?->id, $years, $selectedTaxonomyIds, $excludedTaxonomyIds, $advancedFilters)
            ->select(['id', 'slug', 'title', 'original_title', 'type', 'year', 'poster_url', 'indexed_at'])
            ->with($view === 'list'
                ? array_merge(['latestSeason'], $this->taxonomies->cardRelations())
                : $this->taxonomies->cardRelations())
            ->withCount($this->cardCounts());

        if (in_array($sortOption, [CatalogSort::KinopoiskRating, CatalogSort::ImdbRating], true)) {
            $catalogTitles->withMax($this->ratingAggregates(), 'rating');
        }
        $this->applySort($catalogTitles, $sortOption);
        $catalogTitles = $catalogTitles->paginate($perPage)->appends($paginationQuery);
        $seoRequest = $this->sanitizedSeoRequest($request, $paginationQuery, (int) $catalogTitles->currentPage());

        $filterTaxonomies = collect($filterTypes)->mapWithKeys(function (string $filterType): array {
            return [$filterType => $this->facets->taxonomies($filterType, $this->filterLimit($filterType))];
        });

        $selectedTaxonomies->each(function (Collection $selected, string $filterType) use ($filterTaxonomies): void {
            $items = $filterTaxonomies->get($filterType, collect());
            $selectedIds = $selected->pluck('id')->map(fn (mixed $id): int => (int) $id);
            $remainingItems = $items
                ->reject(fn (Model $record): bool => $selectedIds->contains((int) $record->id))
                ->values();

            $filterTaxonomies->put($filterType, $selected->concat($remainingItems)->values());
        });

        $taxonomyContextCounts = $this->query->relationContextCounts($filterTaxonomies, $activeTaxonomies, $invalidFilterSlugs, $searchQuery, $year, $invalidYear, $titleContext?->id, $years, $selectedTaxonomyIds, $excludedTaxonomyIds, $advancedFilters);
        $filterTaxonomies = $filterTaxonomies->map(function (Collection $items, string $filterType) use ($taxonomyContextCounts): Collection {
            return $items->map(function (Model $record) use ($filterType, $taxonomyContextCounts): Model {
                $record->context_titles_count = (int) ($taxonomyContextCounts->get($filterType.'|'.$record->id) ?? 0);

                return $record;
            });
        });
        $yearBuckets = $this->facets->years($years, 20);

        $hasCatalogContext = $criteria->hasContentFilters()
            || $invalidYear
            || $searchQuery->state !== CatalogSearchState::Empty
            || $selectedTaxonomyIds !== []
            || $excludedTaxonomyIds !== [];

        $yearContextCounts = $hasCatalogContext
            ? $this->query->filteredTitles($activeTaxonomies, $invalidFilterSlugs, $searchQuery, null, null, $invalidYear, $titleContext?->id, [], $selectedTaxonomyIds, $excludedTaxonomyIds, $advancedFilters)
                ->select('year')
                ->selectRaw('count(*) as context_titles_count')
                ->whereNotNull('year')
                ->where('year', '>=', 1900)
                ->where('year', '<=', (int) now()->format('Y') + 1)
                ->groupBy('year')
                ->pluck('context_titles_count', 'year')
            : $yearBuckets->pluck('titles_count', 'year');

        $yearBuckets->each(function (object $bucket) use ($yearContextCounts): void {
            $bucket->context_titles_count = (int) ($yearContextCounts->get((int) $bucket->year) ?? 0);
        });

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

    /**
     * @return array<int|string, string|\Closure(Builder): Builder>
     */
    private function cardCounts(): array
    {
        return [
            'seasons' => fn (Builder $query): Builder => $query->published(),
            'episodes' => fn (Builder $query): Builder => $query
                ->published()
                ->whereHas('season', fn (Builder $query): Builder => $query->published()),
            'licensedMedia as published_media_count' => fn (Builder $query): Builder => $query
                ->published()
                ->forAvailableReleases(null),
        ];
    }

    /** @return array<string, \Closure(Builder): Builder> */
    private function ratingAggregates(): array
    {
        return [
            'ratings as kinopoisk_rating' => fn (Builder $query): Builder => $query->where('provider', 'kinopoisk'),
            'ratings as imdb_rating' => fn (Builder $query): Builder => $query->where('provider', 'imdb'),
        ];
    }

    private function filterLimit(string $filterType): int
    {
        return self::FILTER_LIMITS[$filterType] ?? 24;
    }

    /**
     * @param  Builder<CatalogTitle>  $query
     */
    private function applySort(Builder $query, CatalogSort $sort): void
    {
        match ($sort) {
            CatalogSort::YearDesc => $query->orderByDesc('year')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            CatalogSort::YearAsc => $query->orderBy('year')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            CatalogSort::EpisodesDesc => $query->orderByDesc('episodes_count')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            CatalogSort::SeasonsDesc => $query->orderByDesc('seasons_count')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            CatalogSort::TitleAsc => $query->orderBy('title')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            CatalogSort::TitleDesc => $query->orderByDesc('title')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            CatalogSort::VideoDesc => $query->orderByDesc('published_media_count')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            CatalogSort::KinopoiskRating => $query->orderByDesc('kinopoisk_rating')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            CatalogSort::ImdbRating => $query->orderByDesc('imdb_rating')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            CatalogSort::Popularity => $query->orderByDesc('published_media_count')->orderByDesc('episodes_count')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            CatalogSort::Updated => $query->latest('indexed_at')->orderByDesc('catalog_titles.id'),
        };
    }
}
