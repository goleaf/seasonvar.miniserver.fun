<?php

use App\Enums\AccountSettingsSection;
use App\Enums\CatalogFilterType;
use App\Enums\CatalogRecommendationType;
use App\Enums\CatalogTopListCategory;
use App\Http\Controllers\AccountDataExportController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\CatalogCollectionController;
use App\Http\Controllers\CatalogCollectionCoverController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CatalogDirectoryRedirectController;
use App\Http\Controllers\CatalogSitemapController;
use App\Http\Controllers\CatalogTopListController;
use App\Http\Controllers\CommentRedirectController;
use App\Http\Controllers\DownloadLicensedMediaController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\InfrastructureHealthController;
use App\Http\Controllers\MigrateAnonymousPreferencesController;
use App\Http\Controllers\PlaybackSourceController;
use App\Http\Controllers\ReviewDirectLinkController;
use App\Http\Controllers\TechnicalIssueAttachmentController;
use App\Http\Controllers\UserProfileMediaController;
use App\Http\Middleware\SetSignedAuthenticationLocale;
use App\Livewire\Auth\ConfirmPasswordPage;
use App\Livewire\Auth\ForgotPasswordPage;
use App\Livewire\Auth\LoginPage;
use App\Livewire\Auth\RegisterPage;
use App\Livewire\Auth\ResetPasswordPage;
use App\Livewire\Auth\VerifyEmailPage;
use App\Livewire\CatalogAdministrationManager;
use App\Livewire\CatalogDirectoryBrowser;
use App\Livewire\CatalogDiscoveryPage;
use App\Livewire\CatalogSeries;
use App\Livewire\Collections\CatalogCollectionAdministrationManager;
use App\Livewire\Collections\CatalogCollectionDashboard;
use App\Livewire\Collections\CatalogCollectionDirectory;
use App\Livewire\Collections\CatalogCollectionEditor;
use App\Livewire\Collections\CatalogCollectionProfile;
use App\Livewire\Comments\CommentAdministrationManager;
use App\Livewire\ContentRequests\ContentRequestAdministrationManager;
use App\Livewire\ContentRequests\ContentRequestDetailPage;
use App\Livewire\ContentRequests\ContentRequestDirectory;
use App\Livewire\ContentRequests\ContentRequestFormPage;
use App\Livewire\ContentRequests\MyContentRequestsPage;
use App\Livewire\Library\UserLibraryPage;
use App\Livewire\Profile\DiscussionPage;
use App\Livewire\Profile\ProfilePage;
use App\Livewire\Profile\PublicProfilePage;
use App\Livewire\Profile\ReviewHistoryPage;
use App\Livewire\Profile\SecurityPage;
use App\Livewire\Profile\UserProfileAdministrationManager;
use App\Livewire\Reviews\ReviewModerationManager;
use App\Livewire\SeasonvarImportManager;
use App\Livewire\Settings\AccountSettingsPage;
use App\Livewire\Tags\PersonalTagManager;
use App\Livewire\Tags\TagAdministrationManager;
use App\Livewire\TechnicalIssues\MyTechnicalIssuesPage;
use App\Livewire\TechnicalIssues\TechnicalIssueAdministrationManager;
use App\Livewire\TechnicalIssues\TechnicalIssueDetailPage;
use App\Livewire\TechnicalIssues\TechnicalIssueFormPage;
use App\Services\Catalog\CatalogDirectoryRegistry;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

$discoveryRouteTypes = collect(CatalogRecommendationType::values())
    ->reject(fn (string $type): bool => in_array($type, [
        CatalogRecommendationType::Similar->value,
        CatalogRecommendationType::Related->value,
    ], true))
    ->values()
    ->all();

Route::get('/', [CatalogController::class, 'index'])
    ->middleware('public.page:homepage')
    ->name('home');
Route::get('/search', GlobalSearchController::class)->name('search.index');
Route::get('/{locale}', [CatalogController::class, 'index'])
    ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
    ->middleware(['collection.locale', 'public.page:homepage'])
    ->name('localized.home');
Route::middleware('guest')->group(function (): void {
    Route::get('/login', LoginPage::class)->name('login');
    if (config('authentication.registration.enabled', true)) {
        Route::get('/register', RegisterPage::class)->name('register');
    }
    Route::get('/forgot-password', ForgotPasswordPage::class)->name('password.request');
    Route::get('/reset-password/{token}', ResetPasswordPage::class)
        ->where('token', '[A-Za-z0-9]{1,255}')
        ->name('password.reset');
});

