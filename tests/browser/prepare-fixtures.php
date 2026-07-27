<?php

declare(strict_types=1);

use App\Enums\AdminMembershipStatus;
use App\Enums\AdminRoleCode;
use App\Enums\CatalogCollectionModerationStatus;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionType;
use App\Enums\CatalogCollectionVisibility;
use App\Enums\HelpArticleType;
use App\Enums\HelpAudience;
use App\Enums\HelpEscalationType;
use App\Enums\HelpFeature;
use App\Enums\HelpOwnerTeam;
use App\Enums\HelpPublicationStatus;
use App\Enums\ReleaseDatePrecision;
use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleSource;
use App\Enums\ReleaseScheduleStatus;
use App\Models\Actor;
use App\Models\AdminRole;
use App\Models\AdminUserRole;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogRecommendationBuild;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRating;
use App\Models\CatalogTitleRecommendation;
use App\Models\CatalogTitleUserState;
use App\Models\Country;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\Genre;
use App\Models\HelpArticle;
use App\Models\HelpArticleTranslation;
use App\Models\HelpCategory;
use App\Models\HelpCategoryTranslation;
use App\Models\LicensedMedia;
use App\Models\ReleaseScheduleEntry;
use App\Models\Season;
use App\Models\Tag;
use App\Models\Translation;
use App\Models\User;
use App\Models\UserAccountSetting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
$tag = Tag::query()->create([
    'name' => 'Браузерный тег',
    'slug' => 'browser-tag',
]);
$title->tags()->attach($tag);
$translation = Translation::query()->create([
    'name' => 'Браузерный перевод',
    'slug' => 'browser-translation',
]);
$title->translations()->attach($translation);
$turkey = Country::query()->create([
    'name' => 'Турция',
    'slug' => 'turciia',
]);

foreach (range(1, 20) as $index) {
    $fixtureGenre = Genre::query()->create([
        'name' => sprintf('Полный жанр %02d', $index),
        'slug' => sprintf('full-genre-%02d', $index),
    ]);
    $fixtureCountry = Country::query()->create([
        'name' => sprintf('Полная страна %02d', $index),
        'slug' => sprintf('full-country-%02d', $index),
    ]);
    $title->genres()->attach($fixtureGenre);
    $title->countries()->attach($fixtureCountry);
    CatalogTitle::factory()->create([
        'title' => sprintf('Сериал для года %d', 1999 + $index),
        'slug' => sprintf('full-year-%d', 1999 + $index),
        'year' => 1999 + $index,
        'poster_url' => $posterUrl,
    ]);
}

CatalogTitle::factory()->count(30)->sequence(
    fn (Sequence $sequence): array => [
        'title' => sprintf('Турецкий браузерный сериал %02d', $sequence->index + 1),
        'slug' => sprintf('turkish-browser-title-%02d', $sequence->index + 1),
        'poster_url' => $posterUrl,
        'indexed_at' => now()->subMinutes($sequence->index + 1),
    ],
)->create()->each(function (CatalogTitle $catalogTitle) use ($turkey): void {
    $catalogTitle->countries()->attach($turkey);
});

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
    'variant_key' => 'browser-original',
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
    'variant_key' => 'browser-original',
    'duration_seconds' => 600,
    'status' => 'published',
    'check_status' => 'available',
    'health_status' => 'active',
    'published_at' => now()->subMinute(),
]);

$nextEpisode = Episode::factory()->create([
    'season_id' => $season->id,
    'number' => 2,
    'title' => 'Серия 2',
]);

$nextMedia = LicensedMedia::factory()->create([
    'catalog_title_id' => $title->id,
    'season_id' => $season->id,
    'episode_id' => $nextEpisode->id,
    'title' => 'Browser Smoke 2 серия',
    'storage_disk' => 'external_playlist',
    'path' => 'https://media.example.com/player-fixtures/valid-next.m3u8',
    'playback_url' => 'https://media.example.com/player-fixtures/valid-next.m3u8',
    'format' => 'm3u8',
    'quality' => '1080p',
    'variant_type' => 'original',
    'variant_key' => 'browser-original',
    'duration_seconds' => 600,
    'status' => 'published',
    'check_status' => 'available',
    'health_status' => 'active',
    'published_at' => now()->subMinute(),
]);

