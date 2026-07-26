<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CatalogRecommendationPreferenceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_smart_feedback_schema_has_private_owner_scoped_rows_and_query_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('catalog_recommendation_feedback_details', [
            'id',
            'user_id',
            'catalog_title_id',
            'reason',
            'genre_id',
            'country_id',
            'actor_id',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('catalog_recommendation_preferences', [
            'user_id',
            'diversity',
            'freshness',
            'profile_reset_at',
            'version',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('catalog_recommendation_hidden_genres', [
            'id',
            'user_id',
            'genre_id',
            'hidden_until',
            'created_at',
            'updated_at',
        ]));

        $this->assertIndexColumns(
            'catalog_recommendation_feedback_details',
            'catalog_recommendation_feedback_detail_user_title_unique',
            ['user_id', 'catalog_title_id'],
            true,
        );
        $this->assertIndexColumns(
            'catalog_recommendation_feedback_details',
            'catalog_recommendation_feedback_detail_user_activity_idx',
            ['user_id', 'updated_at', 'id'],
        );
        $this->assertIndexColumns(
            'catalog_recommendation_hidden_genres',
            'catalog_recommendation_hidden_genre_user_genre_unique',
            ['user_id', 'genre_id'],
            true,
        );
        $this->assertIndexColumns(
            'catalog_recommendation_hidden_genres',
            'catalog_recommendation_hidden_genre_user_expiry_idx',
            ['user_id', 'hidden_until', 'id'],
        );

        $this->assertForeignKey('catalog_recommendation_feedback_details', ['user_id'], 'users', 'cascade');
        $this->assertForeignKey('catalog_recommendation_feedback_details', ['catalog_title_id'], 'catalog_titles', 'cascade');
        $this->assertForeignKey('catalog_recommendation_feedback_details', ['genre_id'], 'genres', 'set null');
        $this->assertForeignKey('catalog_recommendation_feedback_details', ['country_id'], 'countries', 'set null');
        $this->assertForeignKey('catalog_recommendation_feedback_details', ['actor_id'], 'actors', 'set null');
        $this->assertForeignKey('catalog_recommendation_preferences', ['user_id'], 'users', 'cascade');
        $this->assertForeignKey('catalog_recommendation_hidden_genres', ['user_id'], 'users', 'cascade');
        $this->assertForeignKey('catalog_recommendation_hidden_genres', ['genre_id'], 'genres', 'cascade');
    }

    /** @param list<string> $columns */
    private function assertIndexColumns(string $table, string $name, array $columns, bool $unique = false): void
    {
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

        $this->assertIsArray($foreignKey, "Внешний ключ {$table} (".implode(', ', $columns).') отсутствует.');
        $this->assertSame($foreignTable, $foreignKey['foreign_table']);
        $this->assertSame($onDelete, mb_strtolower((string) $foreignKey['on_delete']));
    }
}
