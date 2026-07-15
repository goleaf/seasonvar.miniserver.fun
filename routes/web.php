<?php

use App\Enums\CatalogFilterType;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CatalogDirectoryRedirectController;
use App\Http\Controllers\CatalogSitemapController;
use App\Http\Controllers\InfrastructureHealthController;
use App\Http\Controllers\PlaybackSourceController;
use App\Livewire\Auth\ConfirmPasswordPage;
use App\Livewire\Auth\ForgotPasswordPage;
use App\Livewire\Auth\LoginPage;
use App\Livewire\Auth\RegisterPage;
use App\Livewire\Auth\ResetPasswordPage;
use App\Livewire\Auth\VerifyEmailPage;
use App\Livewire\CatalogAdministrationManager;
use App\Livewire\CatalogDirectoryBrowser;
use App\Livewire\CatalogSeries;
use App\Livewire\Library\UserLibraryPage;
use App\Livewire\Profile\ProfilePage;
use App\Livewire\Profile\SecurityPage;
use App\Livewire\SeasonvarImportManager;
use App\Services\Catalog\CatalogDirectoryRegistry;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', [CatalogController::class, 'index'])
    ->middleware('public.page:homepage')
    ->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', LoginPage::class)->name('login');
    Route::get('/register', RegisterPage::class)->name('register');
    Route::get('/forgot-password', ForgotPasswordPage::class)->name('password.request');
    Route::get('/reset-password/{token}', ResetPasswordPage::class)->name('password.reset');
});

Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->whereNumber('id')
    ->middleware('signed')
    ->name('verification.verify');

Route::middleware(['auth', 'auth.session'])->group(function (): void {
    Route::get('/email/verify', VerifyEmailPage::class)->name('verification.notice');
    Route::get('/confirm-password', ConfirmPasswordPage::class)->name('password.confirm');
    Route::get('/profile', ProfilePage::class)->name('profile.show');
    Route::get('/profile/security', SecurityPage::class)->name('profile.security');
    Route::get('/library', UserLibraryPage::class)
        ->defaults('section', 'watchlist')
        ->name('library.index');
    Route::get('/library/{section}', UserLibraryPage::class)
        ->whereIn('section', ['watchlist', 'ratings', 'continue-watching', 'history'])
        ->name('library.section');
    Route::redirect('/watching', '/library/continue-watching')
        ->name('viewing-activity');
});

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
    ->name('health.ready');
Route::get('/stats', [CatalogController::class, 'stats'])
    ->middleware('public.page:stats')
    ->name('stats');
Route::get('/stats/poster/{catalogTitle:slug}', [CatalogController::class, 'statsPoster'])
    ->name('stats.poster');
Route::get('/playback/{licensedMedia}', PlaybackSourceController::class)
    ->middleware('signed')
    ->whereNumber('licensedMedia')
    ->name('playback.source');

foreach (CatalogDirectoryRegistry::routeMap() as $directory => $config) {
    Route::get('/'.$config['path'], CatalogDirectoryBrowser::class)
        ->defaults('directory', $directory)
        ->middleware('public.page:catalog')
        ->name($directory.'.index');
    Route::get('/'.$config['path'].'/{value}', CatalogDirectoryRedirectController::class)
        ->defaults('directory', $directory)
        ->name($directory.'.show');
}

Route::get('/titles', CatalogSeries::class)
    ->middleware('public.page:catalog')
    ->name('titles.index');
Route::get('/admin/imports', SeasonvarImportManager::class)
    ->middleware('can:manage-seasonvar-imports')
    ->name('admin.imports');
Route::get('/admin/catalog', CatalogAdministrationManager::class)
    ->middleware('can:manage-catalog')
    ->name('admin.catalog');
Route::get('/titles/year/{year}', CatalogSeries::class)
    ->where('year', '(?:19|20)\d{2}')
    ->middleware('public.page:catalog')
    ->name('titles.year');
Route::get('/titles/{type}/{taxonomy}', CatalogSeries::class)
    ->where('type', CatalogFilterType::routePattern())
    ->where('taxonomy', '[a-z0-9][a-z0-9-]*')
    ->middleware('public.page:catalog')
    ->name('titles.taxonomy');
Route::get('/titles/{catalogTitle:slug}', [CatalogController::class, 'show'])
    ->middleware('public.page:title')
    ->name('titles.show');

Route::fallback(function (Request $request) {
    if ($request->is('api/*')) {
        abort(404);
    }

    return redirect()->route('home');
});
