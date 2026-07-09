<?php

use App\Http\Controllers\Api\CatalogTitleController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:catalog-api')->group(function (): void {
    Route::get('/titles', [CatalogTitleController::class, 'index'])->name('api.titles.index');
    Route::get('/titles/{catalogTitle:slug}', [CatalogTitleController::class, 'show'])->name('api.titles.show');
});
