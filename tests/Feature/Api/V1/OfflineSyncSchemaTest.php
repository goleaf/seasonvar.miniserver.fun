<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\ApiSyncChange;
use App\Models\ApiSyncMutation;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class OfflineSyncSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_schema_contains_the_required_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('api_sync_changes', [
            'id',
            'scope',
            'user_id',
            'resource_type',
            'resource_key',
            'operation',
            'changed_at',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('api_sync_mutations', [
            'id',
            'user_id',
            'mutation_id',
            'payload_hash',
            'status',
            'result',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('catalog_title_user_states', [
            'watchlist_version',
            'rating_version',
        ]));

        $changeIndexes = $this->sqliteIndexNames('api_sync_changes');
        $mutationIndexes = $this->sqliteIndexNames('api_sync_mutations');

        $this->assertContains('api_sync_changes_scope_cursor_idx', $changeIndexes);
        $this->assertContains('api_sync_changes_user_cursor_idx', $changeIndexes);
        $this->assertContains('api_sync_changes_retention_idx', $changeIndexes);
        $this->assertContains('api_sync_mutations_user_mutation_unique', $mutationIndexes);
        $this->assertContains('api_sync_mutations_retention_idx', $mutationIndexes);
    }

    public function test_mutation_ids_are_unique_per_owner(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $mutationId = 'efdd8c41-a56e-4a4e-bbb0-9497ed1f5a18';

        ApiSyncMutation::query()->create($this->mutationAttributes($firstUser, $mutationId));
        ApiSyncMutation::query()->create($this->mutationAttributes($secondUser, $mutationId));

        $this->expectException(QueryException::class);

        ApiSyncMutation::query()->create($this->mutationAttributes($firstUser, $mutationId));
    }

    public function test_user_owned_sync_rows_are_deleted_with_the_user(): void
    {
        $user = User::factory()->create();

        ApiSyncChange::query()->create([
            'scope' => ApiSyncChange::SCOPE_USER,
            'user_id' => $user->id,
            'resource_type' => 'title_state',
            'resource_key' => 'example-title',
            'operation' => ApiSyncChange::OPERATION_UPSERT,
            'changed_at' => now(),
        ]);
        ApiSyncMutation::query()->create($this->mutationAttributes(
            $user,
            '7e92e54c-fb3a-477a-990a-33855de2abc7',
        ));

        $user->delete();

        $this->assertDatabaseCount('api_sync_changes', 0);
        $this->assertDatabaseCount('api_sync_mutations', 0);
    }

    public function test_state_versions_default_to_zero_and_models_cast_sync_values(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create();
        $state = CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'in_watchlist' => false,
            'rating' => null,
        ])->refresh();

        $this->assertSame(0, $state->watchlist_version);
        $this->assertSame(0, $state->rating_version);

        $change = ApiSyncChange::query()->create([
            'scope' => ApiSyncChange::SCOPE_CATALOG,
            'resource_type' => 'title',
            'resource_key' => $title->slug,
            'operation' => ApiSyncChange::OPERATION_DELETE,
            'changed_at' => now()->startOfSecond(),
        ]);
        $mutation = ApiSyncMutation::query()->create($this->mutationAttributes(
            $user,
            '154b0fc1-55a4-46f3-bc36-21aec0db01dd',
        ));

        $this->assertInstanceOf(\DateTimeInterface::class, $change->changed_at);
        $this->assertSame(['version' => 1], $mutation->result);
        $this->assertTrue($mutation->user->is($user));
    }

    public function test_sync_migration_is_reversible_and_can_be_applied_again(): void
    {
        $migration = require database_path('migrations/2026_07_14_164423_create_api_sync_tables_and_add_state_versions.php');

        $migration->down();

        try {
            $this->assertFalse(Schema::hasTable('api_sync_changes'));
            $this->assertFalse(Schema::hasTable('api_sync_mutations'));
            $this->assertFalse(Schema::hasColumn('catalog_title_user_states', 'watchlist_version'));
            $this->assertFalse(Schema::hasColumn('catalog_title_user_states', 'rating_version'));
        } finally {
            $migration->up();
        }

        $this->assertTrue(Schema::hasTable('api_sync_changes'));
        $this->assertTrue(Schema::hasTable('api_sync_mutations'));
        $this->assertTrue(Schema::hasColumns('catalog_title_user_states', [
            'watchlist_version',
            'rating_version',
        ]));
    }

    /** @return list<string> */
    private function sqliteIndexNames(string $table): array
    {
        return collect(DB::select("PRAGMA index_list('{$table}')"))
            ->pluck('name')
            ->filter(fn (mixed $name): bool => is_string($name))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function mutationAttributes(User $user, string $mutationId): array
    {
        return [
            'user_id' => $user->id,
            'mutation_id' => $mutationId,
            'payload_hash' => hash('sha256', $mutationId),
            'status' => 'applied',
            'result' => ['version' => 1],
        ];
    }
}
