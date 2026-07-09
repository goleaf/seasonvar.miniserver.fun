<?php

namespace Tests\Feature;

use App\Services\Seasonvar\SeasonvarSitemapMirror;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeasonvarSitemapMirrorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'seasonvar.crawl_delay_seconds' => 0,
            'seasonvar.sitemap_url' => 'https://seasonvar.ru/sitemap_index.xml',
        ]);

        File::deleteDirectory(storage_path('app/seasonvar/sitemaps'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/seasonvar/sitemaps'));

        parent::tearDown();
    }

    public function test_it_mirrors_nested_gzip_sitemaps_completely(): void
    {
        Http::preventStrayRequests();

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
            'seasonvar.ru/sitemap_index.xml' => Http::response($indexXml),
            'seasonvar.ru/sitemap-serials.xml.gz' => Http::response(gzencode($archiveXml)),
        ]);

        $result = app(SeasonvarSitemapMirror::class)->mirror();

        $this->assertSame([
            'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html',
            'https://seasonvar.ru/serial-615--Bez_sleda_pssmtlk-1-season.html',
            'https://seasonvar.ru/film-1-Test.html',
        ], $result['urls']);
        $this->assertSame(2, $result['archive_count']);
        $this->assertSame(2, $result['counts']['serial']);
        $this->assertSame(1, $result['counts']['unknown']);
        $this->assertFileExists(storage_path('app/seasonvar/sitemaps/archives/sitemap-serials.xml.gz'));
        $this->assertFileExists(storage_path('app/seasonvar/sitemaps/xml/sitemap-serials.xml'));
    }
}
