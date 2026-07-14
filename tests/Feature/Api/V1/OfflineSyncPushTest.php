<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\ApiSyncMutation;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\CatalogPlaybackProgressSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class OfflineSyncPushTest extends TestCase
{
    use RefreshDatabase;

    public function test_push_requires_read_write_abilities_and_verified_email(): void
    {
        $payload = ['operations' => [$this->historyClear()]];

        $this->postJson('/api/v1/me/sync', $payload)
            ->assertUnauthorized()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->withToken('invalid-mobile-token')
            ->postJson('/api/v1/me/sync', $payload)
            ->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(), ['mobile:read']);
        $this->withHeader('Authorization', '')
            ->postJson('/api/v1/me/sync', $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        Sanctum::actingAs(User::factory()->unverified()->create(), ['mobile:read', 'mobile:write']);
        $this->withHeader('Authorization', '')
            ->postJson('/api/v1/me/sync', $payload)
            ->assertForbidden()
            ->assertJsonPath('code', 'email_not_verified');
    }

    public function test_push_rejects_invalid_batch_and_exact_operation_shapes(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['mobile:read', 'mobile:write']);

        $invalidPayloads = [
            [[], 'operations'],
            [['operations' => []], 'operations'],
            [['operations' => array_map(fn (int $index): array => [
                'mutation_id' => (string) Str::uuid(),
                'type' => 'history.clear',
            ], range(1, 51))], 'operations'],
            [['operations' => [
                ['mutation_id' => 'not-a-uuid', 'type' => 'history.clear'],
            ]], 'operations.0.mutation_id'],
            [['operations' => [
                ['mutation_id' => (string) Str::uuid(), 'type' => 'unknown.operation'],
            ]], 'operations.0.type'],
            [['operations' => [[
                ...$this->historyClear(),
                'unexpected' => 'value',
            ]]], 'operations.0.unexpected'],
            [['operations' => [[
                'mutation_id' => (string) Str::uuid(),
                'type' => 'watchlist.set',
                'title_slug' => 'shape-title',
                'expected_version' => 0,
            ]]], 'operations.0.value'],
            [['operations' => [[
                'mutation_id' => (string) Str::uuid(),
                'type' => 'rating.set',
                'title_slug' => 'shape-title',
                'value' => 11,
                'expected_version' => -1,
            ]]], 'operations.0.value'],
            [['operations' => [[
                'mutation_id' => (string) Str::uuid(),
                'type' => 'progress.set',
                'title_slug' => 'shape-title',
                'episode_id' => 0,
                'playback_session' => '',
                'event_sequence' => 0,
                'position_seconds' => -1,
                'duration_seconds' => -1,
                'ended' => 'false',
            ]]], 'operations.0.episode_id'],
        ];

        foreach ($invalidPayloads as [$payload, $errorKey]) {
            $this->withHeader('Authorization', '')
                ->postJson('/api/v1/me/sync', $payload)
                ->assertUnprocessable()
                ->assertJsonPath('code', 'validation_failed')
                ->assertJsonValidationErrors($errorKey);
        }

        $duplicateId = (string) Str::uuid();
        $this->withHeader('Authorization', '')
            ->postJson('/api/v1/me/sync', ['operations' => [
                ['mutation_id' => $duplicateId, 'type' => 'history.clear'],
                ['mutation_id' => $duplicateId, 'type' => 'history.clear'],
            ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('operations.1.mutation_id');
    }

    public function test_state_mutations_are_idempotent_and_use_optimistic_versions(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create(['slug' => 'offline-state-push']);
        Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);
        $watchlistId = (string) Str::uuid();
        $ratingId = (string) Str::uuid();
        $operations = [
            [
                'mutation_id' => $watchlistId,
                'type' => 'watchlist.set',
                'title_slug' => $title->slug,
                'value' => true,
                'expected_version' => 0,
            ],
            [
                'mutation_id' => $ratingId,
                'type' => 'rating.set',
                'title_slug' => $title->slug,
                'value' => 9,
                'expected_version' => 0,
            ],
        ];

        $this->withHeader('Authorization', '')
            ->postJson('/api/v1/me/sync', ['operations' => $operations])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.results.0.mutation_id', $watchlistId)
            ->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.0.resource_version', 1)
            ->assertJsonPath('data.results.0.data.in_watchlist', true)
            ->assertJsonPath('data.results.1.status', 'applied')
            ->assertJsonPath('data.results.1.resource_version', 1)
            ->assertJsonPath('data.results.1.data.rating', 9);

        $this->withHeader('Authorization', '')
            ->postJson('/api/v1/me/sync', ['operations' => $operations])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'duplicate')
            ->assertJsonPath('data.results.0.resource_version', 1)
            ->assertJsonPath('data.results.1.status', 'duplicate');

        $collision = $operations[0];
        $collision['value'] = false;
        $this->withHeader('Authorization', '')
            ->postJson('/api/v1/me/sync', ['operations' => [$collision]])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'conflict')
            ->assertJsonPath('data.results.0.data.code', 'mutation_id_reused');

        $this->withHeader('Authorization', '')
            ->postJson('/api/v1/me/sync', ['operations' => [[
                'mutation_id' => (string) Str::uuid(),
                'type' => 'watchlist.set',
                'title_slug' => $title->slug,
                'value' => false,
                'expected_version' => 0,
            ]]])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'conflict')
            ->assertJsonPath('data.results.0.resource_version', 1)
            ->assertJsonPath('data.results.0.data.in_watchlist', true);

        $state = CatalogTitleUserState::query()->whereBelongsTo($user)->sole();
        $this->assertTrue($state->in_watchlist);
        $this->assertSame(9, $state->rating);
        $this->assertSame(1, $state->watchlist_version);
        $this->assertSame(1, $state->rating_version);
        $this->assertDatabaseCount('api_sync_mutations', 3);
    }

    public function test_valid_batch_returns_partial_domain_results_without_rolling_back_neighbors(): void
    {
        $user = User::factory()->create();
        $visible = CatalogTitle::factory()->create(['slug' => 'partial-visible']);
        $hidden = CatalogTitle::factory()->create([
            'slug' => 'partial-hidden',
            'is_published' => false,
        ]);
        Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

        $response = $this->withHeader('Authorization', '')
            ->postJson('/api/v1/me/sync', ['operations' => [
                [
                    'mutation_id' => (string) Str::uuid(),
                    'type' => 'watchlist.set',
                    'title_slug' => $visible->slug,
                    'value' => true,
                    'expected_version' => 0,
                ],
                [
                    'mutation_id' => (string) Str::uuid(),
                    'type' => 'rating.set',
                    'title_slug' => $hidden->slug,
                    'value' => 7,
                    'expected_version' => 0,
                ],
                [
                    'mutation_id' => (string) Str::uuid(),
                    'type' => 'progress.set',
                    'title_slug' => $visible->slug,
                    'episode_id' => 999999,
                    'playback_session' => 'invalid-but-opaque',
                    'event_sequence' => 1,
                    'position_seconds' => 10,
                    'duration_seconds' => 600,
                    'ended' => false,
                ],
                [
                    'mutation_id' => (string) Str::uuid(),
                    'type' => 'history.delete',
                    'progress_id' => 999999,
                ],
                $this->historyClear(),
            ]])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.1.status', 'not_found')
            ->assertJsonPath('data.results.2.status', 'rejected')
            ->assertJsonPath('data.results.3.status', 'not_found')
            ->assertJsonPath('data.results.4.status', 'applied');

        $this->assertTrue(CatalogTitleUserState::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($visible)
            ->sole()
            ->in_watchlist);
        $this->assertDatabaseCount('api_sync_mutations', 5);

        foreach (['exception', 'trace', 'source_url', 'playback_url', 'password', 'email'] as $privateField) {
            $response->assertDontSee($privateField, false);
        }
    }

    public function test_progress_and_history_receipts_never_store_playback_session_or_raw_media_fields(): void
    {
        $user = User::factory()->create();
        [$title, $episode, $media] = $this->watchableGraph('safe-receipt');
        $playbackSession = app(CatalogPlaybackProgressSession::class)->issue($user, $title, $episode, $media);
        Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

        $response = $this->withHeader('Authorization', '')
            ->postJson('/api/v1/me/sync', ['operations' => [[
                'mutation_id' => (string) Str::uuid(),
                'type' => 'progress.set',
                'title_slug' => $title->slug,
                'episode_id' => $episode->id,
                'playback_session' => $playbackSession,
                'event_sequence' => 1,
                'position_seconds' => 90,
                'duration_seconds' => 600,
                'ended' => false,
            ]]])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.0.data.episode_id', $episode->id)
            ->assertJsonPath('data.results.0.data.position_seconds', 90);

        $receiptJson = json_encode(ApiSyncMutation::query()->sole()->result, JSON_THROW_ON_ERROR);
        $responseJson = (string) $response->getContent();

        foreach ([$receiptJson, $responseJson] as $safeJson) {
            $this->assertStringNotContainsString($playbackSession, $safeJson);

            foreach (['playback_session', 'playback_url', 'source_url', 'licensed_media_id', 'token'] as $privateField) {
                $this->assertStringNotContainsString($privateField, $safeJson);
            }
        }

        $progress = EpisodeViewProgress::query()->whereBelongsTo($user)->sole();
        $this->assertSame(90, $progress->position_seconds);
    }

    /** @return array{mutation_id: string, type: string} */
    private function historyClear(): array
    {
        return [
            'mutation_id' => (string) Str::uuid(),
            'type' => 'history.clear',
        ];
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
