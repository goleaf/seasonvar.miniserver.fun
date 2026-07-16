<?php

namespace App\Services\Catalog;

use App\Enums\CatalogPublicationType;
use App\Enums\CatalogSort;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleAlias;
use App\Models\CatalogTitleRating;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\Search\CatalogSearchMatchSet;
use App\Services\Catalog\Search\CatalogSearchNormalizer;
use App\Services\Catalog\Search\CatalogSearchQuery;
use App\Services\Catalog\Search\CatalogSearchState;
use App\Services\Catalog\Search\CatalogTitleSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogTitleQuery
{
    /** @var array<int, string> */
    private array $rankedSearchAliases = [];

    /** @var array<string, bool> */
    private array $exactMatchExistsCache = [];

    /** @var array<string, Collection<int, string>> */
    private array $legacyVariantsCache = [];

    public function __construct(
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogSearchNormalizer $searchNormalizer,
        private readonly CatalogEntitlementService $entitlements,
        private readonly CatalogTitleSearch $titleSearch,
        private readonly CatalogPopularityQuery $popularity,
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
        return $this->entitlements->constrain($query, $user);
    }

    /**
     * @return Builder<CatalogTitle>
     */
    public function filteredTitles(
        CatalogTitlesCriteria $criteria,
        ?User $user,
        ?string $exceptTaxonomyType = null,
        bool $rankSearch = false,
        ?CatalogSearchMatchSet $searchMatches = null,
    ): Builder {
        $query = $rankSearch
            ? $this->rankedSearchQuery($criteria->search, $user)
            : null;
        $query ??= $this->visibleTo($user);

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

        if (! isset($this->rankedSearchAliases[spl_object_id($query)])) {
            $this->applySearchFilter($query, $criteria->search, $criteria->titleContextId, $user, $searchMatches);
        }
        $this->applyRelationFilters(
            $query,
            $exceptTaxonomyType,
            $criteria->selectedTaxonomyIds,
            $criteria->excludedTaxonomyIds,
        );
        $this->applyAdvancedFilters($query, $criteria->queryFilters(), $user);

        return $query;
    }

    /** @return Builder<CatalogTitle>|null */
    private function rankedSearchQuery(CatalogSearchQuery $search, ?User $user): ?Builder
    {
        $rankedCandidates = $this->titleSearch->candidateQuery($search);

        if ($rankedCandidates === null) {
            return null;
        }

        $alias = 'catalog_search_candidates';
        $catalogTitleTable = (new CatalogTitle)->getTable();
        $query = CatalogTitle::query()
            ->fromSub($rankedCandidates, $alias)
            ->crossJoin($catalogTitleTable)
            ->whereColumn($catalogTitleTable.'.id', $alias.'.catalog_title_id');

        $this->constrainVisible($query, $user);
        $this->rankedSearchAliases[spl_object_id($query)] = $alias;

        return $query;
    }

    /**
     * @param  Builder<CatalogTitle>  $query
     * @return Builder<CatalogTitle>
     */
    public function sorted(Builder $query, CatalogSort $sort): Builder
    {
        match ($sort) {
            CatalogSort::YearDesc => $query->orderByDesc('year'),
            CatalogSort::YearAsc => $query->orderBy('year'),
            CatalogSort::EpisodesDesc => $query->orderByDesc('episodes_count'),
            CatalogSort::SeasonsDesc => $query->orderByDesc('seasons_count'),
            CatalogSort::TitleAsc => $query->orderBy('title'),
            CatalogSort::TitleDesc => $query->orderByDesc('title'),
            CatalogSort::VideoDesc => $query->orderByDesc('published_media_count'),
            CatalogSort::KinopoiskRating => $query->orderByDesc('kinopoisk_rating'),
            CatalogSort::ImdbRating => $query->orderByDesc('imdb_rating'),
            CatalogSort::Popularity => $this->popularity->apply($query),
            CatalogSort::Relevance, CatalogSort::Updated => $query,
        };

        if ($alias = $this->rankedSearchAliases[spl_object_id($query)] ?? null) {
            $query->orderBy($alias.'.exact_title_rank')
                ->orderBy($alias.'.exact_original_title_rank')
                ->orderBy($alias.'.exact_alias_rank')
                ->orderBy($alias.'.bm25_score');
        }

        return $query->latest('indexed_at')->orderByDesc('catalog_titles.id');
    }

    /**
     * @return array{
     *     seasons: \Closure(Builder<Model>): Builder<Model>,
     *     episodes: \Closure(Builder<Model>): Builder<Model>,
     *     'licensedMedia as published_media_count': \Closure(Builder<Model>): Builder<Model>
     * }
     */
    public function publicCardCounts(?User $user): array
    {
        return [
            'seasons' => fn (Builder $query): Builder => $query->whereIn(
                'seasons.id',
                Season::query()->availableTo($user)->select('seasons.id'),
            ),
            'episodes' => fn (Builder $query): Builder => $query->whereIn(
                'episodes.id',
                Episode::query()
                    ->availableTo($user)
                    ->whereIn('season_id', Season::query()->availableTo($user)->select('seasons.id'))
                    ->select('episodes.id'),
            ),
            'licensedMedia as published_media_count' => fn (Builder $query): Builder => $query->whereIn(
                'licensed_media.id',
                LicensedMedia::query()
                    ->availableTo($user)
                    ->forAvailableReleases($user)
                    ->select('licensed_media.id'),
            ),
        ];
    }

    /**
     * @return array{
     *     'ratings as kinopoisk_rating': \Closure(Builder<CatalogTitleRating>): Builder<CatalogTitleRating>,
     *     'ratings as imdb_rating': \Closure(Builder<CatalogTitleRating>): Builder<CatalogTitleRating>
     * }
     */
    public function ratingAggregates(): array
    {
        return [
            'ratings as kinopoisk_rating' => /** @param Builder<CatalogTitleRating> $query */
                fn (Builder $query): Builder => $query->where('provider', 'kinopoisk'),
            'ratings as imdb_rating' => /** @param Builder<CatalogTitleRating> $query */
                fn (Builder $query): Builder => $query->where('provider', 'imdb'),
        ];
    }

    /** @return array{available: int, missing: int} */
    public function subtitleContextCounts(
        CatalogTitlesCriteria $criteria,
        ?User $user,
        ?CatalogSearchMatchSet $searchMatches = null,
    ): array {
        $contextTitles = $this
            ->filteredTitles($criteria->withoutSubtitleAvailability(), $user, searchMatches: $searchMatches)
            ->select('catalog_titles.id')
            ->withExists([
                'licensedMedia as has_available_subtitles' => /** @param Builder<LicensedMedia> $query */
                    fn (Builder $query): Builder => $query->whereIn(
                        'licensed_media.id',
                        LicensedMedia::query()
                            ->availableTo($user)
                            ->forAvailableReleases($user)
                            ->where('has_subtitles', true)
                            ->select('licensed_media.id'),
                    ),
            ]);
        $counts = DB::query()
            ->fromSub($contextTitles, 'subtitle_context_titles')
            ->selectRaw('count(*) as total_count')
            ->selectRaw('coalesce(sum(has_available_subtitles), 0) as available_count')
            ->first();
        $total = (int) ($counts->total_count ?? 0);
        $available = (int) ($counts->available_count ?? 0);

        return [
            'available' => $available,
            'missing' => max(0, $total - $available),
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
        ?CatalogSearchMatchSet $searchMatches = null,
    ): Collection {
        $visibleIdsByType = $filterTaxonomies
            ->map(fn (Collection $items): Collection => $items->pluck('id')->values())
            ->filter(fn (Collection $ids): bool => $ids->isNotEmpty());

        if ($visibleIdsByType->isEmpty()) {
            return collect();
        }

        $catalogTitleTable = (new CatalogTitle)->getTable();
        $contextQueries = $visibleIdsByType
            ->map(function (Collection $recordIds, string $filterType) use ($criteria, $user, $catalogTitleTable, $searchMatches) {
                $relationName = $this->taxonomies->relationName($filterType);
                $catalogTitleRelation = (new CatalogTitle)->{$relationName}();
                $pivotTable = $catalogTitleRelation->getTable();
                $titlePivotKey = $catalogTitleRelation->getForeignPivotKeyName();
                $relatedPivotKey = $catalogTitleRelation->getRelatedPivotKeyName();
                $filteredTitlesQuery = $this
                    ->filteredTitles($criteria->withoutRelation($filterType), $user, searchMatches: $searchMatches)
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
    private function applySearchFilter(
        Builder $query,
        CatalogSearchQuery $search,
        ?int $titleContextId,
        ?User $user,
        ?CatalogSearchMatchSet $searchMatches,
    ): void {
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

        if ($searchMatches !== null) {
            $query->whereIn('catalog_titles.id', $searchMatches->idsQuery());

            return;
        }

        if (($matchingTitleIds = $this->titleSearch->matchingTitleIdsQuery($search)) !== null) {
            $query->whereIn('catalog_titles.id', $matchingTitleIds);

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
                    $this->orWhereCatalogNameMatches($query, $variant);
                });

                $query->orWhereIn($catalogTitleTable.'.id', $this->aliasSearchTitleIdsSubquery($variants));
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
    private function orWhereCatalogNameMatches(Builder $query, string $variant): void
    {
        $query->orWhere('title', 'like', "%{$variant}%")
            ->orWhere('original_title', 'like', "%{$variant}%");
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
     * @param  Builder<CatalogTitle>  $query
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

        $publicationTypeValues = $filters['publication_type'] ?? [];
        $publicationTypeValues = is_array($publicationTypeValues) ? $publicationTypeValues : [];
        $publicationTypes = collect($publicationTypeValues)
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

    /** @return Builder<Season> */
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

    /** @return Builder<Season> */
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
