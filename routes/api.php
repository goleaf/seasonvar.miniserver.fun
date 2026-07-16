<?php

use App\Http\Controllers\Api\ApiDiscoveryController;
use App\Http\Controllers\Api\CatalogPeopleLookupController;
use App\Http\Controllers\Api\CatalogTitleController;
use App\Http\Controllers\Api\OpenApiController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\ApiConfigController;
use App\Http\Controllers\Api\V1\ApiHealthController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\ResendVerificationController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Auth\TokenController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\CatalogCollectionController;
use App\Http\Controllers\Api\V1\CatalogDirectoryController;
use App\Http\Controllers\Api\V1\CatalogFilterSchemaController;
use App\Http\Controllers\Api\V1\CatalogHomeController;
use App\Http\Controllers\Api\V1\CatalogRecommendationController;
use App\Http\Controllers\Api\V1\CatalogReviewController;
use App\Http\Controllers\Api\V1\CatalogTitleController as V1CatalogTitleController;
use App\Http\Controllers\Api\V1\PersonalTagAssignmentController;
use App\Http\Controllers\Api\V1\PersonalTagController;
use App\Http\Controllers\Api\V1\PlaybackProgressController;
use App\Http\Controllers\Api\V1\PlaybackSessionController;
use App\Http\Controllers\Api\V1\PlaybackSourceController;
use App\Http\Controllers\Api\V1\PublicTagController;
use App\Http\Controllers\Api\V1\SearchSuggestionController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\UserLibraryController;
use App\Http\Controllers\Api\V1\UserTitleStateController;
use App\Http\Controllers\Api\V1\ViewingActivityController;
use App\Http\Middleware\SetSignedAuthenticationLocale;
use App\Services\Catalog\CatalogDirectoryRegistry;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth.optional.sanctum', 'public.cache:api'])->group(function (): void {
    Route::get('/', ApiDiscoveryController::class)->name('api.discovery');
    Route::get('/openapi.json', OpenApiController::class)->name('api.openapi');

    Route::prefix('v1')->name('api.v1.')->group(function (): void {
        Route::get('/config', ApiConfigController::class)->name('config');
        Route::get('/home', CatalogHomeController::class)->name('home');
        Route::get('/catalog/filters', CatalogFilterSchemaController::class)->name('catalog.filters');
        Route::get('/catalog/directories', [CatalogDirectoryController::class, 'index'])->name('catalog.directories.index');
        Route::get('/catalog/directories/{directory}', [CatalogDirectoryController::class, 'show'])
            ->whereIn('directory', array_keys(CatalogDirectoryRegistry::routeMap()))
            ->name('catalog.directories.show');
        Route::get('/search/suggestions', SearchSuggestionController::class)
            ->middleware('throttle:api-search-suggestions')
            ->name('search.suggestions');
        Route::get('/tags', [PublicTagController::class, 'index'])
            ->middleware('throttle:api-search-suggestions')
            ->name('tags.index');
        Route::get('/tags/{tagSlug}', [PublicTagController::class, 'show'])
            ->where('tagSlug', '[A-Za-z0-9][A-Za-z0-9-]*')
            ->name('tags.show');
        Route::get('/titles', [V1CatalogTitleController::class, 'index'])->name('titles.index');
        Route::get('/titles/{titleSlug}', [V1CatalogTitleController::class, 'show'])
            ->where('titleSlug', '[^/]+')
            ->name('titles.show');
        Route::get('/titles/{titleSlug}/seasons', [V1CatalogTitleController::class, 'seasons'])
            ->where('titleSlug', '[^/]+')
            ->name('titles.seasons');
        Route::get('/titles/{titleSlug}/seasons/{season}/episodes', [V1CatalogTitleController::class, 'episodes'])
            ->where('titleSlug', '[^/]+')
            ->whereNumber('season')
            ->name('titles.episodes');
        Route::get('/titles/{titleSlug}/recommendations', CatalogRecommendationController::class)
            ->where('titleSlug', '[^/]+')
            ->name('titles.recommendations');
        Route::get('/titles/{titleSlug}/reviews', CatalogReviewController::class)
            ->where('titleSlug', '[^/]+')
            ->name('titles.reviews');
    });
});

