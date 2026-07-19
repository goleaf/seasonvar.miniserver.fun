<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\DTOs\ApiSyncCursor;
use App\Models\ApiSyncChange;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Api\V1\Sync\ApiSyncCursorCodec;
use App\Services\Api\V1\Sync\UserSyncChangePublisher;
use App\Services\Catalog\CatalogPlaybackProgressSession;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class OfflineUserSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_sync_requires_read_ability_and_returns_an_owner_checkpoint(): void
    {
        $owner = User::factory()->unverified()->create();
        $other = User::factory()->create();
        $ownerChange = $this->change($owner, 'title_state', 'owner-title');
        $this->change($other, 'title_state', 'other-title');

        $this->getJson('/api/v1/me/sync')
            ->assertUnauthorized()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->withToken('invalid-mobile-token')
            ->getJson('/api/v1/me/sync')
            ->assertUnauthorized();

        Sanctum::actingAs($owner, ['mobile:write']);
        $this->withHeader('Authorization', '')
            ->getJson('/api/v1/me/sync')
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        Sanctum::actingAs($owner, ['mobile:read']);
        $response = $this->withHeader('Authorization', '')
            ->getJson('/api/v1/me/sync')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeaderMissing('ETag')
            ->assertHeaderMissing('Last-Modified')
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('meta.limit', 100);

        $cursor = app(ApiSyncCursorCodec::class)->decode(
            (string) $response->json('meta.cursor'),
            ApiSyncChange::SCOPE_USER,
            $owner->id,
        );

        $this->assertSame($ownerChange->id, $cursor->changeId);
    }

    public function test_effective_watchlist_and_rating_changes_increment_separate_versions_and_publish_events(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create(['slug' => 'versioned-user-state']);
        Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

        $this->putJson("/api/v1/me/watchlist/{$title->slug}")
            ->assertOk()
            ->assertJsonPath('data.in_watchlist', true)
            ->assertJsonPath('data.versions.watchlist', 1)
            ->assertJsonPath('data.versions.rating', 0);
        $this->putJson("/api/v1/me/watchlist/{$title->slug}")
            ->assertOk()
            ->assertJsonPath('data.versions.watchlist', 1);
        $this->putJson("/api/v1/me/ratings/{$title->slug}", ['rating' => 8])
            ->assertOk()
            ->assertJsonPath('data.rating', 8)
            ->assertJsonPath('data.versions.watchlist', 1)
            ->assertJsonPath('data.versions.rating', 1);
        $this->putJson("/api/v1/me/ratings/{$title->slug}", ['rating' => 8])
            ->assertOk()
            ->assertJsonPath('data.versions.rating', 1);

        $state = CatalogTitleUserState::query()->whereBelongsTo($user)->sole();
        $this->assertSame(1, $state->watchlist_version);
        $this->assertSame(1, $state->rating_version);
        $this->assertSame([
            ['versioned-user-state', ApiSyncChange::OPERATION_UPSERT],
            ['versioned-user-state', ApiSyncChange::OPERATION_UPSERT],
        ], ApiSyncChange::query()
            ->where('scope', ApiSyncChange::SCOPE_USER)
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->map(fn (ApiSyncChange $change): array => [$change->resource_key, $change->operation])
            ->all());

        $this->getJson('/api/v1/me/watchlist')
            ->assertOk()
            ->assertJsonPath('data.0.state.versions.watchlist', 1)
            ->assertJsonPath('data.0.state.versions.rating', 1);
    }

    public function test_existing_user_state_endpoints_remain_healthy_before_the_sync_migration(): void
    {
        Schema::drop('api_sync_mutations');
        Schema::drop('api_sync_changes');
        Schema::table('catalog_title_user_states', function (Blueprint $table): void {
            $table->dropColumn(['watchlist_version', 'rating_version']);
        });

        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create(['slug' => 'pre-sync-user-state']);
        Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

        $this->getJson('/api/v1/sync/manifest')
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'sync_unavailable');
        $this->putJson("/api/v1/me/watchlist/{$title->slug}")
            ->assertOk()
            ->assertJsonPath('data.in_watchlist', true)
            ->assertJsonPath('data.versions.watchlist', 0)
            ->assertJsonPath('data.versions.rating', 0);
        $this->getJson('/api/v1/me/watchlist')
            ->assertOk()
            ->assertJsonPath('data.0.state.in_watchlist', true)
            ->assertJsonPath('data.0.state.versions.watchlist', 0)
            ->assertJsonPath('data.0.state.versions.rating', 0);
        $this->putJson("/api/v1/me/ratings/{$title->slug}", ['rating' => 8])
            ->assertOk()
            ->assertJsonPath('data.rating', 8)
            ->assertJsonPath('data.versions.watchlist', 0)
            ->assertJsonPath('data.versions.rating', 0);
        $this->getJson('/api/v1/me/ratings')
            ->assertOk()
            ->assertJsonPath('data.0.state.rating', 8)
            ->assertJsonPath('data.0.state.versions.watchlist', 0)
            ->assertJsonPath('data.0.state.versions.rating', 0);
        $this->deleteJson("/api/v1/me/watchlist/{$title->slug}")
            ->assertOk()
            ->assertJsonPath('data.in_watchlist', false)
            ->assertJsonPath('data.versions.watchlist', 0);
        $this->deleteJson("/api/v1/me/ratings/{$title->slug}")
            ->assertOk()
            ->assertJsonPath('data.rating', null)
            ->assertJsonPath('data.versions.rating', 0);
    }

    public function test_private_pull_is_owner_scoped_and_serializes_only_safe_resource_links(): void
    {
        $owner = User::factory()->unverified()->create();
        $other = User::factory()->create();
        $titleState = $this->change($owner, 'title_state', 'private-title');
        $progress = $this->change($owner, 'progress', 'private-title:51');
        $history = $this->change($owner, 'history', '91', ApiSyncChange::OPERATION_DELETE);
        $this->change($owner, 'history', null, ApiSyncChange::OPERATION_CLEAR);
        $this->change($other, 'title_state', 'foreign-title');
        $cursor = app(ApiSyncCursorCodec::class)->encode(
            new ApiSyncCursor(ApiSyncChange::SCOPE_USER, $owner->id, 0),
        );
        Sanctum::actingAs($owner, ['mobile:read']);

        $response = $this->withHeader('Authorization', '')
            ->getJson('/api/v1/me/sync?'.http_build_query(['cursor' => $cursor, 'limit' => 3]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.type', 'title_state')
            ->assertJsonPath('data.0.key', 'private-title')
            ->assertJsonPath('data.0.links.self', url('/api/v1/me/titles/private-title/state'))
            ->assertJsonPath('data.1.type', 'progress')
            ->assertJsonPath('data.1.title_slug', 'private-title')
            ->assertJsonPath('data.1.episode_id', 51)
            ->assertJsonPath('data.1.links.history', url('/api/v1/me/history'))
            ->assertJsonPath('data.2.type', 'history')
            ->assertJsonPath('data.2.key', '91')
            ->assertJsonPath('data.2.links.self', null)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.limit', 3);

        $next = app(ApiSyncCursorCodec::class)->decode(
            (string) $response->json('meta.cursor'),
            ApiSyncChange::SCOPE_USER,
            $owner->id,
        );

        $this->assertSame($history->id, $next->changeId);
        $this->assertNotSame($titleState->id, $next->changeId);
        $this->assertNotSame($progress->id, $next->changeId);

        $response->assertJsonMissingPath('data.0.user_id');

        foreach (['user_id', 'foreign-title', 'email', 'token', 'source_url', 'media_url'] as $privateValue) {
            $response->assertDontSee($privateValue, false);
        }

        $foreignCursor = app(ApiSyncCursorCodec::class)->encode(
            new ApiSyncCursor(ApiSyncChange::SCOPE_USER, $other->id, 0),
        );
        $this->withHeader('Authorization', '')
            ->getJson('/api/v1/me/sync?cursor='.urlencode($foreignCursor))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('cursor');
    }

    public function test_old_owner_cursor_expires_after_retention_when_its_journal_is_empty(): void
    {
        $owner = User::factory()->create();
        $removed = $this->change($owner, 'title_state', 'removed-owner-title');
        $cursor = Crypt::encryptString(json_encode([
            'v' => 1,
            's' => ApiSyncChange::SCOPE_USER,
            'o' => $owner->id,
            'i' => $removed->id,
            't' => now()->subDays(31)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
        $initialCursor = Crypt::encryptString(json_encode([
            'v' => 1,
            's' => ApiSyncChange::SCOPE_USER,
            'o' => $owner->id,
            'i' => 0,
            't' => now()->subDays(31)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
        $removed->delete();
        Sanctum::actingAs($owner, ['mobile:read']);

        foreach ([$cursor, $initialCursor] as $expiredCursor) {
            $this->getJson('/api/v1/me/sync?cursor='.urlencode($expiredCursor))
                ->assertGone()
                ->assertJsonPath('code', 'sync_cursor_expired');
        }
    }

    public function test_owner_publisher_preserves_domain_maximum_slug_and_composite_progress_key(): void
    {
        $slug = str_repeat('a', 255);
        $owner = User::factory()->create();
        $title = CatalogTitle::factory()->create(['slug' => $slug]);
        $publisher = app(UserSyncChangePublisher::class);

        $publisher->publishTitleState($owner, $title);
        $publisher->publishProgress($owner, $title, 123456789);

        $this->assertSame([
            $slug,
            $slug.':123456789',
        ], ApiSyncChange::query()
            ->where('user_id', $owner->id)
            ->orderBy('id')
            ->pluck('resource_key')
            ->all());
    }

    public function test_progress_history_delete_and_clear_publish_only_effective_owner_changes(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$title, $episode, $media] = $this->watchableGraph('offline-activity');
        $session = app(CatalogPlaybackProgressSession::class)->issue($user, $title, $episode, $media);
        Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);
        $payload = [
            'playback_session_token' => $session,
            'event_sequence' => 1,
            'position_seconds' => 90,
            'reported_duration_seconds' => 600,
            'ended' => false,
        ];

        $this->putJson("/api/v1/titles/{$title->slug}/episodes/{$episode->id}/progress", $payload)
            ->assertOk();
        $this->putJson("/api/v1/titles/{$title->slug}/episodes/{$episode->id}/progress", $payload)
            ->assertOk();

        $progress = EpisodeViewProgress::query()->whereBelongsTo($user)->sole();
        $foreign = EpisodeViewProgress::query()->create([
            'user_id' => $other->id,
            'catalog_title_id' => $title->id,
            'episode_id' => $episode->id,
            'position_seconds' => 30,
            'duration_seconds' => 600,
            'first_started_at' => now(),
            'last_watched_at' => now(),
        ]);

        $this->deleteJson("/api/v1/me/history/{$foreign->id}")->assertNotFound();
        $this->deleteJson("/api/v1/me/history/{$progress->id}")->assertNoContent();

        $secondEpisode = Episode::factory()->create([
            'season_id' => $episode->season_id,
            'number' => 2,
        ]);
        EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'episode_id' => $secondEpisode->id,
            'position_seconds' => 40,
            'duration_seconds' => 600,
            'first_started_at' => now(),
            'last_watched_at' => now(),
        ]);
        $this->deleteJson('/api/v1/me/history')->assertNoContent();
        $this->deleteJson('/api/v1/me/history')->assertNoContent();

        $this->assertModelExists($foreign);
        $this->assertSame([
            ['progress', "offline-activity:{$episode->id}", ApiSyncChange::OPERATION_UPSERT],
            ['title_state', 'offline-activity', ApiSyncChange::OPERATION_UPSERT],
            ['history', (string) $progress->id, ApiSyncChange::OPERATION_DELETE],
            ['history', null, ApiSyncChange::OPERATION_CLEAR],
        ], ApiSyncChange::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get()
            ->map(fn (ApiSyncChange $change): array => [
                $change->resource_type,
                $change->resource_key,
                $change->operation,
            ])->all());
    }

    private function change(
        User $user,
        string $type,
        ?string $key,
        string $operation = ApiSyncChange::OPERATION_UPSERT,
    ): ApiSyncChange {
        return ApiSyncChange::query()->create([
            'scope' => ApiSyncChange::SCOPE_USER,
            'user_id' => $user->id,
            'resource_type' => $type,
            'resource_key' => $key,
            'operation' => $operation,
            'changed_at' => now()->startOfSecond(),
        ]);
    }

    /** @return array{CatalogTitle, Episode, LicensedMedia} */
    private function watchableGraph(string $slug): array
    {
        $title = CatalogTitle::factory()->create(['slug' => $slug]);
        $season = Season::factory()->create(['catalog_title_id' => $title->id, 'number' => 1]);
        $episode = Episode::factory()->create(['season_id' => $season->id, 'number' => 1]);
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'published_at' => now(),
            'duration_seconds' => 600,
        ]);

        return [$title, $episode, $media];
    }
}
