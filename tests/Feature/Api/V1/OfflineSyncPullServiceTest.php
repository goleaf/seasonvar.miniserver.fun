<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\DTOs\ApiSyncCursor;
use App\Exceptions\ApiSyncCursorException;
use App\Models\ApiSyncChange;
use App\Models\User;
use App\Services\Api\V1\Sync\ApiSyncCursorCodec;
use App\Services\Api\V1\Sync\ApiSyncPullService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OfflineSyncPullServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_cursor_returns_an_encoded_scope_checkpoint(): void
    {
        $change = $this->change(ApiSyncChange::SCOPE_CATALOG, null, 'catalog-title');

        $result = app(ApiSyncPullService::class)->pull(
            ApiSyncChange::SCOPE_CATALOG,
            null,
            null,
            100,
        );

        $cursor = app(ApiSyncCursorCodec::class)->decode(
            $result->cursor,
            ApiSyncChange::SCOPE_CATALOG,
            null,
        );

        $this->assertTrue($result->changes->isEmpty());
        $this->assertFalse($result->hasMore);
        $this->assertSame($change->id, $cursor->changeId);
    }

    public function test_owner_pull_is_bound_and_encodes_the_next_cursor(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $expected = $this->change(ApiSyncChange::SCOPE_USER, $owner, 'owner-title');
        $this->change(ApiSyncChange::SCOPE_USER, $other, 'other-title');
        $encoded = app(ApiSyncCursorCodec::class)->encode(
            new ApiSyncCursor(ApiSyncChange::SCOPE_USER, $owner->id, 0),
        );

        $result = app(ApiSyncPullService::class)->pull(
            ApiSyncChange::SCOPE_USER,
            $owner,
            $encoded,
            100,
        );

        $cursor = app(ApiSyncCursorCodec::class)->decode(
            $result->cursor,
            ApiSyncChange::SCOPE_USER,
            $owner->id,
        );

        $this->assertSame([$expected->id], $result->changes->pluck('id')->all());
        $this->assertFalse($result->hasMore);
        $this->assertSame($expected->id, $cursor->changeId);
    }

    public function test_owner_mismatch_exception_is_not_hidden(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $encoded = app(ApiSyncCursorCodec::class)->encode(
            new ApiSyncCursor(ApiSyncChange::SCOPE_USER, $other->id, 0),
        );

        $this->expectException(ApiSyncCursorException::class);

        app(ApiSyncPullService::class)->pull(
            ApiSyncChange::SCOPE_USER,
            $owner,
            $encoded,
            100,
        );
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
