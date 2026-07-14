<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\DTOs\ApiSyncCursor;
use App\Models\ApiSyncChange;
use App\Models\User;
use App\Services\Api\V1\Sync\ApiSyncCursorCodec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class OfflineCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_is_guest_accessible_private_and_contains_a_bootstrap_checkpoint(): void
    {
        $change = $this->change('manifest-title', ApiSyncChange::OPERATION_UPSERT);

        $response = $this->getJson('/api/v1/sync/manifest')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeaderMissing('ETag')
            ->assertHeaderMissing('Last-Modified')
            ->assertJsonPath('data.sync_version', 1)
            ->assertJsonPath('data.retention_days', 30)
            ->assertJsonPath('data.max_pull_items', 200)
            ->assertJsonPath('data.max_push_items', 50)
            ->assertJsonPath('data.links.filters', url('/api/v1/catalog/filters'))
            ->assertJsonPath('data.links.directories', url('/api/v1/catalog/directories'))
            ->assertJsonPath('data.links.titles', url('/api/v1/titles'))
            ->assertJsonPath('data.links.changes', url('/api/v1/sync/changes'))
            ->assertJsonPath('data.links.openapi', url('/api/openapi.json'));

        $cursor = app(ApiSyncCursorCodec::class)->decode(
            (string) $response->json('data.cursor'),
            ApiSyncChange::SCOPE_CATALOG,
            null,
        );

        $this->assertSame($change->id, $cursor->changeId);
        $this->assertIsString($response->json('data.bootstrap'));

        foreach (['importer', 'queue', 'database', 'source_url', 'media_url', 'bm25'] as $privateField) {
            $response->assertDontSee($privateField, false);
        }
    }

    public function test_changes_without_cursor_return_only_the_current_checkpoint(): void
    {
        $latest = $this->change('existing-title', ApiSyncChange::OPERATION_UPSERT);

        $response = $this->getJson('/api/v1/sync/changes')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeaderMissing('ETag')
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('meta.limit', 100)
            ->assertJsonCount(2);

        $decoded = app(ApiSyncCursorCodec::class)->decode(
            (string) $response->json('meta.cursor'),
            ApiSyncChange::SCOPE_CATALOG,
            null,
        );

        $this->assertSame($latest->id, $decoded->changeId);
    }

    public function test_changes_return_bounded_upserts_and_deletes_with_safe_links(): void
    {
        $first = $this->change('new-title', ApiSyncChange::OPERATION_UPSERT);
        $second = $this->change('removed-title', ApiSyncChange::OPERATION_DELETE);
        $this->change('later-title', ApiSyncChange::OPERATION_UPSERT);
        $cursor = app(ApiSyncCursorCodec::class)->encode(
            new ApiSyncCursor(ApiSyncChange::SCOPE_CATALOG, null, 0),
        );

        $response = $this->getJson('/api/v1/sync/changes?'.http_build_query([
            'cursor' => $cursor,
            'limit' => 2,
        ]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'title')
            ->assertJsonPath('data.0.key', 'new-title')
            ->assertJsonPath('data.0.operation', 'upsert')
            ->assertJsonPath('data.0.links.self', url('/api/v1/titles/new-title'))
            ->assertJsonPath('data.1.key', 'removed-title')
            ->assertJsonPath('data.1.operation', 'delete')
            ->assertJsonPath('data.1.links.self', null)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.limit', 2);

        $next = app(ApiSyncCursorCodec::class)->decode(
            (string) $response->json('meta.cursor'),
            ApiSyncChange::SCOPE_CATALOG,
            null,
        );

        $this->assertSame($second->id, $next->changeId);
        $this->assertNotSame($first->id, $next->changeId);

        foreach (['source', 'media', 'description', 'search', 'bm25', 'user_id'] as $privateField) {
            $response->assertDontSee($privateField, false);
        }
    }

    public function test_invalid_limit_and_tampered_or_wrong_scope_cursors_are_rejected(): void
    {
        $valid = app(ApiSyncCursorCodec::class)->encode(
            new ApiSyncCursor(ApiSyncChange::SCOPE_CATALOG, null, 0),
        );
        $wrongScope = app(ApiSyncCursorCodec::class)->encode(
            new ApiSyncCursor(ApiSyncChange::SCOPE_USER, User::factory()->create()->id, 0),
        );

        $this->getJson('/api/v1/sync/changes?limit=201')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('limit');
        $this->getJson('/api/v1/sync/changes?cursor='.urlencode(substr($valid, 0, -1).'x'))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('cursor');
        $this->getJson('/api/v1/sync/changes?cursor='.urlencode($wrongScope))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('cursor');
    }

    public function test_cursor_older_than_the_retained_catalog_window_requires_bootstrap(): void
    {
        $removed = $this->change('removed-from-journal', ApiSyncChange::OPERATION_UPSERT);
        $this->change('still-retained', ApiSyncChange::OPERATION_UPSERT);
        $cursor = app(ApiSyncCursorCodec::class)->encode(
            new ApiSyncCursor(ApiSyncChange::SCOPE_CATALOG, null, $removed->id),
        );
        $removed->delete();

        $this->getJson('/api/v1/sync/changes?cursor='.urlencode($cursor))
            ->assertStatus(410)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('code', 'sync_cursor_expired')
            ->assertJsonPath('message', 'Курсор синхронизации устарел. Выполните полную загрузку заново.');
    }

    public function test_discovery_advertises_offline_sync(): void
    {
        $this->getJson('/api')
            ->assertOk()
            ->assertJsonFragment(['offline_sync']);
    }

    public function test_sync_endpoints_are_unavailable_before_the_additive_schema_is_ready(): void
    {
        Schema::drop('api_sync_changes');

        foreach (['/api/v1/sync/manifest', '/api/v1/sync/changes'] as $endpoint) {
            $this->getJson($endpoint)
                ->assertStatus(503)
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('code', 'sync_unavailable')
                ->assertJsonPath('message', 'Синхронизация временно недоступна.');
        }
    }

    private function change(string $key, string $operation): ApiSyncChange
    {
        return ApiSyncChange::query()->create([
            'scope' => ApiSyncChange::SCOPE_CATALOG,
            'resource_type' => 'title',
            'resource_key' => $key,
            'operation' => $operation,
            'changed_at' => now()->startOfSecond(),
        ]);
    }
}
