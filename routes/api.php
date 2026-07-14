<?php

use App\Http\Controllers\Api\ApiDiscoveryController;
use App\Http\Controllers\Api\CatalogPeopleLookupController;
use App\Http\Controllers\Api\CatalogTitleController;
use App\Http\Controllers\Api\OpenApiController;
use App\Http\Controllers\Api\V1\ApiConfigController;
use App\Http\Controllers\Api\V1\ApiHealthController;
use Illuminate\Support\Facades\Route;

Route::middleware('public.cache:api')->group(function (): void {
    Route::get('/', ApiDiscoveryController::class)->name('api.discovery');
    Route::get('/openapi.json', OpenApiController::class)->name('api.openapi');

    Route::prefix('v1')->name('api.v1.')->group(function (): void {
        Route::get('/config', ApiConfigController::class)->name('config');
    });

    Route::get('/catalog/people', CatalogPeopleLookupController::class)->name('api.catalog.people');
    Route::get('/titles', [CatalogTitleController::class, 'index'])->name('api.titles.index');
    Route::get('/titles/{catalogTitle:slug}', [CatalogTitleController::class, 'show'])->name('api.titles.show');
});

Route::get('/v1/health', ApiHealthController::class)->name('api.v1.health');

Route::fallback(static fn () => abort(404))->name('api.fallback');
