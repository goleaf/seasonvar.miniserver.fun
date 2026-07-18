<?php

use App\Enums\AccountSettingsSection;
use App\Enums\CatalogFilterType;
use App\Enums\CatalogRecommendationType;
use App\Enums\CatalogTopListCategory;
use App\Http\Middleware\SetSignedAuthenticationLocale;
use App\Http\Requests\MigrateAnonymousPreferencesRequest;
use App\Livewire\Auth\ConfirmPasswordPage;
use App\Livewire\Auth\ForgotPasswordPage;
use App\Livewire\Auth\LoginPage;
use App\Livewire\Auth\RegisterPage;
use App\Livewire\Auth\ResetPasswordPage;
use App\Livewire\Auth\VerifyEmailPage;
use App\Livewire\CatalogAdministrationManager;
use App\Livewire\CatalogDirectoryBrowser;
use App\Livewire\CatalogDiscoveryPage;
use App\Livewire\CatalogHomePage;
use App\Livewire\CatalogSeries;
use App\Livewire\CatalogTitleDetail;
use App\Livewire\CatalogTopListPage;
use App\Livewire\Collections\CatalogCollectionAdministrationManager;
use App\Livewire\Collections\CatalogCollectionDashboard;
use App\Livewire\Collections\CatalogCollectionDirectory;
use App\Livewire\Collections\CatalogCollectionEditor;
use App\Livewire\Collections\CatalogCollectionPage;
use App\Livewire\Collections\CatalogCollectionProfile;
use App\Livewire\Comments\CommentAdministrationManager;
use App\Livewire\ContentRequests\ContentRequestAdministrationManager;
use App\Livewire\ContentRequests\ContentRequestDetailPage;
use App\Livewire\ContentRequests\ContentRequestDirectory;
use App\Livewire\ContentRequests\ContentRequestFormPage;
use App\Livewire\ContentRequests\MyContentRequestsPage;
use App\Livewire\GlobalSearchPage;
use App\Livewire\HelpCenter\HelpArticlePage;
use App\Livewire\HelpCenter\HelpArticlePreviewPage;
use App\Livewire\HelpCenter\HelpCategoryPage;
use App\Livewire\HelpCenter\HelpCenterAdministrationPage;
use App\Livewire\HelpCenter\HelpCenterHome;
use App\Livewire\HelpCenter\HelpSearchPage;
use App\Livewire\Library\UserLibraryPage;
use App\Livewire\Premium\PremiumAdministrationManager;
use App\Livewire\Premium\PremiumPaymentReturnPage;
use App\Livewire\Premium\PremiumPricingPage;
use App\Livewire\Profile\DiscussionPage;
use App\Livewire\Profile\ProfilePage;
use App\Livewire\Profile\PublicProfilePage;
use App\Livewire\Profile\ReviewHistoryPage;
use App\Livewire\Profile\SecurityPage;
use App\Livewire\Profile\UserProfileAdministrationManager;
use App\Livewire\ReleaseCalendar\ReleaseCalendarAdministrationManager;
use App\Livewire\ReleaseCalendar\ReleaseCalendarPage;
use App\Livewire\Reviews\ReviewModerationManager;
use App\Livewire\SeasonvarImportManager;
use App\Livewire\Settings\AccountSettingsPage;
use App\Livewire\StatsDashboard;
use App\Livewire\Tags\PersonalTagManager;
use App\Livewire\Tags\TagAdministrationManager;
use App\Livewire\TechnicalIssues\MyTechnicalIssuesPage;
use App\Livewire\TechnicalIssues\TechnicalIssueAdministrationManager;
use App\Livewire\TechnicalIssues\TechnicalIssueDetailPage;
use App\Livewire\TechnicalIssues\TechnicalIssueFormPage;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Services\Auth\AccountDataExportResponder;
use App\Services\Auth\AccountEmailVerificationResponder;
use App\Services\Auth\AnonymousPreferencesMigrationResponder;
use App\Services\Catalog\CatalogDirectoryRedirectResponder;
use App\Services\Catalog\CatalogDirectoryRegistry;
use App\Services\Catalog\CatalogPlaybackSourceResponder;
use App\Services\Catalog\CatalogSitemapResponder;
use App\Services\Catalog\CatalogStatsPosterResponder;
use App\Services\Collections\CatalogCollectionCoverResponder;
use App\Services\Collections\CatalogCollectionLegacyRedirectResponder;
use App\Services\Comments\CommentDirectLinkResponder;
use App\Services\Media\LicensedMediaDownloadResponder;
use App\Services\Operations\InfrastructureHealthResponder;
use App\Services\Premium\PremiumWebhookResponder;
use App\Services\Profiles\UserProfileMediaResponder;
use App\Services\Reviews\ReviewDirectLinkResponder;
use App\Services\TechnicalIssues\TechnicalIssueAttachmentResponder;
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

