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
        $titlePivotKey = $relation->getForeignPivotKeyName();
        $relatedPivotKey = $relation->getRelatedPivotKeyName();
        $countAlias = 'published_facet_counts';
        $counts = DB::table($pivotTable)
            ->join($catalogTitleTable, $catalogTitleTable.'.id', '=', $pivotTable.'.'.$titlePivotKey)
            ->where($catalogTitleTable.'.is_published', true)
            ->select($pivotTable.'.'.$relatedPivotKey.' as relation_id')
            ->selectRaw('count(distinct '.$catalogTitleTable.'.id) as catalog_titles_count')
            ->groupBy($pivotTable.'.'.$relatedPivotKey);

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

    /** @return Collection<int, object> */
    public function years(?int $selectedYear, int $limit): Collection
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

        if ($selectedYear === null || $yearBuckets->contains(fn (CatalogTitle $bucket): bool => (int) $bucket->year === $selectedYear)) {
            return $yearBuckets;
        }

        $selectedYearBucket = CatalogTitle::query()
            ->published()
            ->select('year')
            ->selectRaw('count(*) as titles_count')
            ->where('year', $selectedYear)
            ->groupBy('year')
            ->first();

        return $yearBuckets->prepend($selectedYearBucket ?? (object) [
            'year' => $selectedYear,
            'titles_count' => 0,
        ]);
    }
}
