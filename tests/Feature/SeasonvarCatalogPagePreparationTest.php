<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SeasonvarImportRun;
use App\Models\Source;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarSourcePageFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeasonvarCatalogPagePreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fetcher_stores_snapshot_without_writing_catalog_rows(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://seasonvar.ru/*' => Http::response('<html>source</html>', 200, [
                'ETag' => '"source-v1"',
                'Last-Modified' => 'Mon, 13 Jul 2026 10:00:00 GMT',
            ]),
        ]);
        $run = $this->queuedRun();
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $page = SourcePage::factory()->for($source)->create([
            'url' => 'https://seasonvar.ru/serial-24212-Ryzhaya_psbdtie-9-season.html',
            'url_hash' => hash('sha256', 'https://seasonvar.ru/serial-24212-Ryzhaya_psbdtie-9-season.html'),
            'page_type' => 'serial',
            'http_status' => null,
            'content_hash' => null,
            'parse_status' => 'pending',
        ]);

        $fetched = app(SeasonvarSourcePageFetcher::class)->fetch($page, $run->id);

        $this->assertSame($page->id, $fetched->sourcePageId);
        $this->assertSame('<html>source</html>', $fetched->body);
        $this->assertSame(hash('sha256', '<html>source</html>'), $fetched->contentHash);
        $this->assertSame(200, $fetched->httpStatus);
        $this->assertTrue($fetched->contentChanged);
        $this->assertNotNull($fetched->snapshotId);
        $this->assertDatabaseHas('source_page_snapshots', [
            'id' => $fetched->snapshotId,
            'source_page_id' => $page->id,
            'seasonvar_import_run_id' => $run->id,
            'http_status' => 200,
            'html' => '<html>source</html>',
        ]);
        $this->assertDatabaseHas('source_pages', [
            'id' => $page->id,
            'http_status' => 200,
            'content_hash' => $fetched->contentHash,
            'etag' => '"source-v1"',
            'last_modified_header' => 'Mon, 13 Jul 2026 10:00:00 GMT',
        ]);
        $this->assertDatabaseCount('catalog_titles', 0);
        $this->assertDatabaseCount('seasons', 0);
        $this->assertDatabaseCount('episodes', 0);
    }

    private function queuedRun(): SeasonvarImportRun
    {
        return SeasonvarImportRun::query()->create([
            'mode' => 'url',
            'execution_mode' => 'queue',
            'status' => 'running',
            'force' => true,
            'forever' => false,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
    }
}
