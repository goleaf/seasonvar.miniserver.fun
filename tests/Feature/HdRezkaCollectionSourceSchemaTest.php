<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionSourceMatchStatus;
use App\Enums\CatalogCollectionSyncStatus;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionSource;
use App\Models\CatalogCollectionSourceItem;
use App\Models\CatalogCollectionSyncRun;
use App\Models\CatalogTitle;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class HdRezkaCollectionSourceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_source_schema_has_identity_reconciliation_and_match_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('catalog_collection_sources', [
            'provider',
            'source_key',
            'catalog_collection_id',
            'source_path',
            'remote_name',
            'cover_source_path',
            'cover_path',
            'cover_content_hash',
            'semantic_content_hash',
            'last_seen_run_id',
            'last_successful_sync_at',
            'missing_since_at',
        ]));
        $this->assertTrue(Schema::hasColumns('catalog_collection_source_items', [
            'catalog_collection_source_id',
            'source_item_key',
            'source_title',
            'normalized_title_key',
            'normalized_title_hash',
            'source_year',
            'source_type',
            'countries',
            'detail_path',
            'source_page',
            'source_position',
            'match_status',
            'catalog_title_id',
            'match_method',
            'match_confidence',
            'match_reasons',
            'last_seen_run_id',
        ]));
        $this->assertTrue(Schema::hasColumns('catalog_collection_sync_runs', [
            'provider',
            'status',
            'counters',
            'error_summary',
            'started_at',
            'completed_at',
        ]));

        $this->assertIndexColumns('catalog_collection_sources', 'catalog_collection_sources_provider_source_key_unique', [
            'provider',
            'source_key',
        ], true);
        $this->assertIndexColumns('catalog_collection_source_items', 'catalog_collection_source_items_source_identity_unique', [
            'catalog_collection_source_id',
            'source_item_key',
        ], true);
        $this->assertIndexColumns('catalog_collection_source_items', 'catalog_collection_source_items_reconcile_idx', [
            'catalog_collection_source_id',
            'last_seen_run_id',
            'source_position',
            'id',
        ]);
        $this->assertIndexColumns('catalog_collection_source_items', 'catalog_collection_source_items_match_retry_idx', [
            'match_status',
            'updated_at',
            'id',
        ]);
        $this->assertIndexColumns('catalog_collection_source_items', 'catalog_collection_source_items_title_fanout_idx', [
            'catalog_title_id',
            'catalog_collection_source_id',
        ]);
        $this->assertIndexColumns('catalog_collection_sources', 'catalog_collection_sources_provider_missing_idx', [
            'provider',
            'missing_since_at',
            'id',
        ]);
        $this->assertIndexColumns('catalog_title_search_documents', 'catalog_search_docs_title_key_idx', [
            'normalized_title_key',
            'catalog_title_id',
        ]);
        $this->assertIndexColumns('catalog_title_search_documents', 'catalog_search_docs_original_key_idx', [
            'normalized_original_title_key',
            'catalog_title_id',
        ]);
    }

    public function test_source_models_expose_typed_casts_and_relationship_contracts(): void
    {
        $run = new CatalogCollectionSyncRun([
            'status' => CatalogCollectionSyncStatus::Running,
            'counters' => ['collections' => 1],
        ]);
        $source = new CatalogCollectionSource;
        $source->missing_since_at = now();
        $item = new CatalogCollectionSourceItem([
            'match_status' => CatalogCollectionSourceMatchStatus::Matched,
            'countries' => ['США'],
            'match_reasons' => ['exact_title'],
        ]);

        $this->assertSame(CatalogCollectionSyncStatus::Running, $run->status);
        $this->assertSame(['collections' => 1], $run->counters);
        $this->assertSame(CatalogCollectionSourceMatchStatus::Matched, $item->match_status);
        $this->assertSame(['США'], $item->countries);
        $this->assertSame(['exact_title'], $item->match_reasons);
        $this->assertNotNull($source->missing_since_at);

        $this->assertInstanceOf(BelongsTo::class, $source->collection());
        $this->assertInstanceOf(HasMany::class, $source->items());
        $this->assertInstanceOf(HasMany::class, $source->runs());
        $this->assertInstanceOf(BelongsTo::class, $item->catalogTitle());
        $this->assertInstanceOf(BelongsTo::class, $item->source());
        $this->assertInstanceOf(BelongsTo::class, $item->lastSeenRun());
        $this->assertInstanceOf(HasMany::class, $run->sources());
        $this->assertInstanceOf(HasMany::class, $run->sourceItems());
        $this->assertInstanceOf(HasOne::class, (new CatalogCollection)->sourceRecord());
        $this->assertInstanceOf(HasMany::class, (new CatalogTitle)->collectionSourceItems());
    }

    public function test_sync_and_match_status_values_are_stable(): void
    {
        $this->assertSame(
            ['running', 'completed', 'partial', 'failed'],
            array_column(CatalogCollectionSyncStatus::cases(), 'value'),
        );
        $this->assertSame(
            ['matched', 'ambiguous', 'unmatched'],
            array_column(CatalogCollectionSourceMatchStatus::cases(), 'value'),
        );
    }

    /** @param list<string> $columns */
    private function assertIndexColumns(string $table, string $name, array $columns, bool $unique = false): void
    {
        $index = collect(Schema::getIndexes($table))->firstWhere('name', $name);

        $this->assertIsArray($index, "Индекс {$name} отсутствует.");
        $this->assertSame($columns, $index['columns']);
        $this->assertSame($unique, $index['unique']);
    }
}
