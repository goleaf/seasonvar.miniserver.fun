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

    private const SITEMAP_PAGE_SIZE = 10000;

    public function index(): View
    {
        $stats = [
            'titles' => CatalogTitle::query()->count(),
            'sourcePages' => SourcePage::query()->count(),
            'pendingPages' => SourcePage::query()->where('parse_status', 'pending')->count(),
            'episodes' => Episode::query()->count(),
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
            'seo' => $this->titlesSeo(
                $request,
                (int) $titles->total(),
                $search,
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
            ),
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

    public function sitemap(): StreamedResponse
    {
        return response()->stream(function (): void {
            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

            $this->writeSitemapUrl(route('home'), now(), 'daily', '1.0');
            $this->writeSitemapUrl(route('titles.index'), now(), 'daily', '0.9');

            foreach (self::FILTER_RELATIONS as $filterType => $config) {
                $modelClass = $config['model'];

                $modelClass::query()
                    ->select(['id', 'slug'])
                    ->whereHas('catalogTitles')
                    ->orderBy('id')
                    ->chunkById(1000, function (Collection $taxonomies) use ($filterType): void {
                        foreach ($taxonomies as $taxonomy) {
                            $this->writeSitemapUrl(url('/titles/'.$filterType.'/'.$taxonomy->slug), now(), 'weekly', '0.7');
                        }
                    });
            }

            CatalogTitle::query()
                ->select(['id', 'slug', 'updated_at', 'indexed_at'])
                ->where('is_published', true)
                ->whereNotNull('slug')
                ->orderBy('id')
                ->chunkById(1000, function (Collection $titles): void {
                    foreach ($titles as $title) {
                        $this->writeSitemapUrl(url('/titles/'.$title->slug), $title->indexed_at ?: $title->updated_at, 'weekly', '0.8');
                    }
                });

            echo '</urlset>'."\n";
        }, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * @param  array{titles: int, sourcePages: int, pendingPages: int, episodes: int}  $stats
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
            'canonical' => route('home'),
            'type' => 'website',
            'updated_time' => now()->toAtomString(),
            'tags' => ['сериалы онлайн', 'каталог сериалов', 'новые серии', 'сезоны сериалов'],
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
    ): array {
        $activeLabels = $activeTaxonomies
            ->map(fn (Model $record, string $filterType): string => $this->filterTypeLabel($filterType).': '.$record->name)
            ->values();
        $filterText = $activeLabels->implode(', ');
        $title = match (true) {
            $search !== '' => 'Поиск "'.$search.'" - сериалы онлайн',
            $filterText !== '' && $year !== null => 'Сериалы '.$year.' - '.$filterText,
            $filterText !== '' => 'Сериалы - '.$filterText,
            $year !== null => 'Сериалы '.$year.' года онлайн',
            default => 'Все сериалы онлайн',
        };

        if ($currentPage > 1) {
            $title .= ' - страница '.$currentPage;
        }

        $descriptionParts = collect([
            $total.' сериалов найдено в каталоге.',
            $filterText !== '' ? 'Фильтры: '.$filterText.'.' : null,
            $year !== null ? 'Год выхода: '.$year.'.' : null,
            $search !== '' ? 'Поисковый запрос: '.$search.'.' : null,
            $invalidYear ? 'Год '.$requestedYear.' не найден.' : null,
            $invalidFilterSlugs !== [] ? 'Часть фильтров не найдена.' : null,
        ])->filter()->implode(' ');
        $canonical = $this->catalogCanonicalUrl($request, $activeTaxonomies, $year, $currentPage);
        $robots = $search === '' && $invalidFilterSlugs === [] && ! $invalidYear && $activeTaxonomies->count() <= 1
            ? $this->indexRobots()
            : 'noindex,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';
        $keywords = collect(['сериалы онлайн', 'каталог сериалов', 'смотреть сериалы'])
            ->merge($activeTaxonomies->pluck('name'))
            ->when($year !== null, fn (Collection $items): Collection => $items->push('сериалы '.$year))
            ->filter()
            ->unique()
            ->values();
        $tags = $keywords->take(20)->all();

        return [
            'title' => $title,
            'description' => $this->seoDescription($descriptionParts),
            'keywords' => $keywords->implode(', '),
            'canonical' => $canonical,
            'robots' => $robots,
            'prev' => $previousPageUrl,
            'next' => $nextPageUrl,
            'type' => 'website',
            'updated_time' => now()->toAtomString(),
            'tags' => $tags,
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
            $mediaCount > 0 ? 'Видео доступно во встроенном web player.' : null,
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
        $jsonLd = [
            $this->organizationJsonLd(),
            $this->websiteJsonLd(),
            $this->siteNavigationJsonLd(),
            $this->webPageJsonLd($pageTitle, $description, route('titles.show', $catalogTitle), 'VideoObject', collect([$catalogTitle->title])
                ->merge($alternateNames)
                ->merge($genres)
                ->merge($countries)
                ->merge($actors)
                ->merge($directors)
                ->filter()
                ->unique()
                ->take(25)
                ->values()
                ->all()),
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
            'keywords' => collect([
                $catalogTitle->title,
                $catalogTitle->original_title,
                'сериал '.$catalogTitle->title.' смотреть онлайн',
                $catalogTitle->year ? $catalogTitle->title.' '.$catalogTitle->year : null,
            ])
                ->merge($alternateNames)
                ->merge($genres)
                ->merge($countries)
                ->merge($actors)
                ->merge($directors)
                ->filter()
                ->unique()
                ->take(30)
                ->implode(', '),
            'canonical' => route('titles.show', $catalogTitle),
            'image' => $catalogTitle->poster_url,
            'image_alt' => 'Постер '.$catalogTitle->title,
            'video' => $selectedMediaUrl,
            'published_time' => $this->sitemapDate($catalogTitle->created_at),
            'updated_time' => $this->sitemapDate($catalogTitle->indexed_at ?: $catalogTitle->updated_at),
            'tags' => collect([$catalogTitle->title])
                ->merge($alternateNames)
                ->merge($genres)
                ->merge($countries)
                ->merge($actors)
                ->merge($directors)
                ->filter()
                ->unique()
                ->take(25)
                ->values()
                ->all(),
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

    private function catalogCanonicalUrl(Request $request, Collection $activeTaxonomies, ?int $year, int $currentPage): string
    {
        $query = [];

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
            'about' => collect($about)
                ->filter()
                ->unique()
                ->take(20)
                ->map(fn (string $name): array => ['@type' => 'Thing', 'name' => $name])
                ->values()
                ->all(),
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
                    ? 'Сериал '.$catalogTitle->title.' доступен на этой странице во встроенном web player. Видео-файлы подключаются удаленно и открываются через выбранную серию.'
                    : 'Страница сериала '.$catalogTitle->title.' уже создана, а видео-файлы будут показаны здесь после очередной автоматической синхронизации.',
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
                'answer' => 'Да, портал автоматически обновляет метаданные, сезоны, серии, постер, связи каталога и доступные видео через синхронизацию Seasonvar.',
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

    private function writeSitemapUrl(string $loc, mixed $lastmod, string $changefreq, string $priority): void
    {
        echo "    <url>\n";
        echo '        <loc>'.$this->xml($loc)."</loc>\n";
        echo '        <lastmod>'.$this->xml($this->sitemapDate($lastmod))."</lastmod>\n";
        echo '        <changefreq>'.$this->xml($changefreq)."</changefreq>\n";
        echo '        <priority>'.$this->xml($priority)."</priority>\n";
        echo "    </url>\n";
    }

    public function sitemapIndex(): StreamedResponse
    {
        return response()->stream(function (): void {
            $titleSitemapPages = max(1, (int) ceil(CatalogTitle::query()
                ->where('is_published', true)
                ->whereNotNull('slug')
                ->count() / self::SITEMAP_PAGE_SIZE));

            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
            $this->writeSitemapIndexUrl(route('sitemap.static'), now());
            $this->writeSitemapIndexUrl(route('sitemap.taxonomies'), now());

            for ($page = 1; $page <= $titleSitemapPages; $page++) {
                $this->writeSitemapIndexUrl(route('sitemap.titles', ['page' => $page]), now());
            }

            echo '</sitemapindex>'."\n";
        }, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function sitemapStatic(): StreamedResponse
    {
        return response()->stream(function (): void {
            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
            $this->writeSitemapUrl(route('home'), now(), 'daily', '1.0');
            $this->writeSitemapUrl(route('titles.index'), now(), 'daily', '0.9');

            CatalogTitle::query()
                ->select('year')
                ->whereNotNull('year')
                ->where('year', '>=', 1900)
                ->where('year', '<=', (int) now()->format('Y') + 1)
                ->groupBy('year')
                ->orderByDesc('year')
                ->cursor()
                ->each(function (CatalogTitle $bucket): void {
                    $this->writeSitemapUrl(route('titles.index', ['year' => (int) $bucket->year]), now(), 'weekly', '0.7');
                });

            echo '</urlset>'."\n";
        }, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function sitemapTaxonomies(): StreamedResponse
    {
        return response()->stream(function (): void {
            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

            foreach (self::FILTER_RELATIONS as $filterType => $config) {
                $modelClass = $config['model'];

                $modelClass::query()
                    ->select(['id', 'slug'])
                    ->whereHas('catalogTitles')
                    ->orderBy('id')
                    ->chunkById(1000, function (Collection $taxonomies) use ($filterType): void {
                        foreach ($taxonomies as $taxonomy) {
                            $this->writeSitemapUrl(route('titles.taxonomy', ['type' => $filterType, 'taxonomy' => $taxonomy->slug]), now(), 'weekly', '0.7');
                        }
                    });
            }

            echo '</urlset>'."\n";
        }, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function sitemapTitles(int $page): StreamedResponse
    {
        $page = max(1, $page);

        return response()->stream(function () use ($page): void {
            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n";

            CatalogTitle::query()
                ->select(['id', 'slug', 'title', 'poster_url', 'updated_at', 'indexed_at'])
                ->where('is_published', true)
                ->whereNotNull('slug')
                ->orderBy('id')
                ->forPage($page, self::SITEMAP_PAGE_SIZE)
                ->get()
                ->each(function (CatalogTitle $title): void {
                    $this->writeSitemapUrlWithImage(
                        route('titles.show', $title),
                        $title->indexed_at ?: $title->updated_at,
                        'weekly',
                        '0.8',
                        $title->poster_url,
                        'Постер '.$title->title,
                    );
                });

            echo '</urlset>'."\n";
        }, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function feed(): StreamedResponse
    {
        return response()->stream(function (): void {
            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">'."\n";
            echo "    <channel>\n";
            echo '        <title>'.$this->xml($this->siteName())."</title>\n";
            echo '        <link>'.$this->xml(route('home'))."</link>\n";
            echo '        <description>'.$this->xml('Новые и обновленные сериалы каталога')."</description>\n";
            echo '        <language>ru</language>'."\n";
            echo '        <atom:link href="'.$this->xml(route('feed')).'" rel="self" type="application/rss+xml" />'."\n";

            CatalogTitle::query()
                ->select(['id', 'slug', 'title', 'description', 'poster_url', 'updated_at', 'indexed_at'])
                ->where('is_published', true)
                ->whereNotNull('slug')
                ->latest('indexed_at')
                ->limit(100)
                ->get()
                ->each(function (CatalogTitle $title): void {
                    $url = route('titles.show', $title);
                    echo "        <item>\n";
                    echo '            <title>'.$this->xml($title->title)."</title>\n";
                    echo '            <link>'.$this->xml($url)."</link>\n";
                    echo '            <guid isPermaLink="true">'.$this->xml($url)."</guid>\n";
                    echo '            <pubDate>'.$this->xml(Carbon::parse($title->indexed_at ?: $title->updated_at ?: now())->toRssString())."</pubDate>\n";
                    echo '            <description>'.$this->xml($this->seoDescription($title->description ?: 'Сериал '.$title->title.' смотреть онлайн.'))."</description>\n";
                    echo "        </item>\n";
                });

            echo "    </channel>\n";
            echo '</rss>'."\n";
        }, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }

    public function openSearch(): StreamedResponse
    {
        return response()->stream(function (): void {
            $shortName = Str::limit($this->siteName(), 16, '');

            echo '<?xml version="1.0" encoding="UTF-8"?>'."\n";
            echo '<OpenSearchDescription xmlns="http://a9.com/-/spec/opensearch/1.1/">'."\n";
            echo '    <ShortName>'.$this->xml($shortName)."</ShortName>\n";
            echo '    <Description>'.$this->xml('Поиск сериалов по каталогу')."</Description>\n";
            echo '    <InputEncoding>UTF-8</InputEncoding>'."\n";
            echo '    <Url type="text/html" method="get" template="'.$this->xml(route('titles.index').'?q={searchTerms}').'" />'."\n";
            echo '</OpenSearchDescription>'."\n";
        }, 200, ['Content-Type' => 'application/opensearchdescription+xml; charset=UTF-8']);
    }

    private function writeSitemapIndexUrl(string $loc, mixed $lastmod): void
    {
        echo "    <sitemap>\n";
        echo '        <loc>'.$this->xml($loc)."</loc>\n";
        echo '        <lastmod>'.$this->xml($this->sitemapDate($lastmod))."</lastmod>\n";
        echo "    </sitemap>\n";
    }

    private function writeSitemapUrlWithImage(
        string $loc,
        mixed $lastmod,
        string $changefreq,
        string $priority,
        ?string $imageUrl,
        ?string $imageTitle,
    ): void {
        echo "    <url>\n";
        echo '        <loc>'.$this->xml($loc)."</loc>\n";
        echo '        <lastmod>'.$this->xml($this->sitemapDate($lastmod))."</lastmod>\n";
        echo '        <changefreq>'.$this->xml($changefreq)."</changefreq>\n";
        echo '        <priority>'.$this->xml($priority)."</priority>\n";

        if ($imageUrl !== null && trim($imageUrl) !== '') {
            echo "        <image:image>\n";
            echo '            <image:loc>'.$this->xml($imageUrl)."</image:loc>\n";

            if ($imageTitle !== null && trim($imageTitle) !== '') {
                echo '            <image:title>'.$this->xml($imageTitle)."</image:title>\n";
            }

            echo "        </image:image>\n";
        }

        echo "    </url>\n";
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

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
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
