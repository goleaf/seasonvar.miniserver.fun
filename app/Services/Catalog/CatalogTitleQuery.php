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
    /** @var array<string, Collection<int, int>> */
    private array $exactTitleIdsCache = [];

    /** @var array<string, Collection<int, string>> */
    private array $legacyVariantsCache = [];

    /** @var array<string, Collection<int, int>> */
    private array $searchCandidateIdsCache = [];

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
        array $years = [],
        array $selectedTaxonomyIds = [],
        array $excludedTaxonomyIds = [],
        array $advancedFilters = [],
    ): Builder {
        $query = CatalogTitle::query()->published();

        if ($invalidFilterSlugs !== [] || $invalidYear) {
            $query->whereRaw('1 = 0');
        }

        if ($search->year !== null) {
            if ($years !== [] && ! in_array($search->year, $years, true)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('year', $search->year);
            }
        } elseif ($years !== []) {
            $query->whereIn('year', $years);
        } elseif ($year !== null) {
            $query->where('year', $year);
        }

        if ($titleContextId !== null) {
            $query->whereKey($titleContextId);
        }

        $this->applySearchFilter($query, $search, $titleContextId);
        $this->applyRelationFilters($query, $activeTaxonomies, $exceptTaxonomyType, $selectedTaxonomyIds, $excludedTaxonomyIds);
        $this->applyAdvancedFilters($query, $advancedFilters);

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
        array $years = [],
        array $selectedTaxonomyIds = [],
        array $excludedTaxonomyIds = [],
        array $advancedFilters = [],
    ): Collection {
        if (! $this->hasRelationContextConstraints(
            $activeTaxonomies,
            $invalidFilterSlugs,
            $search,
            $year,
            $years,
            $titleContextId,
            $selectedTaxonomyIds,
            $excludedTaxonomyIds,
            $advancedFilters,
        )) {
            return $filterTaxonomies
                ->flatMap(fn (Collection $items, string $filterType): Collection => $items->mapWithKeys(
                    fn (Model $record): array => [$filterType.'|'.$record->id => (int) ($record->catalog_titles_count ?? 0)],
                ));
        }

        $visibleIdsByType = $filterTaxonomies
            ->map(fn (Collection $items): Collection => $items->pluck('id')->values())
            ->filter(fn (Collection $ids): bool => $ids->isNotEmpty());

        if ($visibleIdsByType->isEmpty()) {
            return collect();
        }

        $catalogTitleTable = (new CatalogTitle)->getTable();
        $contextQueries = $visibleIdsByType
            ->map(function (Collection $recordIds, string $filterType) use ($activeTaxonomies, $invalidFilterSlugs, $search, $year, $invalidYear, $titleContextId, $years, $selectedTaxonomyIds, $excludedTaxonomyIds, $advancedFilters, $catalogTitleTable) {
                $relationName = $this->taxonomies->relationName($filterType);
                $catalogTitleRelation = (new CatalogTitle)->{$relationName}();
                $pivotTable = $catalogTitleRelation->getTable();
                $titlePivotKey = $catalogTitleRelation->getForeignPivotKeyName();
                $relatedPivotKey = $catalogTitleRelation->getRelatedPivotKeyName();
                $filteredTitlesQuery = $this
                    ->filteredTitles($activeTaxonomies, $invalidFilterSlugs, $search, $year, $filterType, $invalidYear, $titleContextId, $years, $selectedTaxonomyIds, $excludedTaxonomyIds, $advancedFilters)
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

    /**
     * @param  Collection<string, Model>  $activeTaxonomies
     * @param  array<string, list<int>>  $selectedTaxonomyIds
     * @param  array<string, list<int>>  $excludedTaxonomyIds
     * @param  array<string, mixed>  $advancedFilters
     */
    private function hasRelationContextConstraints(
        Collection $activeTaxonomies,
        array $invalidFilterSlugs,
        CatalogSearchQuery $search,
        ?int $year,
        array $years,
        ?int $titleContextId,
        array $selectedTaxonomyIds,
        array $excludedTaxonomyIds,
        array $advancedFilters,
    ): bool {
        $hasAdvancedFilters = collect($advancedFilters)->contains(
            fn (mixed $value): bool => $value !== null && $value !== [],
        );

        return $activeTaxonomies->isNotEmpty()
            || $invalidFilterSlugs !== []
            || $search->state !== CatalogSearchState::Empty
            || $year !== null
            || $years !== []
            || $titleContextId !== null
            || $selectedTaxonomyIds !== []
            || $excludedTaxonomyIds !== []
            || $hasAdvancedFilters;
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

        $query->whereKey($this->searchCandidateIds($search));
    }

    /** @return Collection<int, int> */
    private function searchCandidateIds(CatalogSearchQuery $search): Collection
    {
        if (array_key_exists($search->normalized, $this->searchCandidateIdsCache)) {
            return $this->searchCandidateIdsCache[$search->normalized];
        }

        $exactTitleIds = $this->exactTitleSearchIds($search);
        if ($exactTitleIds->isNotEmpty()) {
            return $this->searchCandidateIdsCache[$search->normalized] = $exactTitleIds;
        }

        $query = CatalogTitle::query()->published()->select('catalog_titles.id');
        $this->applyLegacySearchTerms($query, $search);

        return $this->searchCandidateIdsCache[$search->normalized] = $query
            ->orderBy('catalog_titles.id')
            ->pluck('catalog_titles.id')
            ->values();
    }

    /**
     * @param  Builder<CatalogTitle>  $query
     */
    private function applyLegacySearchTerms(Builder $query, CatalogSearchQuery $search): void
    {
        $catalogTitleTable = (new CatalogTitle)->getTable();

        foreach ($search->terms as $term) {
            $variants = $this->legacyVariants($term);

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
        if (array_key_exists($search->normalized, $this->exactTitleIdsCache)) {
            return $this->exactTitleIdsCache[$search->normalized];
        }

        $variants = $this->legacyVariants($search->phrase())
            ->flatMap(fn (string $variant): array => [$variant, Str::ucfirst($variant)])
            ->unique()
            ->values();

        if ($variants->isEmpty()) {
            return $this->exactTitleIdsCache[$search->normalized] = collect();
        }

        $catalogTitleTable = (new CatalogTitle)->getTable();

        return $this->exactTitleIdsCache[$search->normalized] = CatalogTitle::query()
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

    /** @return Collection<int, string> */
    private function legacyVariants(string $term): Collection
    {
        return $this->legacyVariantsCache[$term]
            ??= collect($this->searchNormalizer->legacyVariants($term))->values();
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
    /**
     * @param  array<string, list<int>>  $selectedTaxonomyIds
     * @param  array<string, list<int>>  $excludedTaxonomyIds
     */
    private function applyRelationFilters(
        Builder $query,
        Collection $activeTaxonomies,
        ?string $exceptTaxonomyType = null,
        array $selectedTaxonomyIds = [],
        array $excludedTaxonomyIds = [],
    ): void {
        $catalogTitleTable = (new CatalogTitle)->getTable();

        $filterTypes = collect(array_keys($selectedTaxonomyIds))
            ->merge(array_keys($excludedTaxonomyIds))
            ->merge($activeTaxonomies->keys())
            ->unique()
            ->values();

        foreach ($filterTypes as $filterType) {
            if ($filterType === $exceptTaxonomyType) {
                continue;
            }

            $relation = (new CatalogTitle)->{$this->taxonomies->relationName($filterType)}();
            $pivotTable = $relation->getTable();
            $titlePivotKey = $relation->getForeignPivotKeyName();
            $relatedPivotKey = $relation->getRelatedPivotKeyName();
            $selectedIds = $selectedTaxonomyIds[$filterType] ?? [];
            $excludedIds = $excludedTaxonomyIds[$filterType] ?? [];

            if ($selectedIds !== []) {
                $query->whereIn(
                    $catalogTitleTable.'.id',
                    DB::table($pivotTable)
                        ->select($pivotTable.'.'.$titlePivotKey)
                        ->whereIn($pivotTable.'.'.$relatedPivotKey, $selectedIds),
                );
            }

            if ($excludedIds !== []) {
                $query->whereNotIn(
                    $catalogTitleTable.'.id',
                    DB::table($pivotTable)
                        ->select($pivotTable.'.'.$titlePivotKey)
                        ->whereIn($pivotTable.'.'.$relatedPivotKey, $excludedIds),
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyAdvancedFilters(Builder $query, array $filters): void
    {
        $yearFrom = $filters['year_from'] ?? null;
        $yearTo = $filters['year_to'] ?? null;

        if ($yearFrom !== null) {
            $query->where('year', '>=', $yearFrom);
        }

        if ($yearTo !== null) {
            $query->where('year', '<=', $yearTo);
        }

        foreach ([['seasons', 'seasons_min', 'seasons_max'], ['episodes', 'episodes_min', 'episodes_max']] as [$relation, $minimumKey, $maximumKey]) {
            if (($filters[$minimumKey] ?? null) !== null) {
                $query->has($relation, '>=', $filters[$minimumKey]);
            }

            if (($filters[$maximumKey] ?? null) !== null) {
                $query->has($relation, '<=', $filters[$maximumKey]);
            }
        }

        $videoAvailability = $filters['video'] ?? null;
        if ($videoAvailability === 'available') {
            $query->whereHas('licensedMedia', fn (Builder $media): Builder => $media->published());
        } elseif ($videoAvailability === 'missing') {
            $query->whereDoesntHave('licensedMedia', fn (Builder $media): Builder => $media->published());
        }

        $subtitleAvailability = $filters['subtitles'] ?? null;
        if ($subtitleAvailability === 'available') {
            $query->whereHas('licensedMedia', fn (Builder $media): Builder => $media->published()->where('has_subtitles', true));
        } elseif ($subtitleAvailability === 'missing') {
            $query->whereDoesntHave('licensedMedia', fn (Builder $media): Builder => $media->published()->where('has_subtitles', true));
        }

        $qualities = $filters['quality'] ?? [];
        if ($qualities !== []) {
            $query->whereHas('licensedMedia', fn (Builder $media): Builder => $media->published()->whereIn('quality', $qualities));
        }

        $updatedAfter = $filters['updated_after'] ?? null;
        if ($updatedAfter !== null) {
            $query->where('indexed_at', '>=', $updatedAfter);
        }

        $letter = Str::upper(trim((string) ($filters['letter'] ?? '')));
        if ($letter !== '') {
            if ($letter === 'Е') {
                $query->where(fn (Builder $titles): Builder => $titles->where('title', 'like', 'Е%')->orWhere('title', 'like', 'Ё%'));
            } elseif ($letter === '#') {
                $query->whereRaw("title NOT GLOB '[A-Za-zА-Яа-яЁё]*'");
            } elseif ($letter === 'LATIN') {
                $query->where('title', 'glob', '[A-Za-z]*');
            } else {
                $query->where('title', 'like', $letter.'%');
            }
        }

        $ratingSource = $filters['rating_source'] ?? null;
        $ratingMin = $filters['rating_min'] ?? null;
        $votesMin = $filters['votes_min'] ?? null;
        if ($ratingSource !== null || $ratingMin !== null || $votesMin !== null) {
            $query->whereHas('ratings', function (Builder $ratings) use ($ratingSource, $ratingMin, $votesMin): void {
                if ($ratingSource !== null) {
                    $ratings->where('provider', $ratingSource);
                }

                if ($ratingMin !== null) {
                    $ratings->where('rating', '>=', $ratingMin);
                }

                if ($votesMin !== null) {
                    $ratings->where('votes', '>=', $votesMin);
                }
            });
        }
    }
}
