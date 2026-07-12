<?php

namespace App\Services\Catalog;

use App\Enums\CatalogPublicationType;
use App\Models\CatalogTitle;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogFacetQuery
{
    public function __construct(
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogTitleQuery $titles,
    ) {}

    /** @return Collection<int, Model> */
    public function taxonomies(string $filterType, ?int $limit = null, ?User $user = null, ?string $search = null): Collection
    {
        $modelClass = $this->taxonomies->modelClass($filterType);
        $model = new $modelClass;
        $relation = $model->catalogTitles();
        $pivotTable = $relation->getTable();
        $taxonomyPivotKey = $relation->getForeignPivotKeyName();
        $catalogTitlePivotKey = $relation->getRelatedPivotKeyName();
        $countAlias = 'published_facet_counts';
        $counts = DB::table($pivotTable)
            ->joinSub(
                $this->titles->visibleTo($user)->select('catalog_titles.id'),
                'visible_catalog_titles',
                'visible_catalog_titles.id',
                '=',
                $pivotTable.'.'.$catalogTitlePivotKey,
            )
            ->select($pivotTable.'.'.$taxonomyPivotKey.' as relation_id')
            ->selectRaw('count(distinct visible_catalog_titles.id) as catalog_titles_count')
            ->groupBy($pivotTable.'.'.$taxonomyPivotKey);

        $query = $modelClass::query()
            ->joinSub($counts, $countAlias, $model->getTable().'.id', '=', $countAlias.'.relation_id')
            ->select($model->getTable().'.*')
            ->addSelect($countAlias.'.catalog_titles_count')
            ->orderByDesc($countAlias.'.catalog_titles_count')
            ->orderBy($model->getTable().'.name')
            ->orderBy($model->getTable().'.id');

        $search = Str::limit(Str::squish((string) $search), 80, '');
        if (mb_strlen($search) >= 2) {
            $nameSearch = '%'.str_replace(['%', '_'], '', $search).'%';
            $slugSearch = '%'.str_replace(['%', '_'], '', Str::slug($search)).'%';

            $query->where(function ($query) use ($model, $nameSearch, $slugSearch): void {
                $query
                    ->where($model->getTable().'.name', 'like', $nameSearch)
                    ->orWhere($model->getTable().'.slug', 'like', $slugSearch);
            });
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->values();
    }

    /** @return Collection<int, object{value: string, label: string, titles_count: int}> */
    public function publicationTypes(?User $user = null): Collection
    {
        $counts = $this->titles->visibleTo($user)
            ->select('type')
            ->selectRaw('count(*) as titles_count')
            ->groupBy('type')
            ->pluck('titles_count', 'type');

        return collect(CatalogPublicationType::cases())
            ->map(function (CatalogPublicationType $type) use ($counts): object {
                $titlesCount = collect($type->databaseValues())
                    ->sum(fn (string $databaseValue): int => (int) ($counts->get($databaseValue) ?? 0));

                return (object) [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'titles_count' => $titlesCount,
                ];
            })
            ->filter(fn (object $option): bool => $option->titles_count > 0)
            ->values();
    }

    /**
     * @param  list<int>  $selectedYears
     * @return Collection<int, object>
     */
    public function years(array $selectedYears, int $limit, ?User $user = null): Collection
    {
        $yearBuckets = $this->titles->visibleTo($user)
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
            : $this->titles->visibleTo($user)
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
