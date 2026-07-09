<?php

use App\Http\Controllers\CatalogController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', [CatalogController::class, 'index'])->name('home');
Route::get('/sitemap.xml', [CatalogController::class, 'sitemap'])->name('sitemap');
Route::get('/sitemap-index.xml', [CatalogController::class, 'sitemapIndex'])->name('sitemap.index');
Route::get('/sitemap-static.xml', [CatalogController::class, 'sitemapStatic'])->name('sitemap.static');
Route::get('/sitemap-taxonomies.xml', [CatalogController::class, 'sitemapTaxonomies'])->name('sitemap.taxonomies');
Route::get('/sitemap-titles-{page}.xml', [CatalogController::class, 'sitemapTitles'])
    ->whereNumber('page')
    ->name('sitemap.titles');
Route::get('/feed.xml', [CatalogController::class, 'feed'])->name('feed');
Route::get('/opensearch.xml', [CatalogController::class, 'openSearch'])->name('opensearch');
Route::get('/titles', [CatalogController::class, 'titles'])->name('titles.index');
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