LicensedMedia::factory()->create([
    'catalog_title_id' => $title->id,
    'season_id' => $season->id,
    'episode_id' => $nextEpisode->id,
    'title' => 'Browser Smoke 2 серия MP4',
    'storage_disk' => 'external_playlist',
    'path' => 'https://media.example.com/player-fixtures/direct-next.mp4',
    'playback_url' => 'https://media.example.com/player-fixtures/direct-next.mp4',
    'format' => 'mp4',
    'quality' => '720p',
    'variant_type' => 'original',
    'variant_key' => 'browser-original',
    'duration_seconds' => 600,
    'status' => 'published',
    'check_status' => 'available',
    'health_status' => 'active',
    'published_at' => now()->subMinute(),
]);

$thirdEpisode = Episode::factory()->create([
    'season_id' => $season->id,
    'number' => 3,
    'title' => 'Серия 3',
]);

LicensedMedia::factory()->create([
    'catalog_title_id' => $title->id,
    'season_id' => $season->id,
    'episode_id' => $thirdEpisode->id,
    'title' => 'Browser Smoke 3 серия',
    'storage_disk' => 'external_playlist',
    'path' => 'https://media.example.com/player-fixtures/valid-next.m3u8',
    'playback_url' => 'https://media.example.com/player-fixtures/valid-next.m3u8',
    'format' => 'm3u8',
    'quality' => '1080p',
    'variant_type' => 'original',
    'variant_key' => 'browser-original',
    'duration_seconds' => 600,
    'status' => 'published',
    'check_status' => 'available',
    'health_status' => 'active',
    'published_at' => now()->subMinute(),
]);

LicensedMedia::factory()->create([
    'catalog_title_id' => $title->id,
    'season_id' => $season->id,
    'episode_id' => $thirdEpisode->id,
    'title' => 'Browser Smoke 3 серия MP4',
    'storage_disk' => 'external_playlist',
    'path' => 'https://media.example.com/player-fixtures/direct-next.mp4',
    'playback_url' => 'https://media.example.com/player-fixtures/direct-next.mp4',
    'format' => 'mp4',
    'quality' => '720p',
    'variant_type' => 'original',
    'variant_key' => 'browser-original',
    'duration_seconds' => 600,
    'status' => 'published',
    'check_status' => 'available',
    'health_status' => 'active',
    'published_at' => now()->subMinute(),
]);

collect(range(1, 26))->each(
    function (int $offset) use (
        $title,
        $season,
        $episode,
        $media,
        $nextEpisode,
        $nextMedia,
    ): void {
        $useNext = $offset % 2 === 0;
        $entryEpisode = $useNext ? $nextEpisode : $episode;
        $entryMedia = $useNext ? $nextMedia : $media;
        $startsAt = now()->subDays($offset)->startOfHour();

        ReleaseScheduleEntry::query()->create([
            'logical_key' => 'browser-calendar-'.$offset,
            'entry_type' => ReleaseScheduleEntryType::PortalPublication,
            'status' => ReleaseScheduleStatus::Released,
            'precision' => ReleaseDatePrecision::ExactDateTime,
            'source' => ReleaseScheduleSource::Portal,
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $entryEpisode->id,
            'licensed_media_id' => $entryMedia->id,
            'season_number' => $season->number,
            'episode_number' => $entryEpisode->number,
            'starts_at' => $startsAt,
            'released_at' => $startsAt,
            'original_timezone' => 'UTC',
            'is_public' => true,
            'notifications_enabled' => false,
        ]);
    },
);

$batchTitle = CatalogTitle::factory()->create([
    'slug' => 'browser-calendar-batch',
    'title' => 'Осторожно с ангелом',
    'original_title' => 'Cuidado con el ángel',
    'description' => 'Детерминированная пачка серий для браузерной проверки календаря.',
    'poster_url' => $posterUrl,
    'type' => 'show',
    'year' => 2008,
]);
$batchSeason = Season::factory()->for($batchTitle)->create(['number' => 1]);
$batchPublishedAt = now()->subHours(2)->startOfMinute();

