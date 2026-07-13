<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Models\Network;
use App\Models\Season;
use App\Models\SourcePage;
use App\Models\SourcePageSnapshot;
use App\Models\Studio;
use App\Models\Translation;
use App\Services\Catalog\CatalogRelationSyncer;
use App\Services\Seasonvar\SeasonvarCatalogMetadataBackfill;
use App\Services\Seasonvar\SeasonvarCatalogParser;
use App\Services\Seasonvar\SeasonvarCatalogRelationSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SeasonvarCatalogMetadataBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_versioned_metadata_schema_models_and_queue_indexes_are_available(): void
    {
        $this->assertGreaterThan(0, SeasonvarCatalogParser::METADATA_VERSION);
        $this->assertTrue(Schema::hasColumns('source_pages', [
            'metadata_parser_version',
            'metadata_attempted_version',
            'metadata_parsed_at',
            'metadata_presence',
        ]));
        $this->assertTrue(Schema::hasColumn('catalog_titles', 'relation_metadata_version'));

        $page = new SourcePage;
        $title = new CatalogTitle;

        $this->assertSame(0, $page->metadata_parser_version);
        $this->assertSame(0, $page->metadata_attempted_version);
        $this->assertSame(0, $title->relation_metadata_version);
        $this->assertContains('metadata_parser_version', $page->getFillable());
        $this->assertContains('metadata_attempted_version', $page->getFillable());
        $this->assertContains('metadata_parsed_at', $page->getFillable());
        $this->assertContains('metadata_presence', $page->getFillable());
        $this->assertContains('relation_metadata_version', $title->getFillable());

        $storedPage = SourcePage::factory()->create([
            'metadata_parser_version' => '1',
            'metadata_attempted_version' => '2',
            'metadata_parsed_at' => now(),
            'metadata_presence' => ['studios' => 'present'],
        ]);
        $storedTitle = CatalogTitle::factory()->create([
            'relation_metadata_version' => '3',
        ]);

        $this->assertSame(1, $storedPage->metadata_parser_version);
        $this->assertSame(2, $storedPage->metadata_attempted_version);
        $this->assertNotNull($storedPage->metadata_parsed_at);
        $this->assertSame(['studios' => 'present'], $storedPage->metadata_presence);
        $this->assertSame(3, $storedTitle->relation_metadata_version);
        $this->assertSame(
            ['page_type', 'metadata_parser_version', 'metadata_attempted_version', 'id'],
            $this->indexColumns('source_pages', 'source_pages_metadata_queue_idx'),
        );
        $this->assertSame(
            ['relation_metadata_version', 'id'],
            $this->indexColumns('catalog_titles', 'catalog_titles_metadata_queue_idx'),
        );
        $this->assertSame(
            ['source_page_id', 'captured_at', 'id'],
            $this->indexColumns('source_page_snapshots', 'source_page_snapshots_latest_idx'),
        );
        $this->assertSame(
            ['source_page_id', 'deleted_at'],
            $this->indexColumns('catalog_titles', 'catalog_titles_source_page_lookup_idx'),
        );
        $this->assertSame(
            ['source_url_hash', 'deleted_at', 'catalog_title_id'],
            $this->indexColumns('seasons', 'seasons_source_url_hash_lookup_idx'),
        );

        foreach (['page_chunk_size', 'page_limit', 'title_chunk_size', 'title_limit'] as $key) {
            $this->assertGreaterThan(0, config("seasonvar.metadata_backfill.{$key}"));
        }
    }

    public function test_latest_snapshot_prefers_capture_time_and_then_id(): void
    {
        $page = SourcePage::factory()->create();
        $latestCapturedAt = now()->startOfSecond();
        $latestByTime = SourcePageSnapshot::query()->create([
            'source_page_id' => $page->id,
            'url' => $page->url,
            'content_hash' => hash('sha256', 'latest-by-time'),
            'http_status' => 200,
            'body_bytes' => 64,
            'html' => '<html>latest by time</html>',
            'captured_at' => $latestCapturedAt,
        ]);
        SourcePageSnapshot::query()->create([
            'source_page_id' => $page->id,
            'url' => $page->url,
            'content_hash' => hash('sha256', 'newer-id-older-time'),
            'http_status' => 200,
            'body_bytes' => 64,
            'html' => '<html>newer id, older time</html>',
            'captured_at' => $latestCapturedAt->copy()->subDay(),
        ]);

        $this->assertTrue($page->latestSnapshot()->first()?->is($latestByTime));

        $latestByTimeAndId = SourcePageSnapshot::query()->create([
            'source_page_id' => $page->id,
            'url' => $page->url,
            'content_hash' => hash('sha256', 'latest-by-time-and-id'),
            'http_status' => 200,
            'body_bytes' => 64,
            'html' => '<html>latest by time and id</html>',
            'captured_at' => $latestCapturedAt,
        ]);

        $this->assertTrue($page->latestSnapshot()->first()?->is($latestByTimeAndId));
    }

    public function test_metadata_eligibility_uses_indexable_relation_subqueries_instead_of_correlated_scans(): void
    {
        Http::preventStrayRequests();
        $this->pageWithSnapshot($this->trustedMetadataHtml(), 51999);
        $queries = collect();
        DB::listen(fn ($query) => $queries->push($query->sql));

        app(SeasonvarCatalogMetadataBackfill::class)->run();

        $selection = $queries->first(
            fn (string $sql): bool => str_contains($sql, 'from "source_pages"')
                && str_contains($sql, '"metadata_parser_version" < ?')
                && str_contains($sql, 'order by "id" asc'),
        );

        $this->assertIsString($selection);
        $this->assertStringContainsString('in (select "source_page_id" from "catalog_titles"', $selection);
        $this->assertStringContainsString('in (select "source_url_hash" from "seasons"', $selection);
        $this->assertStringNotContainsString('exists (select * from "catalog_titles"', $selection);
        $this->assertStringNotContainsString('exists (select * from "seasons"', $selection);
        Http::assertNothingSent();
    }

    public function test_local_snapshot_backfill_is_versioned_idempotent_and_never_sends_http(): void
    {
        Http::preventStrayRequests();
        $page = SourcePage::factory()->create([
            'url' => 'https://seasonvar.ru/serial-52000-Trusted-1-season.html',
            'url_hash' => hash('sha256', 'https://seasonvar.ru/serial-52000-Trusted-1-season.html'),
            'parse_status' => 'parsed',
        ]);
        $title = CatalogTitle::factory()->create([
            'source_id' => $page->source_id,
            'source_page_id' => $page->id,
            'relation_metadata_version' => 0,
        ]);
        $latestCapturedAt = now()->startOfSecond();
        $latestSnapshot = $this->snapshot($page, $this->trustedMetadataHtml(), $latestCapturedAt);
        $this->snapshot(
            $page,
            '<html><head><title>Устаревший снимок</title></head><body><h1>Устаревший снимок</h1></body></html>',
            $latestCapturedAt->copy()->subDay(),
        );
        $events = [];

        $result = app(SeasonvarCatalogMetadataBackfill::class)->run(
            function (string $event, array $context) use (&$events): void {
                $events[] = compact('event', 'context');
            },
        );

        $page->refresh();
        $title->refresh();

        $this->assertTrue($page->latestSnapshot?->is($latestSnapshot));
        $this->assertSame(SeasonvarCatalogParser::METADATA_VERSION, $page->metadata_parser_version);
        $this->assertSame(SeasonvarCatalogParser::METADATA_VERSION, $page->metadata_attempted_version);
        $this->assertNotNull($page->metadata_parsed_at);
        $this->assertSame('present', $page->metadata_presence['studios']);
        $this->assertSame('present', $page->metadata_presence['networks']);
        $this->assertSame('present', $page->metadata_presence['translations']);
        $this->assertSame('rejected_invalid', $page->metadata_presence['statuses']);
        $this->assertNotContains('Рекомендовано!', $page->metadata_presence);
        $this->assertSame('show', $title->type);
        $this->assertSame('show', $title->provider_field_values['type']);
        $this->assertSame(SeasonvarCatalogParser::METADATA_VERSION, $title->relation_metadata_version);
        $this->assertDatabaseHas((new Studio)->getTable(), ['name' => 'A-1 Pictures']);
        $this->assertDatabaseHas((new Network)->getTable(), ['name' => 'Пятница']);
        $this->assertDatabaseHas((new Translation)->getTable(), ['name' => 'RuDub']);
        $this->assertStringContainsString(
            'A-1 Pictures',
            (string) $title->searchDocument()->value('taxonomies'),
        );
        $this->assertSame(1, $result['pages_checked']);
        $this->assertSame(1, $result['pages_updated']);
        $this->assertGreaterThanOrEqual(3, $result['relations_attached']);
        $this->assertSame(0, $result['failed']);
        $this->assertNotEmpty($events);
        Http::assertNothingSent();

        $second = app(SeasonvarCatalogMetadataBackfill::class)->run();

        $this->assertSame([
            'pages_checked' => 0,
            'pages_updated' => 0,
            'titles_checked' => 0,
            'titles_updated' => 0,
            'relations_attached' => 0,
            'failed' => 0,
        ], $second);
        Http::assertNothingSent();
    }

    public function test_local_snapshot_backfill_resolves_a_title_through_season_source_hash(): void
    {
        Http::preventStrayRequests();
        $canonicalPage = SourcePage::factory()->create();
        $title = CatalogTitle::factory()->create([
            'source_id' => $canonicalPage->source_id,
            'source_page_id' => $canonicalPage->id,
            'relation_metadata_version' => 0,
        ]);
        $seasonUrl = 'https://seasonvar.ru/serial-52001-Linked-2-season.html';
        $seasonPage = SourcePage::factory()->create([
            'source_id' => $canonicalPage->source_id,
            'url' => $seasonUrl,
            'url_hash' => hash('sha256', $seasonUrl),
            'parse_status' => 'parsed',
        ]);
        Season::factory()->create([
            'catalog_title_id' => $title->id,
            'source_url' => $seasonUrl,
            'source_url_hash' => $seasonPage->url_hash,
        ]);
        $this->snapshot($seasonPage, $this->trustedMetadataHtml());

        $result = app(SeasonvarCatalogMetadataBackfill::class)->run();

        $this->assertSame(1, $result['pages_updated']);
        $this->assertSame(SeasonvarCatalogParser::METADATA_VERSION, $seasonPage->fresh()->metadata_parser_version);
        $this->assertSame(SeasonvarCatalogParser::METADATA_VERSION, $title->fresh()->relation_metadata_version);
        $this->assertTrue($title->studios()->where('name', 'A-1 Pictures')->exists());
        Http::assertNothingSent();
    }

    public function test_media_only_title_backfill_normalizes_translation_names(): void
    {
        Http::preventStrayRequests();
        $title = CatalogTitle::factory()->create([
            'relation_metadata_version' => 0,
        ]);
        $title->sourcePage()->update([
            'metadata_parser_version' => SeasonvarCatalogParser::METADATA_VERSION,
            'metadata_attempted_version' => SeasonvarCatalogParser::METADATA_VERSION,
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'variant_type' => 'voiceover',
            'variant_name' => 'HDRuDub',
            'translation_name' => 'HDRuDub',
        ]);

        $result = app(SeasonvarCatalogMetadataBackfill::class)->run();

        $this->assertSame(1, $result['titles_checked']);
        $this->assertSame(1, $result['titles_updated']);
        $this->assertSame(1, $result['relations_attached']);
        $this->assertSame(SeasonvarCatalogParser::METADATA_VERSION, $title->fresh()->relation_metadata_version);
        $this->assertTrue($title->translations()->where('name', 'RuDub')->exists());
        Http::assertNothingSent();
    }

    public function test_page_and_title_hard_limits_bound_total_work_and_invalid_html_is_attempted_once(): void
    {
        Http::preventStrayRequests();
        config([
            'seasonvar.metadata_backfill.page_chunk_size' => 1,
            'seasonvar.metadata_backfill.page_limit' => 2,
            'seasonvar.metadata_backfill.title_chunk_size' => 1,
            'seasonvar.metadata_backfill.title_limit' => 2,
        ]);
        $invalidPage = $this->pageWithSnapshot('<html><body>Нет названия</body></html>', 53000);
        $firstValidPage = $this->pageWithSnapshot($this->trustedMetadataHtml(), 53001);
        $secondValidPage = $this->pageWithSnapshot($this->trustedMetadataHtml(), 53002);

        $first = app(SeasonvarCatalogMetadataBackfill::class)->run();

        $this->assertSame(2, $first['pages_checked']);
        $this->assertSame(1, $first['pages_updated']);
        $this->assertSame(1, $first['failed']);
        $this->assertSame(0, $invalidPage->fresh()->metadata_parser_version);
        $this->assertSame(SeasonvarCatalogParser::METADATA_VERSION, $invalidPage->fresh()->metadata_attempted_version);
        $this->assertSame(SeasonvarCatalogParser::METADATA_VERSION, $firstValidPage->fresh()->metadata_parser_version);
        $this->assertSame(0, $secondValidPage->fresh()->metadata_parser_version);

        $second = app(SeasonvarCatalogMetadataBackfill::class)->run();

        $this->assertSame(1, $second['pages_checked']);
        $this->assertSame(1, $second['pages_updated']);
        $this->assertSame(0, $second['failed']);
        $this->assertSame(SeasonvarCatalogParser::METADATA_VERSION, $secondValidPage->fresh()->metadata_parser_version);
        Http::assertNothingSent();

        CatalogTitle::query()->update(['relation_metadata_version' => 0]);
        $missingSnapshotPage = SourcePage::factory()->create([
            'metadata_parser_version' => 0,
            'metadata_attempted_version' => 0,
        ]);
        CatalogTitle::factory()->create([
            'source_id' => $missingSnapshotPage->source_id,
            'source_page_id' => $missingSnapshotPage->id,
            'relation_metadata_version' => 0,
        ]);

        $titleBounded = app(SeasonvarCatalogMetadataBackfill::class)->run();

        $this->assertSame(0, $titleBounded['pages_checked']);
        $this->assertSame(2, $titleBounded['titles_checked']);
        $this->assertSame(2, CatalogTitle::query()
            ->where('relation_metadata_version', SeasonvarCatalogParser::METADATA_VERSION)
            ->count());
        $this->assertSame(0, $missingSnapshotPage->fresh()->metadata_attempted_version);
        Http::assertNothingSent();
    }

    public function test_present_metadata_has_precedence_over_rejected_values_without_storing_raw_input(): void
    {
        Http::preventStrayRequests();
        $page = $this->pageWithSnapshot(<<<'HTML'
            <html>
                <head><title>Статусный сериал</title></head>
                <body>
                    <h1>Статусный сериал</h1>
                    <div class="pgs-sinfo_list">Статус: Рекомендовано!</div>
                    <div class="pgs-sinfo_list">Статус: идет</div>
                </body>
            </html>
            HTML, 53003);

        app(SeasonvarCatalogMetadataBackfill::class)->run();

        $this->assertSame('present', $page->fresh()->metadata_presence['statuses']);
        $this->assertStringNotContainsString(
            'Рекомендовано!',
            json_encode($page->metadata_presence, JSON_THROW_ON_ERROR),
        );
        $this->assertTrue($page->catalogTitle->statuses()->where('name', 'Выходит')->exists());
        Http::assertNothingSent();
    }

    public function test_relation_sync_failure_rolls_back_pivots_and_all_metadata_versions(): void
    {
        Http::preventStrayRequests();
        $page = $this->pageWithSnapshot($this->trustedMetadataHtml(), 53004);
        $title = $page->catalogTitle;
        $syncer = Mockery::mock(SeasonvarCatalogRelationSyncer::class);
        $syncer->shouldReceive('sync')
            ->once()
            ->andReturnUsing(function (CatalogTitle $candidate): never {
                $studio = Studio::query()->create([
                    'name' => 'Будет отменена',
                    'slug' => 'budet-otmenena',
                ]);
                $candidate->studios()->attach($studio->id);

                throw new RuntimeException('Forced relation sync failure.');
            });
        $this->app->instance(SeasonvarCatalogRelationSyncer::class, $syncer);

        $result = app(SeasonvarCatalogMetadataBackfill::class)->run();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['pages_updated']);
        $this->assertSame(0, $page->fresh()->metadata_parser_version);
        $this->assertSame(0, $page->metadata_attempted_version);
        $this->assertSame(0, $title->fresh()->relation_metadata_version);
        $this->assertFalse($title->studios()->where('slug', 'budet-otmenena')->exists());
        $this->assertDatabaseMissing((new Studio)->getTable(), ['slug' => 'budet-otmenena']);
        Http::assertNothingSent();
    }

    public function test_relation_sync_reuses_one_actor_for_encoded_and_decoded_provider_urls(): void
    {
        $title = CatalogTitle::factory()->create();
        $syncer = app(SeasonvarCatalogRelationSyncer::class);

        $syncer->sync($title, [[
            'type' => 'actor',
            'name' => 'Adam Ian Cohen',
            'source_url' => 'https://seasonvar.ru/actor/Adam Ian Cohen',
        ]]);
        $syncer->sync($title, [[
            'type' => 'actor',
            'name' => 'Adam Ian Cohen',
            'source_url' => 'https://seasonvar.ru/actor/Adam%20Ian%20Cohen',
        ]]);

        $this->assertDatabaseCount('actors', 1);
        $this->assertDatabaseCount('catalog_title_actor', 1);
        $this->assertSame(
            'https://seasonvar.ru/actor/Adam%20Ian%20Cohen',
            Actor::query()->sole()->source_url,
        );
    }

    public function test_relation_sync_reuses_one_actor_for_equivalent_latin_and_cyrillic_names(): void
    {
        $title = CatalogTitle::factory()->create();
        $syncer = app(SeasonvarCatalogRelationSyncer::class);

        $syncer->sync($title, [[
            'type' => 'actor',
            'name' => 'Atsuko Tanaka',
            'source_url' => 'https://seasonvar.ru/actor/Atsuko%20Tanaka',
        ]]);
        $syncer->sync($title, [[
            'type' => 'actor',
            'name' => 'Ацуко Танака',
            'source_url' => 'https://seasonvar.ru/actor/%D0%90%D1%86%D1%83%D0%BA%D0%BE%20%D0%A2%D0%B0%D0%BD%D0%B0%D0%BA%D0%B0',
        ]]);

        $actor = Actor::query()->sole();

        $this->assertDatabaseCount('catalog_title_actor', 1);
        $this->assertSame('atsuko-tanaka', $actor->slug);
        $this->assertSame('Ацуко Танака', $actor->name);
    }

    public function test_catalog_relation_syncer_provides_canonical_identity_for_future_sources(): void
    {
        $title = CatalogTitle::factory()->create();
        $syncer = app(CatalogRelationSyncer::class);

        $syncer->sync($title, [[
            'type' => 'actor',
            'name' => 'Atsuko Tanaka',
            'source_url' => 'https://metadata.example/people/atsuko-tanaka',
        ]]);
        $syncer->sync($title, [[
            'type' => 'actor',
            'name' => 'Ацуко Танака',
            'source_url' => 'https://another-source.example/actors/42',
        ]]);

        $actor = Actor::query()->sole();

        $this->assertSame('atsuko-tanaka', $actor->slug);
        $this->assertSame('Ацуко Танака', $actor->name);
        $this->assertSame('https://metadata.example/people/atsuko-tanaka', $actor->source_url);
        $this->assertDatabaseCount('catalog_title_actor', 1);
    }

    private function pageWithSnapshot(string $html, int $externalId): SourcePage
    {
        $url = "https://seasonvar.ru/serial-{$externalId}-Metadata-1-season.html";
        $page = SourcePage::factory()->create([
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'parse_status' => 'parsed',
        ]);
        CatalogTitle::factory()->create([
            'source_id' => $page->source_id,
            'source_page_id' => $page->id,
            'relation_metadata_version' => 0,
        ]);
        $this->snapshot($page, $html);

        return $page;
    }

    private function snapshot(SourcePage $page, string $html, mixed $capturedAt = null): SourcePageSnapshot
    {
        return SourcePageSnapshot::query()->create([
            'source_page_id' => $page->id,
            'url' => $page->url,
            'content_hash' => hash('sha256', $html.$page->snapshots()->count()),
            'http_status' => 200,
            'body_bytes' => mb_strlen($html, '8bit'),
            'html' => $html,
            'captured_at' => $capturedAt ?? now(),
        ]);
    }

    private function trustedMetadataHtml(): string
    {
        return <<<'HTML'
            <html>
                <head><title>Доверенный сериал смотреть онлайн</title></head>
                <body>
                    <h1>Доверенный сериал</h1>
                    <div class="pgs-sinfo_list">
                        Жанр: реалити-шоу
                        Студии: A-1 Pictures
                        Телеканал: Пятница
                        Статус: Рекомендовано!
                    </div>
                    <ul class="pgs-trans">
                        <li data-click="translate">HDRuDub</li>
                    </ul>
                </body>
            </html>
            HTML;
    }

    /**
     * @return list<string>
     */
    private function indexColumns(string $table, string $index): array
    {
        $definition = collect(Schema::getIndexes($table))->firstWhere('name', $index);

        $this->assertIsArray($definition, "Индекс {$index} отсутствует.");

        return array_values($definition['columns']);
    }
}
