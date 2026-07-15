<?php

use App\Jobs\WakeSeasonvarImportFinalizers;
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

Schedule::command('sanctum:prune-expired --hours=24')
    ->dailyAt('03:41')
    ->name('sanctum-prune-expired')
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('api:sync-prune')
    ->dailyAt('03:23')
    ->name('api-sync-prune')
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::job(new WakeSeasonvarImportFinalizers)
    ->everyTenMinutes()
    ->name('seasonvar-import-finalization-watchdog')
    ->withoutOverlapping(10)
    ->onOneServer();
