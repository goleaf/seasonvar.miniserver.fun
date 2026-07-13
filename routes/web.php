<?php

use App\Enums\CatalogFilterType;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CatalogDirectoryRedirectController;
use App\Http\Controllers\CatalogSitemapController;
use App\Http\Controllers\InfrastructureHealthController;
use App\Http\Controllers\PlaybackSourceController;
use App\Livewire\CatalogAdministrationManager;
use App\Livewire\CatalogDirectoryBrowser;
use App\Livewire\CatalogSeries;
use App\Livewire\SeasonvarImportManager;
use App\Livewire\ViewingActivity;
use App\Services\Catalog\CatalogDirectoryRegistry;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', [CatalogController::class, 'index'])->name('home');
$publicDocumentMiddleware = [
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    PreventRequestForgery::class,
];

Route::middleware('public.cache:documents')
    ->withoutMiddleware($publicDocumentMiddleware)
    ->group(function (): void {
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
    });
Route::get('/health/ready', InfrastructureHealthController::class)
    ->withoutMiddleware($publicDocumentMiddleware)
    ->middleware('throttle:infrastructure-health')
    ->name('health.ready');
Route::get('/stats', [CatalogController::class, 'stats'])
    ->middleware('throttle:catalog-stats')
    ->name('stats');
Route::get('/stats/poster/{catalogTitle:slug}', [CatalogController::class, 'statsPoster'])
    ->middleware('throttle:catalog-stats')
    ->name('stats.poster');
Route::get('/playback/{licensedMedia}', PlaybackSourceController::class)
    ->middleware(['signed', 'throttle:playback-source'])
    ->whereNumber('licensedMedia')
    ->name('playback.source');

foreach (CatalogDirectoryRegistry::routeMap() as $directory => $config) {
    Route::get('/'.$config['path'], CatalogDirectoryBrowser::class)
        ->defaults('directory', $directory)
        ->name($directory.'.index');
    Route::get('/'.$config['path'].'/{value}', CatalogDirectoryRedirectController::class)
        ->defaults('directory', $directory)
        ->name($directory.'.show');
}

Route::get('/titles', CatalogSeries::class)
    ->middleware('throttle:catalog-query')
    ->name('titles.index');
Route::get('/watching', ViewingActivity::class)->name('viewing-activity');
Route::get('/admin/imports', SeasonvarImportManager::class)
    ->middleware('can:manage-seasonvar-imports')
    ->name('admin.imports');
Route::get('/admin/catalog', CatalogAdministrationManager::class)
    ->middleware('can:manage-catalog')
    ->name('admin.catalog');
Route::get('/titles/year/{year}', CatalogSeries::class)
    ->middleware('throttle:catalog-query')
    ->where('year', '(?:19|20)\d{2}')
    ->name('titles.year');
Route::get('/titles/{type}/{taxonomy}', CatalogSeries::class)
    ->middleware('throttle:catalog-query')
    ->where('type', CatalogFilterType::routePattern())
    ->where('taxonomy', '[a-z0-9][a-z0-9-]*')
    ->name('titles.taxonomy');
Route::get('/titles/{catalogTitle:slug}', [CatalogController::class, 'show'])->name('titles.show');

Route::fallback(function (Request $request) {
    if ($request->is('api/*')) {
        abort(404);
    }

    return redirect()->route('home');
});