Route::get('/', CatalogHomePage::class)
    ->middleware('public.page:homepage')
    ->name('home');
Route::get('/premium', PremiumPricingPage::class)->name('premium.index');
Route::get('/{locale}/premium', PremiumPricingPage::class)
    ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
    ->middleware('collection.locale')
    ->name('localized.premium.index');
Route::post('/billing/webhooks/{provider}', fn (Request $request, string $provider, PremiumWebhookResponder $webhooks) => $webhooks->response($request, $provider))
    ->where('provider', '[a-z0-9][a-z0-9_-]{1,31}')
    ->middleware('throttle:premium-webhooks')
    ->name('premium.webhook');
Route::get('/calendar', ReleaseCalendarPage::class)
    ->defaults('view', 'upcoming')
    ->middleware('public.page:calendar')
    ->name('calendar.upcoming');
Route::get('/calendar/day/{period}', ReleaseCalendarPage::class)
    ->defaults('view', 'day')
    ->where('period', '\\d{4}-\\d{2}-\\d{2}')
    ->middleware('public.page:calendar')
    ->name('calendar.day');
Route::get('/calendar/week/{period}', ReleaseCalendarPage::class)
    ->defaults('view', 'week')
    ->where('period', '\\d{4}-W\\d{2}')
    ->middleware('public.page:calendar')
    ->name('calendar.week');
Route::get('/calendar/month/{period}', ReleaseCalendarPage::class)
    ->defaults('view', 'month')
    ->where('period', '\\d{4}-\\d{2}')
    ->middleware('public.page:calendar')
    ->name('calendar.month');
Route::get('/calendar/recent', ReleaseCalendarPage::class)
    ->defaults('view', 'recent')
    ->middleware('public.page:calendar')
    ->name('calendar.recent');
Route::prefix('{locale}')
    ->whereIn('locale', config('release-calendar.supported_locales', ['ru']))
    ->middleware('collection.locale')
    ->name('localized.calendar.')
    ->group(function (): void {
        Route::get('/calendar', ReleaseCalendarPage::class)->defaults('view', 'upcoming')->middleware('public.page:calendar')->name('upcoming');
        Route::get('/calendar/day/{period}', ReleaseCalendarPage::class)->defaults('view', 'day')->where('period', '\\d{4}-\\d{2}-\\d{2}')->middleware('public.page:calendar')->name('day');
        Route::get('/calendar/week/{period}', ReleaseCalendarPage::class)->defaults('view', 'week')->where('period', '\\d{4}-W\\d{2}')->middleware('public.page:calendar')->name('week');
        Route::get('/calendar/month/{period}', ReleaseCalendarPage::class)->defaults('view', 'month')->where('period', '\\d{4}-\\d{2}')->middleware('public.page:calendar')->name('month');
        Route::get('/calendar/recent', ReleaseCalendarPage::class)->defaults('view', 'recent')->middleware('public.page:calendar')->name('recent');
    });
Route::redirect('/schedule', '/calendar', 301)->name('legacy.calendar.schedule');
Route::redirect('/release-calendar', '/calendar', 301)->name('legacy.calendar.index');
Route::get('/help', HelpCenterHome::class)->name('help.index');
Route::get('/help/search', HelpSearchPage::class)->name('help.search');
Route::get('/help/categories/{categorySlug}', HelpCategoryPage::class)
    ->where('categorySlug', '[^/]+')
    ->name('help.categories.show');
Route::get('/help/articles/{articleSlug}', HelpArticlePage::class)
    ->where('articleSlug', '[^/]+')
    ->name('help.articles.show');
Route::redirect('/faq', '/help', 301)->name('legacy.help.faq');
Route::redirect('/support', '/help', 301)->name('legacy.help.support');
Route::redirect('/help-center', '/help', 301)->name('legacy.help.center');
Route::get('/search', GlobalSearchPage::class)->name('search.index');
Route::get('/{locale}', CatalogHomePage::class)
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

Route::get('/email/verify/{id}/{hash}', fn (int $id, string $hash, AccountEmailVerificationResponder $verification) => $verification->response($id, $hash))
    ->whereNumber('id')
    ->where('hash', '[a-f0-9]{40}')
    ->middleware([SetSignedAuthenticationLocale::class, 'signed'])
    ->name('verification.verify');