foreach (range(185, 194) as $episodeNumber) {
    $batchEpisode = Episode::factory()->for($batchSeason)->create([
        'number' => $episodeNumber,
        'title' => 'Название серии '.$episodeNumber,
    ]);
    $batchMedia = LicensedMedia::withoutEvents(fn (): LicensedMedia => LicensedMedia::factory()->create([
        'catalog_title_id' => $batchTitle->id,
        'season_id' => $batchSeason->id,
        'episode_id' => $batchEpisode->id,
        'title' => 'Осторожно с ангелом, серия '.$episodeNumber,
        'storage_disk' => 'external_playlist',
        'path' => 'https://media.example.com/player-fixtures/calendar-'.$episodeNumber.'.mp4',
        'playback_url' => 'https://media.example.com/player-fixtures/calendar-'.$episodeNumber.'.mp4',
        'format' => 'mp4',
        'quality' => '720p',
        'variant_type' => 'original',
        'variant_key' => 'browser-original',
        'duration_seconds' => 600,
        'status' => 'published',
        'check_status' => 'available',
        'health_status' => 'active',
        'published_at' => $batchPublishedAt,
    ]));

    ReleaseScheduleEntry::query()->create([
        'logical_key' => 'browser-calendar-batch-'.$episodeNumber,
        'entry_type' => ReleaseScheduleEntryType::PortalPublication,
        'status' => ReleaseScheduleStatus::Released,
        'precision' => ReleaseDatePrecision::ExactDateTime,
        'source' => ReleaseScheduleSource::Portal,
        'catalog_title_id' => $batchTitle->id,
        'season_id' => $batchSeason->id,
        'episode_id' => $batchEpisode->id,
        'licensed_media_id' => $batchMedia->id,
        'season_number' => $batchSeason->number,
        'episode_number' => $episodeNumber,
        'starts_at' => $batchPublishedAt,
        'released_at' => $batchPublishedAt,
        'original_timezone' => 'UTC',
        'is_public' => true,
        'notifications_enabled' => false,
    ]);
}

foreach (range(1, 12) as $recommendationRank) {
    $recommendedTitle = CatalogTitle::factory()->create([
        'slug' => $recommendationRank === 1
            ? 'browser-recommended'
            : sprintf('browser-recommended-%02d', $recommendationRank),
        'title' => $recommendationRank === 1
            ? 'Рекомендованный браузерный сериал'
            : sprintf('Рекомендованный браузерный сериал %02d', $recommendationRank),
        'original_title' => $recommendationRank === 1
            ? 'Browser Recommended'
            : sprintf('Browser Recommended %02d', $recommendationRank),
        'description' => str_repeat(
            'Детерминированная рекомендация для проверки карточки, причины сходства и ограничения описания. ',
            4,
        ),
        'poster_url' => $posterUrl,
        'type' => 'show',
        'year' => 2014 + $recommendationRank,
    ]);
    $recommendedTitle->genres()->attach($genre);
    $recommendedTitle->countries()->attach($russia);
    Season::factory()->create([
        'catalog_title_id' => $recommendedTitle->id,
        'number' => 1,
        'title' => 'Сезон 1',
    ]);
    CatalogTitleRating::query()->create([
        'catalog_title_id' => $recommendedTitle->id,
        'provider' => 'imdb',
        'rating' => 7.7,
        'votes' => 12_000,
        'raw_value' => '7.7',
    ]);
    LicensedMedia::factory()->for($recommendedTitle)->create([
        'status' => 'published',
        'published_at' => now()->subMinute(),
    ]);
    CatalogTitleRecommendation::query()->create([
        'catalog_title_id' => $title->id,
        'recommended_title_id' => $recommendedTitle->id,
        'score' => 1_300 - $recommendationRank,
        'rank' => $recommendationRank,
        'algorithm_version' => 'v6',
        'matched_features_count' => 3,
        'metadata_score' => 1_120,
        'quality_score' => 80,
        'reasons' => [
            'genre' => ['count' => 1, 'ratio' => 1.0, 'score' => 1_120],
            'country' => ['count' => 1, 'ratio' => 1.0, 'score' => 900],
            'year' => ['difference' => 1, 'score' => 700],
        ],
        'computed_at' => now(),
    ]);
}
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

