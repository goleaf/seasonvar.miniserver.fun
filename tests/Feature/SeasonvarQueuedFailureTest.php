<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\Seasonvar\SeasonvarSourceRequestException;
use App\Models\SeasonvarImportRun;
use App\Models\Source;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarCatalogImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class SeasonvarQueuedFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'seasonvar.crawl_delay_seconds' => 0,
            'seasonvar.media_check.enabled' => false,
        ]);
        Http::preventStrayRequests();
    }

    public function test_queued_parser_rethrows_a_transient_failure_after_recording_it_once(): void
    {
        $this->assertRetryTransientParameterExists();

        [$page, $run] = $this->pageAndRun();
        Http::fake([$page->url => Http::response('temporarily unavailable', 503)]);

        try {
            app(SeasonvarCatalogImporter::class)->parsePages(
                collect([$page]),
                importRunId: $run->id,
                retryTransient: true,
            );
            $this->fail('Transient source exception was not rethrown.');
        } catch (SeasonvarSourceRequestException $exception) {
            $this->assertSame(503, $exception->status);
        }

        $page->refresh();

        $this->assertSame(1, $page->failure_count);
        $this->assertSame('failed', $page->import_status);
        $this->assertSame($run->id, $page->last_import_run_id);
    }

    public function test_queued_parser_returns_a_permanent_failure_without_retrying_it(): void
    {
        $this->assertRetryTransientParameterExists();

        [$page, $run] = $this->pageAndRun();
        Http::fake([$page->url => Http::response('not found', 404)]);

        $result = app(SeasonvarCatalogImporter::class)->parsePages(
            collect([$page]),
            importRunId: $run->id,
            retryTransient: true,
        );

        $page->refresh();

        $this->assertSame(0, $result['parsed']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $page->failure_count);
        $this->assertSame('gone', $page->import_status);
        $this->assertSame($run->id, $page->last_import_run_id);
    }

    /**
     * @return array{SourcePage, SeasonvarImportRun}
     */
    private function pageAndRun(): array
    {
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $page = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => 'https://seasonvar.ru/serial-1-Test-1-season.html',
            'url_hash' => hash('sha256', 'https://seasonvar.ru/serial-1-Test-1-season.html'),
            'parse_status' => 'pending',
            'import_status' => 'pending',
            'failure_count' => 0,
        ]);
        $run = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'running',
            'started_at' => now(),
        ]);

        return [$page, $run];
    }

    private function assertRetryTransientParameterExists(): void
    {
        $parameters = collect((new ReflectionMethod(SeasonvarCatalogImporter::class, 'parsePages'))->getParameters())
            ->map->getName()
            ->all();

        $this->assertContains('retryTransient', $parameters);
    }
}
