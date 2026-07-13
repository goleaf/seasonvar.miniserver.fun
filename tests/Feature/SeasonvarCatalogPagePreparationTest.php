<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\Seasonvar\SeasonvarPreparedCatalogPage;
use App\Models\SeasonvarImportRun;
use App\Models\Source;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarCatalogPagePreparer;
use App\Services\Seasonvar\SeasonvarSourcePageFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeasonvarCatalogPagePreparationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'seasonvar.media_check.enabled' => false,
            'security.external_playlist_enforce_public_dns' => false,
        ]);
    }

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

    public function test_preparer_round_trips_every_discovered_season_without_catalog_writes(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://seasonvar.ru/serial-24212-Ryzhaya_psbdtie-9-season.html' => Http::response(
                $this->nineSeasonPageHtml(),
            ),
        ]);
        $run = $this->queuedRun();
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $url = 'https://seasonvar.ru/serial-24212-Ryzhaya_psbdtie-9-season.html';
        $page = SourcePage::factory()->for($source)->create([
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'serial',
            'content_hash' => null,
            'parse_status' => 'pending',
        ]);

        $prepared = app(SeasonvarCatalogPagePreparer::class)->prepare($page, $run->id);
        $roundTrip = SeasonvarPreparedCatalogPage::fromPayload($prepared->toPayload());

        $this->assertSame($page->id, $roundTrip->sourcePageId);
        $this->assertSame(9, $roundTrip->catalogData->currentSeasonNumber);
        $this->assertCount(9, $roundTrip->discoveredSeasonUrls);
        $this->assertSame(
            range(1, 9),
            collect($roundTrip->catalogData->seasons)->pluck('number')->sort()->values()->all(),
        );
        $this->assertCount(2, $roundTrip->catalogData->episodes);
        $this->assertCount(1, $roundTrip->catalogData->media);
        $this->assertSame('https://media.example.com/ryzhaya-s09e02.mp4', $roundTrip->catalogData->media[0]['url']);
        $this->assertDatabaseCount('catalog_titles', 0);
        $this->assertDatabaseCount('seasons', 0);
        $this->assertDatabaseCount('episodes', 0);
        $this->assertDatabaseCount('licensed_media', 0);
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

    private function nineSeasonPageHtml(): string
    {
        $seasonLinks = collect(range(1, 9))
            ->map(fn (int $season): string => sprintf(
                '<a href="/serial-24212-Ryzhaya_psbdtie-%d-season.html">%d сезон</a>',
                $season,
                $season,
            ))
            ->implode('');

        return <<<HTML
        <html>
            <head><title>Рыжая 9 сезон смотреть онлайн</title></head>
            <body>
                <h1>Рыжая 9 сезон</h1>
                <div class="pgs-sinfo_list">Год: 2026 Жанр: Драма Страна: Россия</div>
                <div class="pgs-seaslist">{$seasonLinks}</div>
                <script>
                    var arEpisodes = [{"1_seriya":{"n":"1","next":"2"},"2_seriya":{"n":"2"}}];
                    var visibleFiles = ["https://media.example.com/ryzhaya-s09e02.mp4"];
                </script>
            </body>
        </html>
        HTML;
    }
}