Route::middleware(['auth.optional.sanctum', 'public.cache:collection_api'])
    ->prefix('v1')
    ->name('api.v1.')
    ->group(function (): void {
        Route::get('/collections', [CatalogCollectionController::class, 'index'])->name('collections.index');
        Route::get('/collections/{collectionSlug}', [CatalogCollectionController::class, 'show'])
            ->where('collectionSlug', '[^/]+')
            ->name('collections.show');
        Route::get('/titles/{titleSlug}/collections', [CatalogCollectionController::class, 'forTitle'])
            ->where('titleSlug', '[^/]+')
            ->name('titles.collections');
    });

Route::middleware('public.cache:api')->group(function (): void {
    Route::get('/catalog/people', CatalogPeopleLookupController::class)
        ->middleware('throttle:api-search-suggestions')
        ->name('api.catalog.people');
    Route::get('/titles', [CatalogTitleController::class, 'index'])->name('api.titles.index');
    Route::get('/titles/{catalogTitle:slug}', [CatalogTitleController::class, 'show'])->name('api.titles.show');
});

Route::get('/v1/health', ApiHealthController::class)->name('api.v1.health');

Route::middleware('throttle:api-catalog-sync')->prefix('v1/sync')->name('api.v1.sync.')->group(function (): void {
    Route::get('/manifest', [SyncController::class, 'manifest'])->name('manifest');
    Route::get('/changes', [SyncController::class, 'catalog'])->name('changes');
});

