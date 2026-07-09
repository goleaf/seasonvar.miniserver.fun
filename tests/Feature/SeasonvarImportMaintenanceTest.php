<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\Source;
use App\Models\SourcePage;
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
}
