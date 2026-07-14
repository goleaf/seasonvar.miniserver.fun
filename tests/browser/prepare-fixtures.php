<?php

declare(strict_types=1);

use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\Artisan;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.sqlite.database');
$expected = base_path('output/playwright/browser.sqlite');

if ($database !== $expected) {
    fwrite(STDERR, "Browser fixtures require the dedicated output/playwright/browser.sqlite file.\n");
    exit(1);
}

if (! is_dir(dirname($database))) {
    mkdir(dirname($database), 0755, true);
}

if (is_file($database) && ! unlink($database)) {
    fwrite(STDERR, "Unable to replace the browser fixture database.\n");
    exit(1);
}

touch($database);

if (Artisan::call('migrate', ['--force' => true]) !== 0) {
    fwrite(STDERR, Artisan::output());
    exit(1);
}

$title = CatalogTitle::factory()->create([
    'slug' => 'browser-smoke',
    'title' => 'Browser Smoke',
    'original_title' => 'Browser Smoke Original',
    'description' => 'Детерминированная карточка для локальной проверки браузера.',
    'poster_url' => null,
    'type' => 'show',
]);
$russia = Country::query()->create([
    'name' => 'Россия',
    'slug' => 'rossiia',
]);
$title->countries()->attach($russia);
$turkey = Country::query()->create([
    'name' => 'Турция',
    'slug' => 'turciia',
]);

CatalogTitle::factory()->count(30)->sequence(
    fn (Sequence $sequence): array => [
        'title' => sprintf('Турецкий браузерный сериал %02d', $sequence->index + 1),
        'slug' => sprintf('turkish-browser-title-%02d', $sequence->index + 1),
        'indexed_at' => now()->subMinutes($sequence->index + 1),
    ],
)->create()->each(fn (CatalogTitle $catalogTitle) => $catalogTitle->countries()->attach($turkey));

collect([
    ['name' => 'Борис Актёр', 'slug' => 'boris-akter'],
    ['name' => 'Ёлка Актриса', 'slug' => 'elka-aktrisa'],
    ['name' => 'Alice Actor', 'slug' => 'alice-actor'],
    ['name' => 'Zed Actor', 'slug' => 'zed-actor'],
    ['name' => '123 Actor', 'slug' => '123-actor'],
])->each(function (array $attributes) use ($title): void {
    $actor = Actor::query()->create($attributes);
    $title->actors()->attach($actor);
});

$season = Season::factory()->create([
    'catalog_title_id' => $title->id,
    'number' => 1,
    'title' => 'Сезон 1',
]);
$episode = Episode::factory()->create([
    'season_id' => $season->id,
    'number' => 1,
    'title' => 'Серия 1',
]);

LicensedMedia::factory()->create([
    'catalog_title_id' => $title->id,
    'season_id' => $season->id,
    'episode_id' => $episode->id,
    'title' => 'Browser Smoke 1 серия',
    'storage_disk' => 'external_playlist',
    'path' => 'https://media.example.com/browser-smoke.m3u8',
    'playback_url' => 'https://media.example.com/browser-smoke.m3u8',
    'format' => 'm3u8',
    'status' => 'published',
    'check_status' => 'available',
    'health_status' => 'active',
    'published_at' => now()->subMinute(),
]);

if (Artisan::call('catalog:search-rebuild', ['--chunk' => 50]) !== 0) {
    fwrite(STDERR, Artisan::output());
    exit(1);
}

fwrite(STDOUT, "Browser fixtures prepared.\n");
