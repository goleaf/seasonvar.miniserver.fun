<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\DTOs\ApiSyncCursor;
use App\Exceptions\ApiSyncCursorException;
use App\Models\ApiSyncChange;
use App\Models\User;
use App\Services\Api\V1\Sync\ApiSyncPullQuery;
use App\Services\Api\V1\Sync\ApiSyncReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OfflineSyncPullQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_detects_the_installed_sync_schema(): void
    {
        $this->assertTrue(app(ApiSyncReadiness::class)->available());
    }

    public function test_checkpoint_uses_the_current_maximum_for_the_exact_scope(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $catalog = $this->change(ApiSyncChange::SCOPE_CATALOG, null, 'catalog-one');
        $this->change(ApiSyncChange::SCOPE_USER, $firstUser, 'first-user-one');
        $firstUserLatest = $this->change(ApiSyncChange::SCOPE_USER, $firstUser, 'first-user-two');
        $this->change(ApiSyncChange::SCOPE_USER, $secondUser, 'second-user-one');

        $query = app(ApiSyncPullQuery::class);
        $catalogCheckpoint = $query->checkpoint(ApiSyncChange::SCOPE_CATALOG, null);
        $ownerCheckpoint = $query->checkpoint(ApiSyncChange::SCOPE_USER, $firstUser);

        $this->assertSame($catalog->id, $catalogCheckpoint->changeId);
        $this->assertNull($catalogCheckpoint->ownerId);
        $this->assertSame($firstUserLatest->id, $ownerCheckpoint->changeId);
        $this->assertSame($firstUser->id, $ownerCheckpoint->ownerId);
    }

    public function test_catalog_pull_is_ordered_bounded_and_advances_to_the_last_returned_change(): void
    {
        $first = $this->change(ApiSyncChange::SCOPE_CATALOG, null, 'first');
        $second = $this->change(ApiSyncChange::SCOPE_CATALOG, null, 'second');
        $third = $this->change(ApiSyncChange::SCOPE_CATALOG, null, 'third');

        $result = app(ApiSyncPullQuery::class)->pull(
            new ApiSyncCursor(ApiSyncChange::SCOPE_CATALOG, null, 0),
            2,
        );

        $this->assertSame([$first->id, $second->id], $result['changes']->pluck('id')->all());
        $this->assertTrue($result['has_more']);
        $this->assertSame($second->id, $result['cursor']->changeId);
        $this->assertSame(
            ['id', 'resource_type', 'resource_key', 'operation', 'changed_at'],
            array_keys($result['changes']->firstOrFail()->getAttributes()),
        );

        $remainder = app(ApiSyncPullQuery::class)->pull($result['cursor'], 200);

        $this->assertSame([$third->id], $remainder['changes']->pluck('id')->all());
        $this->assertFalse($remainder['has_more']);
        $this->assertSame($third->id, $remainder['cursor']->changeId);
    }

    public function test_user_pull_never_returns_another_owners_changes(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownerFirst = $this->change(ApiSyncChange::SCOPE_USER, $owner, 'owner-first');
        $this->change(ApiSyncChange::SCOPE_USER, $other, 'other');
        $ownerSecond = $this->change(ApiSyncChange::SCOPE_USER, $owner, 'owner-second');
        $this->change(ApiSyncChange::SCOPE_CATALOG, null, 'catalog');

        $result = app(ApiSyncPullQuery::class)->pull(
            new ApiSyncCursor(ApiSyncChange::SCOPE_USER, $owner->id, 0),
            200,
        );

        $this->assertSame([$ownerFirst->id, $ownerSecond->id], $result['changes']->pluck('id')->all());
        $this->assertSame($ownerSecond->id, $result['cursor']->changeId);
    }

    public function test_empty_pull_preserves_the_supplied_cursor(): void
    {
        $cursor = new ApiSyncCursor(ApiSyncChange::SCOPE_CATALOG, null, 75);

        $result = app(ApiSyncPullQuery::class)->pull($cursor, 20);

        $this->assertTrue($result['changes']->isEmpty());
        $this->assertFalse($result['has_more']);
        $this->assertSame(75, $result['cursor']->changeId);
    }

    public function test_positive_cursor_older_than_the_oldest_retained_change_is_expired(): void
    {
        $removed = $this->change(ApiSyncChange::SCOPE_CATALOG, null, 'removed');
        $this->change(ApiSyncChange::SCOPE_USER, User::factory()->create(), 'unrelated');
        $this->change(ApiSyncChange::SCOPE_CATALOG, null, 'retained');
        $removed->delete();

        try {
            app(ApiSyncPullQuery::class)->pull(
                new ApiSyncCursor(ApiSyncChange::SCOPE_CATALOG, null, $removed->id),
                20,
            );
            $this->fail('Expired cursor was accepted.');
        } catch (ApiSyncCursorException $exception) {
            $this->assertSame(ApiSyncCursorException::EXPIRED, $exception->reason);
        }
    }

    private function change(string $scope, ?User $user, string $key): ApiSyncChange
    {
        return ApiSyncChange::query()->create([
            'scope' => $scope,
            'user_id' => $user?->id,
            'resource_type' => $scope === ApiSyncChange::SCOPE_CATALOG ? 'title' : 'title_state',
            'resource_key' => $key,
            'operation' => ApiSyncChange::OPERATION_UPSERT,
            'changed_at' => now(),
        ]);
    }
}
