<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CatalogTasteOnboardingSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_schema_is_owner_scoped_and_indexed_for_recommendation_reads(): void
    {
        $this->assertTrue(Schema::hasColumns('catalog_recommendation_preferences', [
            'onboarding_completed_at',
            'playback_preference',
            'completion_preference',
            'episode_length_preference',
        ]));
        $this->assertTrue(Schema::hasColumns('catalog_recommendation_onboarding_titles', [
            'id',
            'user_id',
            'catalog_title_id',
            'kind',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('catalog_recommendation_preferred_genres', [
            'id',
            'user_id',
            'genre_id',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('catalog_recommendation_preferred_countries', [
            'id',
            'user_id',
            'country_id',
            'created_at',
            'updated_at',
        ]));

        $this->assertIndexColumns(
            'catalog_recommendation_onboarding_titles',
            'recommendation_onboarding_title_user_title_unique',
            ['user_id', 'catalog_title_id'],
            true,
        );
        $this->assertIndexColumns(
            'catalog_recommendation_onboarding_titles',
            'recommendation_onboarding_title_user_kind_idx',
            ['user_id', 'kind', 'catalog_title_id'],
        );
        $this->assertIndexColumns(
            'catalog_recommendation_onboarding_titles',
            'recommendation_onboarding_title_merge_lookup_idx',
            ['catalog_title_id', 'id'],
        );
        $this->assertIndexColumns(
            'catalog_recommendation_preferred_genres',
            'recommendation_preferred_genre_user_genre_unique',
            ['user_id', 'genre_id'],
            true,
        );
        $this->assertIndexColumns(
            'catalog_recommendation_preferred_countries',
            'recommendation_preferred_country_user_country_unique',
            ['user_id', 'country_id'],
            true,
        );

        $this->assertForeignKey('catalog_recommendation_onboarding_titles', ['user_id'], 'users');
        $this->assertForeignKey('catalog_recommendation_onboarding_titles', ['catalog_title_id'], 'catalog_titles');
        $this->assertForeignKey('catalog_recommendation_preferred_genres', ['user_id'], 'users');
        $this->assertForeignKey('catalog_recommendation_preferred_genres', ['genre_id'], 'genres');
        $this->assertForeignKey('catalog_recommendation_preferred_countries', ['user_id'], 'users');
        $this->assertForeignKey('catalog_recommendation_preferred_countries', ['country_id'], 'countries');
    }

    public function test_sqlite_query_plan_uses_the_owner_lookup_indexes(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create();
        $genre = Genre::query()->create(['name' => 'Индексируемый жанр', 'slug' => 'onboarding-indexed-genre']);
        $country = Country::query()->create(['name' => 'Индексируемая страна', 'slug' => 'onboarding-indexed-country']);
        DB::table('catalog_recommendation_onboarding_titles')->insert([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'kind' => 'liked',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('catalog_recommendation_preferred_genres')->insert([
            'user_id' => $user->id,
            'genre_id' => $genre->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('catalog_recommendation_preferred_countries')->insert([
            'user_id' => $user->id,
            'country_id' => $country->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $plans = [
            DB::select(
                'EXPLAIN QUERY PLAN SELECT catalog_title_id FROM catalog_recommendation_onboarding_titles WHERE user_id = ? AND kind = ? ORDER BY catalog_title_id',
                [$user->id, 'liked'],
            ),
            DB::select(
                'EXPLAIN QUERY PLAN SELECT genre_id FROM catalog_recommendation_preferred_genres WHERE user_id = ? ORDER BY genre_id',
                [$user->id],
            ),
            DB::select(
                'EXPLAIN QUERY PLAN SELECT country_id FROM catalog_recommendation_preferred_countries WHERE user_id = ? ORDER BY country_id',
                [$user->id],
            ),
            DB::select(
                'EXPLAIN QUERY PLAN SELECT id FROM catalog_recommendation_onboarding_titles WHERE catalog_title_id = ? ORDER BY id',
                [$title->id],
            ),
        ];
        $details = collect($plans)
            ->flatten(1)
            ->map(static fn (object $row): string => (string) $row->detail)
            ->implode("\n");

        $this->assertStringContainsString('recommendation_onboarding_title_user_kind_idx', $details);
        $this->assertStringContainsString('recommendation_preferred_genre_user_genre_unique', $details);
        $this->assertStringContainsString('recommendation_preferred_country_user_country_unique', $details);
        $this->assertStringContainsString('recommendation_onboarding_title_merge_lookup_idx', $details);
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
    private function assertForeignKey(string $table, array $columns, string $foreignTable): void
    {
        $foreignKey = collect(Schema::getForeignKeys($table))
            ->first(fn (array $key): bool => $key['columns'] === $columns);

        $this->assertIsArray($foreignKey, "Внешний ключ {$table} (".implode(', ', $columns).') отсутствует.');
        $this->assertSame($foreignTable, $foreignKey['foreign_table']);
        $this->assertSame('cascade', mb_strtolower((string) $foreignKey['on_delete']));
    }
}
