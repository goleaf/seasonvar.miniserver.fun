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
use App\Models\SourcePage;
use App\Models\Studio;
use App\Models\Tag;
use App\Models\Translation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

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

    public function index(): View
    {
        return view('catalog.index', [
            'stats' => [
                'titles' => CatalogTitle::query()->count(),
                'sourcePages' => SourcePage::query()->count(),
                'pendingPages' => SourcePage::query()->where('parse_status', 'pending')->count(),
                'episodes' => Episode::query()->count(),
            ],
            'latestTitles' => CatalogTitle::query()
                ->with($this->cardRelations())
                ->withCount(['seasons', 'episodes'])
                ->latest('indexed_at')
                ->limit(64)
                ->get(),
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

        $titles = $this->catalogTitleFilterQuery($activeTaxonomies, $invalidFilterSlugs, $search, $year, null, $invalidYear)
            ->with($this->cardRelations())
            ->withCount(['seasons', 'episodes'])
            ->latest('indexed_at')
            ->paginate(24)
            ->withQueryString();

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

        $taxonomyContextCounts = $this->relationContextCounts($filterTaxonomies, $activeTaxonomies, $invalidFilterSlugs, $search, $year, $invalidYear);
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

        $yearContextCounts = $this
            ->catalogTitleFilterQuery($activeTaxonomies, $invalidFilterSlugs, $search, null, null, $invalidYear)
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
            'selectedTaxonomy' => $activeTaxonomies->first(),
            'activeTaxonomies' => $activeTaxonomies,
            'activeFilterSlugs' => $activeFilterSlugs,
            'invalidFilterSlugs' => $invalidFilterSlugs,
            'filterTaxonomies' => $filterTaxonomies,
            'filterTypes' => $filterTypes,
            'yearBuckets' => $yearBuckets,
        ]);
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
    ): Builder {
        $query = CatalogTitle::query();

        if ($invalidFilterSlugs !== [] || $invalidYear) {
            $query->whereRaw('1 = 0');
        }

        if ($year !== null) {
            $query->where('year', $year);
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

        $query->where(function (Builder $query) use ($search): void {
            $query->where('title', 'like', "%{$search}%")
                ->orWhere('original_title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");

            foreach ($this->filterRelationNames() as $relation) {
                $query->orWhereHas($relation, function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%");
                });
            }
        });
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
    ): Collection {
        $visibleIdsByType = $filterTaxonomies
            ->map(fn (Collection $items): Collection => $items->pluck('id')->values())
            ->filter(fn (Collection $ids): bool => $ids->isNotEmpty());

        if ($visibleIdsByType->isEmpty()) {
            return collect();
        }

        $catalogTitleTable = (new CatalogTitle)->getTable();
        $contextQueries = $visibleIdsByType
            ->map(function (Collection $recordIds, string $filterType) use ($activeTaxonomies, $invalidFilterSlugs, $search, $year, $invalidYear, $catalogTitleTable) {
                $relationName = self::FILTER_RELATIONS[$filterType]['relation'];
                $catalogTitleRelation = (new CatalogTitle)->{$relationName}();
                $pivotTable = $catalogTitleRelation->getTable();
                $titlePivotKey = $catalogTitleRelation->getForeignPivotKeyName();
                $relatedPivotKey = $catalogTitleRelation->getRelatedPivotKeyName();
                $filteredTitlesQuery = $this
                    ->catalogTitleFilterQuery($activeTaxonomies, $invalidFilterSlugs, $search, $year, $filterType, $invalidYear)
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
                '%05d-%05d-%s',
                $media->season?->number ?? 99999,
                $media->episode?->number ?? 99999,
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
            'episodeCount' => $seasons->sum(fn ($season): int => (int) $season->episodes->count()),
            'taxonomyCount' => $taxonomiesByType->sum(fn (Collection $items): int => $items->count()),
            'parsedSeasonCount' => $seasons->filter(fn ($season): bool => $season->episodes->isNotEmpty())->count(),
            'selectedEpisode' => $selectedEpisode,
            'selectedMedia' => $selectedMedia,
            'mediaItems' => $mediaItems,
            'mediaCount' => $mediaItems->count(),
            'recommendedTitles' => $recommendedTitlesQuery
                ->latest('indexed_at')
                ->limit(6)
                ->get(),
        ]);
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
