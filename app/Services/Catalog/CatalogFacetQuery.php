<?php

namespace App\Services\Catalog;

use App\Enums\CatalogPublicationType;
use App\Models\CatalogTitle;
use App\Models\Tag;
use App\Models\TagTranslation;
use App\Models\User;
use App\Services\Catalog\Search\CatalogSearchMatchSet;
use App\Services\Catalog\Search\CatalogSearchState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

class CatalogFacetQuery
{
    public function __construct(
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogTitleQuery $titles,
        private readonly CatalogFacetSnapshotCache $snapshots,
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
        ?CatalogSearchMatchSet $searchMatches = null,
    ): Collection {
        $facetQueries = collect($filterTypes)->map(function (string $filterType) use ($criteria, $limits, $searches, $user, $searchMatches): QueryBuilder {
            $modelClass = $this->taxonomies->modelClass($filterType);
            $model = new $modelClass;
            $pivot = $this->taxonomies->pivot($filterType);
            $pivotTable = $pivot['table'];
            $taxonomyPivotKey = $pivot['related_key'];
            $catalogTitlePivotKey = $pivot['title_key'];
            $contextTitles = $criteria === null
                ? $this->titles->visibleTo($user)
                : $this->titles->filteredTitles($criteria->withoutRelation($filterType), $user, searchMatches: $searchMatches);
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
                    $model->qualifyColumn('slug').' as slug',
                    $countsAlias.'.context_titles_count as context_titles_count',
                ]);

            if ($model instanceof Tag && Tag::usesCanonicalSchema()) {
                $query
                    ->whereIn($model->qualifyColumn('id'), Tag::query()->publiclyEligible()->select('tags.id'))
                    ->selectRaw($this->localizedTagNameSql($model->getTable()), $this->localizedTagNameBindings());
            } else {
                $query->addSelect($model->qualifyColumn('name').' as name');
            }

            $query = $this->applySearch($query, $model, $searches[$filterType] ?? null)
                ->orderByDesc($countsAlias.'.context_titles_count');

            if ($model instanceof Tag && Tag::usesCanonicalSchema()) {
                $query->orderBy('name');
            } else {
                $query->orderBy($model->qualifyColumn('name'));
            }

            $query
                ->orderBy($model->qualifyColumn('id'))
                ->limit(max(1, (int) ($limits[$filterType] ?? 1)));

            return DB::query()
                ->fromSub($query, 'bounded_'.$filterType.'_facets')
                ->select(['filter_type', 'id', 'name', 'slug', 'context_titles_count']);
        })->values();
        $unionQuery = $facetQueries->shift();

        if (! $unionQuery instanceof QueryBuilder) {
            return $this->emptyTaxonomyGroups($filterTypes);
        }

        foreach ($facetQueries as $facetQuery) {
            $unionQuery->unionAll($facetQuery);
        }

        $rowsQuery = fn (): array => DB::query()
            ->fromSub($unionQuery, 'catalog_relation_facets')
            ->orderBy('filter_type')
            ->orderByDesc('context_titles_count')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => [
                'filter_type' => (string) $row->filter_type,
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'slug' => (string) $row->slug,
                'context_titles_count' => (int) $row->context_titles_count,
            ])
            ->all();
        $cacheable = $this->isDefaultPublicContext($user, $criteria) && $searches === [];
        $rows = collect($cacheable
            ? $this->snapshots->remember('groups', [
                'signature' => hash('sha256', json_encode([$filterTypes, $limits], JSON_THROW_ON_ERROR)),
                'locale' => app()->getLocale(),
            ], $rowsQuery)
            : $rowsQuery())
            ->groupBy(fn (array $row): string => $row['filter_type']);

        return collect($filterTypes)->mapWithKeys(function (string $filterType) use ($rows): array {
            $modelClass = $this->taxonomies->modelClass($filterType);
            $group = $rows->get($filterType);

            if (! $group instanceof Collection) {
                return [$filterType => $this->emptyTaxonomyCollection()];
            }

            $records = $group->map(function (array $row) use ($modelClass): Model {
                $record = (new $modelClass)->newInstance([], true);
                $record->setRawAttributes([
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'slug' => $row['slug'],
                    'context_titles_count' => $row['context_titles_count'],
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
        bool $refresh = false,
    ): Collection {
        $modelClass = $this->taxonomies->modelClass($filterType);
        $model = new $modelClass;
        $pivot = $this->taxonomies->pivot($filterType);
        $pivotTable = $pivot['table'];
        $taxonomyPivotKey = $pivot['related_key'];
        $catalogTitlePivotKey = $pivot['title_key'];
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
            ->select([
                $model->qualifyColumn('id'),
                $model->qualifyColumn('name'),
                $model->qualifyColumn('slug'),
            ])
            ->addSelect($countAlias.'.context_titles_count')
            ->orderByDesc($countAlias.'.context_titles_count')
            ->orderBy($model->getTable().'.name')
            ->orderBy($model->getTable().'.id');

        if ($model instanceof Tag && Tag::usesCanonicalSchema()) {
            $query
                ->whereIn($model->qualifyColumn('id'), Tag::query()->publiclyEligible()->select('tags.id'))
                ->addSelect([
                    'localized_label' => TagTranslation::query()
                        ->select('label')
                        ->whereColumn('tag_id', $model->qualifyColumn('id'))
                        ->where('locale', app()->getLocale())
                        ->limit(1),
                    'fallback_label' => TagTranslation::query()
                        ->select('label')
                        ->whereColumn('tag_id', $model->qualifyColumn('id'))
                        ->where('locale', (string) config('app.fallback_locale', 'ru'))
                        ->limit(1),
                ]);
        }

        if ($criteria === null) {
            $query->addSelect($countAlias.'.context_titles_count as catalog_titles_count');
        }

        $this->applySearch($query->getQuery(), $model, $search);

        if ($limit !== null) {
            $query->limit($limit);
        }

        if ($user !== null || $criteria !== null || ($search !== null && mb_strlen(Str::squish($search)) >= 2)) {
            return $query->get()->values();
        }

        $rows = $this->snapshots->remember(
            'taxonomy-'.$filterType,
            ['limit' => $limit, 'locale' => app()->getLocale(), 'audience' => 'public'],
            fn (): array => $query->get()
                ->map(fn (Model $record): array => $record->getAttributes())
                ->all(),
            $refresh,
        );

        return collect($rows)->map(function (array $attributes) use ($modelClass): Model {
            $record = (new $modelClass)->newInstance([], true);
            $record->setRawAttributes($attributes, true);

            return $record;
        })->values();
    }

    private function applySearch(QueryBuilder $query, Model $model, ?string $search): QueryBuilder
    {
        $search = Str::limit(Str::squish((string) $search), 80, '');

        if (mb_strlen($search) < 2) {
            return $query;
        }

        $term = str_replace(['%', '_'], '', $search);

        if ($term === '') {
            $query->whereRaw('1 = 0');

            return $query;
        }

        $nameSearch = '%'.$term.'%';
        $slugSearch = '%'.str_replace(['%', '_'], '', Str::slug($search)).'%';

        return $query->where(function ($query) use ($model, $nameSearch, $slugSearch): void {
            $query
                ->where($model->qualifyColumn('name'), 'like', $nameSearch)
                ->orWhere($model->qualifyColumn('slug'), 'like', $slugSearch);

            if ($model instanceof Tag && Tag::usesCanonicalSchema()) {
                $query
                    ->orWhereExists(fn (QueryBuilder $translations): QueryBuilder => $translations
                        ->from('tag_translations')
                        ->selectRaw('1')
                        ->whereColumn('tag_translations.tag_id', 'tags.id')
                        ->whereIn('tag_translations.locale', $this->tagContentLocales())
                        ->where('tag_translations.label', 'like', $nameSearch))
                    ->orWhereExists(fn (QueryBuilder $aliases): QueryBuilder => $aliases
                        ->from('tag_aliases')
                        ->selectRaw('1')
                        ->whereColumn('tag_aliases.tag_id', 'tags.id')
                        ->whereIn('tag_aliases.locale', ['und', ...$this->tagContentLocales()])
                        ->where('tag_aliases.moderation_status', 'approved')
                        ->where('tag_aliases.name', 'like', $nameSearch));
            }
        });
    }

    private function localizedTagNameSql(string $table): string
    {
        return "coalesce((select tag_labels.label from tag_translations as tag_labels where tag_labels.tag_id = {$table}.id and tag_labels.locale = ? limit 1), (select fallback_tag_labels.label from tag_translations as fallback_tag_labels where fallback_tag_labels.tag_id = {$table}.id and fallback_tag_labels.locale = ? limit 1), {$table}.name) as name";
    }

    /** @return list<string> */
    private function localizedTagNameBindings(): array
    {
        return [app()->getLocale(), (string) config('app.fallback_locale', 'ru')];
    }

    /** @return list<string> */
    private function tagContentLocales(): array
    {
        return collect($this->localizedTagNameBindings())
            ->filter(fn (string $locale): bool => in_array($locale, config('tags.supported_locales', []), true))
            ->unique()
            ->values()
            ->all();
    }

    /** @return Collection<int, stdClass> */
    public function publicationTypes(
        CatalogTitlesCriteria $criteria,
        ?User $user = null,
        ?CatalogSearchMatchSet $searchMatches = null,
    ): Collection {
        $rebuild = function () use ($criteria, $user, $searchMatches): array {
            $counts = $this->titles
                ->filteredTitles($criteria->withoutPublicationTypes(), $user, searchMatches: $searchMatches)
                ->select('type')
                ->selectRaw('count(*) as context_titles_count')
                ->groupBy('type')
                ->pluck('context_titles_count', 'type');

            return collect(CatalogPublicationType::cases())
                ->map(function (CatalogPublicationType $type) use ($counts): array {
                    $contextTitlesCount = collect($type->databaseValues())
                        ->sum(fn (string $databaseValue): int => (int) ($counts->get($databaseValue) ?? 0));

                    return [
                        'value' => $type->value,
                        'label' => $type->label(),
                        'context_titles_count' => $contextTitlesCount,
                    ];
                })
                ->values()
                ->all();
        };
        $rows = $this->isDefaultPublicContext($user, $criteria)
            ? $this->snapshots->remember('publication-types', ['locale' => app()->getLocale()], $rebuild)
            : $rebuild();

        return collect($rows)->map(fn (array $row): stdClass => (object) $row)->values();
    }

    /** @return Collection<int, stdClass> */
    public function subtitleAvailability(
        CatalogTitlesCriteria $criteria,
        ?User $user = null,
        ?CatalogSearchMatchSet $searchMatches = null,
    ): Collection {
        $rebuild = function () use ($criteria, $user, $searchMatches): array {
            $counts = $this->titles->subtitleContextCounts($criteria, $user, $searchMatches);

            return [
                [
                    'value' => 'available',
                    'label' => 'Есть',
                    'context_titles_count' => $counts['available'],
                ],
                [
                    'value' => 'missing',
                    'label' => 'Нет',
                    'context_titles_count' => $counts['missing'],
                ],
            ];
        };
        $rows = $this->isDefaultPublicContext($user, $criteria)
            ? $this->snapshots->remember('subtitle-availability', ['locale' => app()->getLocale()], $rebuild)
            : $rebuild();

        return collect($rows)->map(fn (array $row): stdClass => (object) $row)->values();
    }

    /**
     * @param  list<int>  $selectedYears
     * @return Collection<int, stdClass>
     */
    public function years(
        CatalogTitlesCriteria $criteria,
        array $selectedYears,
        int $limit,
        ?User $user = null,
        ?CatalogSearchMatchSet $searchMatches = null,
    ): Collection {
        $context = $this->titles
            ->filteredTitles($criteria->withoutYears(), $user, searchMatches: $searchMatches)
            ->select('year')
            ->selectRaw('count(*) as context_titles_count')
            ->whereNotNull('year')
            ->where('year', '>=', 1900)
            ->where('year', '<=', (int) now()->format('Y') + 1)
            ->groupBy('year');
        $yearBucketsQuery = fn (): array => (clone $context)
            ->orderByDesc('year')
            ->limit($limit)
            ->get()
            ->map(fn (CatalogTitle $bucket): array => [
                'year' => (int) $bucket->year,
                'context_titles_count' => (int) $bucket->getAttribute('context_titles_count'),
            ])
            ->all();
        $yearRows = $selectedYears === [] && $this->isDefaultPublicContext($user, $criteria)
            ? $this->snapshots->remember('years', ['limit' => $limit, 'year' => (int) now()->format('Y')], $yearBucketsQuery)
            : $yearBucketsQuery();
        $yearBuckets = collect($yearRows)->map(fn (array $row): stdClass => (object) $row);

        $selectedYears = collect($selectedYears)
            ->filter(fn (int $year): bool => $year >= 1900 && $year <= ((int) now()->format('Y') + 1))
            ->unique()
            ->sortDesc()
            ->values();

        if ($selectedYears->isEmpty()) {
            return $yearBuckets;
        }

        $visibleYears = $yearBuckets->keyBy(fn (stdClass $bucket): int => (int) $bucket->year);
        $missingYears = $selectedYears
            ->reject(fn (int $year): bool => $visibleYears->has($year))
            ->values();
        $selectedYearBuckets = $missingYears->isEmpty()
            ? collect()
            : (clone $context)
                ->whereIn('year', $missingYears->all())
                ->get()
                ->map(fn (CatalogTitle $bucket): stdClass => (object) [
                    'year' => (int) $bucket->year,
                    'context_titles_count' => (int) $bucket->getAttribute('context_titles_count'),
                ])
                ->keyBy(fn (stdClass $bucket): int => (int) $bucket->year);

        $selectedBuckets = $selectedYears
            ->map(fn (int $selectedYear): stdClass => $visibleYears->get($selectedYear) ?? $selectedYearBuckets->get($selectedYear) ?? (object) [
                'year' => $selectedYear,
                'context_titles_count' => 0,
            ]);
        $remainingBuckets = $yearBuckets
            ->reject(fn (stdClass $bucket): bool => $selectedYears->contains((int) $bucket->year))
            ->values();

        return $selectedBuckets
            ->concat($remainingBuckets)
            ->unique(fn (stdClass $bucket): int => (int) $bucket->year)
            ->values();
    }

    /** @return Collection<int, Model> */
    private function emptyTaxonomyCollection(): Collection
    {
        return collect();
    }

    /**
     * @param  list<string>  $filterTypes
     * @return Collection<string, Collection<int, Model>>
     */
    private function emptyTaxonomyGroups(array $filterTypes): Collection
    {
        return collect($filterTypes)->mapWithKeys(
            fn (string $filterType): array => [$filterType => $this->emptyTaxonomyCollection()],
        );
    }

    private function isDefaultPublicContext(?User $user, ?CatalogTitlesCriteria $criteria): bool
    {
        return $user === null
            && ($criteria === null || (
                ! $criteria->hasContentFilters()
                && $criteria->search->state === CatalogSearchState::Empty
            ));
    }
}