Route::prefix('{locale}')
    ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
    ->middleware(['guest', 'collection.locale'])
    ->name('localized.')
    ->group(function (): void {
        Route::get('/login', LoginPage::class)->name('login');
        if (config('authentication.registration.enabled', true)) {
            Route::get('/register', RegisterPage::class)->name('register');
        }
        Route::get('/forgot-password', ForgotPasswordPage::class)->name('password.request');
        Route::get('/reset-password/{token}', ResetPasswordPage::class)
            ->where('token', '[A-Za-z0-9]{1,255}')
            ->name('password.reset');
    });

Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
    ->whereNumber('id')
    ->where('hash', '[a-f0-9]{40}')
    ->middleware([SetSignedAuthenticationLocale::class, 'signed'])
    ->name('verification.verify');

Route::middleware(['auth', 'auth.session', 'account.private'])->group(function (): void {
    Route::get('/titles/{catalogTitle:slug}/media/{licensedMedia}/download', DownloadLicensedMediaController::class)
        ->whereNumber('licensedMedia')
        ->scopeBindings()
        ->middleware('throttle:media-downloads')
        ->name('titles.media.download');
    Route::get('/email/verify', VerifyEmailPage::class)->name('verification.notice');
    Route::get('/confirm-password', ConfirmPasswordPage::class)->name('password.confirm');
    Route::get('/profile', ProfilePage::class)->name('profile.show');
    Route::get('/profile/discussions', DiscussionPage::class)->name('profile.discussions');
    Route::get('/profile/reviews', ReviewHistoryPage::class)->name('profile.reviews');
    Route::get('/notifications', DiscussionPage::class)->name('notifications.index');
    Route::get('/profile/security', SecurityPage::class)->name('profile.security');
    Route::get('/settings/{section?}', AccountSettingsPage::class)
        ->whereIn('section', AccountSettingsSection::values())
        ->name('settings.index');
    Route::get('/{locale}/settings/{section?}', AccountSettingsPage::class)
        ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
        ->whereIn('section', AccountSettingsSection::values())
        ->middleware('collection.locale')
        ->name('localized.settings.index');
    Route::post('/settings/preferences/migrate', MigrateAnonymousPreferencesController::class)
        ->middleware('throttle:12,1')
        ->name('settings.preferences.migrate');
    Route::get('/profile/export', AccountDataExportController::class)
        ->middleware(['password.confirm', 'throttle:6,1'])
        ->name('profile.export');
    Route::get('/my/collections', CatalogCollectionDashboard::class)->name('collections.mine');
    Route::get('/requests/create', ContentRequestFormPage::class)->name('requests.create');
    Route::get('/requests/mine', MyContentRequestsPage::class)->name('requests.mine');
    Route::get('/issues/new', TechnicalIssueFormPage::class)->name('issues.create');
    Route::get('/issues', MyTechnicalIssuesPage::class)->name('issues.mine');
    Route::get('/issues/{technicalIssue}', TechnicalIssueDetailPage::class)
        ->whereUuid('technicalIssue')
        ->name('issues.show');
    Route::get('/issues/{technicalIssue}/attachments/{attachment}', TechnicalIssueAttachmentController::class)
        ->whereUuid('technicalIssue')
        ->whereUuid('attachment')
        ->scopeBindings()
        ->name('issues.attachments.show');
    Route::get('/{locale}/requests/create', ContentRequestFormPage::class)
        ->whereIn('locale', config('content-requests.supported_locales', ['ru']))
        ->middleware('collection.locale')
        ->name('localized.requests.create');
    Route::get('/{locale}/requests/mine', MyContentRequestsPage::class)
        ->whereIn('locale', config('content-requests.supported_locales', ['ru']))
        ->middleware('collection.locale')
        ->name('localized.requests.mine');
    Route::get('/{locale}/issues/new', TechnicalIssueFormPage::class)
        ->whereIn('locale', config('technical-issues.supported_locales', ['ru']))
        ->middleware('collection.locale')
        ->name('localized.issues.create');
    Route::get('/{locale}/issues', MyTechnicalIssuesPage::class)
        ->whereIn('locale', config('technical-issues.supported_locales', ['ru']))
        ->middleware('collection.locale')
        ->name('localized.issues.mine');
    Route::get('/{locale}/issues/{technicalIssue}', TechnicalIssueDetailPage::class)
        ->whereIn('locale', config('technical-issues.supported_locales', ['ru']))
        ->whereUuid('technicalIssue')
        ->middleware('collection.locale')
        ->name('localized.issues.show');
    Route::get('/my/collections/{collectionPublicId}/edit', CatalogCollectionEditor::class)
        ->whereUuid('collectionPublicId')
        ->name('collections.edit');
    Route::redirect('/my/lists', '/my/collections', 301)->name('legacy.collections.mine');
    Route::get('/library', UserLibraryPage::class)
        ->defaults('section', 'watchlist')
        ->name('library.index');
    Route::get('/library/{section}', UserLibraryPage::class)
        ->whereIn('section', ['watchlist', 'ratings', 'continue-watching', 'history', 'hidden-recommendations'])
        ->name('library.section');
    Route::get('/library/tags/manage', PersonalTagManager::class)
        ->name('personal-tags.index');
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
        Route::get('/sitemap-requests-{page}.xml', [CatalogSitemapController::class, 'sitemapRequests'])
            ->whereNumber('page')
            ->name('sitemap.requests');
        Route::get('/feed.xml', [CatalogSitemapController::class, 'feed'])->name('feed');
        Route::get('/opensearch.xml', [CatalogSitemapController::class, 'openSearch'])->name('opensearch');
        Route::get('/llms.txt', [CatalogSitemapController::class, 'llms'])->name('llms');
    });
