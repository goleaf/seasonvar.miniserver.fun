<?php

use App\Http\Controllers\CatalogController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', [CatalogController::class, 'index'])->name('home');
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
