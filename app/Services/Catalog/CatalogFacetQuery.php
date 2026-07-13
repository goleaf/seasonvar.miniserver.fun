<?php

namespace App\Services\Catalog;

use App\Enums\CatalogPublicationType;
use App\Models\CatalogTitle;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogFacetQuery
{
    public function __construct(
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogTitleQuery $titles,
    ) {}

    /**
     * Build every bounded relation facet in one database round trip.
     *
     * @param  list<string>  $filterTypes
     * @param  array<string, int>  $limits
     * @param  array<string, string>  $searches
     * @return Collection<string, Collection<int, Model>>
     */
    public function taxonomyGroups(
        array $filterTypes,
        array $limits,
        ?User $user = null,
        array $searches = [],
        ?CatalogTitlesCriteria $criteria = null,
    ): Collection {
        $facetQueries = collect($filterTypes)->map(function (string $filterType) use ($criteria, $limits, $searches, $user): QueryBuilder {
            $modelClass = $this->taxonomies->modelClass($filterType);
            $model = new $modelClass;
            $relation = $model->catalogTitles();
            $pivotTable = $relation->getTable();
            $taxonomyPivotKey = $relation->getForeignPivotKeyName();
            $catalogTitlePivotKey = $relation->getRelatedPivotKeyName();
            $contextTitles = $criteria === null
                ? $this->titles->visibleTo($user)
                : $this->titles->filteredTitles($criteria->withoutRelation($filterType), $user);
            $countsAlias = 'facet_counts_'.preg_replace('/[^a-z0-9_]+/i', '_', $filterType);
            $titlesAlias = 'facet_titles_'.preg_replace('/[^a-z0-9_]+/i', '_', $filterType);
            $counts = DB::table($pivotTable)
                ->joinSub(
                    $contextTitles->select('catalog_titles.id'),
                    $titlesAlias,
                    $titlesAlias.'.id',
                    '=',
                    $pivotTable.'.'.$catalogTitlePivotKey,
                )
                ->select($pivotTable.'.'.$taxonomyPivotKey.' as relation_id')
                ->selectRaw('count(distinct '.$titlesAlias.'.id) as context_titles_count')
                ->groupBy($pivotTable.'.'.$taxonomyPivotKey);
            $query = DB::table($model->getTable())
                ->joinSub($counts, $countsAlias, $model->qualifyColumn('id'), '=', $countsAlias.'.relation_id')
                ->selectRaw('? as filter_type', [$filterType])
                ->addSelect([
                    $model->qualifyColumn('id').' as id',
                    $model->qualifyColumn('name').' as name',
                    $model->qualifyColumn('slug').' as slug',
                    $countsAlias.'.context_titles_count as context_titles_count',
                ]);

            $query = $this->applySearch($query, $model, $searches[$filterType] ?? null)
                ->orderByDesc($countsAlias.'.context_titles_count')
                ->orderBy($model->qualifyColumn('name'))
                ->orderBy($model->qualifyColumn('id'))
                ->limit(max(1, (int) ($limits[$filterType] ?? 1)));

            return DB::query()
                ->fromSub($query, 'bounded_'.$filterType.'_facets')
                ->select(['filter_type', 'id', 'name', 'slug', 'context_titles_count']);
        })->values();
        $unionQuery = $facetQueries->shift();

        if (! $unionQuery instanceof QueryBuilder) {
            return collect($filterTypes)->mapWithKeys(fn (string $filterType): array => [$filterType => collect()]);
        }

        foreach ($facetQueries as $facetQuery) {
            $unionQuery->unionAll($facetQuery);
        }

        $rows = DB::query()
            ->fromSub($unionQuery, 'catalog_relation_facets')
            ->orderBy('filter_type')
            ->orderByDesc('context_titles_count')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->groupBy('filter_type');

        return collect($filterTypes)->mapWithKeys(function (string $filterType) use ($rows): array {
            $modelClass = $this->taxonomies->modelClass($filterType);
            $records = $rows->get($filterType, collect())->map(function (object $row) use ($modelClass): Model {
                $record = (new $modelClass)->newInstance([], true);
                $record->setRawAttributes([
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'slug' => (string) $row->slug,
                    'context_titles_count' => (int) $row->context_titles_count,
                ], true);

                return $record;
            })->values();

            return [$filterType => $records];
        });
    }

    /** @return Collection<int, Model> */
    public function taxonomies(
        string $filterType,
        ?int $limit = null,
        ?User $user = null,
        ?string $search = null,
        ?CatalogTitlesCriteria $criteria = null,
    ): Collection {
        $modelClass = $this->taxonomies->modelClass($filterType);
        $model = new $modelClass;
        $relation = $model->catalogTitles();
        $pivotTable = $relation->getTable();
        $taxonomyPivotKey = $relation->getForeignPivotKeyName();
        $catalogTitlePivotKey = $relation->getRelatedPivotKeyName();
        $countAlias = 'context_facet_counts';
        $contextTitles = $criteria === null
            ? $this->titles->visibleTo($user)
            : $this->titles->filteredTitles($criteria->withoutRelation($filterType), $user);
        $counts = DB::table($pivotTable)
            ->joinSub(
                $contextTitles->select('catalog_titles.id'),
                'context_catalog_titles',
                'context_catalog_titles.id',
                '=',
                $pivotTable.'.'.$catalogTitlePivotKey,
            )
            ->select($pivotTable.'.'.$taxonomyPivotKey.' as relation_id')
            ->selectRaw('count(distinct context_catalog_titles.id) as context_titles_count')
            ->groupBy($pivotTable.'.'.$taxonomyPivotKey);

        $query = $modelClass::query()
            ->joinSub($counts, $countAlias, $model->getTable().'.id', '=', $countAlias.'.relation_id')
            ->select($model->getTable().'.*')
            ->addSelect($countAlias.'.context_titles_count')
            ->orderByDesc($countAlias.'.context_titles_count')
            ->orderBy($model->getTable().'.name')
            ->orderBy($model->getTable().'.id');

        if ($criteria === null) {
            $query->addSelect($countAlias.'.context_titles_count as catalog_titles_count');
        }

        $this->applySearch($query, $model, $search);

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->values();
    }

    private function applySearch(QueryBuilder|EloquentBuilder $query, Model $model, ?string $search): QueryBuilder|EloquentBuilder
    {
        $search = Str::limit(Str::squish((string) $search), 80, '');

        if (mb_strlen($search) < 2) {
            return $query;
        }

        $nameSearch = '%'.str_replace(['%', '_'], '', $search).'%';
        $slugSearch = '%'.str_replace(['%', '_'], '', Str::slug($search)).'%';

        return $query->where(function ($query) use ($model, $nameSearch, $slugSearch): void {
            $query
                ->where($model->qualifyColumn('name'), 'like', $nameSearch)
                ->orWhere($model->qualifyColumn('slug'), 'like', $slugSearch);
        });
    }

    /** @return Collection<int, object{value: string, label: string, context_titles_count: int}> */
    public function publicationTypes(CatalogTitlesCriteria $criteria, ?User $user = null): Collection
    {
        $counts = $this->titles
            ->filteredTitles($criteria->withoutPublicationTypes(), $user)
            ->select('type')
            ->selectRaw('count(*) as context_titles_count')
            ->groupBy('type')
            ->pluck('context_titles_count', 'type');

        return collect(CatalogPublicationType::cases())
            ->map(function (CatalogPublicationType $type) use ($counts): object {
                $contextTitlesCount = collect($type->databaseValues())
                    ->sum(fn (string $databaseValue): int => (int) ($counts->get($databaseValue) ?? 0));

                return (object) [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'context_titles_count' => $contextTitlesCount,
                ];
            })
            ->values();
    }

    /** @return Collection<int, object{value: string, label: string, context_titles_count: int}> */
    public function subtitleAvailability(CatalogTitlesCriteria $criteria, ?User $user = null): Collection
    {
        $counts = $this->titles->subtitleContextCounts($criteria, $user);

        return collect([
            (object) [
                'value' => 'available',
                'label' => 'Есть',
                'context_titles_count' => $counts['available'],
            ],
            (object) [
                'value' => 'missing',
                'label' => 'Нет',
                'context_titles_count' => $counts['missing'],
            ],
        ]);
    }

    /**
     * @param  list<int>  $selectedYears
     * @return Collection<int, object>
     */
    public function years(
        CatalogTitlesCriteria $criteria,
        array $selectedYears,
        int $limit,
        ?User $user = null,
    ): Collection {
        $context = $this->titles
            ->filteredTitles($criteria->withoutYears(), $user)
            ->select('year')
            ->selectRaw('count(*) as context_titles_count')
            ->whereNotNull('year')
            ->where('year', '>=', 1900)
            ->where('year', '<=', (int) now()->format('Y') + 1)
            ->groupBy('year');
        $yearBuckets = (clone $context)
            ->orderByDesc('year')
            ->limit($limit)
            ->get();

        $selectedYears = collect($selectedYears)
            ->filter(fn (int $year): bool => $year >= 1900 && $year <= ((int) now()->format('Y') + 1))
            ->unique()
            ->sortDesc()
            ->values();

        if ($selectedYears->isEmpty()) {
            return $yearBuckets;
        }

        $visibleYears = $yearBuckets->keyBy(fn (CatalogTitle $bucket): int => (int) $bucket->year);
        $missingYears = $selectedYears
            ->reject(fn (int $year): bool => $visibleYears->has($year))
            ->values();
        $selectedYearBuckets = $missingYears->isEmpty()
            ? collect()
            : (clone $context)
                ->whereIn('year', $missingYears->all())
                ->get()
                ->keyBy(fn (CatalogTitle $bucket): int => (int) $bucket->year);

        $selectedBuckets = $selectedYears
            ->map(fn (int $selectedYear): object => $visibleYears->get($selectedYear) ?? $selectedYearBuckets->get($selectedYear) ?? (object) [
                'year' => $selectedYear,
                'context_titles_count' => 0,
            ]);
        $remainingBuckets = $yearBuckets
            ->reject(fn (CatalogTitle $bucket): bool => $selectedYears->contains((int) $bucket->year))
            ->values();

        return $selectedBuckets
            ->concat($remainingBuckets)
            ->unique(fn (object $bucket): int => (int) $bucket->year)
            ->values();
    }
}
