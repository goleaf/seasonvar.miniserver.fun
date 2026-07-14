<?php

use App\Http\Controllers\Api\ApiDiscoveryController;
use App\Http\Controllers\Api\CatalogPeopleLookupController;
use App\Http\Controllers\Api\CatalogTitleController;
use App\Http\Controllers\Api\OpenApiController;
use App\Http\Controllers\Api\V1\ApiConfigController;
use App\Http\Controllers\Api\V1\ApiHealthController;
use App\Http\Controllers\Api\V1\CatalogDirectoryController;
use App\Http\Controllers\Api\V1\CatalogFilterSchemaController;
use App\Http\Controllers\Api\V1\CatalogHomeController;
use App\Http\Controllers\Api\V1\CatalogTitleController as V1CatalogTitleController;
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
        Route::get('/titles', [V1CatalogTitleController::class, 'index'])->name('titles.index');
    });
});

Route::middleware('public.cache:api')->group(function (): void {
    Route::get('/catalog/people', CatalogPeopleLookupController::class)->name('api.catalog.people');
    Route::get('/titles', [CatalogTitleController::class, 'index'])->name('api.titles.index');
    Route::get('/titles/{catalogTitle:slug}', [CatalogTitleController::class, 'show'])->name('api.titles.show');
});

Route::get('/v1/health', ApiHealthController::class)->name('api.v1.health');

Route::fallback(static fn () => abort(404))->name('api.fallback');
