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

        $titles = CatalogTitle::query()
            ->with(['taxonomies', 'seasons'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('original_title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->latest('indexed_at')
            ->paginate(24)
            ->withQueryString();

        return view('catalog.titles', [
            'titles' => $titles,
            'search' => $search,
        ]);
    }

    public function show(CatalogTitle $catalogTitle): View
    {
        $catalogTitle->load(['source', 'taxonomies', 'seasons.episodes', 'licensedMedia']);

        return view('catalog.show', [
            'title' => $catalogTitle,
        ]);
    }
}
