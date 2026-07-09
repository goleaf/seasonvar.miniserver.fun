<?php

namespace App\Http\Controllers;

use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\SourcePage;
use App\Models\Taxonomy;
use Illuminate\Http\Request;
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
            ],
            'latestTitles' => CatalogTitle::query()
                ->with(['taxonomies', 'seasons'])
                ->latest('indexed_at')
                ->limit(12)
                ->get(),
            'genres' => Taxonomy::query()
                ->where('type', 'genre')
                ->withCount('catalogTitles')
                ->orderByDesc('catalog_titles_count')
                ->limit(12)
                ->get(),
        ]);
    }

    public function titles(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $taxonomySlug = trim((string) $request->query('taxonomy', ''));
        $taxonomyType = trim((string) $request->query('type', ''));
        $selectedTaxonomy = $taxonomySlug === ''
            ? null
            : Taxonomy::query()
                ->where('slug', $taxonomySlug)
                ->when($taxonomyType !== '', fn ($query) => $query->where('type', $taxonomyType))
                ->first();

        $titles = CatalogTitle::query()
            ->with(['taxonomies', 'seasons'])
            ->when($selectedTaxonomy !== null, function ($query) use ($selectedTaxonomy): void {
                $query->whereHas('taxonomies', function ($query) use ($selectedTaxonomy): void {
                    $query->whereKey($selectedTaxonomy->id);
                });
            })
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('original_title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('taxonomies', function ($query) use ($search): void {
                            $query->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->latest('indexed_at')
            ->paginate(24)
            ->withQueryString();

        return view('catalog.titles', [
            'titles' => $titles,
            'search' => $search,
            'selectedTaxonomy' => $selectedTaxonomy,
        ]);
    }

    public function show(Request $request, CatalogTitle $catalogTitle): View
    {
        $catalogTitle->load([
            'source',
            'taxonomies',
            'seasons.episodes',
            'licensedMedia' => fn ($query) => $query->published()->latest('published_at')->latest(),
        ]);

        $selectedMedia = $catalogTitle->licensedMedia->firstWhere('id', $request->integer('media'))
            ?? $catalogTitle->licensedMedia->first();
        $relatedTaxonomyIds = $catalogTitle->taxonomies
            ->whereIn('type', ['genre', 'country'])
            ->pluck('id');
        $seasonUrls = $catalogTitle->seasons
            ->pluck('source_url')
            ->filter()
            ->unique()
            ->values();
        $episodeCountsBySeasonUrl = CatalogTitle::query()
            ->select('catalog_titles.source_url')
            ->selectRaw('count(episodes.id) as episodes_count')
            ->join('seasons', 'seasons.catalog_title_id', '=', 'catalog_titles.id')
            ->leftJoin('episodes', 'episodes.season_id', '=', 'seasons.id')
            ->whereIn('catalog_titles.source_url', $seasonUrls)
            ->groupBy('catalog_titles.source_url')
            ->pluck('episodes_count', 'source_url');

        return view('catalog.show', [
            'title' => $catalogTitle,
            'selectedMedia' => $selectedMedia,
            'episodeCountsBySeasonUrl' => $episodeCountsBySeasonUrl,
            'recommendedTitles' => CatalogTitle::query()
                ->with(['taxonomies', 'seasons'])
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
