<?php

declare(strict_types=1);

use App\Models\Actor;
use App\Models\CatalogRecommendationBuild;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRating;
use App\Models\CatalogTitleRecommendation;
use App\Models\CatalogTitleUserState;
use App\Models\Country;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Models\UserAccountSetting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.sqlite.database');
$configuredDatabase = getenv('BROWSER_TEST_DATABASE');
$expected = is_string($configuredDatabase) && $configuredDatabase !== ''
    ? $configuredDatabase
    : base_path('output/playwright/browser.sqlite');

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

$posterUrl = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22200%22 height=%22300%22 viewBox=%220 0 200 300%22%3E%3Crect width=%22200%22 height=%22300%22 fill=%22%23047857%22/%3E%3Ccircle cx=%22100%22 cy=%22105%22 r=%2248%22 fill=%22%23d1fae5%22/%3E%3Cpath d=%22M48 250c8-54 34-81 52-81s44 27 52 81%22 fill=%22%23d1fae5%22/%3E%3C/svg%3E';

$title = CatalogTitle::factory()->create([
    'slug' => 'browser-smoke',
    'title' => 'Browser Smoke',
    'original_title' => 'Browser Smoke Original',
    'description' => 'Детерминированная карточка для локальной проверки браузера.',
    'poster_url' => $posterUrl,
    'type' => 'show',
    'year' => 2025,
]);
$russia = Country::query()->create([
    'name' => 'Россия',
    'slug' => 'rossiia',
]);
$title->countries()->attach($russia);
$genre = Genre::query()->create([
    'name' => 'Браузерная драма',
    'slug' => 'brauzernaia-drama',
]);
$title->genres()->attach($genre);
$searchGenre = Genre::query()->create([
    'name' => 'Browser Smoke category',
    'slug' => 'browser-smoke-category',
]);
$title->genres()->attach($searchGenre);
$turkey = Country::query()->create([
    'name' => 'Турция',
    'slug' => 'turciia',
]);

CatalogTitle::factory()->count(30)->sequence(
    fn (Sequence $sequence): array => [
        'title' => sprintf('Турецкий браузерный сериал %02d', $sequence->index + 1),
        'slug' => sprintf('turkish-browser-title-%02d', $sequence->index + 1),
        'poster_url' => $posterUrl,
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

$media = LicensedMedia::factory()->create([
    'catalog_title_id' => $title->id,
    'season_id' => $season->id,
    'episode_id' => $episode->id,
    'title' => 'Browser Smoke 1 серия',
    'storage_disk' => 'external_playlist',
    'path' => 'https://media.example.com/player-fixtures/valid.m3u8',
    'playback_url' => 'https://media.example.com/player-fixtures/valid.m3u8',
    'format' => 'm3u8',
    'quality' => '1080p',
    'variant_type' => 'original',
    'variant_key' => 'browser-hls',
    'duration_seconds' => 600,
    'status' => 'published',
    'check_status' => 'available',
    'health_status' => 'active',
    'published_at' => now()->subMinute(),
]);

CatalogTitleRating::query()->create([
    'catalog_title_id' => $title->id,
    'provider' => 'kinopoisk',
    'rating' => 8.4,
    'votes' => 25_000,
    'raw_value' => '8.4',
]);

LicensedMedia::factory()->create([
    'catalog_title_id' => $title->id,
    'season_id' => $season->id,
    'episode_id' => $episode->id,
    'title' => 'Browser Smoke 1 серия MP4',
    'storage_disk' => 'external_playlist',
    'path' => 'https://media.example.com/player-fixtures/direct.mp4',
    'playback_url' => 'https://media.example.com/player-fixtures/direct.mp4',
    'format' => 'mp4',
    'quality' => '720p',
    'variant_type' => 'original',
    'variant_key' => 'browser-mp4',
    'duration_seconds' => 600,
    'status' => 'published',
    'check_status' => 'available',
    'health_status' => 'active',
    'published_at' => now()->subMinute(),
]);

$recommendedTitle = CatalogTitle::factory()->create([
    'slug' => 'browser-recommended',
    'title' => 'Рекомендованный браузерный сериал',
    'original_title' => 'Browser Recommended',
    'description' => 'Детерминированная рекомендация для проверки карточки и причины.',
    'poster_url' => $posterUrl,
    'type' => 'show',
    'year' => 2024,
]);
$recommendedTitle->genres()->attach($genre);
LicensedMedia::factory()->for($recommendedTitle)->create([
    'status' => 'published',
    'published_at' => now()->subMinute(),
]);
CatalogTitleRecommendation::query()->create([
    'catalog_title_id' => $title->id,
    'recommended_title_id' => $recommendedTitle->id,
    'score' => 1_200,
    'rank' => 1,
    'algorithm_version' => 'v6',
    'matched_features_count' => 1,
    'metadata_score' => 1_120,
    'quality_score' => 80,
    'reasons' => ['genre' => ['count' => 1, 'ratio' => 1.0, 'score' => 1_120]],
    'computed_at' => now(),
]);
CatalogRecommendationBuild::query()->create([
    'algorithm_version' => 'v6',
    'feature_version' => 'tokens-v2',
    'status' => 'active',
    'metrics' => [
        'score_min' => 600,
        'score_median' => 1_000,
        'score_p95' => 1_600,
    ],
    'started_at' => now()->subMinutes(5),
    'completed_at' => now()->subMinutes(4),
    'activated_at' => now()->subMinutes(4),
]);

$user = User::factory()->create([
    'name' => 'Browser User',
    'email' => 'browser@example.com',
    'email_verified_at' => now()->subDay(),
    'password' => Hash::make('Browser-Strong-Password-42!'),
]);

$englishUser = User::factory()->create([
    'name' => 'Browser English User',
    'email' => 'browser-en@example.com',
    'email_verified_at' => now()->subDay(),
    'password' => Hash::make('Browser-Strong-Password-42!'),
]);

UserAccountSetting::query()->create([
    'user_id' => $englishUser->id,
    'locale' => 'en',
]);

CatalogTitleUserState::query()->create([
    'user_id' => $user->id,
    'catalog_title_id' => $title->id,
    'in_watchlist' => true,
    'rating' => null,
]);

EpisodeViewProgress::query()->create([
    'user_id' => $user->id,
    'catalog_title_id' => $title->id,
    'episode_id' => $episode->id,
    'licensed_media_id' => $media->id,
    'position_seconds' => 120,
    'duration_seconds' => 600,
    'progress_percent' => 20,
    'first_started_at' => now()->subMinutes(10),
    'last_watched_at' => now()->subMinute(),
]);

if (Artisan::call('catalog:search-rebuild', ['--chunk' => 50]) !== 0) {
    fwrite(STDERR, Artisan::output());
    exit(1);
}

fwrite(STDOUT, "Browser fixtures prepared.\n");
