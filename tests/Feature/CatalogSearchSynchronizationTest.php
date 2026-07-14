<?php

namespace Tests\Feature;

use App\Enums\PublicationStatus;
use App\Models\ApiSyncChange;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleAlias;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\SourcePage;
use App\Models\User;
use App\Services\Catalog\CatalogAdministrationService;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use App\Services\Seasonvar\SeasonvarCatalogImporter;
use App\Services\Seasonvar\SeasonvarCatalogParser;
use App\Services\Seasonvar\SeasonvarSource;
use App\Services\Seasonvar\SeasonvarTitleMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class CatalogSearchSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unchanged_import_fast_path_does_not_reindex_the_title(): void
    {
        config(['seasonvar.crawl_delay_seconds' => 0]);
        $body = '<html><head><title>Неизменённая страница</title></head></html>';
        $url = 'https://seasonvar.ru/serial-59001-Unchanged-1-season.html';
        $source = app(SeasonvarSource::class)->current();
        $page = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'content_hash' => hash('sha256', $body),
            'parse_status' => 'parsed',
            'metadata_parser_version' => SeasonvarCatalogParser::METADATA_VERSION,
            'metadata_attempted_version' => SeasonvarCatalogParser::METADATA_VERSION,
        ]);
        $title = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $page->id,
            'source_url' => $url,
            'source_url_hash' => hash('sha256', $url),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        Http::fake([$url => Http::response($body)]);
        $indexer = Mockery::mock(CatalogSearchIndexer::class);
        $indexer->shouldNotReceive('synchronizeTitleIds');
        $this->app->instance(CatalogSearchIndexer::class, $indexer);

        $result = app(SeasonvarCatalogImporter::class)->parsePage($page);

        $this->assertTrue($result['catalog_title']?->is($title));
        $this->assertDatabaseCount('api_sync_changes', 0);
        Http::assertSentCount(1);
    }

    public function test_catalog_administration_reindexes_title_without_adding_relation_names(): void
    {
        config(['seasonvar.admin_emails' => ['admin@example.com']]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $title = CatalogTitle::factory()->create(['title' => 'Старое редакторское имя']);
        $genre = Genre::query()->create(['name' => 'Редакторский жанр', 'slug' => 'redaktorskii-zhanr']);
        $indexer = app(CatalogSearchIndexer::class);
        $indexer->indexTitleIds([$title->id]);
        $service = app(CatalogAdministrationService::class);
        $title->refresh();

        $updated = $service->updateTitle($admin, $title, [
            'external_id' => $title->external_id,
            'slug' => $title->slug,
            'title' => 'Новое редакторское имя',
            'original_title' => $title->original_title,
            'type' => $title->type,
            'year' => $title->year,
            'description' => $title->description,
            'poster_url' => $title->poster_url,
            'publication_status' => PublicationStatus::Published->value,
            'audience' => $title->audience->value,
            'available_from' => $title->available_from,
            'available_until' => $title->available_until,
        ], $service->titleVersion($title));
        $updated = $service->attachRelation(
            $admin,
            $updated,
            'genre',
            $genre->id,
            $service->titleVersion($updated),
        );

        $document = $updated->searchDocument()->firstOrFail();

        $this->assertSame('Новое редакторское имя', $document->title);
        $this->assertSame('', $document->taxonomies);
        $this->assertSame(
            [ApiSyncChange::OPERATION_UPSERT, ApiSyncChange::OPERATION_UPSERT],
            ApiSyncChange::query()->orderBy('id')->pluck('operation')->all(),
        );
    }

    public function test_title_merge_cascades_duplicate_document_and_reindexes_canonical_aliases(): void
    {
        $source = app(SeasonvarSource::class)->current();
        $firstUrl = 'https://seasonvar.ru/serial-59002-Search-Merge-1-season.html';
        $secondUrl = 'https://seasonvar.ru/serial-59002-Search-Merge-2-season.html';
        $firstPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $firstUrl,
            'url_hash' => hash('sha256', $firstUrl),
        ]);
        $secondPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $secondUrl,
            'url_hash' => hash('sha256', $secondUrl),
        ]);
        $canonical = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $firstPage->id,
            'external_id' => null,
            'title' => 'Каноническое имя',
            'source_url' => $firstUrl,
            'source_url_hash' => hash('sha256', $firstUrl),
        ]);
        $duplicate = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $secondPage->id,
            'external_id' => null,
            'title' => 'Альтернативное имя дубля',
            'source_url' => $secondUrl,
            'source_url_hash' => hash('sha256', $secondUrl),
        ]);
        CatalogTitleAlias::query()->create([
            'catalog_title_id' => $duplicate->id,
            'name' => 'Альтернативное имя дубля',
            'name_hash' => hash('sha256', 'альтернативное имя дубля'),
            'type' => 'alternative',
            'source' => 'seasonvar',
        ]);
        app(CatalogSearchIndexer::class)->indexTitleIds([$canonical->id, $duplicate->id]);

        $result = app(SeasonvarTitleMerger::class)->merge();

        $this->assertSame(1, $result['titles']);
        $this->assertDatabaseMissing('catalog_title_search_documents', [
            'catalog_title_id' => $duplicate->id,
        ]);
        $this->assertStringContainsString(
            'Альтернативное имя дубля',
            (string) $canonical->fresh()->searchDocument?->aliases,
        );
        $this->assertSame([
            [$canonical->slug, ApiSyncChange::OPERATION_UPSERT],
            [$duplicate->slug, ApiSyncChange::OPERATION_DELETE],
        ], ApiSyncChange::query()
            ->orderBy('id')
            ->get()
            ->map(fn (ApiSyncChange $change): array => [$change->resource_key, $change->operation])
            ->all());
    }
}