$pwaHelpCategory = HelpCategory::query()->create([
    'public_id' => (string) Str::uuid(),
    'code' => 'browser_pwa_offline',
    'position' => 999,
    'is_visible' => true,
    'content_version' => 1,
]);
HelpCategoryTranslation::query()->create([
    'help_category_id' => $pwaHelpCategory->id,
    'locale' => 'ru',
    'slug' => 'browser-pwa-offline',
    'title' => 'Помощь PWA',
    'description' => 'Публичная справка для browser QA.',
]);
$pwaHelpArticle = HelpArticle::query()->create([
    'public_id' => (string) Str::uuid(),
    'code' => 'browser-pwa-offline-library',
    'help_category_id' => $pwaHelpCategory->id,
    'type' => HelpArticleType::HowTo,
    'audience' => HelpAudience::Everyone,
    'status' => HelpPublicationStatus::Published,
    'owner_team' => HelpOwnerTeam::Support,
    'feature_code' => HelpFeature::General,
    'primary_escalation' => HelpEscalationType::None,
    'secondary_escalation' => HelpEscalationType::None,
    'position' => 1,
    'editorial_priority' => 1,
    'is_featured' => false,
    'is_indexable' => true,
    'content_version' => 1,
    'published_at' => now(),
]);
HelpArticleTranslation::query()->create([
    'help_article_id' => $pwaHelpArticle->id,
    'locale' => 'ru',
    'slug' => 'browser-pwa-offline-library',
    'title' => 'Как работает сохранённая библиотека',
    'summary' => 'Без сети доступны только сохранённые метаданные и публичная справка.',
    'body_markdown' => 'Видео, HLS и защищённые источники без сети не сохраняются.',
    'search_text' => 'сохранённая библиотека offline PWA',
    'is_published' => true,
    'published_at' => now(),
]);

$englishUser = User::factory()->create([
    'name' => 'Browser English User',
    'email' => 'browser-en@example.com',
    'email_verified_at' => now()->subDay(),
    'password' => Hash::make('Browser-Strong-Password-42!'),
]);

$administrator = User::factory()->create([
    'name' => 'Browser Administrator',
    'email' => 'browser-admin@example.com',
    'email_verified_at' => now()->subDay(),
    'password' => Hash::make('Browser-Strong-Password-42!'),
]);
AdminUserRole::query()->create([
    'user_id' => $administrator->id,
    'admin_role_id' => AdminRole::query()->where('code', AdminRoleCode::Superadministrator)->valueOrFail('id'),
    'status' => AdminMembershipStatus::Active,
    'reason_code' => 'browser_fixture',
    'assigned_at' => now()->subMinute(),
]);

$collectionCategory = CatalogCollectionCategory::query()
    ->where('slug', 'detective-and-crime')
    ->firstOrFail();
$collection = CatalogCollection::query()->create([
    'public_id' => (string) Str::uuid(),
    'owner_id' => $user->id,
    'catalog_collection_category_id' => $collectionCategory->id,
    'name' => 'Браузерная подборка детективов',
    'description' => 'Текстовая подборка для проверки категорий без собственной обложки.',
    'slug' => 'browser-detective-collection',
    'type' => CatalogCollectionType::User,
    'visibility' => CatalogCollectionVisibility::Public,
    'moderation_status' => CatalogCollectionModerationStatus::Approved,
    'sort_mode' => CatalogCollectionSort::Manual,
    'content_locale' => 'ru',
    'content_version' => 1,
    'published_at' => now()->subMinute(),
]);
$collectionItem = CatalogCollectionItem::query()->create([
    'catalog_collection_id' => $collection->id,
    'catalog_title_id' => $title->id,
    'added_by_id' => $user->id,
    'position' => 1,
]);
$collectionItem->forceFill([
    'theme_match_percent' => 80,
    'inclusion_reason_code' => 'title_theme',
    'quality_content_version' => $collection->content_version,
])->save();
$collection->forceFill([
    'quality_score' => 82,
    'quality_content_version' => $collection->content_version,
    'quality_evaluated_at' => now(),
    'quality_details' => [
        'components' => [
            'metadata' => 22,
            'structure' => 25,
            'theme' => 23,
            'trust' => 12,
        ],
        'engagement' => [
            'saves' => 1,
            'completions' => 0,
            'returns' => 0,
            'reports' => 0,
        ],
    ],
])->save();

