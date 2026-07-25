<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Models\CatalogCollectionCategoryTranslation;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CatalogCollectionCategorySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_schema_has_stable_identity_hierarchy_translations_and_directory_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('catalog_collection_categories', [
            'id',
            'public_id',
            'parent_id',
            'slug',
            'position',
            'is_active',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('catalog_collection_category_translations', [
            'id',
            'catalog_collection_category_id',
            'locale',
            'name',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumn('catalog_collections', 'catalog_collection_category_id'));

        $this->assertIndexColumns(
            'catalog_collection_categories',
            'catalog_collection_categories_public_id_unique',
            ['public_id'],
            true,
        );
        $this->assertIndexColumns(
            'catalog_collection_categories',
            'catalog_collection_categories_slug_unique',
            ['slug'],
            true,
        );
        $this->assertIndexColumns(
            'catalog_collection_categories',
            'catalog_collection_categories_tree_idx',
            ['parent_id', 'is_active', 'position', 'id'],
        );
        $this->assertIndexColumns(
            'catalog_collection_category_translations',
            'catalog_collection_category_translations_identity_unique',
            ['catalog_collection_category_id', 'locale'],
            true,
        );
        $this->assertIndexColumns(
            'catalog_collections',
            'catalog_collections_category_public_order_idx',
            [
                'catalog_collection_category_id',
                'visibility',
                'moderation_status',
                'deleted_at',
                'updated_at',
                'id',
            ],
        );

        $this->assertForeignKey(
            'catalog_collection_categories',
            ['parent_id'],
            'catalog_collection_categories',
            'restrict',
        );
        $this->assertForeignKey(
            'catalog_collection_category_translations',
            ['catalog_collection_category_id'],
            'catalog_collection_categories',
            'cascade',
        );
        $this->assertForeignKey(
            'catalog_collections',
            ['catalog_collection_category_id'],
            'catalog_collection_categories',
            'restrict',
        );
    }

    public function test_category_models_expose_typed_casts_and_relationship_contracts(): void
    {
        $category = new CatalogCollectionCategory([
            'position' => '7',
            'is_active' => 1,
        ]);

        $this->assertSame(7, $category->position);
        $this->assertTrue($category->is_active);
        $this->assertInstanceOf(BelongsTo::class, $category->parent());
        $this->assertInstanceOf(HasMany::class, $category->children());
        $this->assertInstanceOf(HasMany::class, $category->translations());
        $this->assertInstanceOf(HasMany::class, $category->collections());
        $this->assertInstanceOf(BelongsTo::class, (new CatalogCollectionCategoryTranslation)->category());
        $this->assertInstanceOf(BelongsTo::class, (new CatalogCollection)->category());
    }

    /** @param list<string> $columns */
    private function assertIndexColumns(string $table, string $name, array $columns, bool $unique = false): void
    {
        $index = collect(Schema::getIndexes($table))->firstWhere('name', $name);

        $this->assertIsArray($index, "Индекс {$name} отсутствует.");
        $this->assertSame($columns, $index['columns']);
        $this->assertSame($unique, $index['unique']);
    }

    /**
     * @param  list<string>  $columns
     */
    private function assertForeignKey(
        string $table,
        array $columns,
        string $foreignTable,
        string $onDelete,
    ): void {
        $foreignKey = collect(Schema::getForeignKeys($table))
            ->first(fn (array $key): bool => $key['columns'] === $columns);

        $this->assertIsArray($foreignKey, "Внешний ключ {$table} (".implode(', ', $columns).') отсутствует.');
        $this->assertSame($foreignTable, $foreignKey['foreign_table']);
        $this->assertSame($onDelete, mb_strtolower((string) $foreignKey['on_delete']));
    }
}
