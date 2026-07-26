<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogCollectionMode;
use App\Models\CatalogCollection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogSmartCollectionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_schema_stores_versioned_smart_rules_without_a_redundant_index(): void
    {
        $this->assertTrue(Schema::hasColumns('catalog_collections', [
            'mode',
            'smart_rules',
            'smart_rules_version',
        ]));

        $indexedColumns = collect(Schema::getIndexes('catalog_collections'))
            ->flatMap(fn (array $index): array => $index['columns'])
            ->all();

        $this->assertNotContains('smart_rules', $indexedColumns);
        $this->assertNotContains('smart_rules_version', $indexedColumns);
    }

    public function test_existing_collection_contract_defaults_to_manual_mode_and_typed_rule_casts(): void
    {
        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'owner_id' => User::factory()->create()->id,
            'name' => 'Ручная подборка',
            'slug' => 'manual-'.Str::lower(Str::random(8)),
        ])->refresh();

        $this->assertSame(CatalogCollectionMode::Manual, $collection->mode);
        $this->assertNull($collection->smart_rules);
        $this->assertSame(1, $collection->smart_rules_version);
        $this->assertFalse($collection->isSmart());
    }

    public function test_smart_rule_migration_is_reversible_and_preserves_existing_collection_rows(): void
    {
        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'owner_id' => User::factory()->create()->id,
            'name' => 'Сохраняемая подборка',
            'slug' => 'preserved-'.Str::lower(Str::random(8)),
        ]);
        $migration = require database_path(
            'migrations/2026_07_26_232000_add_smart_rules_to_catalog_collections.php',
        );

        $migration->down();

        try {
            $this->assertFalse(Schema::hasColumn('catalog_collections', 'mode'));
            $this->assertFalse(Schema::hasColumn('catalog_collections', 'smart_rules'));
            $this->assertFalse(Schema::hasColumn('catalog_collections', 'smart_rules_version'));
            $this->assertDatabaseHas('catalog_collections', ['id' => $collection->id]);
        } finally {
            $migration->up();
        }

        $this->assertTrue(Schema::hasColumns('catalog_collections', [
            'mode',
            'smart_rules',
            'smart_rules_version',
        ]));
        $this->assertDatabaseHas('catalog_collections', ['id' => $collection->id]);
    }
}
