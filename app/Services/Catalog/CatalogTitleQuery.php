<?php

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogTitleQuery
{
    private const SEARCH_STOP_WORDS = [
        'a', 'an', 'and', 'by', 'for', 'from', 'in', 'of', 'on', 'or', 'the', 'to', 'with',
        'актеры', 'альтернативы', 'без', 'в', 'веб', 'все', 'выхода', 'где', 'год', 'года',
        'дат', 'дата', 'для', 'жанр', 'жанры', 'и', 'какая', 'какие', 'календарь',
        'каталог', 'когда', 'качество', 'качестве', 'лучшие', 'мобильный', 'на', 'новая',
        'новые', 'онлайн', 'описание', 'плеер', 'по',
        'подборка', 'подряд', 'после', 'последняя', 'похожие', 'про', 'расписание', 'роли',
        'русском', 'с', 'сезон', 'сезона', 'сезоны', 'серии', 'серий', 'сериал',
        'сериала', 'сериалы', 'сколько', 'смотреть', 'страна', 'страны', 'тема',
        'темы', 'телефоне', 'хорошем', 'что',
    ];

    public function __construct(private readonly CatalogTaxonomyRegistry $taxonomies) {}

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

        $exactTitleIds = $this->exactTitleSearchIds($terms);

        if ($exactTitleIds->isNotEmpty()) {
            $query->whereKey($exactTitleIds);

            return;
        }

        $query->where(function (Builder $query) use ($search, $terms): void {
            $query->where('title', 'like', "%{$search}%")
                ->orWhere('original_title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%");

            foreach ($this->taxonomies->relationNames() as $relation) {
                $query->orWhereHas($relation, function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%");
                });
            }

            $terms->each(function (string $term) use ($query): void {
                if (preg_match('/^\d{4}$/', $term) === 1) {
                    $query->orWhere('year', (int) $term);
                }

                $this->searchTermVariants($term)->each(function (string $variant) use ($query): void {
                    $query->orWhere('title', 'like', "%{$variant}%")
                        ->orWhere('original_title', 'like', "%{$variant}%")
                        ->orWhere('description', 'like', "%{$variant}%")
                        ->orWhere('slug', 'like', "%{$variant}%");

                    foreach ($this->taxonomies->relationNames() as $relation) {
                        $query->orWhereHas($relation, function (Builder $query) use ($variant): void {
                            $query->where('name', 'like', "%{$variant}%");
                        });
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

        $ids = CatalogTitle::query()
            ->select('id')
            ->where(function (Builder $query) use ($titleTerms): void {
                $titleTerms->each(function (string $term) use ($query): void {
                    $variants = $this->searchTermVariants($term);

                    $query->where(function (Builder $query) use ($variants): void {
                        $variants->each(function (string $variant) use ($query): void {
                            $query->orWhere('title', 'like', "%{$variant}%")
                                ->orWhere('original_title', 'like', "%{$variant}%")
                                ->orWhere('slug', 'like', "%{$variant}%");
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
                ->where(function (Builder $query) use ($variants): void {
                    $variants->each(function (string $variant) use ($query): void {
                        $query->orWhere('title', 'like', "%{$variant}%")
                            ->orWhere('original_title', 'like', "%{$variant}%")
                            ->orWhere('slug', 'like', "%{$variant}%");
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
     * @return Collection<int, string>
     */
    private function searchTerms(string $search): Collection
    {
        $normalized = preg_replace('/[^\pL\pN]+/u', ' ', $search) ?: '';

        return collect(explode(' ', $normalized))
            ->map(fn (string $term): string => trim($term))
            ->filter()
            ->reject(fn (string $term): bool => in_array(mb_strtolower($term), self::SEARCH_STOP_WORDS, true))
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
        foreach ($activeTaxonomies as $filterType => $activeTaxonomy) {
            if ($filterType === $exceptTaxonomyType) {
                continue;
            }

            $relation = $this->taxonomies->relationName($filterType);
            $query->whereHas($relation, function (Builder $query) use ($activeTaxonomy): void {
                $query->whereKey($activeTaxonomy->id);
            });
        }
    }
}