Route::get('/sitemap-collections.xml', [CatalogSitemapController::class, 'sitemapCollections'])
    ->middleware('public.cache:collection_documents')
    ->withoutMiddleware($publicDocumentMiddleware)
    ->name('sitemap.collections');
Route::get('/sitemap-profiles.xml', [CatalogSitemapController::class, 'sitemapProfiles'])
    ->withoutMiddleware($publicDocumentMiddleware)
    ->name('sitemap.profiles');
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

Route::redirect('/discover', '/discover/popular', 302)->name('discover.default');
Route::get('/discover/{type}', CatalogDiscoveryPage::class)
    ->whereIn('type', $discoveryRouteTypes)
    ->middleware('public.page:discovery')
    ->name('discover.index');
Route::redirect('/recommendations', '/discover/popular', 301)->name('legacy.recommendations.index');
Route::redirect('/top', '/top/movies', 302)->name('top.default');
Route::get('/top/{category}', [CatalogTopListController::class, 'show'])
    ->whereIn('category', CatalogTopListCategory::values())
    ->middleware('public.page:catalog')
    ->name('top.show');

Route::get('/comments/{comment}', CommentRedirectController::class)
    ->whereNumber('comment')
    ->name('comments.show');
Route::get('/reviews/{review}', ReviewDirectLinkController::class)
    ->whereNumber('review')
    ->name('reviews.show');

Route::get('/requests', ContentRequestDirectory::class)
    ->middleware('public.page:requests')
    ->name('requests.index');
Route::get('/requests/{contentRequest}', ContentRequestDetailPage::class)
    ->whereUuid('contentRequest')
    ->middleware('public.page:requests')
    ->name('requests.show');

Route::get('/collections', CatalogCollectionDirectory::class)
    ->middleware('public.page:collections')
    ->name('collections.index');
Route::get('/collections/covers/{publicId}/{version}', CatalogCollectionCoverController::class)
    ->whereUuid('publicId')
    ->whereNumber('version')
    ->name('collections.cover');
Route::get('/collections/{collectionSlug}', [CatalogCollectionController::class, 'show'])
    ->where('collectionSlug', '[^/]+')
    ->name('collections.show');
Route::get('/profiles/media/{userPublicId}/{kind}/{version}', UserProfileMediaController::class)
    ->whereUuid('userPublicId')
    ->whereIn('kind', ['avatar', 'cover'])
    ->whereNumber('version')
    ->name('profiles.media');
Route::get('/users/{username}', PublicProfilePage::class)
    ->where('username', '[A-Za-z0-9_]{3,32}')
    ->name('users.show');
Route::get('/profiles/{userPublicId}/collections', CatalogCollectionProfile::class)
    ->whereUuid('userPublicId')
    ->name('profiles.collections');