Route::middleware('auth.optional.sanctum')->prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('/titles/{titleSlug}/playback-sessions', PlaybackSessionController::class)
        ->middleware('throttle:api-playback-session')
        ->where('titleSlug', '[^/]+')
        ->name('titles.playback-sessions.store');
    Route::get('/playback/{licensedMedia}', PlaybackSourceController::class)
        ->middleware('signed')
        ->whereNumber('licensedMedia')
        ->name('playback.source');
});

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    if (config('authentication.registration.enabled', true)) {
        Route::post('/auth/register', RegisterController::class)
            ->middleware('throttle:mobile-register')
            ->name('auth.register');
    }
    Route::post('/auth/login', LoginController::class)
        ->middleware('throttle:mobile-login')
        ->name('auth.login');
    Route::get('/auth/email/verify/{id}/{hash}', VerifyEmailController::class)
        ->whereNumber('id')
        ->where('hash', '[a-f0-9]{40}')
        ->middleware([SetSignedAuthenticationLocale::class, 'signed'])
        ->name('auth.verify');
    Route::post('/auth/email/verification-notification', ResendVerificationController::class)
        ->middleware(['auth:sanctum', 'throttle:mobile-verification'])
        ->name('auth.verification-notification');
    Route::post('/auth/forgot-password', ForgotPasswordController::class)
        ->middleware('throttle:mobile-forgot-password')
        ->name('auth.forgot-password');
    Route::post('/auth/reset-password', ResetPasswordController::class)
        ->middleware('throttle:mobile-reset-password')
        ->name('auth.reset-password');

    Route::put('/titles/{titleSlug}/episodes/{episode}/progress', PlaybackProgressController::class)
        ->where('titleSlug', '[^/]+')
        ->whereNumber('episode')
        ->middleware(['auth:sanctum', 'abilities:mobile:write', 'verified.api', 'throttle:api-playback-progress'])
        ->name('titles.episodes.progress.update');

    Route::middleware(['auth:sanctum', 'abilities:mobile:read'])->group(function (): void {
        Route::get('/auth/devices', [TokenController::class, 'index'])
            ->name('auth.devices.index');

        Route::middleware('abilities:mobile:write')->group(function (): void {
            Route::post('/auth/token/refresh', [TokenController::class, 'refresh'])
                ->middleware('throttle:mobile-token-refresh')
                ->name('auth.token.refresh');
            Route::post('/auth/logout', [TokenController::class, 'logout'])
                ->name('auth.logout');
            Route::post('/auth/logout-all', [TokenController::class, 'logoutAll'])
                ->name('auth.logout-all');
            Route::delete('/auth/devices/{token}', [TokenController::class, 'destroy'])
                ->whereNumber('token')
                ->name('auth.devices.destroy');
        });
    });

    Route::middleware(['auth:sanctum', 'abilities:mobile:read'])->group(function (): void {
        Route::get('/me', [AccountController::class, 'show'])->name('me.show');
        Route::get('/me/sync', [SyncController::class, 'user'])->name('me.sync.show');
        Route::get('/me/watchlist', [UserLibraryController::class, 'watchlist'])
            ->name('me.watchlist.index');
        Route::get('/me/ratings', [UserLibraryController::class, 'ratings'])
            ->name('me.ratings.index');
        Route::get('/me/library/summary', [UserLibraryController::class, 'summary'])
            ->name('me.library.summary');
        Route::get('/me/continue-watching', [ViewingActivityController::class, 'continueWatching'])
            ->name('me.continue-watching.index');
        Route::get('/me/history', [ViewingActivityController::class, 'history'])
            ->name('me.history.index');
        Route::get('/me/titles/{catalogTitle:slug}/state', [UserTitleStateController::class, 'show'])
            ->name('me.titles.state.show');
        Route::get('/me/tags', [PersonalTagController::class, 'index'])
            ->name('me.tags.index');
        Route::get('/me/titles/{titleSlug}/tags', [PersonalTagAssignmentController::class, 'show'])
            ->where('titleSlug', '[^/]+')
            ->name('me.titles.tags.show');

        Route::middleware('abilities:mobile:write')->group(function (): void {
            Route::patch('/me', [AccountController::class, 'update'])->name('me.update');
            Route::patch('/me/password', [AccountController::class, 'updatePassword'])->name('me.password.update');
            Route::delete('/me', [AccountController::class, 'destroy'])->name('me.destroy');

            Route::middleware('verified.api')->group(function (): void {
                Route::post('/me/tags', [PersonalTagController::class, 'store'])
                    ->middleware('throttle:20,60')
                    ->name('me.tags.store');
                Route::patch('/me/tags/{tagPublicId}', [PersonalTagController::class, 'update'])
                    ->whereUuid('tagPublicId')
                    ->name('me.tags.update');
                Route::delete('/me/tags/{tagPublicId}', [PersonalTagController::class, 'destroy'])
                    ->whereUuid('tagPublicId')
                    ->name('me.tags.destroy');
                Route::post('/me/tags/{tagPublicId}/restore', [PersonalTagController::class, 'restore'])
                    ->whereUuid('tagPublicId')
                    ->name('me.tags.restore');
                Route::put('/me/titles/{titleSlug}/tags', [PersonalTagAssignmentController::class, 'update'])
                    ->where('titleSlug', '[^/]+')
                    ->name('me.titles.tags.update');
                Route::delete('/me/titles/{titleSlug}/tags/{tagPublicId}', [PersonalTagAssignmentController::class, 'destroy'])
                    ->where('titleSlug', '[^/]+')
                    ->whereUuid('tagPublicId')
                    ->name('me.titles.tags.destroy');
                Route::post('/me/sync', [SyncController::class, 'push'])
                    ->middleware('throttle:api-user-sync')
                    ->name('me.sync.push');
                Route::put('/me/watchlist/{catalogTitle:slug}', [UserTitleStateController::class, 'storeWatchlist'])
                    ->name('me.watchlist.store');
                Route::delete('/me/watchlist/{catalogTitle:slug}', [UserTitleStateController::class, 'destroyWatchlist'])
                    ->name('me.watchlist.destroy');
                Route::put('/me/ratings/{catalogTitle:slug}', [UserTitleStateController::class, 'storeRating'])
                    ->name('me.ratings.store');
                Route::delete('/me/ratings/{catalogTitle:slug}', [UserTitleStateController::class, 'destroyRating'])
                    ->name('me.ratings.destroy');
                Route::delete('/me/history/{episodeViewProgress}', [ViewingActivityController::class, 'destroy'])
                    ->whereNumber('episodeViewProgress')
                    ->name('me.history.destroy');
                Route::delete('/me/history', [ViewingActivityController::class, 'clear'])
                    ->name('me.history.clear');
            });
        });
    });
});

Route::fallback(static fn () => abort(404))->name('api.fallback');
