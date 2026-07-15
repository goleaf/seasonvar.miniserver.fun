<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Catalog\CatalogHomeSnapshotCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogHomeContentAdditionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/__tests/collections', fn (): string => '')->name('collections.index');
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_latest_updates_use_created_content_instead_of_metadata_index_time(): void
    {
        $this->travelTo(now()->setDate(2026, 7, 15)->setTime(12, 0));

        $metadataOnlyTitle = CatalogTitle::factory()->create([
            'title' => 'Обновлены только метаданные',
            'indexed_at' => now(),
        ]);
        $olderContentTitle = CatalogTitle::factory()->create([
            'title' => 'Старое фактическое пополнение',
            'indexed_at' => now(),
        ]);
        $olderSeason = Season::factory()->create([
            'catalog_title_id' => $olderContentTitle->id,
            'number' => 1,
        ]);
        Episode::factory()->create([
            'season_id' => $olderSeason->id,
            'number' => 1,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $episodeTitle = CatalogTitle::factory()->create([
            'title' => 'Добавлена новая серия',
            'indexed_at' => now()->subMonth(),
        ]);
        $episodeSeason = Season::factory()->create([
            'catalog_title_id' => $episodeTitle->id,
            'number' => 2,
        ]);
        Episode::factory()->create([
            'season_id' => $episodeSeason->id,
            'number' => 7,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $videoTitle = CatalogTitle::factory()->create([
            'title' => 'Добавлено новое видео',
            'indexed_at' => now()->subMonths(2),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $videoTitle->id,
            'status' => 'published',
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $snapshot = app(CatalogHomeSnapshotCache::class)->refresh();
        $updates = $snapshot['latest_title_updates'] ?? [];

        $this->assertSame(
            [$videoTitle->id, $episodeTitle->id, $olderContentTitle->id],
            collect($updates)->pluck('id')->all(),
        );
        $this->assertNotContains($metadataOnlyTitle->id, collect($updates)->pluck('id')->all());
    }

    public function test_new_episodes_group_every_series_and_video_under_one_title(): void
    {
        $this->travelTo(now()->setDate(2026, 7, 15)->setTime(12, 0));

        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Один сериал с несколькими сериями',
            'poster_url' => 'https://media.example.com/grouped-series.jpg',
            'indexed_at' => now()->subYear(),
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 3,
        ]);
        $firstEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 1,
            'title' => 'Первая новая серия',
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);
        $secondEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 2,
            'title' => 'Вторая новая серия',
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);
        Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 3,
            'title' => 'Серия пока без видео',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $firstEpisode->id,
            'status' => 'published',
            'quality' => '1080p',
            'translation_name' => 'Профессиональный перевод',
            'format' => 'm3u8',
            'published_at' => now()->subMinutes(3),
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $firstEpisode->id,
            'status' => 'published',
            'quality' => '720p',
            'translation_name' => 'Оригинальная дорожка',
            'format' => 'mp4',
            'published_at' => now()->subMinutes(2),
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $secondEpisode->id,
            'status' => 'published',
            'quality' => '480p',
            'translation_name' => 'Субтитры',
            'format' => 'webm',
            'published_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        app(CatalogHomeSnapshotCache::class)->refresh();

        $response = $this->get(route('home'))->assertOk();
        $html = $response->getContent();

        $this->assertSame(
            1,
            substr_count($html, 'data-home-latest-media-group="'.$catalogTitle->id.'"'),
        );
        $response
            ->assertSeeText('Сезон 3')
            ->assertSeeText('1 серия')
            ->assertSeeText('2 серия')
            ->assertSeeText('3 серия')
            ->assertSeeText('Первая новая серия')
            ->assertSeeText('Вторая новая серия')
            ->assertSeeText('Серия пока без видео')
            ->assertSeeText('1080P')
            ->assertSeeText('720P')
            ->assertSeeText('480P')
            ->assertSeeText('Профессиональный перевод / M3U8 / 15.07.2026')
            ->assertSeeText('Оригинальная дорожка / MP4 / 15.07.2026')
            ->assertSeeText('Субтитры / WEBM / 15.07.2026')
            ->assertSeeText('Видео для серии пока не добавлено.');
    }

    public function test_home_content_addition_queries_have_covering_indexes(): void
    {
        $episodesIndex = collect(Schema::getIndexes('episodes'))
            ->firstWhere('name', 'episodes_home_additions_idx');
        $mediaIndex = collect(Schema::getIndexes('licensed_media'))
            ->firstWhere('name', 'licensed_media_home_additions_idx');

        $this->assertIsArray($episodesIndex);
        $this->assertSame(['season_id', 'created_at', 'id'], $episodesIndex['columns'] ?? []);
        $this->assertIsArray($mediaIndex);
        $this->assertSame(['catalog_title_id', 'created_at', 'id'], $mediaIndex['columns'] ?? []);
    }
}
