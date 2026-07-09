<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CatalogSitemapController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', [CatalogController::class, 'index'])->name('home');
Route::get('/sitemap.xml', [CatalogSitemapController::class, 'sitemap'])->name('sitemap');
Route::get('/sitemap-index.xml', [CatalogSitemapController::class, 'sitemapIndex'])->name('sitemap.index');
Route::get('/sitemap-static.xml', [CatalogSitemapController::class, 'sitemapStatic'])->name('sitemap.static');
Route::get('/sitemap-taxonomies.xml', [CatalogSitemapController::class, 'sitemapTaxonomies'])->name('sitemap.taxonomies');
Route::get('/sitemap-landings.xml', [CatalogSitemapController::class, 'sitemapLandings'])->name('sitemap.landings');
Route::get('/sitemap-titles-{page}.xml', [CatalogSitemapController::class, 'sitemapTitles'])
    ->whereNumber('page')
    ->name('sitemap.titles');
Route::get('/sitemap-videos-{page}.xml', [CatalogSitemapController::class, 'sitemapVideos'])
    ->whereNumber('page')
    ->name('sitemap.videos');
Route::get('/feed.xml', [CatalogSitemapController::class, 'feed'])->name('feed');
Route::get('/opensearch.xml', [CatalogSitemapController::class, 'openSearch'])->name('opensearch');
Route::get('/llms.txt', [CatalogSitemapController::class, 'llms'])->name('llms');
Route::get('/titles', [CatalogController::class, 'titles'])->name('titles.index');
Route::get('/titles/year/{year}', [CatalogController::class, 'titlesByYear'])
    ->where('year', '(?:19|20)\d{2}')
    ->name('titles.year');
Route::get('/titles/{type}/{taxonomy}', [CatalogController::class, 'titles'])
    ->where('type', 'genre|country|actor|director|age_rating|translation|status|network|studio|tag')
    ->where('taxonomy', '[a-z0-9][a-z0-9-]*')
    ->name('titles.taxonomy');
Route::get('/titles/{catalogTitle:slug}', [CatalogController::class, 'show'])->name('titles.show');

Route::fallback(function (Request $request) {
    if ($request->is('api/*')) {
        abort(404);
    }

    return redirect()->route('home');
});
