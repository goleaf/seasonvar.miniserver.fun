<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\PublicationStatus;
use App\Models\ApiSyncChange;
use App\Models\CatalogTitle;
use App\Services\Api\V1\Sync\CatalogSyncChangePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class OfflineCatalogSyncPublishingTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_change_is_written_only_after_its_transaction_commits(): void
    {
        $title = CatalogTitle::factory()->create(['slug' => 'after-commit-title']);
        $publisher = app(CatalogSyncChangePublisher::class);

        DB::beginTransaction();
        $publisher->publishUpsert($title);

        $this->assertDatabaseCount('api_sync_changes', 0);

        DB::commit();

        $change = ApiSyncChange::query()->sole();
        $this->assertSame(ApiSyncChange::SCOPE_CATALOG, $change->scope);
        $this->assertNull($change->user_id);
        $this->assertSame('title', $change->resource_type);
        $this->assertSame('after-commit-title', $change->resource_key);
        $this->assertSame(ApiSyncChange::OPERATION_UPSERT, $change->operation);
    }

    public function test_catalog_change_is_discarded_when_its_transaction_rolls_back(): void
    {
        $title = CatalogTitle::factory()->create(['slug' => 'rolled-back-title']);

        try {
            DB::transaction(function () use ($title): void {
                app(CatalogSyncChangePublisher::class)->publishUpsert($title);

                throw new RuntimeException('Rollback the domain write.');
            });
        } catch (RuntimeException) {
            // The rollback is the behavior under test.
        }

        $this->assertDatabaseCount('api_sync_changes', 0);
    }

    public function test_hidden_title_publishes_a_delete_tombstone(): void
    {
        $title = CatalogTitle::factory()->create(['slug' => 'hidden-title']);
        $title->forceFill([
            'is_published' => false,
            'publication_status' => PublicationStatus::Hidden,
        ])->save();

        app(CatalogSyncChangePublisher::class)->publishUpsert($title, 'hidden-title');

        $change = ApiSyncChange::query()->sole();
        $this->assertSame('hidden-title', $change->resource_key);
        $this->assertSame(ApiSyncChange::OPERATION_DELETE, $change->operation);
    }

    public function test_slug_change_publishes_old_tombstone_before_current_upsert(): void
    {
        $title = CatalogTitle::factory()->create(['slug' => 'old-title-slug']);
        $title->forceFill(['slug' => 'new-title-slug'])->save();

        app(CatalogSyncChangePublisher::class)->publishUpsert($title, 'old-title-slug');

        $this->assertSame([
            ['old-title-slug', ApiSyncChange::OPERATION_DELETE],
            ['new-title-slug', ApiSyncChange::OPERATION_UPSERT],
        ], ApiSyncChange::query()
            ->orderBy('id')
            ->get()
            ->map(fn (ApiSyncChange $change): array => [$change->resource_key, $change->operation])
            ->all());
    }

    public function test_explicit_delete_publishes_only_a_valid_tombstone(): void
    {
        $publisher = app(CatalogSyncChangePublisher::class);

        $publisher->publishDelete('deleted-title');
        $publisher->publishDelete('');
        $publisher->publishDelete(str_repeat('x', ApiSyncChange::MAX_TITLE_SLUG_LENGTH + 1));

        $change = ApiSyncChange::query()->sole();
        $this->assertSame('deleted-title', $change->resource_key);
        $this->assertSame(ApiSyncChange::OPERATION_DELETE, $change->operation);
    }

    public function test_catalog_publisher_preserves_a_domain_maximum_length_slug(): void
    {
        $slug = str_repeat('a', 255);
        $title = CatalogTitle::factory()->create(['slug' => $slug]);

        app(CatalogSyncChangePublisher::class)->publishUpsert($title);

        $change = ApiSyncChange::query()->sole();
        $this->assertSame($slug, $change->resource_key);
        $this->assertSame(ApiSyncChange::OPERATION_UPSERT, $change->operation);
    }

    public function test_missing_sync_schema_never_breaks_the_completed_domain_write(): void
    {
        $title = CatalogTitle::factory()->create(['slug' => 'schema-not-ready']);
        Schema::drop('api_sync_changes');

        app(CatalogSyncChangePublisher::class)->publishUpsert($title);

        $this->assertSame('schema-not-ready', $title->fresh()?->slug);
        $this->assertFalse(Schema::hasTable('api_sync_changes'));
    }
}
