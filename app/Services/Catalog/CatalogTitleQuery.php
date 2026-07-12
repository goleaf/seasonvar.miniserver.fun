<?php

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleAlias;
use App\Services\Catalog\Search\CatalogSearchQueryParser;
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
        private readonly CatalogSearchQueryParser $searchParser,
    ) {}

    /**
     * @param  Collection<string, Model>  $activeTaxonomies
     * @param  array<string, string>  $invalidFilterSlugs
     * @return Builder<CatalogTitle>
     */
    public function filteredTitles(
        Collection $activeTaxonomies,
        array $invalidFilterSlugs,
        string $search,
        ?int $year = null,
        ?string $exceptTaxonomyType = null,
        bool $invalidYear = false,
        ?int $titleContextId = null,
    ): Builder {
        $query = CatalogTitle::query();

        if ($invalidFilterSlugs !== [] || $invalidYear) {
            $query->whereRaw('1 = 0');
        }

        if ($year !== null) {
            $query->where('year', $year);
        }

        if ($titleContextId !== null) {
            $query->whereKey($titleContextId);
        }

        $this->applySearchFilter($query, $search);
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
        string $search,
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
    private function applySearchFilter(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $terms = $this->searchTerms($search);

        if ($terms->isEmpty()) {
            return;
        }

        $catalogTitleTable = (new CatalogTitle)->getTable();
        $exactTitleIds = $this->exactTitleSearchIds($terms);

        if ($exactTitleIds->isNotEmpty()) {
            $query->whereKey($exactTitleIds);

            return;
        }

        $query->where(function (Builder $query) use ($search, $terms, $catalogTitleTable): void {
            $this->whereCatalogTextMatches($query, $search, $catalogTitleTable);

            foreach ($this->taxonomies->relationNames() as $relation) {
                $this->orWhereRelationNameMatches($query, $relation, $search, $catalogTitleTable);
            }

            $terms->each(function (string $term) use ($query, $catalogTitleTable): void {
                if (preg_match('/^\d{4}$/', $term) === 1) {
                    $query->orWhere('year', (int) $term);
                }

                $this->searchTermVariants($term)->each(function (string $variant) use ($query, $catalogTitleTable): void {
                    $this->orWhereCatalogTextMatches($query, $variant, $catalogTitleTable);

                    foreach ($this->taxonomies->relationNames() as $relation) {
                        $this->orWhereRelationNameMatches($query, $relation, $variant, $catalogTitleTable);
                    }
                });
            });
        });
    }

    /**
     * @param  Collection<int, string>  $terms
     * @return Collection<int, int>
     */
    private function exactTitleSearchIds(Collection $terms): Collection
    {
        $titleTerms = $terms
            ->reject(fn (string $term): bool => preg_match('/^\d{4}$/', $term) === 1)
            ->values();

        if ($titleTerms->isEmpty()) {
            return collect();
        }

        $exactAliasIds = $this->exactAliasSearchIds($titleTerms);

        if ($exactAliasIds->isNotEmpty() && $exactAliasIds->count() <= 3) {
            return $exactAliasIds->values();
        }

        $catalogTitleTable = (new CatalogTitle)->getTable();
        $ids = CatalogTitle::query()
            ->select('id')
            ->where(function (Builder $query) use ($titleTerms, $catalogTitleTable): void {
                $titleTerms->each(function (string $term) use ($query, $catalogTitleTable): void {
                    $variants = $this->searchTermVariants($term);

                    $query->where(function (Builder $query) use ($variants, $catalogTitleTable): void {
                        $variants->each(function (string $variant) use ($query, $catalogTitleTable): void {
                            $query->orWhere('title', 'like', "%{$variant}%")
                                ->orWhere('original_title', 'like', "%{$variant}%")
                                ->orWhere('slug', 'like', "%{$variant}%")
                                ->orWhere('external_id', 'like', "%{$variant}%")
                                ->orWhereIn($catalogTitleTable.'.id', $this->aliasSearchTitleIdsSubquery(collect([$variant])));
                        });
                    });
                });
            })
            ->orderBy('id')
            ->limit(6)
            ->pluck('id');

        if ($ids->isNotEmpty() && $ids->count() <= 3) {
            return $ids->values();
        }

        foreach ($titleTerms as $term) {
            $variants = $this->searchTermVariants($term);
            $ids = CatalogTitle::query()
                ->select('id')
                ->where(function (Builder $query) use ($variants, $catalogTitleTable): void {
                    $variants->each(function (string $variant) use ($query, $catalogTitleTable): void {
                        $query->orWhere('title', 'like', "%{$variant}%")
                            ->orWhere('original_title', 'like', "%{$variant}%")
                            ->orWhere('slug', 'like', "%{$variant}%")
                            ->orWhere('external_id', 'like', "%{$variant}%")
                            ->orWhereIn($catalogTitleTable.'.id', $this->aliasSearchTitleIdsSubquery(collect([$variant])));
                    });
                })
                ->orderBy('id')
                ->limit(6)
                ->pluck('id');

            if ($ids->isNotEmpty() && $ids->count() <= 3) {
                return $ids->values();
            }
        }

        return collect();
    }

    /**
     * @param  Collection<int, string>  $terms
     * @return Collection<int, int>
     */
    private function exactAliasSearchIds(Collection $terms): Collection
    {
        $phrases = collect([$terms->implode(' ')])
            ->when($terms->count() === 1, fn (Collection $phrases): Collection => $phrases->merge($terms))
            ->flatMap(fn (string $phrase): Collection => $this->searchTermVariants($phrase))
            ->map(fn (string $phrase): string => hash('sha256', Str::lower(Str::squish($phrase))))
            ->filter()
            ->unique()
            ->values();

        if ($phrases->isEmpty()) {
            return collect();
        }

        return CatalogTitleAlias::query()
            ->select('catalog_title_id')
            ->whereIn('name_hash', $phrases)
            ->orderBy('catalog_title_id')
            ->limit(6)
            ->pluck('catalog_title_id')
            ->unique()
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
    private function whereCatalogTextMatches(Builder $query, string $variant, string $catalogTitleTable): void
    {
        $query->where('title', 'like', "%{$variant}%")
            ->orWhere('original_title', 'like', "%{$variant}%")
            ->orWhere('description', 'like', "%{$variant}%")
            ->orWhere('slug', 'like', "%{$variant}%")
            ->orWhere('external_id', 'like', "%{$variant}%")
            ->orWhereIn($catalogTitleTable.'.id', $this->aliasSearchTitleIdsSubquery(collect([$variant])));
    }

    /**
     * @param  Builder<CatalogTitle>  $query
     */
    private function orWhereCatalogTextMatches(Builder $query, string $variant, string $catalogTitleTable): void
    {
        $query->orWhere('title', 'like', "%{$variant}%")
            ->orWhere('original_title', 'like', "%{$variant}%")
            ->orWhere('description', 'like', "%{$variant}%")
            ->orWhere('slug', 'like', "%{$variant}%")
            ->orWhere('external_id', 'like', "%{$variant}%")
            ->orWhereIn($catalogTitleTable.'.id', $this->aliasSearchTitleIdsSubquery(collect([$variant])));
    }

    /**
     * @param  Builder<CatalogTitle>  $query
     */
    private function orWhereRelationNameMatches(Builder $query, string $relationName, string $variant, string $catalogTitleTable): void
    {
        $query->orWhereIn($catalogTitleTable.'.id', $this->relationTitleIdsByNameSubquery($relationName, $variant));
    }

    private function relationTitleIdsByNameSubquery(string $relationName, string $variant): QueryBuilder
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
            ->where($relatedTable.'.name', 'like', "%{$variant}%");
    }

    /**
     * @return Collection<int, string>
     */
    private function searchTerms(string $search): Collection
    {
        $normalized = preg_replace('/[^\pL\pN]+/u', ' ', $search) ?: '';

        return collect(explode(' ', $normalized))
            ->map(fn (string $term): string => trim($term))
            ->filter()
            ->reject(fn (string $term): bool => $this->searchParser->isStopWord(mb_strtolower($term)))
            ->filter(fn (string $term): bool => preg_match('/^\d{4}$/', $term) === 1 || mb_strlen($term) >= 3)
            ->unique(fn (string $term): string => mb_strtolower($term))
            ->take(8)
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    private function searchTermVariants(string $term): Collection
    {
        return collect([
            $term,
            mb_strtolower($term),
            str_replace(['ё', 'Ё'], ['е', 'Е'], $term),
            mb_convert_case($term, MB_CASE_TITLE, 'UTF-8'),
            mb_strtoupper($term),
        ])
            ->map(fn (string $variant): string => trim($variant))
            ->filter()
            ->unique()
            ->values();
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
