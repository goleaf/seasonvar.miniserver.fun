<?php

namespace App\Services\Catalog;

use App\Enums\CatalogSort;
use App\Enums\CatalogPublicationType;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleAlias;
use App\Models\CatalogTitleRating;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
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
    /** @var array<string, bool> */
    private array $exactMatchExistsCache = [];

    /** @var array<string, Collection<int, string>> */
    private array $legacyVariantsCache = [];

    public function __construct(
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogSearchNormalizer $searchNormalizer,
    ) {}

    /**
     * @return Builder<CatalogTitle>
     */
    public function visibleTo(?User $user): Builder
    {
        return $this->constrainVisible(CatalogTitle::query(), $user);
    }

    /**
     * @param  Builder<CatalogTitle>  $query
     * @return Builder<CatalogTitle>
     */
    public function constrainVisible(Builder $query, ?User $user): Builder
    {
        return $query->availableTo($user);
    }

    /**
     * @return Builder<CatalogTitle>
     */
    public function filteredTitles(
        CatalogTitlesCriteria $criteria,
        ?User $user,
        ?string $exceptTaxonomyType = null,
    ): Builder {
        $query = $this->visibleTo($user);

        if ($criteria->invalidYear || $criteria->invalidTitleContext) {
            $query->whereRaw('1 = 0');
        }

        if ($criteria->search->year !== null) {
            if ($criteria->years !== [] && ! in_array($criteria->search->year, $criteria->years, true)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('year', $criteria->search->year);
            }
        } elseif ($criteria->years !== []) {
            $query->whereIn('year', $criteria->years);
        }

        if ($criteria->titleContextId !== null) {
            $query->whereKey($criteria->titleContextId);
        }

        $this->applySearchFilter($query, $criteria->search, $criteria->titleContextId, $user);
        $this->applyRelationFilters(
            $query,
            $exceptTaxonomyType,
            $criteria->selectedTaxonomyIds,
            $criteria->excludedTaxonomyIds,
        );
        $this->applyAdvancedFilters($query, $criteria->queryFilters(), $user);

        return $query;
    }

    /**
     * @param  Builder<CatalogTitle>  $query
     * @return Builder<CatalogTitle>
     */
    public function sorted(Builder $query, CatalogSort $sort): Builder
    {
        return match ($sort) {
            CatalogSort::YearDesc => $query->orderByDesc('year')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            CatalogSort::YearAsc => $query->orderBy('year')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            CatalogSort::EpisodesDesc => $query->orderByDesc('episodes_count')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            CatalogSort::SeasonsDesc => $query->orderByDesc('seasons_count')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            CatalogSort::TitleAsc => $query->orderBy('title')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            CatalogSort::TitleDesc => $query->orderByDesc('title')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            CatalogSort::VideoDesc => $query->orderByDesc('published_media_count')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            CatalogSort::KinopoiskRating => $query->orderByDesc('kinopoisk_rating')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            CatalogSort::ImdbRating => $query->orderByDesc('imdb_rating')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            CatalogSort::Popularity => $query->orderByDesc('published_media_count')->orderByDesc('episodes_count')->latest('indexed_at')->orderByDesc('catalog_titles.id'),
            CatalogSort::Updated => $query->latest('indexed_at')->orderByDesc('catalog_titles.id'),
        };
    }

    /**
     * @return array<int|string, string|\Closure(Builder): Builder>
     */
    public function publicCardCounts(?User $user): array
    {
        return [
            'seasons' => fn (Builder $query): Builder => $query->availableTo($user),
            'episodes' => fn (Builder $query): Builder => $query
                ->availableTo($user)
                ->whereHas('season', fn (Builder $query): Builder => $query->availableTo($user)),
            'licensedMedia as published_media_count' => fn (Builder $query): Builder => $query
                ->availableTo($user)
                ->forAvailableReleases($user),
        ];
    }

    /** @return array<string, \Closure(Builder): Builder> */
    public function ratingAggregates(): array
    {
        return [
            'ratings as kinopoisk_rating' => fn (Builder $query): Builder => $query->where('provider', 'kinopoisk'),
            'ratings as imdb_rating' => fn (Builder $query): Builder => $query->where('provider', 'imdb'),
        ];
    }

    /**
     * @param  Collection<string, Collection<int, Model>>  $filterTaxonomies
     * @return Collection<string, int>
     */
    public function relationContextCounts(
        Collection $filterTaxonomies,
        CatalogTitlesCriteria $criteria,
        ?User $user,
    ): Collection {
        if (! $this->hasRelationContextConstraints($criteria)) {
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
            ->map(function (Collection $recordIds, string $filterType) use ($criteria, $user, $catalogTitleTable) {
                $relationName = $this->taxonomies->relationName($filterType);
                $catalogTitleRelation = (new CatalogTitle)->{$relationName}();
                $pivotTable = $catalogTitleRelation->getTable();
                $titlePivotKey = $catalogTitleRelation->getForeignPivotKeyName();
                $relatedPivotKey = $catalogTitleRelation->getRelatedPivotKeyName();
                $filteredTitlesQuery = $this
                    ->filteredTitles($criteria->withoutRelation($filterType), $user)
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

    private function hasRelationContextConstraints(CatalogTitlesCriteria $criteria): bool
    {
        $hasAdvancedFilters = collect($criteria->queryFilters())->contains(
            fn (mixed $value): bool => $value !== null && $value !== [],
        );

        return $criteria->search->state !== CatalogSearchState::Empty
            || $criteria->years !== []
            || $criteria->titleContextId !== null
            || $criteria->invalidTitleContext
            || $criteria->invalidYear
            || $criteria->selectedTaxonomyIds !== []
            || $criteria->excludedTaxonomyIds !== []
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
    private function applySearchFilter(Builder $query, CatalogSearchQuery $search, ?int $titleContextId, ?User $user): void
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

        $query->whereIn('catalog_titles.id', $this->searchCandidateIdsQuery($search, $user));
    }

    /** @return Builder<CatalogTitle> */
    private function searchCandidateIdsQuery(CatalogSearchQuery $search, ?User $user): Builder
    {
        $exactMatches = $this->exactTitleSearchQuery($search, $user);
        if ($this->exactMatchExists($search, $user, $exactMatches)) {
            return $exactMatches;
        }

        $query = $this->visibleTo($user)->select('catalog_titles.id');
        $this->applyLegacySearchTerms($query, $search);

        return $query;
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

    /** @return Builder<CatalogTitle> */
    private function exactTitleSearchQuery(CatalogSearchQuery $search, ?User $user): Builder
    {
        $variants = $this->legacyVariants($search->phrase())
            ->flatMap(fn (string $variant): array => [$variant, Str::ucfirst($variant)])
            ->unique()
            ->values();
        $query = $this->visibleTo($user)->select('catalog_titles.id');

        if ($variants->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        $catalogTitleTable = (new CatalogTitle)->getTable();

        return $query
            ->where(function (Builder $query) use ($catalogTitleTable, $search, $variants): void {
                $query->whereIn('title', $variants)
                    ->orWhereIn('original_title', $variants)
                    ->orWhereIn(
                        $catalogTitleTable.'.id',
                        CatalogTitleAlias::query()
                            ->select('catalog_title_id')
                            ->whereIn('name_hash', $search->exactNameHashes),
                    );
            });
    }

    /** @param Builder<CatalogTitle> $exactMatches */
    private function exactMatchExists(
        CatalogSearchQuery $search,
        ?User $user,
        Builder $exactMatches,
    ): bool {
        $cacheKey = $this->searchCacheKey($search, $user);

        return $this->exactMatchExistsCache[$cacheKey]
            ??= (clone $exactMatches)->exists();
    }

    private function searchCacheKey(CatalogSearchQuery $search, ?User $user): string
    {
        return ($user === null ? 'guest' : 'authenticated').'|'.$search->normalized;
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
     * @param  array<string, list<int>>  $selectedTaxonomyIds
     * @param  array<string, list<int>>  $excludedTaxonomyIds
     */
    private function applyRelationFilters(
        Builder $query,
        ?string $exceptTaxonomyType = null,
        array $selectedTaxonomyIds = [],
        array $excludedTaxonomyIds = [],
    ): void {
        $catalogTitleTable = (new CatalogTitle)->getTable();

        $filterTypes = collect(array_keys($selectedTaxonomyIds))
            ->merge(array_keys($excludedTaxonomyIds))
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
            $selectedIds = $this->normalizeRelationIds($selectedTaxonomyIds[$filterType] ?? []);
            $excludedIds = $this->normalizeRelationIds($excludedTaxonomyIds[$filterType] ?? []);

            if ($selectedIds !== []) {
                $query->whereIn(
                    $catalogTitleTable.'.id',
                    DB::table($pivotTable)
                        ->select($pivotTable.'.'.$titlePivotKey)
                        ->whereIn($pivotTable.'.'.$relatedPivotKey, $selectedIds)
                        ->groupBy($pivotTable.'.'.$titlePivotKey),
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
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function normalizeRelationIds(array $ids): array
    {
        return collect($ids)
            ->map(fn (int $id): int => $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyAdvancedFilters(Builder $query, array $filters, ?User $user): void
    {
        $catalogTitleTable = (new CatalogTitle)->getTable();
        $yearFrom = $filters['year_from'] ?? null;
        $yearTo = $filters['year_to'] ?? null;

        if ($yearFrom !== null) {
            $query->where('year', '>=', $yearFrom);
        }

        if ($yearTo !== null) {
            $query->where('year', '<=', $yearTo);
        }

        foreach ([['seasons_min', 'seasons_max', 'seasonTitleIdsByCount'], ['episodes_min', 'episodes_max', 'episodeTitleIdsByCount']] as [$minimumKey, $maximumKey, $subqueryMethod]) {
            $minimum = $filters[$minimumKey] ?? null;
            $maximum = $filters[$maximumKey] ?? null;

            if ($minimum !== null && (int) $minimum > 0) {
                $query->whereIn($catalogTitleTable.'.id', $this->{$subqueryMethod}('>=', (int) $minimum, $user));
            }

            if ($maximum !== null) {
                $query->whereNotIn($catalogTitleTable.'.id', $this->{$subqueryMethod}('>', (int) $maximum, $user));
            }
        }

        $videoAvailability = $filters['video'] ?? null;
        if ($videoAvailability === 'available') {
            $query->whereIn($catalogTitleTable.'.id', $this->publishedMediaTitleIds($user));
        } elseif ($videoAvailability === 'missing') {
            $query->whereNotIn($catalogTitleTable.'.id', $this->publishedMediaTitleIds($user));
        }

        $subtitleAvailability = $filters['subtitles'] ?? [];
        if ($subtitleAvailability === ['available']) {
            $query->whereIn($catalogTitleTable.'.id', $this->publishedMediaTitleIds($user, true));
        } elseif ($subtitleAvailability === ['missing']) {
            $query->whereNotIn($catalogTitleTable.'.id', $this->publishedMediaTitleIds($user, true));
        }

        $publicationTypes = collect($filters['publication_type'] ?? [])
            ->map(fn (mixed $type): ?CatalogPublicationType => is_string($type) ? CatalogPublicationType::tryFrom($type) : null)
            ->filter()
            ->flatMap(fn (CatalogPublicationType $type): array => $type->databaseValues())
            ->unique()
            ->values()
            ->all();
        if ($publicationTypes !== []) {
            $query->whereIn($catalogTitleTable.'.type', $publicationTypes);
        }

        $qualities = $filters['quality'] ?? [];
        if ($qualities !== []) {
            $query->whereIn($catalogTitleTable.'.id', $this->publishedMediaTitleIds($user, null, $qualities));
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
            $query->whereIn($catalogTitleTable.'.id', $this->ratingTitleIds($ratingSource, $ratingMin, $votesMin));
        }
    }

    /** @return Builder<CatalogTitleRating> */
    private function ratingTitleIds(?string $source, ?float $minimumRating, ?int $minimumVotes): Builder
    {
        $query = CatalogTitleRating::query()
            ->select('catalog_title_id')
            ->whereNotNull('catalog_title_id');

        if ($source !== null) {
            $query->where('provider', $source);
        }

        if ($minimumRating !== null) {
            $query->where('rating', '>=', $minimumRating);
        }

        if ($minimumVotes !== null) {
            $query->where('votes', '>=', $minimumVotes);
        }

        return $query;
    }

    private function seasonTitleIdsByCount(string $operator, int $count, ?User $user): Builder
    {
        $seasonTable = (new Season)->getTable();

        return Season::query()
            ->availableTo($user)
            ->select($seasonTable.'.catalog_title_id')
            ->whereNotNull($seasonTable.'.catalog_title_id')
            ->groupBy($seasonTable.'.catalog_title_id')
            ->havingRaw('count(*) '.$operator.' ?', [$count]);
    }

    private function episodeTitleIdsByCount(string $operator, int $count, ?User $user): Builder
    {
        $episodeTable = (new Episode)->getTable();
        $seasonTable = (new Season)->getTable();
        $episodeCounts = Episode::query()
            ->availableTo($user)
            ->select($episodeTable.'.season_id')
            ->selectRaw('count(*) as visible_episode_count')
            ->groupBy($episodeTable.'.season_id');

        return Season::query()
            ->availableTo($user)
            ->joinSub($episodeCounts, 'visible_episode_counts', function ($join) use ($seasonTable): void {
                $join->on('visible_episode_counts.season_id', '=', $seasonTable.'.id');
            })
            ->select($seasonTable.'.catalog_title_id')
            ->whereNotNull($seasonTable.'.catalog_title_id')
            ->groupBy($seasonTable.'.catalog_title_id')
            ->havingRaw('sum(visible_episode_counts.visible_episode_count) '.$operator.' ?', [$count]);
    }

    /**
     * @param  list<string>  $qualities
     * @return Builder<LicensedMedia>
     */
    private function publishedMediaTitleIds(?User $user, ?bool $requiresSubtitles = null, array $qualities = []): Builder
    {
        $query = LicensedMedia::query()
            ->select('catalog_title_id')
            ->whereNotNull('catalog_title_id')
            ->availableTo($user)
            ->forAvailableReleases($user);

        if ($requiresSubtitles !== null) {
            $query->where('has_subtitles', $requiresSubtitles);
        }

        if ($qualities !== []) {
            $query->whereIn('quality', $qualities);
        }

        return $query;
    }
}
