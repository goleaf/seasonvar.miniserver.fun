<?php

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CatalogFacetQuery
{
    public function __construct(
        private readonly CatalogTaxonomyRegistry $taxonomies,
    ) {}

    /** @return Collection<int, Model> */
    public function taxonomies(string $filterType, ?int $limit = null): Collection
    {
        $modelClass = $this->taxonomies->modelClass($filterType);
        $model = new $modelClass;
        $relation = $model->catalogTitles();
        $pivotTable = $relation->getTable();
        $catalogTitleTable = (new CatalogTitle)->getTable();
        $taxonomyPivotKey = $relation->getForeignPivotKeyName();
        $catalogTitlePivotKey = $relation->getRelatedPivotKeyName();
        $countAlias = 'published_facet_counts';
        $counts = DB::table($pivotTable)
            ->join($catalogTitleTable, $catalogTitleTable.'.id', '=', $pivotTable.'.'.$catalogTitlePivotKey)
            ->where($catalogTitleTable.'.is_published', true)
            ->select($pivotTable.'.'.$taxonomyPivotKey.' as relation_id')
            ->selectRaw('count(distinct '.$catalogTitleTable.'.id) as catalog_titles_count')
            ->groupBy($pivotTable.'.'.$taxonomyPivotKey);

        $query = $modelClass::query()
            ->joinSub($counts, $countAlias, $model->getTable().'.id', '=', $countAlias.'.relation_id')
            ->select($model->getTable().'.*')
            ->addSelect($countAlias.'.catalog_titles_count')
            ->orderByDesc($countAlias.'.catalog_titles_count')
            ->orderBy($model->getTable().'.name')
            ->orderBy($model->getTable().'.id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->values();
    }

    /**
     * @param  list<int>  $selectedYears
     * @return Collection<int, object>
     */
    public function years(array $selectedYears, int $limit): Collection
    {
        $yearBuckets = CatalogTitle::query()
            ->published()
            ->select('year')
            ->selectRaw('count(*) as titles_count')
            ->whereNotNull('year')
            ->where('year', '>=', 1900)
            ->where('year', '<=', (int) now()->format('Y') + 1)
            ->groupBy('year')
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
            : CatalogTitle::query()
                ->published()
                ->select('year')
                ->selectRaw('count(*) as titles_count')
                ->whereIn('year', $missingYears->all())
                ->groupBy('year')
                ->get()
                ->keyBy(fn (CatalogTitle $bucket): int => (int) $bucket->year);

        $selectedBuckets = $selectedYears
            ->map(fn (int $selectedYear): object => $visibleYears->get($selectedYear) ?? $selectedYearBuckets->get($selectedYear) ?? (object) [
                'year' => $selectedYear,
                'titles_count' => 0,
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
