<?php

namespace App\Http\Controllers;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\SourcePage;
use App\Models\Taxonomy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class CatalogController extends Controller
{
    public function index(): View
    {
        return view('catalog.index', [
            'stats' => [
                'titles' => CatalogTitle::query()->count(),
                'sourcePages' => SourcePage::query()->count(),
                'pendingPages' => SourcePage::query()->where('parse_status', 'pending')->count(),
                'licensedMedia' => LicensedMedia::query()->count(),
                'episodes' => Episode::query()->count(),
            ],
            'latestTitles' => CatalogTitle::query()
                ->with(['taxonomies', 'seasons'])
                ->withCount(['seasons', 'episodes'])
                ->latest('indexed_at')
                ->limit(64)
                ->get(),
            'posterTitles' => CatalogTitle::query()
                ->with(['taxonomies', 'seasons'])
                ->withCount(['seasons', 'episodes'])
                ->whereNotNull('poster_url')
                ->latest('indexed_at')
                ->limit(18)
                ->get(),
            'genres' => Taxonomy::query()
                ->where('type', 'genre')
                ->withCount('catalogTitles')
                ->orderByDesc('catalog_titles_count')
                ->limit(18)
                ->get(),
            'countries' => Taxonomy::query()
                ->where('type', 'country')
                ->withCount('catalogTitles')
                ->orderByDesc('catalog_titles_count')
                ->limit(10)
                ->get(),
            'subtitleTag' => Taxonomy::query()
                ->where('type', 'tag')
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
        $filterTypes = ['genre', 'country', 'actor', 'director', 'age_rating', 'translation', 'status', 'network', 'studio', 'tag'];
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

        $activeTaxonomies = collect($activeFilterSlugs)
            ->map(function (string $slug, string $filterType): ?Taxonomy {
                return Taxonomy::query()
                    ->where('type', $filterType)
                    ->where('slug', $slug)
                    ->withCount('catalogTitles')
                    ->first();
            })
            ->filter();
        $invalidFilterSlugs = $invalidInputFilterSlugs + array_diff_key($activeFilterSlugs, $activeTaxonomies->all());

        if ($taxonomy !== null && ! in_array($type, $filterTypes, true)) {
            abort(404);
        }

        $activeFilterSlugs = $activeTaxonomies
            ->mapWithKeys(fn (Taxonomy $taxonomy, string $filterType): array => [$filterType => $taxonomy->slug])
            ->all();

        $titles = $this->catalogTitleFilterQuery($activeTaxonomies, $invalidFilterSlugs, $search, $year, null, $invalidYear)
            ->with(['taxonomies', 'seasons'])
            ->withCount(['seasons', 'episodes'])
            ->latest('indexed_at')
            ->paginate(24)
            ->withQueryString();
        $filterTaxonomies = Taxonomy::query()
            ->whereIn('type', $filterTypes)
            ->withCount('catalogTitles')
            ->orderBy('type')
            ->orderByDesc('catalog_titles_count')
            ->get()
            ->filter(fn (Taxonomy $taxonomy): bool => $taxonomy->catalog_titles_count > 0)
            ->groupBy('type')
            ->map(fn ($items) => $items->take(12)->values());

        $activeTaxonomies->each(function (Taxonomy $activeTaxonomy, string $filterType) use ($filterTaxonomies): void {
            $items = $filterTaxonomies->get($filterType, collect());

            if (! $items->contains(fn (Taxonomy $taxonomy): bool => $taxonomy->id === $activeTaxonomy->id)) {
                $filterTaxonomies->put($filterType, $items->prepend($activeTaxonomy)->values());
            }
        });

        $filterTaxonomies->each(function (Collection $items) use ($activeTaxonomies, $invalidFilterSlugs, $search, $year, $invalidYear): void {
            foreach ($items as $candidateTaxonomy) {
                $candidateTaxonomy->context_titles_count = $this
                    ->catalogTitleFilterQuery($activeTaxonomies, $invalidFilterSlugs, $search, $year, $candidateTaxonomy->type, $invalidYear)
                    ->whereHas('taxonomies', function (Builder $query) use ($candidateTaxonomy): void {
                        $query->whereKey($candidateTaxonomy->id);
                    })
                    ->count();
            }
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

        $yearBuckets->each(function (object $bucket) use ($activeTaxonomies, $invalidFilterSlugs, $search, $invalidYear): void {
            $bucket->context_titles_count = $this
                ->catalogTitleFilterQuery($activeTaxonomies, $invalidFilterSlugs, $search, (int) $bucket->year, null, $invalidYear)
                ->count();
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
        $this->applyTaxonomyFilters($query, $activeTaxonomies, $exceptTaxonomyType);

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
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhereHas('taxonomies', function (Builder $query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%");
                });
        });
    }

    private function applyTaxonomyFilters(Builder $query, Collection $activeTaxonomies, ?string $exceptTaxonomyType = null): void
    {
        foreach ($activeTaxonomies as $filterType => $activeTaxonomy) {
            if ($filterType === $exceptTaxonomyType) {
                continue;
            }

            $query->whereHas('taxonomies', function (Builder $query) use ($activeTaxonomy): void {
                $query->whereKey($activeTaxonomy->id);
            });
        }
    }

    public function show(Request $request, CatalogTitle $catalogTitle): View
    {
        $catalogTitle->load([
            'source',
            'taxonomies',
            'seasons.episodes',
            'licensedMedia' => fn ($query) => $query->published()->with(['season', 'episode'])->latest('published_at')->latest(),
        ]);

        $mediaItems = $catalogTitle->licensedMedia
            ->sortBy(fn (LicensedMedia $media): string => sprintf(
                '%05d-%05d-%s',
                $media->season?->number ?? 99999,
                $media->episode?->number ?? 99999,
                $media->title,
            ))
            ->values();
        $selectedMedia = $mediaItems->firstWhere('id', $request->integer('media'))
            ?? $mediaItems->first();
        $relatedTaxonomyIds = $catalogTitle->taxonomies
            ->whereIn('type', ['genre', 'country'])
            ->pluck('id');

        return view('catalog.show', [
            'title' => $catalogTitle,
            'selectedMedia' => $selectedMedia,
            'mediaItems' => $mediaItems,
            'recommendedTitles' => CatalogTitle::query()
                ->with(['taxonomies', 'seasons'])
                ->withCount(['seasons', 'episodes'])
                ->whereKeyNot($catalogTitle->id)
                ->when($relatedTaxonomyIds->isNotEmpty(), function ($query) use ($relatedTaxonomyIds): void {
                    $query->whereHas('taxonomies', function ($query) use ($relatedTaxonomyIds): void {
                        $query->whereIn('taxonomies.id', $relatedTaxonomyIds);
                    });
                })
                ->latest('indexed_at')
                ->limit(4)
                ->get(),
        ]);
    }
}