$uncategorizedCollection = CatalogCollection::query()->create([
    'public_id' => (string) Str::uuid(),
    'owner_id' => $user->id,
    'catalog_collection_category_id' => null,
    'name' => 'Браузерная подборка Netflix',
    'description' => 'Оригинальные проекты Netflix для проверки рекомендации.',
    'slug' => 'browser-netflix-collection',
    'type' => CatalogCollectionType::User,
    'visibility' => CatalogCollectionVisibility::Public,
    'moderation_status' => CatalogCollectionModerationStatus::Approved,
    'sort_mode' => CatalogCollectionSort::Manual,
    'content_locale' => 'ru',
    'content_version' => 1,
    'published_at' => now()->subMinute(),
]);
CatalogCollectionItem::query()->create([
    'catalog_collection_id' => $uncategorizedCollection->id,
    'catalog_title_id' => $title->id,
    'added_by_id' => $user->id,
    'position' => 1,
]);

$editorialTitles = CatalogTitle::factory()
    ->count(11)
    ->create()
    ->each(function (CatalogTitle $catalogTitle): void {
        LicensedMedia::factory()->for($catalogTitle)->create([
            'status' => 'published',
            'published_at' => now()->subMinute(),
        ]);
    })
    ->prepend($title)
    ->values();
$readyEditorialCollection = CatalogCollection::query()->create([
    'public_id' => (string) Str::uuid(),
    'owner_id' => null,
    'catalog_collection_category_id' => $collectionCategory->id,
    'name' => 'Готовая браузерная редакционная подборка',
    'description' => 'Двенадцать доступных сериалов для проверки редакционной готовности.',
    'slug' => 'browser-ready-editorial-collection',
    'type' => CatalogCollectionType::Editorial,
    'visibility' => CatalogCollectionVisibility::Public,
    'moderation_status' => CatalogCollectionModerationStatus::Approved,
    'sort_mode' => CatalogCollectionSort::Manual,
    'content_locale' => 'ru',
    'content_version' => 1,
    'is_featured' => true,
    'published_at' => now()->subMinute(),
]);
$thinEditorialCollection = CatalogCollection::query()->create([
    'public_id' => (string) Str::uuid(),
    'owner_id' => null,
    'catalog_collection_category_id' => $collectionCategory->id,
    'name' => 'Тонкая браузерная редакционная подборка',
    'description' => 'Одиннадцать доступных сериалов для проверки безопасного отказа.',
    'slug' => 'browser-thin-editorial-collection',
    'type' => CatalogCollectionType::Editorial,
    'visibility' => CatalogCollectionVisibility::Public,
    'moderation_status' => CatalogCollectionModerationStatus::Approved,
    'sort_mode' => CatalogCollectionSort::Manual,
    'content_locale' => 'ru',
    'content_version' => 1,
    'published_at' => now()->subMinute(),
]);

$editorialTitles->each(function (CatalogTitle $catalogTitle, int $index) use ($readyEditorialCollection): void {
    CatalogCollectionItem::query()->create([
        'catalog_collection_id' => $readyEditorialCollection->id,
        'catalog_title_id' => $catalogTitle->id,
        'position' => $index + 1,
    ]);
});
$editorialTitles->take(11)->each(function (CatalogTitle $catalogTitle, int $index) use ($thinEditorialCollection): void {
    CatalogCollectionItem::query()->create([
        'catalog_collection_id' => $thinEditorialCollection->id,
        'catalog_title_id' => $catalogTitle->id,
        'position' => $index + 1,
    ]);
});
$readyEditorialCollection->forceFill([
    'quality_score' => 88,
    'quality_content_version' => $readyEditorialCollection->content_version,
    'quality_evaluated_at' => now(),
    'quality_details' => [
        'components' => [
            'metadata' => 25,
            'structure' => 25,
            'theme' => 25,
            'trust' => 13,
        ],
        'engagement' => [
            'saves' => 1,
            'completions' => 1,
            'returns' => 1,
            'reports' => 0,
        ],
    ],
    'editorially_verified_at' => now(),
    'editorially_verified_by_id' => $administrator->id,
    'editorially_verified_content_version' => $readyEditorialCollection->content_version,
])->save();

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
