<?php

namespace Tests\Feature;

use App\Enums\CatalogSearchIndexStatus;
use App\Models\CatalogSearchIndexState;
use App\Models\CatalogTitle;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogSearchIndexerTest extends TestCase
{
    use RefreshDatabase;

    public function test_indexer_bulk_upserts_changed_documents_and_is_idempotent(): void
    {
        $titles = CatalogTitle::factory()->count(3)->create();
        $indexer = app(CatalogSearchIndexer::class);

        $first = $indexer->indexTitleIds($titles->pluck('id'), 2);
        $timestamps = DB::table('catalog_title_search_documents')
            ->orderBy('catalog_title_id')
            ->pluck('updated_at', 'catalog_title_id')
            ->all();
        $second = $indexer->indexTitleIds($titles->pluck('id'), 2);

        $this->assertSame([
            'requested' => 3,
            'indexed' => 3,
            'unchanged' => 0,
            'deleted' => 0,
        ], $first);
        $this->assertSame([
            'requested' => 3,
            'indexed' => 0,
            'unchanged' => 3,
            'deleted' => 0,
        ], $second);
        $this->assertSame($timestamps, DB::table('catalog_title_search_documents')
            ->orderBy('catalog_title_id')
            ->pluck('updated_at', 'catalog_title_id')
            ->all());
        $this->assertSame(3, $indexer->documentCount());
        $this->assertTrue($indexer->integrityCheck());
    }

    public function test_indexer_uses_bounded_eager_loaded_chunks_and_fts_triggers_are_visible(): void
    {
        $titles = CatalogTitle::factory()->count(9)->sequence(
            fn ($sequence): array => ['title' => 'Индексируемый '.$sequence->index],
        )->create();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = app(CatalogSearchIndexer::class)->indexTitleIds($titles->pluck('id'), 4);
        $queryCount = count(DB::getQueryLog());
        $matched = DB::select(
            "SELECT count(*) AS aggregate FROM catalog_title_search_fts WHERE catalog_title_search_fts MATCH 'индексируемый'",
        );

        $this->assertSame(9, $result['indexed']);
        $this->assertSame(9, (int) $matched[0]->aggregate);
        $this->assertLessThanOrEqual(45, $queryCount);
    }

    public function test_indexer_deletes_documents_for_titles_outside_public_visibility(): void
    {
        $title = CatalogTitle::factory()->create();
        $indexer = app(CatalogSearchIndexer::class);
        $indexer->indexTitleIds([$title->id]);

        $title->update(['is_published' => false]);
        $result = $indexer->indexTitleIds([$title->id]);

        $this->assertSame(1, $result['deleted']);
        $this->assertDatabaseMissing('catalog_title_search_documents', [
            'catalog_title_id' => $title->id,
        ]);
    }

    public function test_incremental_sync_failure_marks_index_stale_with_sanitized_error(): void
    {
        $title = CatalogTitle::factory()->create();
        CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID)->update([
            'status' => CatalogSearchIndexStatus::Ready,
        ]);
        DB::statement(<<<'SQL'
            CREATE TRIGGER force_catalog_search_failure
            BEFORE INSERT ON catalog_title_search_documents BEGIN
                SELECT RAISE(ABORT, 'https://private.example/token /tmp/private/search-index');
            END
            SQL);

        $synchronized = app(CatalogSearchIndexer::class)->synchronizeTitleIds([$title->id]);
        $state = CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID);

        $this->assertFalse($synchronized);
        $this->assertSame(CatalogSearchIndexStatus::Stale, $state->status);
        $this->assertNotNull($state->failed_at);
        $this->assertStringNotContainsString('private.example', (string) $state->last_error);
        $this->assertStringNotContainsString('/tmp/private', (string) $state->last_error);
    }
}
