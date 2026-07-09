<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\SeasonvarImportRun;
use App\Models\Source;
use App\Models\SourcePage;
use App\Services\Media\ExternalMediaMetadata;
use App\Services\Seasonvar\SeasonvarRefreshPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeasonvarImportMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'seasonvar.crawl_delay_seconds' => 0,
            'seasonvar.import.parse_batch_size' => 5,
            'seasonvar.media_check.backfill_per_cycle' => 5,
            'seasonvar.media_identity.backfill_per_cycle' => 5,
        ]);
    }

    public function test_it_refuses_to_start_when_import_lock_is_held(): void
    {
        $lock = Cache::lock('seasonvar-import', 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('seasonvar:import', ['--no-discovery' => true])
                ->expectsOutputToContain('Обновление уже запущено')
                ->assertExitCode(1);
        } finally {
            $lock->release();
        }
    }

    public function test_it_recovers_a_stale_import_lock_when_previous_run_stopped_without_finishing(): void
    {
        Http::preventStrayRequests();
        config(['seasonvar.import.stale_after_minutes' => 5]);
        $lock = Cache::lock('seasonvar-import', 60);
        $this->assertTrue($lock->get());
        $staleRun = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'status' => 'running',
            'force' => false,
            'forever' => false,
            'started_at' => now()->subHour(),
        ]);
        SeasonvarImportRun::query()
            ->whereKey($staleRun->id)
            ->update([
                'created_at' => now()->subHour(),
                'updated_at' => now()->subMinutes(10),
            ]);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->expectsOutputToContain('Найден зависший запуск импорта')
            ->assertExitCode(0);

        $staleRun->refresh();

        $this->assertSame('failed', $staleRun->status);
        $this->assertSame('Предыдущий запуск остановился без завершения и был закрыт автоматически.', $staleRun->last_error);
        $this->assertNotNull($staleRun->finished_at);
    }

    public function test_it_marks_malformed_nested_source_urls_as_unavailable_without_requesting_them(): void
    {
        Http::preventStrayRequests();

        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $url = 'https://seasonvar.ru/serial-615--Bez_sleda_pssmtlk-1-season.html/serial-29641-Mariya_Vern_psydwch-8-season.html';

        SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'serial',
            'parse_status' => 'pending',
            'import_status' => 'pending',
        ]);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('source_pages', [
            'url' => $url,
            'parse_status' => 'failed',
            'import_status' => 'gone',
            'error_message' => 'Некорректная склеенная ссылка',
        ]);
    }

    public function test_it_checks_old_media_without_check_status_during_import_cycle(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'media.example.com/*' => Http::response('', 206),
        ]);

        $catalogTitle = CatalogTitle::factory()->create();
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'playback_url' => 'https://media.example.com/video/s01e01.mp4',
            'path' => 'https://media.example.com/video/s01e01.mp4',
            'status' => 'draft',
            'check_status' => null,
            'checked_at' => null,
        ]);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $media->refresh();

        $this->assertSame('published', $media->status);
        $this->assertSame('available', $media->check_status);
        $this->assertSame(206, $media->last_http_status);
        $this->assertNotNull($media->checked_at);
    }

    public function test_it_marks_legacy_parsed_source_pages_as_imported(): void
    {
        Http::preventStrayRequests();

        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $url = 'https://seasonvar.ru/serial-615--Bez_sleda_pssmtlk-1-season.html';
        $page = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'serial',
            'parse_status' => 'parsed',
            'import_status' => 'pending',
            'last_crawled_at' => now()->subDay(),
        ]);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $page->refresh();

        $this->assertSame('parsed', $page->import_status);
        $this->assertNotNull($page->last_imported_at);
    }

    public function test_it_rechecks_recent_parsed_page_when_existing_episode_has_no_video(): void
    {
        $this->travelTo('2026-07-09 12:00:00');
        Http::preventStrayRequests();
        config(['seasonvar.media_check.enabled' => false]);

        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $url = 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html';
        $body = $this->refreshPlannerSeasonPageHtml([
            1 => 'Начало',
            2 => 'Проверка',
        ], ['https://media.example.com/kitchen/cernyi-spisok-na-kuxne-s01e02.mp4']);
        $page = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'serial',
            'content_hash' => hash('sha256', $body),
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'last_imported_at' => now(),
        ]);
        $catalogTitle = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $page->id,
            'external_id' => '47915',
            'slug' => 'chernyi-spisok-na-kuhne',
            'title' => 'Черный список: На кухне',
            'source_url' => $url,
            'source_url_hash' => hash('sha256', $url),
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'source_page_id' => $page->id,
            'number' => 1,
            'source_url' => $url,
            'source_url_hash' => hash('sha256', $url),
        ]);
        $firstEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 1,
            'title' => 'Начало',
        ]);
        $secondEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 2,
            'title' => 'Проверка',
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $firstEpisode->id,
            'title' => '1 серия',
            'storage_disk' => 'seasonvar_parsed',
            'path' => 'https://media.example.com/kitchen/cernyi-spisok-na-kuxne-s01e01.mp4',
            'playback_url' => 'https://media.example.com/kitchen/cernyi-spisok-na-kuxne-s01e01.mp4',
            'status' => 'published',
            'check_status' => 'available',
            'checked_at' => now(),
        ]);

        Http::fake([
            'seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html' => Http::response($body),
        ]);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('licensed_media', [
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $secondEpisode->id,
            'playback_url' => 'https://media.example.com/kitchen/cernyi-spisok-na-kuxne-s01e02.mp4',
            'status' => 'published',
        ]);

        $page->refresh();
        $this->assertSame('parsed', $page->import_status);
        $this->assertSame([], $page->missing_data_flags);
        $this->assertNull($page->retry_after_at);
    }

    public function test_it_does_not_recheck_recent_complete_parsed_page_without_refresh_reason(): void
    {
        Http::preventStrayRequests();
        config(['seasonvar.media_check.enabled' => false]);

        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $url = 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html';
        $page = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'serial',
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'last_imported_at' => now(),
        ]);
        $catalogTitle = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $page->id,
            'source_url' => $url,
            'source_url_hash' => hash('sha256', $url),
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'source_page_id' => $page->id,
            'number' => 1,
            'source_url' => $url,
            'source_url_hash' => hash('sha256', $url),
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 1,
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'storage_disk' => 'seasonvar_parsed',
            'path' => 'https://media.example.com/kitchen/cernyi-spisok-na-kuxne-s01e01.mp4',
            'playback_url' => 'https://media.example.com/kitchen/cernyi-spisok-na-kuxne-s01e01.mp4',
            'status' => 'published',
            'check_status' => 'available',
            'checked_at' => now(),
            'quality' => '720p',
            'format' => 'mp4',
        ]);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);
    }

    public function test_refresh_planner_prioritizes_pages_with_episodes_without_video(): void
    {
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $missingVideoPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'page_type' => 'serial',
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'last_imported_at' => now()->subDays(10),
            'retry_after_at' => null,
        ]);
        $catalogTitle = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $missingVideoPage->id,
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'source_page_id' => $missingVideoPage->id,
            'number' => 1,
        ]);
        Episode::factory()->create([
            'season_id' => $season->id,
            'source_page_id' => $missingVideoPage->id,
            'number' => 1,
        ]);
        $pendingPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'page_type' => 'serial',
            'parse_status' => 'pending',
            'import_status' => 'pending',
            'last_imported_at' => null,
        ]);
        SourcePage::factory()->create([
            'source_id' => $source->id,
            'page_type' => 'serial',
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'last_imported_at' => now()->subDays(30),
        ]);
        $events = [];

        $pages = app(SeasonvarRefreshPlanner::class)->pagesForImportCycle(
            2,
            now()->subHours(168),
            function (string $event, array $context) use (&$events): void {
                $events[] = ['event' => $event, 'context' => $context];
            },
        );

        $selectedReasons = collect($events)
            ->filter(fn (array $event): bool => $event['context']['selected'] > 0)
            ->pluck('context.reason')
            ->all();

        $this->assertSame([$missingVideoPage->id, $pendingPage->id], $pages->pluck('id')->all());
        $this->assertSame(['episodes_without_video', 'pending'], $selectedReasons);
    }

    public function test_it_backfills_missing_media_quality_and_format_during_import_cycle(): void
    {
        Http::preventStrayRequests();

        $catalogTitle = CatalogTitle::factory()->create();
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'title' => 'Серия 1 WEB-DL',
            'playback_url' => 'https://media.example.com/video/series.s01e01.1920x1080.mp4',
            'path' => 'https://media.example.com/video/series.s01e01.1920x1080.mp4',
            'status' => 'published',
            'check_status' => 'available',
            'checked_at' => now(),
            'quality' => null,
            'format' => null,
        ]);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $media->refresh();

        $this->assertSame('1080p', $media->quality);
        $this->assertSame('mp4', $media->format);
    }

    public function test_it_detects_dvd_media_quality_during_import_cycle(): void
    {
        Http::preventStrayRequests();

        $catalogTitle = CatalogTitle::factory()->create();
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'title' => '2 серия [DVD]',
            'playback_url' => 'https://media.example.com/video/show.s01e02.dvd.mp4',
            'path' => 'https://media.example.com/video/show.s01e02.dvd.mp4',
            'status' => 'published',
            'check_status' => 'available',
            'checked_at' => now(),
            'quality' => null,
            'format' => null,
        ]);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $media->refresh();

        $this->assertSame('480p', $media->quality);
        $this->assertSame('mp4', $media->format);
    }

    public function test_it_backfills_missing_media_source_keys_during_import_cycle(): void
    {
        Http::preventStrayRequests();

        $catalogTitle = CatalogTitle::factory()->create([
            'source_url_hash' => hash('sha256', 'https://seasonvar.ru/serial-615--Bez_sleda_pssmtlk-1-season.html'),
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 1,
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 1,
        ]);
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'title' => '1 серия SD/HD',
            'storage_disk' => 'seasonvar_parsed',
            'playback_url' => 'https://media.example.com/video/without-a-trace.s01e01.720p.mp4',
            'path' => 'https://media.example.com/video/without-a-trace.s01e01.720p.mp4',
            'source_url' => 'https://seasonvar.ru/playls2/hash/trans/123/plist.txt?time=1',
            'source_media_key' => null,
            'quality' => null,
            'format' => null,
            'status' => 'published',
            'check_status' => 'available',
            'checked_at' => now(),
        ]);

        $this->artisan('seasonvar:import', ['--no-discovery' => true])
            ->assertExitCode(0);

        $media->refresh();
        $expectedSourceMediaKey = app(ExternalMediaMetadata::class)->sourceMediaKey(
            'seasonvar',
            $catalogTitle->source_url_hash,
            $season->number,
            $episode->number,
            'https://seasonvar.ru/playls2/hash/trans/123/plist.txt?time=1',
            'https://media.example.com/video/without-a-trace.s01e01.720p.mp4',
            '1 серия SD/HD',
            '720p',
            'mp4',
        );

        $this->assertSame($expectedSourceMediaKey, $media->source_media_key);
        $this->assertSame('720p', $media->quality);
        $this->assertSame('mp4', $media->format);
    }

    /**
     * @param  array<int, string>  $episodes
     * @param  list<string>  $mediaUrls
     */
    private function refreshPlannerSeasonPageHtml(array $episodes, array $mediaUrls = []): string
    {
        $episodeItems = collect($episodes)
            ->mapWithKeys(fn (string $title, int $number): array => [
                "{$number}_seriya" => ['n' => (string) $number, 'title' => $title],
            ])
            ->all();
        $episodesJson = json_encode([$episodeItems], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $mediaJson = json_encode($mediaUrls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return <<<HTML
            <html>
                <head>
                    <title>Черный список: На кухне 1 сезон смотреть онлайн</title>
                    <meta name="description" content="Описание передачи">
                </head>
                <body>
                    <h1>Сериал Черный список: На кухне 1 сезон онлайн</h1>
                    <div class="pgs-sinfo_list">
                        Жанр: Кулинария
                        Страна: Россия
                        Вышел: 2024
                        Перевод: Оригинал
                    </div>
                    <div class="pgs-seaslist">
                        <a href="/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html">1 сезон (Оригинал)</a>
                    </div>
                    <script>
                        var arEpisodes = {$episodesJson};
                        var parsedMedia = {$mediaJson};
                    </script>
                </body>
            </html>
            HTML;
    }
}
