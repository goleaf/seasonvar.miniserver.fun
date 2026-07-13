<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::useCache((string) config('cache-architecture.stores.locks', 'redis-locks'));

Schedule::command('cache:warm-catalog --queue --refresh')
    ->hourlyAt(17)
    ->name('catalog-cache-warm')
    ->withoutOverlapping(10)
    ->onOneServer();
