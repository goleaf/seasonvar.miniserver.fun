<?php

namespace Tests\Unit;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleReview;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\SeasonvarImportEvent;
use App\Models\SeasonvarImportRun;
use App\Models\Source;
use App\Models\SourcePage;
use App\Models\SourcePageSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EloquentRelationshipTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_page_and_import_run_inverse_relationships_resolve_catalog_records(): void
    {
        $run = SeasonvarImportRun::query()->create([
            'mode' => 'parse',
            'status' => 'completed',
            'force' => false,
            'forever' => false,
        ]);
        $source = Source::factory()->create();
        $sourcePage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => 'https://seasonvar.ru/serial-101-test-1-season.html',
            'url_hash' => hash('sha256', 'https://seasonvar.ru/serial-101-test-1-season.html'),
            'last_import_run_id' => $run->id,
        ]);
        $catalogTitle = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $sourcePage->id,
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'source_page_id' => $sourcePage->id,
            'number' => 1,
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'source_page_id' => $sourcePage->id,
            'number' => 2,
        ]);
        $review = CatalogTitleReview::query()->create([
            'catalog_title_id' => $catalogTitle->id,
            'source_page_id' => $sourcePage->id,
            'author' => 'Seasonvar',
            'body' => 'Проверочный отзыв',
            'body_hash' => hash('sha256', 'Проверочный отзыв'),
        ]);
        $snapshot = SourcePageSnapshot::query()->create([
            'source_page_id' => $sourcePage->id,
            'seasonvar_import_run_id' => $run->id,
            'url' => $sourcePage->url,
            'content_hash' => hash('sha256', 'snapshot'),
            'http_status' => 200,
            'body_bytes' => 128,
            'html' => '<html></html>',
            'captured_at' => now(),
        ]);
        $event = SeasonvarImportEvent::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'source_page_id' => $sourcePage->id,
            'catalog_title_id' => $catalogTitle->id,
            'event' => 'page-parse-complete',
            'level' => 'info',
            'context' => ['source_page_id' => $sourcePage->id],
        ]);

        $sourcePage->load(['catalogTitle', 'seasons', 'episodes', 'reviews', 'snapshots', 'importEvents', 'lastImportRun']);
        $run->load(['events', 'snapshots', 'lastImportedSourcePages']);
        $catalogTitle->load('importEvents');

        $this->assertTrue($sourcePage->catalogTitle->is($catalogTitle));
        $this->assertTrue($sourcePage->seasons->first()?->is($season));
        $this->assertTrue($sourcePage->episodes->first()?->is($episode));
        $this->assertTrue($sourcePage->reviews->first()?->is($review));
        $this->assertTrue($sourcePage->snapshots->first()?->is($snapshot));
        $this->assertTrue($sourcePage->importEvents->first()?->is($event));
        $this->assertTrue($sourcePage->lastImportRun?->is($run));
        $this->assertTrue($run->events->first()?->is($event));
        $this->assertTrue($run->snapshots->first()?->is($snapshot));
        $this->assertTrue($run->lastImportedSourcePages->first()?->is($sourcePage));
        $this->assertTrue($catalogTitle->importEvents->first()?->is($event));
    }

    public function test_model_scopes_and_schema_casts_match_relationship_query_usage(): void
    {
        $publishedTitle = CatalogTitle::factory()->create([
            'year' => '2024',
            'is_published' => true,
        ]);
        CatalogTitle::factory()->create(['is_published' => false]);
        $source = Source::factory()->create([
            'crawl_delay_seconds' => '7',
            'is_active' => '1',
            'settings' => ['timeout' => 15],
        ]);
        $run = SeasonvarImportRun::query()->create([
            'mode' => 'discover',
            'status' => 'running',
            'force' => '1',
            'forever' => '0',
            'process_id' => '1234',
            'cycles' => '2',
            'discovered' => '3',
            'stored' => '4',
            'selected' => '5',
            'parsed' => '6',
            'failed' => '1',
            'media_attached' => '7',
            'media_updated' => '8',
            'media_skipped' => '9',
            'media_failed' => '10',
            'summary' => ['ok' => true],
        ]);
        $sourcePage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'http_status' => '304',
            'missing_data_flags' => ['no_video'],
            'failure_count' => '4',
            'last_import_run_id' => $run->id,
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $publishedTitle->id,
            'source_page_id' => $sourcePage->id,
            'number' => '3',
            'episodes_released' => '11',
            'episodes_total' => '12',
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'source_page_id' => $sourcePage->id,
            'number' => '5',
        ]);
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $publishedTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'duration_seconds' => '1500',
            'last_http_status' => '206',
        ]);
        $snapshot = SourcePageSnapshot::query()->create([
            'source_page_id' => $sourcePage->id,
            'seasonvar_import_run_id' => $run->id,
            'url' => $sourcePage->url,
            'content_hash' => hash('sha256', 'cast-snapshot'),
            'http_status' => '200',
            'body_bytes' => '512',
            'html' => '<html></html>',
            'captured_at' => now(),
        ]);

        $this->assertSame([$publishedTitle->id], CatalogTitle::query()->published()->pluck('id')->all());
        $this->assertSame(2024, $publishedTitle->refresh()->year);
        $this->assertTrue($publishedTitle->is_published);
        $this->assertSame(7, $source->refresh()->crawl_delay_seconds);
        $this->assertTrue($source->is_active);
        $this->assertSame(['timeout' => 15], $source->settings);
        $this->assertTrue($run->refresh()->force);
        $this->assertFalse($run->forever);
        $this->assertSame(1234, $run->process_id);
        $this->assertSame(2, $run->cycles);
        $this->assertSame(10, $run->media_failed);
        $this->assertSame(['ok' => true], $run->summary);
        $this->assertSame(304, $sourcePage->refresh()->http_status);
        $this->assertSame(4, $sourcePage->failure_count);
        $this->assertSame(['no_video'], $sourcePage->missing_data_flags);
        $this->assertSame(3, $season->refresh()->number);
        $this->assertSame(11, $season->episodes_released);
        $this->assertSame(12, $season->episodes_total);
        $this->assertSame(5, $episode->refresh()->number);
        $this->assertSame(1500, $media->refresh()->duration_seconds);
        $this->assertSame(206, $media->last_http_status);
        $this->assertSame(200, $snapshot->refresh()->http_status);
        $this->assertSame(512, $snapshot->body_bytes);
    }
}