Route::middleware('collection.locale')->group(function () use ($discoveryRouteTypes): void {
    Route::get('/{locale}/search', GlobalSearchController::class)
        ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
        ->name('localized.search.index');
    Route::get('/{locale}/requests', ContentRequestDirectory::class)
        ->whereIn('locale', config('content-requests.supported_locales', ['ru']))
        ->middleware('public.page:requests')
        ->name('localized.requests.index');
    Route::get('/{locale}/requests/{contentRequest}', ContentRequestDetailPage::class)
        ->whereIn('locale', config('content-requests.supported_locales', ['ru']))
        ->whereUuid('contentRequest')
        ->middleware('public.page:requests')
        ->name('localized.requests.show');
    Route::get('/{locale}/discover', function (string $locale) {
        return redirect()->route('localized.discover.index', [
            'locale' => $locale,
            'type' => CatalogRecommendationType::Popular->value,
        ], 302);
    })
        ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
        ->name('localized.discover.default');
    Route::get('/{locale}/discover/{type}', CatalogDiscoveryPage::class)
        ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
        ->whereIn('type', $discoveryRouteTypes)
        ->middleware('public.page:discovery')
        ->name('localized.discover.index');
    Route::get('/{locale}/top', function (string $locale) {
        return redirect()->route('localized.top.show', [
            'locale' => $locale,
            'category' => CatalogTopListCategory::Movies->value,
        ], 302);
    })
        ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
        ->name('localized.top.default');
    Route::get('/{locale}/top/{category}', [CatalogTopListController::class, 'localized'])
        ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
        ->whereIn('category', CatalogTopListCategory::values())
        ->middleware('public.page:catalog')
        ->name('localized.top.show');
    Route::get('/{locale}/recommendations', function (string $locale) {
        return redirect()->route('localized.discover.index', [
            'locale' => $locale,
            'type' => CatalogRecommendationType::Popular->value,
        ], 301);
    })
        ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
        ->name('localized.legacy.recommendations.index');
    Route::get('/{locale}/comments/{comment}', CommentRedirectController::class)
        ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
        ->whereNumber('comment')
        ->name('localized.comments.show');
    Route::get('/{locale}/collections', CatalogCollectionDirectory::class)
        ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
        ->middleware('public.page:collections')
        ->name('localized.collections.index');
    Route::get('/{locale}/collections/{collectionSlug}', [CatalogCollectionController::class, 'localizedShow'])
        ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
        ->where('collectionSlug', '[^/]+')
        ->name('localized.collections.show');
    Route::get('/{locale}/profiles/{userPublicId}/collections', CatalogCollectionProfile::class)
        ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
        ->whereUuid('userPublicId')
        ->name('localized.profiles.collections');
    Route::get('/{locale}/users/{username}', PublicProfilePage::class)
        ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
        ->where('username', '[A-Za-z0-9_]{3,32}')
        ->name('localized.users.show');
});

Route::redirect('/lists', '/collections', 301)->name('legacy.collections.index');
Route::get('/lists/{collectionSlug}', [CatalogCollectionController::class, 'legacyShow'])
    ->where('collectionSlug', '[^/]+')
    ->name('legacy.collections.show');
Route::get('/selections/{collectionSlug}', [CatalogCollectionController::class, 'legacyShow'])
    ->where('collectionSlug', '[^/]+')
    ->name('legacy.selections.show');

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
Route::get('/admin/collections', CatalogCollectionAdministrationManager::class)
    ->middleware('can:manage-catalog')
    ->name('admin.collections');
Route::get('/admin/comments', CommentAdministrationManager::class)
    ->middleware('can:manage-comments')
    ->name('admin.comments');
Route::get('/admin/reviews', ReviewModerationManager::class)
    ->middleware('can:manage-reviews')
    ->name('admin.reviews');
Route::get('/admin/profiles', UserProfileAdministrationManager::class)
    ->middleware('can:manage-catalog')
    ->name('admin.profiles');
Route::get('/admin/tags', TagAdministrationManager::class)
    ->middleware('can:manage-catalog')
    ->name('admin.tags');
Route::get('/admin/requests', ContentRequestAdministrationManager::class)
    ->middleware('can:manage-content-requests')
    ->name('admin.requests');
Route::get('/admin/issues', TechnicalIssueAdministrationManager::class)
    ->middleware(['auth', 'auth.session', 'account.private', 'can:manage-technical-issues'])
    ->name('admin.issues');
Route::get('/titles/year/{year}', CatalogSeries::class)
    ->where('year', '(?:19|20)\d{2}')
    ->middleware('public.page:catalog')
    ->name('titles.year');
Route::get('/tag/{taxonomy}', CatalogSeries::class)
    ->defaults('type', 'tag')
    ->where('taxonomy', '[A-Za-z0-9][A-Za-z0-9-]*')
    ->middleware(['canonical.tag', 'public.page:catalog'])
    ->name('legacy.tags.show');
Route::get('/titles/{type}/{taxonomy}', CatalogSeries::class)
    ->where('type', CatalogFilterType::routePattern())
    ->where('taxonomy', '[A-Za-z0-9][A-Za-z0-9-]*')
    ->middleware(['canonical.tag', 'public.page:catalog'])
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