Route::middleware(['auth', 'auth.session', 'account.private'])->group(function (): void {
    Route::get('/premium/return/{checkout}', PremiumPaymentReturnPage::class)
        ->whereUuid('checkout')
        ->name('premium.return');
    Route::get('/{locale}/premium/return/{checkout}', PremiumPaymentReturnPage::class)
        ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
        ->whereUuid('checkout')
        ->middleware('collection.locale')
        ->name('localized.premium.return');
    Route::get('/calendar/mine', ReleaseCalendarPage::class)
        ->defaults('view', 'personal')
        ->name('calendar.mine');
    Route::get('/{locale}/calendar/mine', ReleaseCalendarPage::class)
        ->defaults('view', 'personal')
        ->whereIn('locale', config('release-calendar.supported_locales', ['ru']))
        ->middleware('collection.locale')
        ->name('localized.calendar.mine');
    Route::get('/titles/{catalogTitle:slug}/media/{licensedMedia}/download', fn (Request $request, CatalogTitle $catalogTitle, LicensedMedia $licensedMedia, LicensedMediaDownloadResponder $downloads) => $downloads->response($request, $catalogTitle, $licensedMedia))
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
    Route::post('/settings/preferences/migrate', fn (MigrateAnonymousPreferencesRequest $request, AnonymousPreferencesMigrationResponder $preferences) => $preferences->response($request))
        ->middleware('throttle:12,1')
        ->name('settings.preferences.migrate');
    Route::get('/profile/export', fn (Request $request, AccountDataExportResponder $exports) => $exports->response($request))
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
    Route::get('/issues/{technicalIssue}/attachments/{attachment}', fn (string $technicalIssue, string $attachment, TechnicalIssueAttachmentResponder $attachments) => $attachments->response($technicalIssue, $attachment))
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
        ->whereIn('section', [
            'watchlist', 'ratings', 'planned', 'watching', 'paused', 'completed', 'dropped',
            'not-interested', 'blacklisted', 'with-updates', 'without-updates', 'markers',
            'continue-watching', 'history', 'hidden-recommendations',
        ])
        ->name('library.section');
    Route::get('/{locale}/library', UserLibraryPage::class)
        ->defaults('section', 'watchlist')
        ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
        ->middleware('collection.locale')
        ->name('localized.library.index');
    Route::get('/{locale}/library/{section}', UserLibraryPage::class)
        ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
        ->whereIn('section', [
            'watchlist', 'ratings', 'planned', 'watching', 'paused', 'completed', 'dropped',
            'not-interested', 'blacklisted', 'with-updates', 'without-updates', 'markers',
            'continue-watching', 'history', 'hidden-recommendations',
        ])
        ->middleware('collection.locale')
        ->name('localized.library.section');
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
        Route::get('/sitemap.xml', fn (CatalogSitemapResponder $sitemaps) => $sitemaps->index())->name('sitemap');
        Route::get('/sitemap-index.xml', fn (CatalogSitemapResponder $sitemaps) => $sitemaps->index())->name('sitemap.index');
        Route::get('/sitemap-static.xml', fn (CatalogSitemapResponder $sitemaps) => $sitemaps->staticPages())->name('sitemap.static');
        Route::get('/sitemap-taxonomies.xml', fn (CatalogSitemapResponder $sitemaps) => $sitemaps->taxonomies())->name('sitemap.taxonomies');
        Route::get('/sitemap-landings.xml', fn (CatalogSitemapResponder $sitemaps) => $sitemaps->landings())->name('sitemap.landings');
        Route::get('/sitemap-titles-{page}.xml', fn (int $page, CatalogSitemapResponder $sitemaps) => $sitemaps->titles($page))
            ->whereNumber('page')
            ->name('sitemap.titles');
        Route::get('/sitemap-videos-{page}.xml', fn (int $page, CatalogSitemapResponder $sitemaps) => $sitemaps->videos($page))
            ->whereNumber('page')
            ->name('sitemap.videos');
        Route::get('/sitemap-requests-{page}.xml', fn (int $page, CatalogSitemapResponder $sitemaps) => $sitemaps->requests($page))
            ->whereNumber('page')
            ->name('sitemap.requests');
        Route::get('/sitemap-help.xml', fn (CatalogSitemapResponder $sitemaps) => $sitemaps->help())->name('sitemap.help');
        Route::get('/feed.xml', fn (CatalogSitemapResponder $sitemaps) => $sitemaps->feed())->name('feed');
        Route::get('/opensearch.xml', fn (CatalogSitemapResponder $sitemaps) => $sitemaps->openSearch())->name('opensearch');
        Route::get('/llms.txt', fn (CatalogSitemapResponder $sitemaps) => $sitemaps->llms())->name('llms');
    });
