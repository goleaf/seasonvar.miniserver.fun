<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\SeasonvarImportRun;
use App\Models\Source;
use App\Models\SourcePage;
use App\Services\ProjectDocumentation\ProjectDocumentationRefresher;
use App\Services\Seasonvar\SeasonvarSitemapMirror;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeasonvarSitemapMirrorTest extends TestCase
{
    use RefreshDatabase;

    private string $sitemapStorageDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sitemapStorageDirectory = 'seasonvar/tests/sitemaps-'.getmypid();

        config([
            'seasonvar.crawl_delay_seconds' => 0,
            'seasonvar.sitemap_url' => 'https://seasonvar.ru/sitemap_index.xml',
            'seasonvar.sitemap_storage_directory' => $this->sitemapStorageDirectory,
        ]);

        File::deleteDirectory(storage_path('app/'.$this->sitemapStorageDirectory));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/'.$this->sitemapStorageDirectory));

        parent::tearDown();
    }

    public function test_it_mirrors_nested_gzip_sitemaps_completely(): void
    {
        Http::preventStrayRequests();
        config(['seasonvar.crawl_delay_seconds' => 1]);

        $indexXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <sitemap><loc>https://seasonvar.ru/sitemap-serials.xml.gz</loc></sitemap>
</sitemapindex>
XML;

        $archiveXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url><loc>https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html</loc></url>
    <url><loc>https://seasonvar.ru/serial-615--Bez_sleda_pssmtlk-1-season.html</loc></url>
    <url><loc>https://seasonvar.ru/film-1-Test.html</loc></url>
</urlset>
XML;

        Http::fake([
            'seasonvar.ru/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
            'seasonvar.ru/sitemap_index.xml' => Http::response($indexXml),
            'seasonvar.ru/sitemap-serials.xml.gz' => Http::response(gzencode($archiveXml)),
        ]);

        $events = [];
        $result = app(SeasonvarSitemapMirror::class)->mirror(
            function (string $event, array $context) use (&$events): void {
                $events[] = ['event' => $event, 'context' => $context];
            },
        );

        $this->assertSame([
            'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html',
            'https://seasonvar.ru/serial-615--Bez_sleda_pssmtlk-1-season.html',
            'https://seasonvar.ru/film-1-Test.html',
        ], $result['urls']);
        $this->assertSame(2, $result['archive_count']);
        $this->assertSame(2, $result['counts']['serial']);
        $this->assertSame(1, $result['counts']['unknown']);
        $this->assertCount(2, collect($events)->where('event', 'crawl-delay-wait-started'));
        $this->assertFileExists(storage_path('app/'.$this->sitemapStorageDirectory.'/archives/sitemap-serials.xml.gz'));
        $this->assertFileExists(storage_path('app/'.$this->sitemapStorageDirectory.'/xml/sitemap-serials.xml'));
    }

    public function test_inventory_mode_classifies_every_safe_url_without_touching_catalog_records(): void
    {
        Http::preventStrayRequests();
        $this->travelTo('2026-07-13 12:00:00');

        $catalogTitle = CatalogTitle::factory()->create(['title' => 'Редакционный тайтл']);
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $serialUrl = 'https://seasonvar.ru/serial-615--Bez_sleda_pssmtlk-1-season.html';
        $existingPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $serialUrl,
            'url_hash' => hash('sha256', $serialUrl),
            'page_type' => 'serial',
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
        ]);
        $existingPageUpdatedAt = $existingPage->updated_at?->toDateTimeString();
        $sourcePageCountBefore = SourcePage::query()->count();

        Http::fake($this->inventoryResponses($serialUrl));

        $this->artisan('seasonvar:import', ['--inventory-only' => true])
            ->expectsOutputToContain('Инвентаризация страниц Seasonvar завершена')
            ->expectsOutputToContain('сериал (serial)')
            ->expectsOutputToContain('Неизвестных URL: 1')
            ->assertExitCode(0);

        $run = SeasonvarImportRun::query()->where('mode', 'inventory')->sole();
        $inventory = $run->summary['source_inventory'];

        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $inventory['sitemap_count']);
        $this->assertSame(7, $inventory['total_url_count']);
        $this->assertSame(2, $inventory['counts_by_page_type']['sitemap']);
        $this->assertSame(1, $inventory['counts_by_page_type']['serial']);
        $this->assertSame(1, $inventory['counts_by_page_type']['actor']);
        $this->assertSame(1, $inventory['counts_by_page_type']['genre']);
        $this->assertSame(1, $inventory['counts_by_page_type']['static']);
        $this->assertSame(1, $inventory['counts_by_page_type']['unknown']);
        $this->assertSame(1, $inventory['unknown_url_count']);
        $this->assertSame(1, $inventory['malformed_url_count']);
        $this->assertSame(3, $inventory['blocked_url_count']);
        $this->assertContains('unknown', $inventory['discovered_but_unsupported_types']);
        $this->assertContains('static', $inventory['discovered_but_unsupported_types']);
        $this->assertSame(['/film-1-Test.html'], $inventory['sample_urls_by_page_type']['unknown']);
        $this->assertSame($sourcePageCountBefore + 6, SourcePage::query()->count());
        $this->assertSame(1, CatalogTitle::query()->count());
        $this->assertSame($catalogTitle->id, CatalogTitle::query()->sole()->id);
        $this->assertSame('parsed', $existingPage->fresh()->parse_status);
        $this->assertSame('parsed', $existingPage->fresh()->import_status);
        $this->assertSame($existingPageUpdatedAt, $existingPage->fresh()->updated_at?->toDateTimeString());

        Http::fake($this->inventoryResponses($serialUrl));
        $this->travel(1)->minute();

        $this->artisan('seasonvar:import', ['--inventory-only' => true])->assertExitCode(0);

        $this->assertSame(2, SeasonvarImportRun::query()->where('mode', 'inventory')->count());
        $this->assertSame($sourcePageCountBefore + 6, SourcePage::query()->count());
        $this->assertSame(1, CatalogTitle::query()->count());
        $this->assertSame('parsed', $existingPage->fresh()->parse_status);
        $this->assertSame($existingPageUpdatedAt, $existingPage->fresh()->updated_at?->toDateTimeString());

        $refreshed = app(ProjectDocumentationRefresher::class)->refreshContents(
            'docs/SOURCE_PARITY.md',
            "# Паритет источника Seasonvar\n",
        );

        $this->assertStringContainsString('13.07.2026 12:01:00', $refreshed);
        $this->assertStringContainsString('| `serial` | 1 | да | да |', $refreshed);
        $this->assertStringContainsString('| `unknown` | 1 | нет | нет |', $refreshed);

        Http::assertNotSent(fn (Request $request): bool => ! str_contains($request->url(), 'sitemap')
            && ! str_ends_with($request->url(), '/robots.txt'));
    }

    public function test_inventory_failure_is_persisted_without_a_false_success_or_catalog_changes(): void
    {
        Http::preventStrayRequests();
        CatalogTitle::factory()->create();
        $sourcePageCountBefore = SourcePage::query()->count();

        Http::fake([
            'seasonvar.ru/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
            'seasonvar.ru/sitemap_index.xml' => Http::response('provider unavailable', 503),
        ]);

        $this->artisan('seasonvar:import', ['--inventory-only' => true])
            ->expectsOutputToContain('Инвентаризация страниц Seasonvar не завершена')
            ->assertExitCode(1);

        $run = SeasonvarImportRun::query()->where('mode', 'inventory')->sole();

        $this->assertSame('failed', $run->status);
        $this->assertNotEmpty($run->summary['source_inventory']['failure_details']);
        $this->assertSame(1, CatalogTitle::query()->count());
        $this->assertSame($sourcePageCountBefore, SourcePage::query()->count());
    }

    public function test_inventory_does_not_request_a_configured_sitemap_blocked_by_robots(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'seasonvar.ru/robots.txt' => Http::response("User-agent: *\nDisallow: /sitemap_index.xml\n"),
            'seasonvar.ru/sitemap_index.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>',
            ),
        ]);

        $this->artisan('seasonvar:import', ['--inventory-only' => true])
            ->expectsOutputToContain('Инвентаризация страниц Seasonvar не завершена')
            ->assertExitCode(1);

        $this->assertSame('failed', SeasonvarImportRun::query()->where('mode', 'inventory')->sole()->status);
        Http::assertSentCount(1);
    }

    /**
     * @return array<string, Response|ResponseSequence>
     */
    private function inventoryResponses(string $serialUrl): array
    {
        $indexXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <sitemap><loc>https://seasonvar.ru/sitemap-pages.xml.gz</loc></sitemap>
    <sitemap><loc>https://blocked.example/sitemap.xml</loc></sitemap>
</sitemapindex>
XML;
        $pagesXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url><loc>{$serialUrl}</loc></url>
    <url><loc>https://www.seasonvar.ru//serial-615--Bez_sleda_pssmtlk-1-season.html#episode</loc></url>
    <url><loc>https://seasonvar.ru/actor/ivan-ivanov</loc></url>
    <url><loc>https://seasonvar.ru/genre/%64rama/?utm_source=inventory</loc></url>
    <url><loc>https://seasonvar.ru/tag/robots-blocked</loc></url>
    <url><loc>https://seasonvar.ru/st/serials.html</loc></url>
    <url><loc>https://seasonvar.ru/film-1-Test.html</loc></url>
    <url><loc>https://seasonvar.ru/serial-1-Test.html/serial-2-Other.html</loc></url>
    <url><loc>https://seasonvar.ru/player.php?token=private</loc></url>
</urlset>
XML;

        return [
            'seasonvar.ru/robots.txt' => Http::response("User-agent: *\nAllow: /\nDisallow: /tag/\n"),
            'seasonvar.ru/sitemap_index.xml' => Http::response($indexXml),
            'seasonvar.ru/sitemap-pages.xml.gz' => Http::response(gzencode($pagesXml)),
        ];
    }
}
