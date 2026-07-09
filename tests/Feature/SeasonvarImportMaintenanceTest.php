<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\Source;
use App\Models\SourcePage;
use App\Services\Media\ExternalMediaMetadata;
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
}