Route::get('/sitemap-collections.xml', fn (CatalogSitemapResponder $sitemaps) => $sitemaps->collections())
    ->middleware('public.cache:collection_documents')
    ->withoutMiddleware($publicDocumentMiddleware)
    ->name('sitemap.collections');
Route::get('/sitemap-profiles.xml', fn (CatalogSitemapResponder $sitemaps) => $sitemaps->profiles())
    ->withoutMiddleware($publicDocumentMiddleware)
    ->name('sitemap.profiles');
Route::get('/health/ready', fn (InfrastructureHealthResponder $health) => $health->response())
    ->withoutMiddleware($publicDocumentMiddleware)
    ->name('health.ready');
Route::get('/stats', StatsDashboard::class)
    ->middleware('public.page:stats')
    ->name('stats');
Route::get('/stats/poster/{catalogTitle:slug}', fn (CatalogTitle $catalogTitle, CatalogStatsPosterResponder $posters) => $posters->response($catalogTitle))
    ->name('stats.poster');
Route::get('/playback/{licensedMedia}', fn (Request $request, LicensedMedia $licensedMedia, CatalogPlaybackSourceResponder $sources) => $sources->response($request, $licensedMedia))
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
Route::get('/top/{category}', CatalogTopListPage::class)
    ->whereIn('category', CatalogTopListCategory::values())
    ->middleware('public.page:catalog')
    ->name('top.show');

Route::get('/comments/{comment}', fn (Request $request, string $comment, CommentDirectLinkResponder $comments) => $comments->response($request, $comment))
    ->whereNumber('comment')
    ->name('comments.show');
Route::get('/reviews/{review}', fn (Request $request, string $review, ReviewDirectLinkResponder $reviews) => $reviews->response($request, $review))
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
Route::get('/collections/covers/{publicId}/{version}', fn (Request $request, string $publicId, int $version, CatalogCollectionCoverResponder $covers) => $covers->response($request, $publicId, $version))
    ->whereUuid('publicId')
    ->whereNumber('version')
    ->name('collections.cover');
Route::get('/collections/{collectionSlug}', CatalogCollectionPage::class)
    ->where('collectionSlug', '[^/]+')
    ->middleware('collection.response')
    ->name('collections.show');
Route::get('/profiles/media/{userPublicId}/{kind}/{version}', fn (Request $request, string $userPublicId, string $kind, int $version, UserProfileMediaResponder $media) => $media->response($request, $userPublicId, $kind, $version))
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
    Route::get('/{locale}/help', HelpCenterHome::class)
        ->whereIn('locale', config('help-center.supported_locales', ['ru']))
        ->name('localized.help.index');
    Route::get('/{locale}/help/search', HelpSearchPage::class)
        ->whereIn('locale', config('help-center.supported_locales', ['ru']))
        ->name('localized.help.search');
    Route::get('/{locale}/help/categories/{categorySlug}', HelpCategoryPage::class)
        ->whereIn('locale', config('help-center.supported_locales', ['ru']))
        ->where('categorySlug', '[^/]+')
        ->name('localized.help.categories.show');
    Route::get('/{locale}/help/articles/{articleSlug}', HelpArticlePage::class)
        ->whereIn('locale', config('help-center.supported_locales', ['ru']))
        ->where('articleSlug', '[^/]+')
        ->name('localized.help.articles.show');
    Route::get('/{locale}/faq', fn (string $locale) => redirect()->route('localized.help.index', ['locale' => $locale], 301))
        ->whereIn('locale', config('help-center.supported_locales', ['ru']))
        ->name('localized.legacy.help.faq');
    Route::get('/{locale}/support', fn (string $locale) => redirect()->route('localized.help.index', ['locale' => $locale], 301))
        ->whereIn('locale', config('help-center.supported_locales', ['ru']))
        ->name('localized.legacy.help.support');
    Route::get('/{locale}/help-center', fn (string $locale) => redirect()->route('localized.help.index', ['locale' => $locale], 301))
        ->whereIn('locale', config('help-center.supported_locales', ['ru']))
        ->name('localized.legacy.help.center');
    Route::get('/{locale}/search', GlobalSearchPage::class)
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
    Route::get('/{locale}/top/{category}', CatalogTopListPage::class)
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
    Route::get('/{locale}/comments/{comment}', fn (Request $request, string $locale, string $comment, CommentDirectLinkResponder $comments) => $comments->response($request, $comment))
        ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
        ->whereNumber('comment')
        ->name('localized.comments.show');
    Route::get('/{locale}/collections', CatalogCollectionDirectory::class)
        ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
        ->middleware('public.page:collections')
        ->name('localized.collections.index');
    Route::get('/{locale}/collections/{collectionSlug}', CatalogCollectionPage::class)
        ->whereIn('locale', config('catalog-collections.supported_locales', ['ru']))
        ->where('collectionSlug', '[^/]+')
        ->middleware('collection.response')
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
Route::get('/lists/{collectionSlug}', fn (string $collectionSlug, CatalogCollectionLegacyRedirectResponder $collections) => $collections->response($collectionSlug))
    ->where('collectionSlug', '[^/]+')
    ->name('legacy.collections.show');
