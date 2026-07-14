<?php

use App\Http\Controllers\Api\ApiDiscoveryController;
use App\Http\Controllers\Api\CatalogPeopleLookupController;
use App\Http\Controllers\Api\CatalogTitleController;
use App\Http\Controllers\Api\OpenApiController;
use App\Http\Controllers\Api\V1\ApiConfigController;
use App\Http\Controllers\Api\V1\ApiHealthController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\ResendVerificationController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use App\Http\Controllers\Api\V1\CatalogDirectoryController;
use App\Http\Controllers\Api\V1\CatalogFilterSchemaController;
use App\Http\Controllers\Api\V1\CatalogHomeController;
use App\Http\Controllers\Api\V1\CatalogRecommendationController;
use App\Http\Controllers\Api\V1\CatalogReviewController;
use App\Http\Controllers\Api\V1\CatalogTitleController as V1CatalogTitleController;
use App\Http\Controllers\Api\V1\SearchSuggestionController;
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
        Route::get('/search/suggestions', SearchSuggestionController::class)->name('search.suggestions');
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

Route::middleware('public.cache:api')->group(function (): void {
    Route::get('/catalog/people', CatalogPeopleLookupController::class)->name('api.catalog.people');
    Route::get('/titles', [CatalogTitleController::class, 'index'])->name('api.titles.index');
    Route::get('/titles/{catalogTitle:slug}', [CatalogTitleController::class, 'show'])->name('api.titles.show');
});

Route::get('/v1/health', ApiHealthController::class)->name('api.v1.health');

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::post('/auth/register', RegisterController::class)
        ->middleware('throttle:mobile-register')
        ->name('auth.register');
    Route::post('/auth/login', LoginController::class)
        ->middleware('throttle:mobile-login')
        ->name('auth.login');
    Route::get('/auth/email/verify/{id}/{hash}', VerifyEmailController::class)
        ->whereNumber('id')
        ->middleware('signed')
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
});

Route::fallback(static fn () => abort(404))->name('api.fallback');
