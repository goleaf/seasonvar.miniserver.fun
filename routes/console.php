<?php

use App\Jobs\WakeSeasonvarImportFinalizers;
use App\Services\Media\LicensedMediaFileSizeBackfillSchedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::useCache((string) config('cache-architecture.stores.locks', 'redis-locks'));

Schedule::command('cache:warm-catalog --queue --refresh')
    ->everyTenMinutes()
    ->name('catalog-cache-warm')
    ->withoutOverlapping(10)
    ->onOneServer()
    ->when(static fn (): bool => (bool) config('cache-architecture.warming.enabled', true));

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

Schedule::command('catalog-collections:prune --limit='.(int) config('catalog-collections.prune_batch_size', 200))
    ->dailyAt('04:07')
    ->name('catalog-collections-prune')
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::job(new WakeSeasonvarImportFinalizers)
    ->everyTenMinutes()
    ->name('seasonvar-import-finalization-watchdog')
    ->withoutOverlapping(10)
    ->onOneServer();

$mediaFileSizeBackfillSchedule = LicensedMediaFileSizeBackfillSchedule::fromConfig();

Schedule::command(sprintf(
    'seasonvar:import --refresh-media-sizes --media-size-limit=%d --media-size-time-budget=%d',
    $mediaFileSizeBackfillSchedule->limit,
    $mediaFileSizeBackfillSchedule->timeBudgetSeconds,
))
    ->everyTenMinutes()
    ->name('seasonvar-media-file-size-backfill')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->when(static fn (): bool => (bool) config('seasonvar.media_file_size.enabled', true)
        && (bool) config('seasonvar.media_file_size.scheduled_backfill_enabled', true));
