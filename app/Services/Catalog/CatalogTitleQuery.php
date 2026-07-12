<?php

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleAlias;
use App\Services\Catalog\Search\CatalogSearchNormalizer;
use App\Services\Catalog\Search\CatalogSearchQuery;
use App\Services\Catalog\Search\CatalogSearchState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogTitleQuery
{
    public function __construct(
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogSearchNormalizer $searchNormalizer,
    ) {}

    /**
     * @param  Collection<string, Model>  $activeTaxonomies
     * @param  array<string, string>  $invalidFilterSlugs
     * @return Builder<CatalogTitle>
     */
    public function filteredTitles(
        Collection $activeTaxonomies,
        array $invalidFilterSlugs,
        CatalogSearchQuery $search,
        ?int $year = null,
        ?string $exceptTaxonomyType = null,
        bool $invalidYear = false,
        ?int $titleContextId = null,
    ): Builder {
        $query = CatalogTitle::query()->published();

        if ($invalidFilterSlugs !== [] || $invalidYear) {
            $query->whereRaw('1 = 0');
        }

        if ($search->year !== null) {
            if ($year !== null && $year !== $search->year) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('year', $search->year);
            }
        } elseif ($year !== null) {
            $query->where('year', $year);
        }

        if ($titleContextId !== null) {
            $query->whereKey($titleContextId);
        }

        $this->applySearchFilter($query, $search, $titleContextId);
        $this->applyRelationFilters($query, $activeTaxonomies, $exceptTaxonomyType);

        return $query;
    }

    /**
     * @param  Collection<string, Collection<int, Model>>  $filterTaxonomies
     * @param  Collection<string, Model>  $activeTaxonomies
     * @param  array<string, string>  $invalidFilterSlugs
     * @return Collection<string, int>
     */
    public function relationContextCounts(
        Collection $filterTaxonomies,
        Collection $activeTaxonomies,
        array $invalidFilterSlugs,
        CatalogSearchQuery $search,
        ?int $year,
        bool $invalidYear,
        ?int $titleContextId = null,
    ): Collection {
        $visibleIdsByType = $filterTaxonomies
            ->map(fn (Collection $items): Collection => $items->pluck('id')->values())
            ->filter(fn (Collection $ids): bool => $ids->isNotEmpty());

        if ($visibleIdsByType->isEmpty()) {
            return collect();
        }

        $catalogTitleTable = (new CatalogTitle)->getTable();
        $contextQueries = $visibleIdsByType
            ->map(function (Collection $recordIds, string $filterType) use ($activeTaxonomies, $invalidFilterSlugs, $search, $year, $invalidYear, $titleContextId, $catalogTitleTable) {
                $relationName = $this->taxonomies->relationName($filterType);
                $catalogTitleRelation = (new CatalogTitle)->{$relationName}();
                $pivotTable = $catalogTitleRelation->getTable();
                $titlePivotKey = $catalogTitleRelation->getForeignPivotKeyName();
                $relatedPivotKey = $catalogTitleRelation->getRelatedPivotKeyName();
                $filteredTitlesQuery = $this
                    ->filteredTitles($activeTaxonomies, $invalidFilterSlugs, $search, $year, $filterType, $invalidYear, $titleContextId)
                    ->select($catalogTitleTable.'.id');
                $alias = 'filtered_titles_'.preg_replace('/[^a-z0-9_]+/i', '_', $filterType);

                return DB::table($pivotTable)
                    ->selectRaw('? as filter_type, '.$pivotTable.'.'.$relatedPivotKey.' as relation_id, count(distinct '.$pivotTable.'.'.$titlePivotKey.') as context_titles_count', [$filterType])
                    ->joinSub($filteredTitlesQuery, $alias, function ($join) use ($alias, $pivotTable, $titlePivotKey): void {
                        $join->on($alias.'.id', '=', $pivotTable.'.'.$titlePivotKey);
                    })
                    ->whereIn($pivotTable.'.'.$relatedPivotKey, $recordIds)
                    ->groupBy($pivotTable.'.'.$relatedPivotKey);
            })
            ->values();
        $unionQuery = $contextQueries->shift();

        foreach ($contextQueries as $contextQuery) {
            $unionQuery->unionAll($contextQuery);
        }

        return DB::query()
            ->fromSub($unionQuery, 'relation_context_counts')
            ->get()
            ->mapWithKeys(fn (object $row): array => [$row->filter_type.'|'.$row->relation_id => (int) $row->context_titles_count]);
    }

    public function mediaQualityRank(?string $quality): int
    {
        return match (Str::lower((string) $quality)) {
            '2160p' => 0,
            '1440p' => 1,
            '1080p' => 2,
            '720p' => 3,
            '480p' => 4,
            '360p' => 5,
            '240p' => 6,
            default => 9,
        };
    }

    /**
     * @param  Builder<CatalogTitle>  $query
     */
    private function applySearchFilter(Builder $query, CatalogSearchQuery $search, ?int $titleContextId): void
    {
        if ($search->state === CatalogSearchState::Empty) {
            return;
        }

        if ($search->state === CatalogSearchState::Insufficient) {
            if ($titleContextId === null) {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        if ($search->terms === []) {
            return;
        }

        $exactTitleIds = $this->exactTitleSearchIds($search);

        if ($exactTitleIds->isNotEmpty()) {
            $query->whereKey($exactTitleIds);

            return;
        }

        $catalogTitleTable = (new CatalogTitle)->getTable();

        foreach ($search->terms as $term) {
            $variants = collect($this->searchNormalizer->legacyVariants($term));

            $query->where(function (Builder $query) use ($variants, $catalogTitleTable): void {
                $variants->each(function (string $variant) use ($query): void {
                    $this->orWhereCatalogTextMatches($query, $variant);
                });

                $query->orWhereIn($catalogTitleTable.'.id', $this->aliasSearchTitleIdsSubquery($variants));

                foreach ($this->taxonomies->relationNames() as $relation) {
                    $query->orWhereIn(
                        $catalogTitleTable.'.id',
                        $this->relationTitleIdsByNameSubquery($relation, $variants),
                    );
                }
            });
        }
    }

    /**
     * @return Collection<int, int>
     */
    private function exactTitleSearchIds(CatalogSearchQuery $search): Collection
    {
        $variants = collect($this->searchNormalizer->legacyVariants($search->phrase()))
            ->flatMap(fn (string $variant): array => [$variant, Str::ucfirst($variant)])
            ->unique()
            ->values();

        if ($variants->isEmpty()) {
            return collect();
        }

        $catalogTitleTable = (new CatalogTitle)->getTable();

        return CatalogTitle::query()
            ->published()
            ->select($catalogTitleTable.'.id')
            ->where(function (Builder $query) use ($catalogTitleTable, $search, $variants): void {
                $query->whereIn('title', $variants)
                    ->orWhereIn('original_title', $variants)
                    ->orWhereIn(
                        $catalogTitleTable.'.id',
                        CatalogTitleAlias::query()
                            ->select('catalog_title_id')
                            ->whereIn('name_hash', $search->exactNameHashes),
                    );
            })
            ->orderBy($catalogTitleTable.'.id')
            ->pluck($catalogTitleTable.'.id')
            ->values();
    }

    /**
     * @param  Collection<int, string>  $variants
     * @return Builder<CatalogTitleAlias>
     */
    private function aliasSearchTitleIdsSubquery(Collection $variants): Builder
    {
        return CatalogTitleAlias::query()
            ->select('catalog_title_id')
            ->where(function (Builder $query) use ($variants): void {
                $variants->each(function (string $variant) use ($query): void {
                    $query->orWhere('name', 'like', "%{$variant}%");
                });
            });
    }

    /**
     * @param  Builder<CatalogTitle>  $query
     */
    private function orWhereCatalogTextMatches(Builder $query, string $variant): void
    {
        $query->orWhere('title', 'like', "%{$variant}%")
            ->orWhere('original_title', 'like', "%{$variant}%")
            ->orWhere('description', 'like', "%{$variant}%")
            ->orWhere('slug', 'like', "%{$variant}%")
            ->orWhere('external_id', 'like', "%{$variant}%");
    }

    /**
     * @param  Collection<int, string>  $variants
     */
    private function relationTitleIdsByNameSubquery(string $relationName, Collection $variants): QueryBuilder
    {
        $relation = (new CatalogTitle)->{$relationName}();
        $pivotTable = $relation->getTable();
        $relatedTable = $relation->getRelated()->getTable();
        $relatedKey = $relation->getRelated()->getKeyName();
        $titlePivotKey = $relation->getForeignPivotKeyName();
        $relatedPivotKey = $relation->getRelatedPivotKeyName();

        return DB::table($pivotTable)
            ->select($pivotTable.'.'.$titlePivotKey)
            ->join($relatedTable, $relatedTable.'.'.$relatedKey, '=', $pivotTable.'.'.$relatedPivotKey)
            ->where(function (QueryBuilder $query) use ($relatedTable, $variants): void {
                $variants->each(function (string $variant) use ($query, $relatedTable): void {
                    $query->orWhere($relatedTable.'.name', 'like', "%{$variant}%");
                });
            });
    }

    /**
     * @param  Builder<CatalogTitle>  $query
     * @param  Collection<string, Model>  $activeTaxonomies
     */
    private function applyRelationFilters(Builder $query, Collection $activeTaxonomies, ?string $exceptTaxonomyType = null): void
    {
        $catalogTitleTable = (new CatalogTitle)->getTable();

        foreach ($activeTaxonomies as $filterType => $activeTaxonomy) {
            if ($filterType === $exceptTaxonomyType) {
                continue;
            }

            $relation = (new CatalogTitle)->{$this->taxonomies->relationName($filterType)}();
            $pivotTable = $relation->getTable();
            $titlePivotKey = $relation->getForeignPivotKeyName();
            $relatedPivotKey = $relation->getRelatedPivotKeyName();

            $query->whereIn(
                $catalogTitleTable.'.id',
                DB::table($pivotTable)
                    ->select($pivotTable.'.'.$titlePivotKey)
                    ->where($pivotTable.'.'.$relatedPivotKey, $activeTaxonomy->getKey()),
            );
        }
    }
}
