<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::fallback(function (Request $request) {
    if ($request->is('api/*')) {
        abort(404);
    }

    return view('welcome');
});
