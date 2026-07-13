<?php

namespace Tests\Feature;

use App\Enums\CatalogSearchIndexStatus;
use App\Models\CatalogSearchIndexState;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleSearchDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CatalogSearchIndexSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_document_fts_and_singleton_state_schema_are_available(): void
    {
        $this->assertTrue(Schema::hasColumns('catalog_title_search_documents', [
            'catalog_title_id',
            'title',
            'original_title',
            'aliases',
            'transliteration',
            'people',
            'taxonomies',
            'description',
            'suggestion_names',
            'normalized_title_key',
            'normalized_original_title_key',
            'normalized_alias_keys',
            'fingerprint',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('catalog_search_index_states', [
            'id',
            'version',
            'status',
            'source_count',
            'document_count',
            'checkpoint_id',
            'build_started_at',
            'completed_at',
            'failed_at',
            'last_error',
            'created_at',
            'updated_at',
        ]));

        $ftsSql = (string) DB::table('sqlite_master')
            ->where('type', 'table')
            ->where('name', 'catalog_title_search_fts')
            ->value('sql');

        $this->assertStringContainsString('fts5', strtolower($ftsSql));
        $this->assertStringContainsString("content='catalog_title_search_documents'", $ftsSql);
        $this->assertStringContainsString("content_rowid='catalog_title_id'", $ftsSql);
        $this->assertStringContainsString('unicode61 remove_diacritics 2', $ftsSql);

        $state = CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID);

        $this->assertSame(CatalogSearchIndexStatus::Building, $state->status);
        $this->assertSame(1, $state->version);
        $this->assertSame(0, $state->source_count);
        $this->assertSame(0, $state->document_count);
        $this->assertSame(0, $state->checkpoint_id);
    }

    public function test_document_triggers_keep_external_content_fts_in_sync(): void
    {
        $title = CatalogTitle::factory()->create();
        $document = CatalogTitleSearchDocument::query()->create($this->documentAttributes(
            $title,
            'Первое название',
        ));

        $this->assertSame([$title->id], $this->ftsTitleIds('первое'));

        $document->update([
            'title' => 'Второе название',
            'fingerprint' => hash('sha256', 'updated'),
        ]);

        $this->assertSame([], $this->ftsTitleIds('первое'));
        $this->assertSame([$title->id], $this->ftsTitleIds('второе'));

        $document->delete();

        $this->assertSame([], $this->ftsTitleIds('второе'));
    }

    public function test_force_deleting_a_title_cascades_its_document_and_fts_row(): void
    {
        $title = CatalogTitle::factory()->create();
        CatalogTitleSearchDocument::query()->create($this->documentAttributes(
            $title,
            'Каскадное название',
        ));

        $this->assertTrue($title->searchDocument()->exists());

        $title->forceDelete();

        $this->assertDatabaseMissing('catalog_title_search_documents', [
            'catalog_title_id' => $title->id,
        ]);
        $this->assertSame([], $this->ftsTitleIds('каскадное'));
    }

    public function test_search_models_expose_typed_casts_and_relationships(): void
    {
        $title = CatalogTitle::factory()->create();
        $document = CatalogTitleSearchDocument::query()->create($this->documentAttributes(
            $title,
            'Связанное название',
        ));
        $state = CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID);

        $this->assertTrue($document->catalogTitle->is($title));
        $this->assertTrue($title->searchDocument?->is($document));
        $this->assertSame(CatalogSearchIndexStatus::Building, $state->status);
        $this->assertContains('fingerprint', $document->getFillable());
        $this->assertContains('checkpoint_id', $state->getFillable());
        $this->assertSame([
            'building',
            'ready',
            'stale',
            'failed',
        ], array_column(CatalogSearchIndexStatus::cases(), 'value'));
    }

    /** @return array<string, mixed> */
    private function documentAttributes(CatalogTitle $title, string $name): array
    {
        return [
            'catalog_title_id' => $title->id,
            'title' => $name,
            'original_title' => '',
            'aliases' => '',
            'transliteration' => '',
            'people' => '',
            'taxonomies' => '',
            'description' => '',
            'suggestion_names' => $name,
            'normalized_title_key' => mb_strtolower($name),
            'normalized_original_title_key' => '',
            'normalized_alias_keys' => '',
            'fingerprint' => hash('sha256', $name),
        ];
    }

    /** @return list<int> */
    private function ftsTitleIds(string $expression): array
    {
        return DB::select(
            'SELECT rowid FROM catalog_title_search_fts WHERE catalog_title_search_fts MATCH ? ORDER BY rowid',
            [$expression],
        ) === []
            ? []
            : array_map(
                static fn (object $row): int => (int) $row->rowid,
                DB::select(
                    'SELECT rowid FROM catalog_title_search_fts WHERE catalog_title_search_fts MATCH ? ORDER BY rowid',
                    [$expression],
                ),
            );
    }
}