Route::get('/selections/{collectionSlug}', fn (string $collectionSlug, CatalogCollectionLegacyRedirectResponder $collections) => $collections->response($collectionSlug))
    ->where('collectionSlug', '[^/]+')
    ->name('legacy.selections.show');

foreach (CatalogDirectoryRegistry::routeMap() as $directory => $config) {
    Route::get('/'.$config['path'], CatalogDirectoryBrowser::class)
        ->defaults('directory', $directory)
        ->middleware('public.page:catalog')
        ->name($directory.'.index');
    Route::get('/'.$config['path'].'/{value}', fn (Request $request, string $value, CatalogDirectoryRedirectResponder $redirects) => $redirects->response($request, $value))
        ->defaults('directory', $directory)
        ->name($directory.'.show');
}

Route::get('/titles', CatalogSeries::class)
    ->middleware('public.page:catalog')
    ->name('titles.index');
Route::get('/admin/imports', SeasonvarImportManager::class)
    ->middleware(['auth', 'auth.session', 'account.private', 'can:manage-seasonvar-imports'])
    ->name('admin.imports');
Route::get('/admin/catalog', CatalogAdministrationManager::class)
    ->middleware(['auth', 'auth.session', 'account.private', 'can:manage-catalog'])
    ->name('admin.catalog');
Route::get('/admin/collections', CatalogCollectionAdministrationManager::class)
    ->middleware(['auth', 'auth.session', 'account.private', 'can:manage-catalog'])
    ->name('admin.collections');
Route::get('/admin/comments', CommentAdministrationManager::class)
    ->middleware(['auth', 'auth.session', 'account.private', 'can:manage-comments'])
    ->name('admin.comments');
Route::get('/admin/reviews', ReviewModerationManager::class)
    ->middleware(['auth', 'auth.session', 'account.private', 'can:manage-reviews'])
    ->name('admin.reviews');
Route::get('/admin/profiles', UserProfileAdministrationManager::class)
    ->middleware(['auth', 'auth.session', 'account.private', 'can:manage-catalog'])
    ->name('admin.profiles');
Route::get('/admin/tags', TagAdministrationManager::class)
    ->middleware(['auth', 'auth.session', 'account.private', 'can:manage-catalog'])
    ->name('admin.tags');
Route::get('/admin/requests', ContentRequestAdministrationManager::class)
    ->middleware(['auth', 'auth.session', 'account.private', 'can:manage-content-requests'])
    ->name('admin.requests');
Route::get('/admin/issues', TechnicalIssueAdministrationManager::class)
    ->middleware(['auth', 'auth.session', 'account.private', 'can:manage-technical-issues'])
    ->name('admin.issues');
Route::get('/admin/calendar', ReleaseCalendarAdministrationManager::class)
    ->middleware(['auth', 'auth.session', 'account.private', 'can:manage-release-calendar'])
    ->name('admin.calendar');
Route::get('/admin/premium', PremiumAdministrationManager::class)
    ->middleware(['auth', 'auth.session', 'account.private', 'can:view-premium-administration'])
    ->name('admin.premium');
Route::get('/admin/help', HelpCenterAdministrationPage::class)
    ->middleware(['auth', 'auth.session', 'account.private', 'can:manage-help-center'])
    ->name('admin.help');
Route::get('/admin/help/articles/{helpArticle}/preview/{locale}', HelpArticlePreviewPage::class)
    ->whereUuid('helpArticle')
    ->whereIn('locale', config('help-center.supported_locales', ['ru']))
    ->middleware(['auth', 'auth.session', 'account.private', 'can:manage-help-center'])
    ->name('admin.help.preview');
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
Route::get('/titles/{catalogTitle:slug}', CatalogTitleDetail::class)
    ->middleware('public.page:title')
    ->name('titles.show');

Route::fallback(function (Request $request) {
    if ($request->is('api/*')) {
        abort(404);
    }

    return redirect()->route('home');
});
