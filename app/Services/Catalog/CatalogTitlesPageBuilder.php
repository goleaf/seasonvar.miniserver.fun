<?php

namespace App\Services\Catalog;

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
        $sort = $request->sort()->value;
        $requestedYear = $request->requestedYear();
        $year = $request->year();
        $invalidYear = $request->invalidYear();
        $titleContextSlug = $request->titleContextSlug();
        $titleContext = $titleContextSlug === null || $titleContextSlug === ''
            ? null
            : CatalogTitle::query()->select(['id', 'slug', 'title'])->where('slug', $titleContextSlug)->first();
        $filterTypes = $this->taxonomies->filterTypes();
        $legacyType = $request->legacyType($filterTypes);
        $legacyTaxonomy = $request->legacyTaxonomy();
        $invalidInputFilterSlugs = [];

        if ($legacyType !== '' && $legacyTaxonomy === null) {
            $invalidInputFilterSlugs[$legacyType] = 'invalid';
        }

        $activeFilterSlugs = collect($filterTypes)
            ->mapWithKeys(function (string $filterType) use ($request, $type, $taxonomy, $legacyType, $legacyTaxonomy, &$invalidInputFilterSlugs): array {
                $value = $type === $filterType
                    ? $taxonomy
                    : $request->query($filterType, '');

                if ($value === '' && $legacyType === $filterType && $legacyTaxonomy !== null) {
                    $value = $legacyTaxonomy;
                }

                $value = $request->filterSlug($value);

                if ($value === null) {
                    $invalidInputFilterSlugs[$filterType] = 'invalid';

                    return [];
                }

                return $value === '' ? [] : [$filterType => $value];
            })
            ->all();

        if ($taxonomy !== null && ! in_array($type, $filterTypes, true)) {
            abort(404);
        }

        $activeTaxonomies = collect();

        foreach ($activeFilterSlugs as $filterType => $slug) {
            $modelClass = $this->taxonomies->modelClass($filterType);
            $record = $modelClass::query()
                ->where('slug', $slug)
                ->withCount('catalogTitles')
                ->first();

            if ($record !== null) {
                $activeTaxonomies->put($filterType, $record);
            }
        }

        $invalidFilterSlugs = $invalidInputFilterSlugs + array_diff_key($activeFilterSlugs, $activeTaxonomies->all());
        $activeFilterSlugs = $activeTaxonomies
            ->mapWithKeys(fn (Model $record, string $filterType): array => [$filterType => $record->slug])
            ->all();

        $catalogTitles = $this->query->filteredTitles($activeTaxonomies, $invalidFilterSlugs, $searchQuery, $year, null, $invalidYear, $titleContext?->id)
            ->select(['id', 'slug', 'title', 'original_title', 'type', 'year', 'poster_url', 'indexed_at'])
            ->with($this->taxonomies->cardRelations())
            ->withCount($this->cardCounts());
        $this->applySort($catalogTitles, $sort);
        $catalogTitles = $catalogTitles->paginate(24)->withQueryString();

        $filterTaxonomies = collect($filterTypes)->mapWithKeys(function (string $filterType): array {
            $modelClass = $this->taxonomies->modelClass($filterType);
            $items = $modelClass::query()
                ->withCount('catalogTitles')
                ->orderByDesc('catalog_titles_count')
                ->limit($this->filterLimit($filterType))
                ->get()
                ->filter(fn (Model $record): bool => $record->catalog_titles_count > 0)
                ->values();

            return [$filterType => $items];
        });

        $activeTaxonomies->each(function (Model $activeTaxonomy, string $filterType) use ($filterTaxonomies): void {
            $items = $filterTaxonomies->get($filterType, collect());

            if (! $items->contains(fn (Model $record): bool => $record->id === $activeTaxonomy->id)) {
                $filterTaxonomies->put($filterType, $items->prepend($activeTaxonomy)->values());
            }
        });

        $taxonomyContextCounts = $this->query->relationContextCounts($filterTaxonomies, $activeTaxonomies, $invalidFilterSlugs, $searchQuery, $year, $invalidYear, $titleContext?->id);
        $filterTaxonomies = $filterTaxonomies->map(function (Collection $items, string $filterType) use ($taxonomyContextCounts): Collection {
            return $items->map(function (Model $record) use ($filterType, $taxonomyContextCounts): Model {
                $record->context_titles_count = (int) ($taxonomyContextCounts->get($filterType.'|'.$record->id) ?? 0);

                return $record;
            });
        });
        $yearBuckets = CatalogTitle::query()
            ->select('year')
            ->selectRaw('count(*) as titles_count')
            ->whereNotNull('year')
            ->where('year', '>=', 1900)
            ->where('year', '<=', (int) now()->format('Y') + 1)
            ->groupBy('year')
            ->orderByDesc('year')
            ->limit(20)
            ->get();

        if ($year !== null && ! $yearBuckets->contains(fn (CatalogTitle $bucket): bool => (int) $bucket->year === $year)) {
            $selectedYearBucket = CatalogTitle::query()
                ->select('year')
                ->selectRaw('count(*) as titles_count')
                ->where('year', $year)
                ->groupBy('year')
                ->first();

            $yearBuckets->prepend($selectedYearBucket ?? (object) [
                'year' => $year,
                'titles_count' => 0,
            ]);
        }

        $yearContextCounts = $this->query->filteredTitles($activeTaxonomies, $invalidFilterSlugs, $searchQuery, null, null, $invalidYear, $titleContext?->id)
            ->select('year')
            ->selectRaw('count(*) as context_titles_count')
            ->whereNotNull('year')
            ->where('year', '>=', 1900)
            ->where('year', '<=', (int) now()->format('Y') + 1)
            ->groupBy('year')
            ->pluck('context_titles_count', 'year');

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
            activeFilterSlugs: $activeFilterSlugs,
            invalidFilterSlugs: $invalidFilterSlugs,
            titleContext: $titleContext,
        );

        return [
            'titles' => $catalogTitles,
            'search' => $search,
            'sort' => $sort,
            'year' => $year,
            'requestedYear' => $requestedYear,
            'invalidYear' => $invalidYear,
            'searchState' => $searchQuery->state->value,
            'insufficientSearch' => $searchQuery->state === CatalogSearchState::Insufficient && $titleContext === null,
            'titleContext' => $titleContext,
            'selectedTaxonomy' => $activeTaxonomies->first(),
            'activeTaxonomies' => $activeTaxonomies,
            'activeFilterSlugs' => $activeFilterSlugs,
            'invalidFilterSlugs' => $invalidFilterSlugs,
            'filterTaxonomies' => $filterTaxonomies,
            'filterTypes' => $filterTypes,
            'filterView' => $filterView,
            'yearBuckets' => $yearBuckets,
            'seo' => $this->seo->titles(
                $request,
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

    private function filterLimit(string $filterType): int
    {
        return self::FILTER_LIMITS[$filterType] ?? 24;
    }

    /**
     * @param  Builder<CatalogTitle>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'year_desc' => $query->orderByDesc('year')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            'year_asc' => $query->orderBy('year')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            'episodes_desc' => $query->orderByDesc('episodes_count')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            'title_asc' => $query->orderBy('title')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            'with_video' => $query->orderByDesc('published_media_count')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            default => $query->latest('indexed_at')->orderByDesc('catalog_titles.id'),
        };
    }
}
