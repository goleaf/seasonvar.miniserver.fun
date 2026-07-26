<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogCollection;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogCollectionQualityIssue;
use App\Models\CatalogCollectionQualityRun;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CatalogCollectionQualitySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_quality_schema_is_additive_indexed_and_version_aware(): void
    {
        $this->assertTrue(Schema::hasColumns('catalog_collections', [
            'quality_score',
            'quality_content_version',
            'quality_evaluated_at',
            'content_signature',
            'normalized_text_hash',
            'quality_details',
            'editorially_verified_at',
            'editorially_verified_by_id',
            'editorially_verified_content_version',
        ]));
        $this->assertTrue(Schema::hasColumns('catalog_collection_items', [
            'theme_match_percent',
            'inclusion_reason_code',
            'quality_content_version',
        ]));
        $this->assertTrue(Schema::hasTable('catalog_collection_quality_issues'));
        $this->assertTrue(Schema::hasTable('catalog_collection_quality_runs'));

        $this->assertIndex(
            'catalog_collections',
            'catalog_collections_content_signature_idx',
            ['content_signature', 'id'],
        );
        $this->assertIndex(
            'catalog_collection_quality_issues',
            'catalog_collection_quality_issues_fingerprint_unique',
            ['fingerprint'],
            true,
        );
        $this->assertIndex(
            'catalog_collection_quality_issues',
            'catalog_collection_quality_issues_queue_idx',
            ['status', 'severity', 'created_at', 'id'],
        );
        $this->assertForeignKey(
            'catalog_collection_quality_issues',
            ['catalog_collection_id'],
            'catalog_collections',
            'cascade',
        );
        $this->assertForeignKey(
            'catalog_collections',
            ['editorially_verified_by_id'],
            'users',
            'set null',
        );
    }

    public function test_quality_models_expose_typed_casts_and_relationships(): void
    {
        $collection = (new CatalogCollection)->forceFill([
            'quality_score' => '74',
            'quality_content_version' => '3',
            'quality_details' => ['components' => ['theme' => 20]],
            'editorially_verified_content_version' => '3',
        ]);
        $item = (new CatalogCollectionItem)->forceFill([
            'theme_match_percent' => '83',
            'quality_content_version' => '3',
        ]);

        $this->assertSame(74, $collection->quality_score);
        $this->assertSame(3, $collection->quality_content_version);
        $this->assertSame(['components' => ['theme' => 20]], $collection->quality_details);
        $this->assertSame(83, $item->theme_match_percent);
        $this->assertInstanceOf(HasMany::class, $collection->qualityIssues());
        $this->assertInstanceOf(BelongsTo::class, $collection->editoriallyVerifiedBy());
        $this->assertInstanceOf(BelongsTo::class, (new CatalogCollectionQualityIssue)->collection());
        $this->assertInstanceOf(BelongsTo::class, (new CatalogCollectionQualityRun)->startedBy());
    }

    public function test_derived_quality_evidence_is_not_mass_assignable(): void
    {
        $collection = new CatalogCollection;
        $item = new CatalogCollectionItem;

        $this->assertTrue($collection->isFillable('name'));
        $this->assertFalse($collection->isFillable('quality_score'));
        $this->assertFalse($collection->isFillable('content_signature'));
        $this->assertFalse($collection->isFillable(
            'editorially_verified_content_version',
        ));
        $this->assertTrue($item->isFillable('position'));
        $this->assertFalse($item->isFillable('theme_match_percent'));
        $this->assertFalse($item->isFillable('inclusion_reason_code'));
    }

    public function test_quality_migration_can_be_rolled_back_and_reapplied(): void
    {
        $migration = require database_path(
            'migrations/2026_07_26_095636_create_catalog_collection_quality_system.php',
        );

        try {
            $migration->down();

            $this->assertFalse(Schema::hasTable('catalog_collection_quality_issues'));
            $this->assertFalse(Schema::hasTable('catalog_collection_quality_runs'));
            $this->assertFalse(Schema::hasColumn('catalog_collections', 'quality_score'));
            $this->assertFalse(Schema::hasColumn(
                'catalog_collection_items',
                'theme_match_percent',
            ));
            $this->assertSame(
                0,
                CatalogCollection::query()->publiclyListed()->count(),
                'Публичный read path должен переживать rolling deploy до quality migration.',
            );
        } finally {
            $migration->up();
        }

        $this->assertTrue(Schema::hasTable('catalog_collection_quality_issues'));
        $this->assertTrue(Schema::hasColumn('catalog_collections', 'quality_score'));
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertIndex(
        string $table,
        string $name,
        array $columns,
        bool $unique = false,
    ): void {
        $index = collect(Schema::getIndexes($table))->firstWhere('name', $name);

        $this->assertIsArray($index, "Индекс {$name} отсутствует.");
        $this->assertSame($columns, $index['columns']);
        $this->assertSame($unique, $index['unique']);
    }

    /** @param list<string> $columns */
    private function assertForeignKey(
        string $table,
        array $columns,
        string $foreignTable,
        string $onDelete,
    ): void {
        $foreignKey = collect(Schema::getForeignKeys($table))
            ->first(fn (array $key): bool => $key['columns'] === $columns);

        $this->assertIsArray($foreignKey, "Foreign key {$table} отсутствует.");
        $this->assertSame($foreignTable, $foreignKey['foreign_table']);
        $this->assertSame($onDelete, $foreignKey['on_delete']);
    }
}
