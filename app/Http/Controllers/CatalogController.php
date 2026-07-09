<?php

namespace App\Http\Controllers;

use App\Models\Actor;
use App\Models\AgeRating;
use App\Models\CatalogStatus;
use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Director;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\Network;
use App\Models\Studio;
use App\Models\Tag;
use App\Models\Translation;
use App\Services\Catalog\CatalogSitemapResponder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CatalogController extends Controller
{
    private const FILTER_RELATIONS = [
        'genre' => ['model' => Genre::class, 'relation' => 'genres'],
        'country' => ['model' => Country::class, 'relation' => 'countries'],
        'actor' => ['model' => Actor::class, 'relation' => 'actors'],
        'director' => ['model' => Director::class, 'relation' => 'directors'],
        'age_rating' => ['model' => AgeRating::class, 'relation' => 'ageRatings'],
        'translation' => ['model' => Translation::class, 'relation' => 'translations'],
        'status' => ['model' => CatalogStatus::class, 'relation' => 'statuses'],
        'network' => ['model' => Network::class, 'relation' => 'networks'],
        'studio' => ['model' => Studio::class, 'relation' => 'studios'],
        'tag' => ['model' => Tag::class, 'relation' => 'tags'],
    ];

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

    public function index(): View
    {
        $stats = [
            'titles' => CatalogTitle::query()->count(),
            'episodes' => Episode::query()->count(),
            'genres' => Genre::query()->count(),
            'countries' => Country::query()->count(),
        ];
        $latestTitles = CatalogTitle::query()
            ->with($this->cardRelations())
            ->withCount(['seasons', 'episodes'])
            ->latest('indexed_at')
            ->limit(64)
            ->get();

        return view('catalog.index', [
            'stats' => $stats,
            'latestTitles' => $latestTitles,
            'genres' => Genre::query()
                ->withCount('catalogTitles')
                ->orderByDesc('catalog_titles_count')
                ->limit(18)
                ->get(),
            'countries' => Country::query()
                ->withCount('catalogTitles')
                ->orderByDesc('catalog_titles_count')
                ->limit(10)
                ->get(),
            'subtitleTag' => Tag::query()
                ->where('slug', 'subtitry')
                ->withCount('catalogTitles')
                ->first(),
            'seo' => $this->homeSeo($stats, $latestTitles),
        ]);
    }

    public function titles(Request $request, ?string $type = null, ?string $taxonomy = null): View
    {
        $search = $this->normalizeSearch($request->query('q', ''));
        $requestedYear = $request->query('year');
        $requestedYear = is_scalar($requestedYear) ? trim((string) $requestedYear) : '';
        $parsedYear = preg_match('/^\d{4}$/', $requestedYear) === 1 ? (int) $requestedYear : null;
        $year = $parsedYear !== null && $parsedYear >= 1900 && $parsedYear <= ((int) now()->format('Y') + 1)
            ? $parsedYear
            : null;
        $invalidYear = $requestedYear !== '' && $year === null;
        $titleContextSlug = $this->normalizeFilterSlug($request->query('title', ''));
        $titleContext = $titleContextSlug === null || $titleContextSlug === ''
            ? null
            : CatalogTitle::query()->select(['id', 'slug', 'title'])->where('slug', $titleContextSlug)->first();
        $filterTypes = array_keys(self::FILTER_RELATIONS);
        $legacyType = $this->normalizeLegacyType($request->query('type', ''), $filterTypes);
        $legacyTaxonomy = $this->normalizeFilterSlug($request->query('taxonomy', ''));
        $invalidInputFilterSlugs = [];

        if ($legacyType !== '' && $legacyTaxonomy === null) {
            $invalidInputFilterSlugs[$legacyType] = 'invalid';
        }

        $activeFilterSlugs = collect($filterTypes)
            ->mapWithKeys(function (string $filterType) use ($request, $type, $taxonomy, $legacyType, $legacyTaxonomy, &$invalidInputFilterSlugs): array {
                $value = $type === $filterType
                    ? $taxonomy
                    : $request->query($filterType, '');

                if ($value === '' && $legacyType === $filterType && $legacyTaxonomy !== null) {
                    $value = $legacyTaxonomy;
                }

                $value = $this->normalizeFilterSlug($value);

                if ($value === null) {
                    $invalidInputFilterSlugs[$filterType] = 'invalid';

                    return [];
                }

                return $value === '' ? [] : [$filterType => $value];
            })
            ->all();

        if ($taxonomy !== null && ! in_array($type, $filterTypes, true)) {
            abort(404);
        }

        $activeTaxonomies = collect();

        foreach ($activeFilterSlugs as $filterType => $slug) {
            $modelClass = $this->filterModelClass($filterType);
            $record = $modelClass::query()
                ->where('slug', $slug)
                ->withCount('catalogTitles')
                ->first();

            if ($record !== null) {
                $activeTaxonomies->put($filterType, $record);
            }
        }

        $invalidFilterSlugs = $invalidInputFilterSlugs + array_diff_key($activeFilterSlugs, $activeTaxonomies->all());
        $activeFilterSlugs = $activeTaxonomies
            ->mapWithKeys(fn (Model $record, string $filterType): array => [$filterType => $record->slug])
            ->all();

        $querySearch = $search;
        $searchFallback = false;
        $titles = $this->catalogTitleFilterQuery($activeTaxonomies, $invalidFilterSlugs, $querySearch, $year, null, $invalidYear, $titleContext?->id)
            ->with($this->cardRelations())
            ->withCount(['seasons', 'episodes'])
            ->latest('indexed_at')
            ->paginate(24)
            ->withQueryString();

        if ($search !== '' && $titles->total() === 0) {
            $querySearch = '';
            $searchFallback = true;
            $titles = $this->catalogTitleFilterQuery($activeTaxonomies, $invalidFilterSlugs, $querySearch, $year, null, $invalidYear, $titleContext?->id)
                ->with($this->cardRelations())
                ->withCount(['seasons', 'episodes'])
                ->latest('indexed_at')
                ->paginate(24)
                ->withQueryString();
        }

        $filterTaxonomies = collect($filterTypes)->mapWithKeys(function (string $filterType): array {
            $modelClass = $this->filterModelClass($filterType);
            $items = $modelClass::query()
                ->withCount('catalogTitles')
                ->orderByDesc('catalog_titles_count')
                ->limit(12)
                ->get()
                ->filter(fn (Model $record): bool => $record->catalog_titles_count > 0)
                ->values();

            return [$filterType => $items];
        });

        $activeTaxonomies->each(function (Model $activeTaxonomy, string $filterType) use ($filterTaxonomies): void {
            $items = $filterTaxonomies->get($filterType, collect());

            if (! $items->contains(fn (Model $record): bool => $record->id === $activeTaxonomy->id)) {
                $filterTaxonomies->put($filterType, $items->prepend($activeTaxonomy)->values());
            }
        });

        $taxonomyContextCounts = $this->relationContextCounts($filterTaxonomies, $activeTaxonomies, $invalidFilterSlugs, $querySearch, $year, $invalidYear, $titleContext?->id);
        $filterTaxonomies = $filterTaxonomies->map(function (Collection $items, string $filterType) use ($taxonomyContextCounts): Collection {
            return $items->map(function (Model $record) use ($filterType, $taxonomyContextCounts): Model {
                $record->context_titles_count = (int) ($taxonomyContextCounts->get($filterType.'|'.$record->id) ?? 0);

                return $record;
            });
        });
        $yearBuckets = CatalogTitle::query()
            ->select('year')
            ->selectRaw('count(*) as titles_count')
            ->whereNotNull('year')
            ->where('year', '>=', 1900)
            ->where('year', '<=', (int) now()->format('Y') + 1)
            ->groupBy('year')
            ->orderByDesc('year')
            ->limit(20)
            ->get();

        if ($year !== null && ! $yearBuckets->contains(fn (CatalogTitle $bucket): bool => (int) $bucket->year === $year)) {
            $selectedYearBucket = CatalogTitle::query()
                ->select('year')
                ->selectRaw('count(*) as titles_count')
                ->where('year', $year)
                ->groupBy('year')
                ->first();

            $yearBuckets->prepend($selectedYearBucket ?? (object) [
                'year' => $year,
                'titles_count' => 0,
            ]);
        }

        $yearContextCounts = $this->catalogTitleFilterQuery($activeTaxonomies, $invalidFilterSlugs, $querySearch, null, null, $invalidYear, $titleContext?->id)
            ->select('year')
            ->selectRaw('count(*) as context_titles_count')
            ->whereNotNull('year')
            ->where('year', '>=', 1900)
            ->where('year', '<=', (int) now()->format('Y') + 1)
            ->groupBy('year')
            ->pluck('context_titles_count', 'year');

        $yearBuckets->each(function (object $bucket) use ($yearContextCounts): void {
            $bucket->context_titles_count = (int) ($yearContextCounts->get((int) $bucket->year) ?? 0);
        });

        return view('catalog.titles', [
            'titles' => $titles,
            'search' => $search,
            'year' => $year,
            'requestedYear' => $requestedYear,
            'invalidYear' => $invalidYear,
            'searchFallback' => $searchFallback,
            'titleContext' => $titleContext,
            'selectedTaxonomy' => $activeTaxonomies->first(),
            'activeTaxonomies' => $activeTaxonomies,
            'activeFilterSlugs' => $activeFilterSlugs,
            'invalidFilterSlugs' => $invalidFilterSlugs,
            'filterTaxonomies' => $filterTaxonomies,
            'filterTypes' => $filterTypes,
            'yearBuckets' => $yearBuckets,
            'seo' => $this->titlesSeo(
                $request,
                (int) $titles->total(),
                $search,
                $searchFallback,
                $year,
                $activeTaxonomies,
                $invalidFilterSlugs,
                $invalidYear,
                $requestedYear,
                (int) $titles->currentPage(),
                $titles->previousPageUrl(),
                $titles->nextPageUrl(),
                $titles->getCollection(),
                (int) ($titles->firstItem() ?? 1),
                $titleContext,
            ),
        ]);
    }

    public function titlesByYear(Request $request, int $year): View
    {
        $request->query->set('year', (string) $year);

        return $this->titles($request);
    }

    private function normalizeSearch(mixed $value): string
    {
        $search = is_scalar($value) ? (string) $value : '';
        $search = preg_replace('/\s+/u', ' ', trim($search)) ?: '';

        if (mb_strlen($search) < 2) {
            return '';
        }

        return mb_substr($search, 0, 80);
    }

    private function normalizeLegacyType(mixed $value, array $filterTypes): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        $value = trim((string) $value);

        return in_array($value, $filterTypes, true) ? $value : '';
    }

    private function normalizeFilterSlug(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) > 120 || preg_match('/^[a-z0-9][a-z0-9-]*$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function catalogTitleFilterQuery(
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

            foreach ($this->filterRelationNames() as $relation) {
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

                    foreach ($this->filterRelationNames() as $relation) {
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

    private function applyRelationFilters(Builder $query, Collection $activeTaxonomies, ?string $exceptTaxonomyType = null): void
    {
        foreach ($activeTaxonomies as $filterType => $activeTaxonomy) {
            if ($filterType === $exceptTaxonomyType) {
                continue;
            }

            $relation = self::FILTER_RELATIONS[$filterType]['relation'];
            $query->whereHas($relation, function (Builder $query) use ($activeTaxonomy): void {
                $query->whereKey($activeTaxonomy->id);
            });
        }
    }

    private function relationContextCounts(
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
                $relationName = self::FILTER_RELATIONS[$filterType]['relation'];
                $catalogTitleRelation = (new CatalogTitle)->{$relationName}();
                $pivotTable = $catalogTitleRelation->getTable();
                $titlePivotKey = $catalogTitleRelation->getForeignPivotKeyName();
                $relatedPivotKey = $catalogTitleRelation->getRelatedPivotKeyName();
                $filteredTitlesQuery = $this
                    ->catalogTitleFilterQuery($activeTaxonomies, $invalidFilterSlugs, $search, $year, $filterType, $invalidYear, $titleContextId)
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

    public function show(Request $request, CatalogTitle $catalogTitle): View
    {
        $catalogTitle->load(array_merge([
            'sourcePage',
            'aliases',
            'ratings',
            'seasons.episodes',
            'licensedMedia' => fn ($query) => $query->published()->with(['season', 'episode'])->latest('published_at')->latest(),
        ], $this->filterRelationNames()));
        $taxonomiesByType = collect(self::FILTER_RELATIONS)
            ->mapWithKeys(fn (array $config, string $filterType): array => [$filterType => $catalogTitle->{$config['relation']}->values()]);
        $taxonomyGroups = $taxonomiesByType;
        $seasons = $catalogTitle->seasons->sortBy('number')->values();
        $episodes = $seasons
            ->flatMap(fn ($season): Collection => $season->episodes->sortBy('number')->values())
            ->values();

        $mediaItems = $catalogTitle->licensedMedia
            ->sortBy(fn (LicensedMedia $media): string => sprintf(
                '%05d-%05d-%02d-%s',
                $media->season?->number ?? 99999,
                $media->episode?->number ?? 99999,
                $this->mediaQualityRank($media->quality),
                $media->title,
            ))
            ->values();
        $requestedEpisodeId = $request->integer('episode');
        $requestedMediaId = $request->integer('media');
        $selectedEpisode = $requestedEpisodeId > 0
            ? $episodes->firstWhere('id', $requestedEpisodeId)
            : null;
        $selectedMedia = $requestedMediaId > 0
            ? $mediaItems->firstWhere('id', $requestedMediaId)
            : null;

        if ($selectedMedia === null && $selectedEpisode !== null) {
            $selectedMedia = $mediaItems->firstWhere('episode_id', $selectedEpisode->id);
        }

        if ($selectedEpisode === null && $selectedMedia?->episode_id !== null) {
            $selectedEpisode = $episodes->firstWhere('id', $selectedMedia->episode_id)
                ?? $selectedMedia->episode;
        }

        if ($selectedMedia === null && $selectedEpisode === null) {
            $selectedMedia = $mediaItems->first();
        }

        if ($selectedEpisode === null && $selectedMedia?->episode_id !== null) {
            $selectedEpisode = $episodes->firstWhere('id', $selectedMedia->episode_id)
                ?? $selectedMedia->episode;
        }

        $selectedEpisode ??= $episodes->first();
        $selectedMediaUrl = $selectedMedia ? ($selectedMedia->playback_url ?: $selectedMedia->path) : null;
        $episodeCount = $seasons->sum(fn ($season): int => (int) $season->episodes->count());
        $taxonomyCount = $taxonomiesByType->sum(fn (Collection $items): int => $items->count());
        $parsedSeasonCount = $seasons->filter(fn ($season): bool => $season->episodes->isNotEmpty())->count();
        $mediaCount = $mediaItems->count();
        $relatedIdsByType = $taxonomiesByType
            ->map(fn (Collection $items): Collection => $items->pluck('id')->unique()->values())
            ->filter(fn (Collection $ids): bool => $ids->isNotEmpty());
        $recommendedTitlesQuery = CatalogTitle::query()
            ->select(['id', 'slug', 'title', 'description', 'poster_url', 'indexed_at'])
            ->whereKeyNot($catalogTitle->id);

        if ($relatedIdsByType->isNotEmpty()) {
            $recommendedTitlesQuery->where(function (Builder $query) use ($relatedIdsByType): void {
                foreach ($relatedIdsByType as $filterType => $ids) {
                    $relation = self::FILTER_RELATIONS[$filterType]['relation'];
                    $query->orWhereHas($relation, function (Builder $query) use ($ids): void {
                        $query->whereKey($ids);
                    });
                }
            });
        }

        return view('catalog.show', [
            'title' => $catalogTitle,
            'taxonomiesByType' => $taxonomiesByType,
            'taxonomyGroups' => $taxonomyGroups,
            'seasons' => $seasons,
            'episodeCount' => $episodeCount,
            'taxonomyCount' => $taxonomyCount,
            'parsedSeasonCount' => $parsedSeasonCount,
            'selectedEpisode' => $selectedEpisode,
            'selectedMedia' => $selectedMedia,
            'mediaItems' => $mediaItems,
            'mediaCount' => $mediaCount,
            'recommendedTitles' => $recommendedTitlesQuery
                ->latest('indexed_at')
                ->limit(6)
                ->get(),
            'seo' => $this->titleSeo($catalogTitle, $taxonomiesByType, $seasons, $episodeCount, $mediaCount, $selectedMedia, $selectedMediaUrl),
        ]);
    }

    private function mediaQualityRank(?string $quality): int
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

    public function sitemap(CatalogSitemapResponder $sitemaps): StreamedResponse
    {
        return $sitemaps->index();
    }

    public function sitemapIndex(CatalogSitemapResponder $sitemaps): StreamedResponse
    {
        return $sitemaps->index();
    }

    public function sitemapStatic(CatalogSitemapResponder $sitemaps): StreamedResponse
    {
        return $sitemaps->staticPages();
    }

    public function sitemapTaxonomies(CatalogSitemapResponder $sitemaps): StreamedResponse
    {
        return $sitemaps->taxonomies();
    }

    public function sitemapLandings(CatalogSitemapResponder $sitemaps): StreamedResponse
    {
        return $sitemaps->landings();
    }

    public function sitemapTitles(CatalogSitemapResponder $sitemaps, int $page): StreamedResponse
    {
        return $sitemaps->titles($page);
    }

    public function sitemapVideos(CatalogSitemapResponder $sitemaps, int $page): StreamedResponse
    {
        return $sitemaps->videos($page);
    }

    public function feed(CatalogSitemapResponder $sitemaps): StreamedResponse
    {
        return $sitemaps->feed();
    }

    public function openSearch(CatalogSitemapResponder $sitemaps): StreamedResponse
    {
        return $sitemaps->openSearch();
    }

    public function llms(CatalogSitemapResponder $sitemaps): StreamedResponse
    {
        return $sitemaps->llms();
    }

    /**
     * @param  array{titles: int, episodes: int, genres: int, countries: int}  $stats
     * @return array<string, mixed>
     */
    private function homeSeo(array $stats, Collection $latestTitles): array
    {
        $title = 'Сериалы онлайн - '.$this->siteName();
        $description = 'Каталог сериалов онлайн: '.$stats['titles'].' сериалов, '.$stats['episodes'].' серий, фильтры по жанрам, странам, актерам, годам и переводам.';

        return [
            'title' => $title,
            'description' => $description,
            'keywords' => 'сериалы онлайн, смотреть сериалы, каталог сериалов, новые серии, сезоны сериалов',
            'news_keywords' => 'сериалы онлайн, смотреть сериалы онлайн, новые серии сериалов, каталог сериалов',
            'canonical' => route('home'),
            'type' => 'website',
            'updated_time' => now()->toAtomString(),
            'section' => 'Каталог сериалов',
            'tags' => ['сериалы онлайн', 'каталог сериалов', 'новые серии', 'сезоны сериалов'],
            'seo_text' => [
                'Каталог автоматически собирает сериалы, сезоны, серии, постеры, жанры, страны, актеров, режиссеров и доступные видео для просмотра во встроенном плеере.',
                'На главной странице появляются последние обновления, поэтому поисковые страницы получают актуальную информацию после каждого обновления каталога.',
            ],
            'search_phrases' => [
                'смотреть сериалы онлайн',
                'новые серии сериалов',
                'каталог сериалов по жанрам',
                'сериалы по странам',
                'сериалы по актерам',
                'сериалы с сезонами и сериями',
                'сериалы во встроенном плеере',
                'обновления сериалов онлайн',
            ],
            'keyword_clusters' => [
                [
                    'title' => 'Смотреть онлайн',
                    'items' => ['смотреть сериалы онлайн', 'сериалы онлайн бесплатно', 'сериалы в плеере', 'новые серии онлайн'],
                ],
                [
                    'title' => 'Каталог и фильтры',
                    'items' => ['сериалы по жанрам', 'сериалы по странам', 'сериалы по актерам', 'сериалы по годам'],
                ],
                [
                    'title' => 'Сезоны и серии',
                    'items' => ['все сезоны сериалов', 'все серии сериалов', 'обновления сезонов', 'новые эпизоды'],
                ],
            ],
            'related_links' => [
                ['name' => 'Все сериалы онлайн', 'url' => route('titles.index')],
                ['name' => 'Сериалы '.now()->year.' года', 'url' => route('titles.year', ['year' => now()->year])],
                ['name' => 'RSS обновлений', 'url' => route('feed')],
            ],
            'breadcrumbs' => [
                ['name' => 'Главная', 'url' => route('home')],
            ],
            'jsonLd' => [
                $this->organizationJsonLd(),
                $this->websiteJsonLd(),
                $this->siteNavigationJsonLd(),
                $this->webPageJsonLd($title, $description, route('home'), 'WebPage', ['сериалы онлайн', 'каталог сериалов']),
                $this->collectionPageJsonLd($title, $description, route('home')),
                $this->itemListJsonLd($latestTitles, 1, 'Новые сериалы каталога'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function titlesSeo(
        Request $request,
        int $total,
        string $search,
        bool $searchFallback,
        ?int $year,
        Collection $activeTaxonomies,
        array $invalidFilterSlugs,
        bool $invalidYear,
        string $requestedYear,
        int $currentPage,
        ?string $previousPageUrl,
        ?string $nextPageUrl,
        Collection $pageTitles,
        int $firstItemPosition,
        ?CatalogTitle $titleContext,
    ): array {
        $activeLabels = $activeTaxonomies
            ->map(fn (Model $record, string $filterType): string => $this->filterTypeLabel($filterType).': '.$record->name)
            ->values();
        $filterText = $activeLabels->implode(', ');
        $title = match (true) {
            $search !== '' && $titleContext !== null => 'Поиск "'.$search.'" по сериалу '.$titleContext->title,
            $search !== '' => 'Поиск "'.$search.'" - сериалы онлайн',
            $filterText !== '' && $year !== null => 'Сериалы '.$year.' - '.$filterText,
            $filterText !== '' => 'Сериалы - '.$filterText,
            $year !== null => 'Сериалы '.$year.' года онлайн',
            $titleContext !== null => 'Подборка по сериалу '.$titleContext->title,
            default => 'Все сериалы онлайн',
        };

        if ($currentPage > 1) {
            $title .= ' - страница '.$currentPage;
        }

        $descriptionParts = collect([
            $total.' сериалов найдено в каталоге.',
            $titleContext !== null ? 'Показаны результаты по сериалу '.$titleContext->title.'.' : null,
            $filterText !== '' ? 'Фильтры: '.$filterText.'.' : null,
            $year !== null ? 'Год выхода: '.$year.'.' : null,
            $search !== '' ? 'Поисковый запрос: '.$search.'.' : null,
            $searchFallback ? 'Точных совпадений не найдено, поэтому показаны ближайшие страницы каталога.' : null,
            $invalidYear ? 'Год '.$requestedYear.' не найден.' : null,
            $invalidFilterSlugs !== [] ? 'Часть фильтров не найдена.' : null,
        ])->filter()->implode(' ');
        $canonical = $this->catalogCanonicalUrl($request, $activeTaxonomies, $year, $currentPage, $titleContext);
        $robots = $search === '' && $invalidFilterSlugs === [] && ! $invalidYear && $activeTaxonomies->count() <= 1
            ? $this->indexRobots()
            : 'noindex,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';
        $keywords = collect(['сериалы онлайн', 'каталог сериалов', 'смотреть сериалы'])
            ->when($titleContext !== null, fn (Collection $items): Collection => $items->push($titleContext->title))
            ->merge($activeTaxonomies->pluck('name'))
            ->when($year !== null, fn (Collection $items): Collection => $items->push('сериалы '.$year))
            ->filter()
            ->unique()
            ->values();
        $tags = $keywords->take(20)->all();

        return [
            'title' => $title,
            'h1' => $searchFallback ? 'Поиск "'.$search.'" - ближайшие результаты каталога' : $this->catalogSeoHeading($search, $year, $activeTaxonomies),
            'lead' => $searchFallback
                ? 'По точному запросу совпадений не найдено, поэтому ниже показаны ближайшие страницы каталога, популярные сериалы и связанные подборки.'
                : $this->catalogSeoLead($total, $search, $year, $activeTaxonomies),
            'description' => $this->seoDescription($descriptionParts),
            'keywords' => $keywords->implode(', '),
            'news_keywords' => $keywords->take(10)->implode(', '),
            'canonical' => $canonical,
            'robots' => $robots,
            'prev' => $previousPageUrl,
            'next' => $nextPageUrl,
            'type' => 'website',
            'updated_time' => now()->toAtomString(),
            'section' => 'Сериалы',
            'tags' => $tags,
            'seo_text' => collect($searchFallback ? [
                'По точному запросу «'.$search.'» совпадений пока нет, поэтому страница автоматически показывает ближайшие результаты каталога и связанные SEO-направления.',
            ] : [])->merge($this->catalogSeoText($total, $search, $year, $activeTaxonomies))->values()->all(),
            'search_phrases' => $this->catalogSearchPhrases($search, $year, $activeTaxonomies),
            'keyword_clusters' => $this->catalogKeywordClusters($search, $year, $activeTaxonomies),
            'related_links' => $this->catalogRelatedLinks($search, $year, $activeTaxonomies),
            'search_context' => $titleContext === null ? null : [
                'type' => 'title',
                'title' => $titleContext->title,
                'slug' => $titleContext->slug,
            ],
            'breadcrumbs' => [
                ['name' => 'Главная', 'url' => route('home')],
                ['name' => 'Сериалы', 'url' => route('titles.index')],
            ],
            'jsonLd' => [
                $this->organizationJsonLd(),
                $this->websiteJsonLd(),
                $this->siteNavigationJsonLd(),
                $this->webPageJsonLd($title, $descriptionParts, $canonical, 'CollectionPage', $tags),
                $this->collectionPageJsonLd($title, $descriptionParts, $canonical),
                $this->itemListJsonLd($pageTitles, $firstItemPosition, $title),
                $this->breadcrumbJsonLd([
                    ['name' => 'Главная', 'url' => route('home')],
                    ['name' => 'Сериалы', 'url' => route('titles.index')],
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function titleSeo(
        CatalogTitle $catalogTitle,
        Collection $taxonomiesByType,
        Collection $seasons,
        int $episodeCount,
        int $mediaCount,
        ?LicensedMedia $selectedMedia,
        ?string $selectedMediaUrl,
    ): array {
        $genres = $this->taxonomyNames($taxonomiesByType, 'genre');
        $countries = $this->taxonomyNames($taxonomiesByType, 'country');
        $actors = $this->taxonomyNames($taxonomiesByType, 'actor')->take(10);
        $directors = $this->taxonomyNames($taxonomiesByType, 'director')->take(10);
        $ageRatings = $this->taxonomyNames($taxonomiesByType, 'age_rating');
        $fallbackDescription = collect([
            'Сериал '.$catalogTitle->title.' смотреть онлайн.',
            $catalogTitle->year ? 'Год выхода: '.$catalogTitle->year.'.' : null,
            $genres->isNotEmpty() ? 'Жанры: '.$genres->take(5)->implode(', ').'.' : null,
            $countries->isNotEmpty() ? 'Страна: '.$countries->take(3)->implode(', ').'.' : null,
            $seasons->count() > 0 ? 'Сезонов: '.$seasons->count().'.' : null,
            $episodeCount > 0 ? 'Серий: '.$episodeCount.'.' : null,
            $mediaCount > 0 ? 'Видео доступно во встроенном плеере.' : null,
        ])->filter()->implode(' ');
        $description = $this->seoDescription($catalogTitle->description ?: $fallbackDescription, 190);
        $pageTitle = 'Сериал '.$catalogTitle->title.' смотреть онлайн';

        if ($catalogTitle->year) {
            $pageTitle .= ' - '.$catalogTitle->year;
        }

        $alternateNames = collect([$catalogTitle->original_title])
            ->merge($catalogTitle->relationLoaded('aliases') ? $catalogTitle->aliases->pluck('name') : [])
            ->filter()
            ->unique()
            ->values();
        $rating = $catalogTitle->relationLoaded('ratings')
            ? $catalogTitle->ratings->first(fn ($rating): bool => $rating->rating !== null)
            : null;
        $seriesSchema = $this->withoutEmpty([
            '@context' => 'https://schema.org',
            '@type' => 'TVSeries',
            'name' => $catalogTitle->title,
            'alternateName' => $alternateNames->isNotEmpty() ? $alternateNames->all() : null,
            'description' => $description,
            'url' => route('titles.show', $catalogTitle),
            'mainEntityOfPage' => route('titles.show', $catalogTitle),
            'sameAs' => $catalogTitle->source_url,
            'image' => $catalogTitle->poster_url,
            'inLanguage' => 'ru',
            'isAccessibleForFree' => true,
            'publisher' => $this->organizationJsonLd(false),
            'datePublished' => $catalogTitle->year ? (string) $catalogTitle->year : null,
            'genre' => $genres->isNotEmpty() ? $genres->all() : null,
            'countryOfOrigin' => $countries->map(fn (string $name): array => ['@type' => 'Country', 'name' => $name])->values()->all(),
            'actor' => $actors->map(fn (string $name): array => ['@type' => 'Person', 'name' => $name])->values()->all(),
            'director' => $directors->map(fn (string $name): array => ['@type' => 'Person', 'name' => $name])->values()->all(),
            'contentRating' => $ageRatings->first(),
            'numberOfSeasons' => $seasons->count() > 0 ? $seasons->count() : null,
            'numberOfEpisodes' => $episodeCount > 0 ? $episodeCount : null,
            'containsSeason' => $this->seasonJsonLd($catalogTitle, $seasons),
            'aggregateRating' => $rating ? $this->withoutEmpty([
                '@type' => 'AggregateRating',
                'ratingValue' => (float) $rating->rating,
                'bestRating' => 10,
                'worstRating' => 0,
                'ratingCount' => $rating->votes,
            ]) : null,
        ]);
        $faqItems = $this->titleFaqItems($catalogTitle, $genres, $countries, $seasons->count(), $episodeCount, $mediaCount);
        $keywordCollection = $this->titleKeywordCollection($catalogTitle, $alternateNames, $genres, $countries, $actors, $directors, $seasons->count(), $episodeCount, $mediaCount);
        $searchPhrases = $this->titleSearchPhrases($catalogTitle, $keywordCollection, $genres, $countries, $seasons->count(), $episodeCount, $mediaCount);
        $jsonLd = [
            $this->organizationJsonLd(),
            $this->websiteJsonLd(),
            $this->siteNavigationJsonLd(),
            $this->webPageJsonLd($pageTitle, $description, route('titles.show', $catalogTitle), 'VideoObject', $keywordCollection->take(25)->all()),
            $seriesSchema,
            $this->faqJsonLd($faqItems),
            $this->episodeItemListJsonLd($catalogTitle, $seasons),
            $this->breadcrumbJsonLd([
                ['name' => 'Главная', 'url' => route('home')],
                ['name' => 'Сериалы', 'url' => route('titles.index')],
                ['name' => $catalogTitle->title, 'url' => route('titles.show', $catalogTitle)],
            ]),
        ];

        if ($selectedMediaUrl !== null) {
            $jsonLd[] = $this->withoutEmpty([
                '@context' => 'https://schema.org',
                '@type' => 'VideoObject',
                'name' => $selectedMedia?->title ?: $catalogTitle->title,
                'description' => $description,
                'thumbnailUrl' => $catalogTitle->poster_url,
                'uploadDate' => $this->sitemapDate($catalogTitle->indexed_at ?: $catalogTitle->updated_at),
                'contentUrl' => $selectedMediaUrl,
                'url' => route('titles.show', $catalogTitle).'#player',
            ]);
        }

        return [
            'title' => $pageTitle,
            'description' => $description,
            'keywords' => $keywordCollection->take(45)->implode(', '),
            'news_keywords' => $keywordCollection->take(15)->implode(', '),
            'canonical' => route('titles.show', $catalogTitle),
            'image' => $catalogTitle->poster_url,
            'image_alt' => 'Постер '.$catalogTitle->title,
            'video' => $selectedMediaUrl,
            'published_time' => $this->sitemapDate($catalogTitle->created_at),
            'updated_time' => $this->sitemapDate($catalogTitle->indexed_at ?: $catalogTitle->updated_at),
            'section' => 'Сериал онлайн',
            'tags' => $keywordCollection->take(25)->values()->all(),
            'seo_text' => $this->titleSeoText($catalogTitle, $genres, $countries, $actors, $directors, $seasons->count(), $episodeCount, $mediaCount),
            'search_phrases' => $searchPhrases,
            'keyword_clusters' => $this->titleKeywordClusters($catalogTitle, $genres, $countries, $actors, $directors, $seasons->count(), $episodeCount, $mediaCount),
            'related_links' => $this->titleRelatedLinks($catalogTitle, $taxonomiesByType),
            'search_context' => [
                'type' => 'title',
                'title' => $catalogTitle->title,
                'slug' => $catalogTitle->slug,
            ],
            'breadcrumbs' => [
                ['name' => 'Главная', 'url' => route('home')],
                ['name' => 'Сериалы', 'url' => route('titles.index')],
                ['name' => $catalogTitle->title, 'url' => route('titles.show', $catalogTitle)],
            ],
            'faq' => $faqItems,
            'type' => 'video.tv_show',
            'jsonLd' => $jsonLd,
        ];
    }

    private function siteName(): string
    {
        return (string) config('app.name', 'Каталог сериалов');
    }

    private function indexRobots(): string
    {
        return 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';
    }

    private function seoDescription(?string $value, int $limit = 180): string
    {
        $text = strip_tags((string) $value);
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?: '';

        return Str::limit($text, $limit, '...');
    }

    private function canonicalFromRequest(Request $request): string
    {
        $query = collect($request->query())
            ->filter(fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '')
            ->map(fn (mixed $value): string => (string) $value)
            ->sortKeys()
            ->all();

        return $query === []
            ? $request->url()
            : $request->url().'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function catalogCanonicalUrl(Request $request, Collection $activeTaxonomies, ?int $year, int $currentPage, ?CatalogTitle $titleContext = null): string
    {
        $query = [];

        if ($titleContext !== null) {
            $query['title'] = $titleContext->slug;
        }

        if ($year !== null) {
            $query['year'] = $year;
        }

        if ($currentPage > 1) {
            $query['page'] = $currentPage;
        }

        if ($activeTaxonomies->count() === 1) {
            $filterType = (string) $activeTaxonomies->keys()->first();
            $taxonomy = $activeTaxonomies->first();
            $url = route('titles.taxonomy', ['type' => $filterType, 'taxonomy' => $taxonomy->slug]);

            return $query === []
                ? $url
                : $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        if ($activeTaxonomies->isEmpty()) {
            $search = $request->query('q');

            if (is_scalar($search) && trim((string) $search) !== '') {
                $query['q'] = trim((string) $search);
            }

            if ($titleContext === null && $year !== null && ! isset($query['q']) && $currentPage === 1) {
                return route('titles.year', ['year' => $year]);
            }

            return $query === []
                ? route('titles.index')
                : route('titles.index').'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $this->canonicalFromRequest($request);
    }

    /**
     * @return Collection<int, string>
     */
    private function taxonomyNames(Collection $taxonomiesByType, string $type): Collection
    {
        return $taxonomiesByType
            ->get($type, collect())
            ->pluck('name')
            ->filter()
            ->unique()
            ->values();
    }

    private function filterTypeLabel(string $filterType): string
    {
        return [
            'genre' => 'Жанр',
            'country' => 'Страна',
            'actor' => 'Актер',
            'director' => 'Режиссер',
            'age_rating' => 'Возраст',
            'translation' => 'Перевод',
            'status' => 'Статус',
            'network' => 'Канал',
            'studio' => 'Студия',
            'tag' => 'Тег',
        ][$filterType] ?? 'Фильтр';
    }

    /**
     * @return array<string, mixed>
     */
    private function websiteJsonLd(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $this->siteName(),
            'url' => route('home'),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => route('titles.index').'?q={search_term_string}',
                'query-input' => 'required name=search_term_string',
            ],
            'sameAs' => [
                route('sitemap.index'),
                route('feed'),
            ],
            'keywords' => 'сериалы онлайн, каталог сериалов, смотреть сериалы онлайн',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function siteNavigationJsonLd(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => 'Навигация сайта',
            'itemListElement' => [
                [
                    '@type' => 'SiteNavigationElement',
                    'position' => 1,
                    'name' => 'Главная',
                    'url' => route('home'),
                ],
                [
                    '@type' => 'SiteNavigationElement',
                    'position' => 2,
                    'name' => 'Все сериалы',
                    'url' => route('titles.index'),
                ],
                [
                    '@type' => 'SiteNavigationElement',
                    'position' => 3,
                    'name' => 'Поиск',
                    'url' => route('titles.index').'?q={search_term_string}',
                ],
                [
                    '@type' => 'SiteNavigationElement',
                    'position' => 4,
                    'name' => 'RSS',
                    'url' => route('feed'),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function organizationJsonLd(bool $withContext = true): array
    {
        return $this->withoutEmpty([
            '@context' => $withContext ? 'https://schema.org' : null,
            '@type' => 'Organization',
            'name' => $this->siteName(),
            'url' => route('home'),
            'logo' => null,
        ]);
    }

    /**
     * @param  list<string>  $about
     * @return array<string, mixed>
     */
    private function webPageJsonLd(string $name, string $description, string $url, string $type = 'WebPage', array $about = []): array
    {
        return $this->withoutEmpty([
            '@context' => 'https://schema.org',
            '@type' => $type === 'VideoObject' ? 'WebPage' : $type,
            'name' => $name,
            'description' => $this->seoDescription($description),
            'url' => $url,
            'inLanguage' => 'ru',
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => $this->siteName(),
                'url' => route('home'),
            ],
            'publisher' => $this->organizationJsonLd(false),
            'mainEntity' => $url,
            'speakable' => [
                '@type' => 'SpeakableSpecification',
                'cssSelector' => ['[data-seo-summary]', 'h1'],
            ],
            'about' => collect($about)
                ->filter()
                ->unique()
                ->take(20)
                ->map(fn (string $name): array => ['@type' => 'Thing', 'name' => $name])
                ->values()
                ->all(),
            'keywords' => collect($about)->filter()->unique()->take(30)->implode(', '),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function collectionPageJsonLd(string $name, string $description, string $url): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $name,
            'description' => $this->seoDescription($description),
            'url' => $url,
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => $this->siteName(),
                'url' => route('home'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemListJsonLd(Collection $titles, int $startPosition, string $name): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $name,
            'numberOfItems' => $titles->count(),
            'itemListElement' => $titles
                ->values()
                ->map(fn (CatalogTitle $title, int $index): array => $this->withoutEmpty([
                    '@type' => 'ListItem',
                    'position' => $startPosition + $index,
                    'url' => route('titles.show', $title),
                    'name' => $title->title,
                    'image' => $title->poster_url,
                ]))
                ->all(),
        ];
    }

    /**
     * @return list<string>
     */
    private function catalogSeoText(int $total, string $search, ?int $year, Collection $activeTaxonomies): array
    {
        $parts = collect([
            $total.' сериалов найдено в текущей подборке каталога.',
            $year !== null ? 'Подборка сфокусирована на сериалах '.$year.' года.' : null,
            $search !== '' ? 'Поиск учитывает название, оригинальное название, описание, актеров, режиссеров, жанры, страны и другие связи каталога.' : null,
            $activeTaxonomies->isNotEmpty() ? 'Активные фильтры: '.$activeTaxonomies->pluck('name')->implode(', ').'.' : null,
        ])->filter()->implode(' ');

        return [
            $parts !== '' ? $parts : 'Каталог сериалов поддерживает фильтрацию по жанрам, странам, актерам, режиссерам, годам, переводам, статусам, каналам, студиям и тегам.',
            'Все страницы формируются автоматически из актуальной базы: после обновления появляются постеры, описания, сезоны, серии, видео и SEO-данные.',
        ];
    }

    /**
     * @return list<array{name: string, url: string}>
     */
    private function catalogRelatedLinks(string $search, ?int $year, Collection $activeTaxonomies): array
    {
        $links = collect([
            ['name' => 'Все сериалы', 'url' => route('titles.index')],
            ['name' => 'Сериалы '.now()->year.' года', 'url' => route('titles.year', ['year' => now()->year])],
        ]);

        if ($year !== null) {
            $links->push(['name' => 'Сериалы '.($year - 1).' года', 'url' => route('titles.year', ['year' => $year - 1])]);
            $links->push(['name' => 'Сериалы '.($year + 1).' года', 'url' => route('titles.year', ['year' => $year + 1])]);
        }

        foreach ($activeTaxonomies as $filterType => $taxonomy) {
            $links->push([
                'name' => $this->filterTypeLabel($filterType).': '.$taxonomy->name,
                'url' => route('titles.taxonomy', ['type' => $filterType, 'taxonomy' => $taxonomy->slug]),
            ]);
        }

        if ($search !== '') {
            $links->push(['name' => 'Поиск: '.$search, 'url' => route('titles.index', ['q' => $search])]);
        }

        return $links
            ->unique('url')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function titleSeoText(
        CatalogTitle $catalogTitle,
        Collection $genres,
        Collection $countries,
        Collection $actors,
        Collection $directors,
        int $seasonCount,
        int $episodeCount,
        int $mediaCount,
    ): array {
        $facts = collect([
            $catalogTitle->year ? 'год выхода '.$catalogTitle->year : null,
            $genres->isNotEmpty() ? 'жанры: '.$genres->take(5)->implode(', ') : null,
            $countries->isNotEmpty() ? 'страна: '.$countries->take(3)->implode(', ') : null,
            $directors->isNotEmpty() ? 'режиссеры: '.$directors->take(4)->implode(', ') : null,
            $actors->isNotEmpty() ? 'в ролях: '.$actors->take(8)->implode(', ') : null,
        ])->filter()->implode('; ');

        return [
            'Страница сериала '.$catalogTitle->title.' автоматически собирает описание, постер, сезоны, серии, связи каталога и доступные видео для просмотра онлайн.',
            ($facts !== '' ? 'Основная информация: '.$facts.'. ' : '').'Сейчас в базе указано сезонов: '.$seasonCount.', серий: '.$episodeCount.', видео-файлов: '.$mediaCount.'.',
            'SEO-данные этой страницы обновляются из импортированной информации: алиасы, оригинальное название, рейтинги, актеры, жанры, страны, сезоны и серии используются для формирования поисковых фраз.',
        ];
    }

    /**
     * @return list<array{name: string, url: string}>
     */
    private function titleRelatedLinks(CatalogTitle $catalogTitle, Collection $taxonomiesByType): array
    {
        $links = collect([
            ['name' => 'Все сериалы', 'url' => route('titles.index')],
        ]);

        if ($catalogTitle->year) {
            $links->push(['name' => 'Сериалы '.$catalogTitle->year.' года', 'url' => route('titles.year', ['year' => $catalogTitle->year])]);
        }

        foreach (['genre', 'country', 'actor', 'director', 'age_rating', 'translation'] as $type) {
            foreach ($taxonomiesByType->get($type, collect())->take(3) as $taxonomy) {
                $links->push([
                    'name' => $this->filterTypeLabel($type).': '.$taxonomy->name,
                    'url' => route('titles.taxonomy', ['type' => $type, 'taxonomy' => $taxonomy->slug]),
                ]);
            }
        }

        return $links
            ->unique('url')
            ->take(14)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function catalogSearchPhrases(string $search, ?int $year, Collection $activeTaxonomies): array
    {
        $base = collect([
            'смотреть сериалы онлайн',
            'каталог сериалов онлайн',
            'сериалы по жанрам',
            'сериалы по странам',
            'сериалы по актерам',
        ]);

        if ($search !== '') {
            $base = $base->merge([
                $search.' смотреть онлайн',
                'сериалы похожие на '.$search,
                $search.' все серии',
            ]);
        }

        if ($year !== null) {
            $base = $base->merge([
                'сериалы '.$year.' года',
                'смотреть сериалы '.$year.' онлайн',
                'новые сериалы '.$year,
            ]);
        }

        foreach ($activeTaxonomies as $taxonomy) {
            $base = $base->merge([
                'сериалы '.$taxonomy->name.' онлайн',
                'смотреть сериалы '.$taxonomy->name,
                'лучшие сериалы '.$taxonomy->name,
            ]);
        }

        return $base
            ->filter()
            ->map(fn (string $phrase): string => Str::lower($phrase))
            ->unique()
            ->take(16)
            ->values()
            ->all();
    }

    private function catalogSeoHeading(string $search, ?int $year, Collection $activeTaxonomies): string
    {
        if ($search !== '') {
            return 'Сериалы по запросу '.$search;
        }

        if ($activeTaxonomies->isNotEmpty() && $year !== null) {
            return 'Сериалы '.$year.' года: '.$activeTaxonomies->pluck('name')->implode(', ');
        }

        if ($activeTaxonomies->isNotEmpty()) {
            return 'Сериалы: '.$activeTaxonomies->pluck('name')->implode(', ');
        }

        if ($year !== null) {
            return 'Сериалы '.$year.' года смотреть онлайн';
        }

        return 'Все сериалы онлайн';
    }

    private function catalogSeoLead(int $total, string $search, ?int $year, Collection $activeTaxonomies): string
    {
        $parts = collect([
            $total.' сериалов в подборке',
            $year !== null ? 'год '.$year : null,
            $search !== '' ? 'поиск: '.$search : null,
            $activeTaxonomies->isNotEmpty() ? 'фильтры: '.$activeTaxonomies->pluck('name')->implode(', ') : null,
        ])->filter()->implode(', ');

        return ucfirst($parts).'. Автоматическая выдача учитывает названия, описания, жанры, страны, актеров, режиссеров, сезоны, серии и доступное видео.';
    }

    private function titleKeywordCollection(
        CatalogTitle $catalogTitle,
        Collection $alternateNames,
        Collection $genres,
        Collection $countries,
        Collection $actors,
        Collection $directors,
        int $seasonCount,
        int $episodeCount,
        int $mediaCount,
    ): Collection {
        $baseNames = collect([$catalogTitle->title, $catalogTitle->original_title])
            ->merge($alternateNames)
            ->filter()
            ->unique()
            ->values();
        $keywords = collect([
            $catalogTitle->title,
            $catalogTitle->original_title,
            'сериал '.$catalogTitle->title,
            'сериал '.$catalogTitle->title.' смотреть онлайн',
            $catalogTitle->title.' онлайн',
            $catalogTitle->title.' все серии',
            $catalogTitle->title.' все сезоны',
            $catalogTitle->title.' в хорошем качестве',
            $catalogTitle->title.' плеер',
            $catalogTitle->year ? $catalogTitle->title.' '.$catalogTitle->year : null,
            $catalogTitle->year ? 'сериал '.$catalogTitle->title.' '.$catalogTitle->year.' смотреть онлайн' : null,
            $seasonCount > 0 ? $catalogTitle->title.' '.$seasonCount.' сезон' : null,
            $episodeCount > 0 ? $catalogTitle->title.' '.$episodeCount.' серий' : null,
            $mediaCount > 0 ? $catalogTitle->title.' смотреть в плеере' : null,
        ])
            ->merge($baseNames)
            ->merge($genres)
            ->merge($countries)
            ->merge($actors)
            ->merge($directors);

        foreach ($baseNames->take(5) as $name) {
            $keywords = $keywords->merge([
                $name.' смотреть онлайн',
                $name.' все серии онлайн',
                $name.' сезоны и серии',
            ]);
        }

        foreach ($genres->take(5) as $genre) {
            $keywords = $keywords->merge([
                $catalogTitle->title.' '.$genre,
                'сериалы '.$genre.' онлайн',
            ]);
        }

        foreach ($countries->take(3) as $country) {
            $keywords = $keywords->merge([
                $catalogTitle->title.' '.$country,
                'сериалы '.$country.' смотреть онлайн',
            ]);
        }

        foreach ($actors->take(5) as $actor) {
            $keywords = $keywords->push($catalogTitle->title.' '.$actor);
        }

        return $keywords
            ->filter()
            ->map(fn (string $keyword): string => trim(preg_replace('/\s+/u', ' ', $keyword) ?: $keyword))
            ->filter(fn (string $keyword): bool => $keyword !== '' && mb_strlen($keyword) <= 120)
            ->unique(fn (string $keyword): string => Str::lower($keyword))
            ->values();
    }

    /**
     * @return list<string>
     */
    private function titleSearchPhrases(
        CatalogTitle $catalogTitle,
        Collection $keywords,
        Collection $genres,
        Collection $countries,
        int $seasonCount,
        int $episodeCount,
        int $mediaCount,
    ): array {
        $phrases = collect([
            'смотреть '.$catalogTitle->title.' онлайн',
            $catalogTitle->title.' все серии подряд',
            $catalogTitle->title.' все сезоны онлайн',
            $catalogTitle->title.' бесплатно в плеере',
            $catalogTitle->title.' описание сериала',
            $catalogTitle->title.' актеры и роли',
            $catalogTitle->title.' жанр и страна',
            $catalogTitle->year ? $catalogTitle->title.' '.$catalogTitle->year.' онлайн' : null,
            $seasonCount > 0 ? $catalogTitle->title.' '.$seasonCount.' сезон смотреть' : null,
            $episodeCount > 0 ? $catalogTitle->title.' '.$episodeCount.' серий смотреть' : null,
            $mediaCount > 0 ? $catalogTitle->title.' видео онлайн' : null,
        ])
            ->merge($genres->take(3)->map(fn (string $genre): string => $catalogTitle->title.' '.$genre.' сериал'))
            ->merge($countries->take(2)->map(fn (string $country): string => $catalogTitle->title.' '.$country.' сериал'))
            ->merge($keywords->take(8));

        return $phrases
            ->filter()
            ->map(fn (string $phrase): string => trim(preg_replace('/\s+/u', ' ', $phrase) ?: $phrase))
            ->filter(fn (string $phrase): bool => $phrase !== '' && mb_strlen($phrase) <= 140)
            ->unique(fn (string $phrase): string => Str::lower($phrase))
            ->take(18)
            ->values()
            ->all();
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    private function titleFaqItems(
        CatalogTitle $catalogTitle,
        Collection $genres,
        Collection $countries,
        int $seasonCount,
        int $episodeCount,
        int $mediaCount,
    ): array {
        $genreText = $genres->take(5)->implode(', ');
        $countryText = $countries->take(3)->implode(', ');

        return collect([
            [
                'question' => 'Где смотреть сериал '.$catalogTitle->title.' онлайн?',
                'answer' => $mediaCount > 0
                    ? 'Сериал '.$catalogTitle->title.' доступен на этой странице во встроенном плеере. Видео открывается через выбранную серию.'
                    : 'Страница сериала '.$catalogTitle->title.' уже создана, а видео появится здесь после ближайшего обновления.',
            ],
            [
                'question' => 'Сколько сезонов и серий в сериале '.$catalogTitle->title.'?',
                'answer' => 'В каталоге сейчас указано сезонов: '.$seasonCount.', серий: '.$episodeCount.'. Эти данные обновляются автоматически после обработки источника.',
            ],
            [
                'question' => 'Какой год, жанр и страна у сериала '.$catalogTitle->title.'?',
                'answer' => collect([
                    $catalogTitle->year ? 'Год выхода: '.$catalogTitle->year : null,
                    $genreText !== '' ? 'жанры: '.$genreText : null,
                    $countryText !== '' ? 'страна: '.$countryText : null,
                ])->filter()->implode('; ').'.',
            ],
            [
                'question' => 'Обновляется ли информация по сериалу '.$catalogTitle->title.'?',
                'answer' => 'Да, портал автоматически обновляет описание, сезоны, серии, постер, связи каталога и доступные видео.',
            ],
        ])
            ->filter(fn (array $item): bool => trim($item['answer'], " .\t\n\r\0\x0B") !== '')
            ->values()
            ->all();
    }

    /**
     * @param  list<array{question: string, answer: string}>  $items
     * @return array<string, mixed>
     */
    private function faqJsonLd(array $items): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => collect($items)
                ->map(fn (array $item): array => [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $item['answer'],
                    ],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function seasonJsonLd(CatalogTitle $catalogTitle, Collection $seasons): array
    {
        return $seasons
            ->values()
            ->map(fn ($season): array => $this->withoutEmpty([
                '@type' => 'CreativeWorkSeason',
                'name' => 'Сезон '.$season->number,
                'seasonNumber' => (int) $season->number,
                'numberOfEpisodes' => $season->episodes->count(),
                'url' => route('titles.show', $catalogTitle).'#season-'.$season->number,
            ]))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function episodeItemListJsonLd(CatalogTitle $catalogTitle, Collection $seasons): array
    {
        $episodes = $seasons
            ->flatMap(fn ($season): Collection => $season->episodes
                ->sortBy('number')
                ->values()
                ->map(fn ($episode): array => [
                    'season' => $season,
                    'episode' => $episode,
                ]))
            ->values()
            ->take(100);

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => 'Серии '.$catalogTitle->title,
            'numberOfItems' => $episodes->count(),
            'itemListElement' => $episodes
                ->values()
                ->map(fn (array $item, int $index): array => $this->withoutEmpty([
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => $this->withoutEmpty([
                        '@type' => 'TVEpisode',
                        'name' => $item['episode']->title ?: $item['episode']->number.' серия',
                        'episodeNumber' => (int) $item['episode']->number,
                        'partOfSeason' => [
                            '@type' => 'CreativeWorkSeason',
                            'seasonNumber' => (int) $item['season']->number,
                        ],
                        'partOfSeries' => [
                            '@type' => 'TVSeries',
                            'name' => $catalogTitle->title,
                        ],
                        'url' => route('titles.show', ['catalogTitle' => $catalogTitle, 'episode' => $item['episode']->id]).'#player',
                    ]),
                ]))
                ->all(),
        ];
    }

    /**
     * @param  list<array{name: string, url: string}>  $items
     * @return array<string, mixed>
     */
    private function breadcrumbJsonLd(array $items): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($items)
                ->values()
                ->map(fn (array $item, int $index): array => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $item['name'],
                    'item' => $item['url'],
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutEmpty(array $data): array
    {
        return array_filter($data, fn (mixed $value): bool => ! ($value === null || $value === '' || $value === []));
    }

    /**
     * @return list<array{title: string, items: list<string>}>
     */
    private function catalogKeywordClusters(string $search, ?int $year, Collection $activeTaxonomies): array
    {
        $taxonomyNames = $activeTaxonomies->pluck('name')->filter()->values();

        return [
            [
                'title' => 'Смотреть онлайн',
                'items' => collect(['смотреть сериалы онлайн', 'сериалы онлайн в хорошем качестве', 'все серии сериалов онлайн', 'все сезоны сериалов'])
                    ->when($search !== '', fn (Collection $items): Collection => $items->push($search.' смотреть онлайн'))
                    ->values()
                    ->all(),
            ],
            [
                'title' => 'Подборки',
                'items' => collect(['сериалы по жанрам', 'сериалы по странам', 'сериалы по актерам', 'сериалы по режиссерам'])
                    ->merge($taxonomyNames->map(fn (string $name): string => 'сериалы '.$name.' онлайн'))
                    ->values()
                    ->all(),
            ],
            [
                'title' => 'Годы и обновления',
                'items' => collect(['новые сериалы', 'обновления сериалов', 'новые серии'])
                    ->when($year !== null, fn (Collection $items): Collection => $items->push('сериалы '.$year.' года')->push('смотреть сериалы '.$year.' онлайн'))
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * @return list<array{title: string, items: list<string>}>
     */
    private function titleKeywordClusters(
        CatalogTitle $catalogTitle,
        Collection $genres,
        Collection $countries,
        Collection $actors,
        Collection $directors,
        int $seasonCount,
        int $episodeCount,
        int $mediaCount,
    ): array {
        return [
            [
                'title' => 'Смотреть онлайн',
                'items' => collect([
                    $catalogTitle->title.' смотреть онлайн',
                    $catalogTitle->title.' все серии',
                    $catalogTitle->title.' все сезоны',
                    $mediaCount > 0 ? $catalogTitle->title.' смотреть в плеере' : $catalogTitle->title.' видео скоро появится',
                ])->filter()->values()->all(),
            ],
            [
                'title' => 'Информация о сериале',
                'items' => collect([
                    $catalogTitle->year ? $catalogTitle->title.' '.$catalogTitle->year : null,
                    $seasonCount > 0 ? $catalogTitle->title.' '.$seasonCount.' сезон' : null,
                    $episodeCount > 0 ? $catalogTitle->title.' '.$episodeCount.' серий' : null,
                    $genres->isNotEmpty() ? $catalogTitle->title.' '.$genres->first() : null,
                    $countries->isNotEmpty() ? $catalogTitle->title.' '.$countries->first() : null,
                ])->filter()->values()->all(),
            ],
            [
                'title' => 'Люди и связи',
                'items' => collect()
                    ->merge($actors->take(5)->map(fn (string $actor): string => $catalogTitle->title.' '.$actor))
                    ->merge($directors->take(3)->map(fn (string $director): string => $catalogTitle->title.' режиссер '.$director))
                    ->values()
                    ->all(),
            ],
        ];
    }

    private function sitemapDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->toAtomString();
            } catch (\Throwable) {
                return now()->toAtomString();
            }
        }

        return now()->toAtomString();
    }

    /**
     * @return list<string>
     */
    private function filterRelationNames(): array
    {
        return collect(self::FILTER_RELATIONS)->pluck('relation')->values()->all();
    }

    /**
     * @return list<string>
     */
    private function cardRelations(): array
    {
        return ['genres', 'countries', 'ageRatings', 'translations', 'tags', 'seasons'];
    }

    /**
     * @return class-string<Model>
     */
    private function filterModelClass(string $filterType): string
    {
        return self::FILTER_RELATIONS[$filterType]['model'];
    }
}
