<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PlaybackProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_is_canonical_idempotent_and_uses_server_duration(): void
    {
        [$user, $token, $title, $episode] = $this->createVerifiedSessionGraph('progress-canonical');
        $progressToken = $this->progressToken($token, $title, $episode);

        $this->withToken($token)->putJson($this->progressUrl($title, $episode), [
            'playback_session_token' => $progressToken,
            'event_sequence' => 1,
            'position_seconds' => 90,
            'reported_duration_seconds' => 999,
            'ended' => false,
        ])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.position_seconds', 90)
            ->assertJsonPath('data.duration_seconds', 600)
            ->assertJsonPath('data.completed', false)
            ->assertJsonMissingPath('data.playback_session_id')
            ->assertJsonMissingPath('data.licensed_media_id');

        $this->withToken($token)->putJson($this->progressUrl($title, $episode), [
            'playback_session_token' => $progressToken,
            'event_sequence' => 1,
            'position_seconds' => 120,
            'reported_duration_seconds' => 600,
            'ended' => false,
        ])->assertOk()
            ->assertJsonPath('data.position_seconds', 90);

        $this->withToken($token)->putJson($this->progressUrl($title, $episode), [
            'playback_session_token' => $progressToken,
            'event_sequence' => 2,
            'position_seconds' => 590,
            'reported_duration_seconds' => 600,
            'ended' => false,
        ])->assertOk()
            ->assertJsonPath('data.position_seconds', 590)
            ->assertJsonPath('data.progress_percent', 98)
            ->assertJsonPath('data.completed', true);

        $this->withToken($token)->putJson($this->progressUrl($title, $episode), [
            'playback_session_token' => $progressToken,
            'event_sequence' => 3,
            'position_seconds' => 10,
            'reported_duration_seconds' => 600,
            'ended' => false,
        ])->assertOk()
            ->assertJsonPath('data.position_seconds', 10)
            ->assertJsonPath('data.completed', true);

        $this->assertSame(1, EpisodeViewProgress::query()->whereBelongsTo($user)->count());
    }

    public function test_newer_session_wins_and_older_session_cannot_overwrite_it(): void
    {
        [, $token, $title, $episode] = $this->createVerifiedSessionGraph('progress-session-order');
        $olderToken = $this->progressToken($token, $title, $episode);

        $this->withToken($token)->putJson($this->progressUrl($title, $episode), [
            'playback_session_token' => $olderToken,
            'event_sequence' => 1,
            'position_seconds' => 60,
            'reported_duration_seconds' => 600,
            'ended' => false,
        ])->assertOk();

        $newerToken = $this->progressToken($token, $title, $episode);
        $this->withToken($token)->putJson($this->progressUrl($title, $episode), [
            'playback_session_token' => $newerToken,
            'event_sequence' => 1,
            'position_seconds' => 180,
            'reported_duration_seconds' => 600,
            'ended' => false,
        ])->assertOk()
            ->assertJsonPath('data.position_seconds', 180);

        $this->withToken($token)->putJson($this->progressUrl($title, $episode), [
            'playback_session_token' => $olderToken,
            'event_sequence' => 2,
            'position_seconds' => 300,
            'reported_duration_seconds' => 600,
            'ended' => false,
        ])->assertOk()
            ->assertJsonPath('data.position_seconds', 180);
    }

    public function test_tampered_cross_user_and_invalid_progress_events_are_rejected(): void
    {
        [$user, $token, $title, $episode] = $this->createVerifiedSessionGraph('progress-rejection');
        $progressToken = $this->progressToken($token, $title, $episode);
        $other = User::factory()->create();
        $payload = [
            'playback_session_token' => $progressToken,
            'event_sequence' => 1,
            'position_seconds' => 90,
            'reported_duration_seconds' => 600,
            'ended' => false,
        ];

        Sanctum::actingAs($other, ['mobile:write']);
        $this->withHeader('Authorization', '')
            ->putJson($this->progressUrl($title, $episode), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_playback_progress');

        $tamperIndex = intdiv(strlen($progressToken), 2);
        $payload['playback_session_token'] = substr_replace(
            $progressToken,
            $progressToken[$tamperIndex] === 'a' ? 'b' : 'a',
            $tamperIndex,
            1,
        );
        Sanctum::actingAs($user, ['mobile:write']);
        $this->withHeader('Authorization', '')
            ->putJson($this->progressUrl($title, $episode), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'invalid_playback_progress');

        foreach ([
            ['event_sequence' => 0],
            ['position_seconds' => -1],
            ['position_seconds' => 86401],
            ['reported_duration_seconds' => -1],
            ['ended' => 'no'],
        ] as $invalid) {
            $this->withHeader('Authorization', '')
                ->putJson($this->progressUrl($title, $episode), array_merge($payload, $invalid, [
                    'playback_session_token' => $progressToken,
                ]))
                ->assertUnprocessable()
                ->assertJsonPath('code', 'validation_failed');
        }
    }

    public function test_unverified_user_cannot_record_progress(): void
    {
        $user = User::factory()->unverified()->create();
        $token = $user->createToken('Unverified phone', ['mobile:write'], now()->addDay())->plainTextToken;
        [$title, , $episode] = $this->createGraph('progress-unverified');

        $this->withToken($token)->putJson($this->progressUrl($title, $episode), [
            'playback_session_token' => 'opaque',
            'event_sequence' => 1,
            'position_seconds' => 10,
            'reported_duration_seconds' => 600,
            'ended' => false,
        ])->assertForbidden()
            ->assertJsonPath('code', 'email_not_verified');
    }

    /** @return array{User, string, CatalogTitle, Episode} */
    private function createVerifiedSessionGraph(string $slug): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('Progress phone', ['mobile:read', 'mobile:write'], now()->addDay())->plainTextToken;
        [$title, , $episode] = $this->createGraph($slug);

        return [$user, $token, $title, $episode];
    }

    /** @return array{CatalogTitle, Season, Episode, LicensedMedia} */
    private function createGraph(string $slug): array
    {
        $title = CatalogTitle::factory()->create(['slug' => $slug]);
        $season = Season::factory()->create(['catalog_title_id' => $title->id, 'number' => 1]);
        $episode = Episode::factory()->create(['season_id' => $season->id, 'number' => 1]);
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'storage_disk' => 'seasonvar_parsed',
            'path' => "https://data00-cdn.11cdn.org/{$slug}.m3u8",
            'playback_url' => "https://data00-cdn.11cdn.org/{$slug}.m3u8",
            'status' => 'published',
            'published_at' => now(),
            'quality' => '1080p',
            'format' => 'm3u8',
            'duration_seconds' => 600,
        ]);

        return [$title, $season, $episode, $media];
    }

    private function progressToken(string $token, CatalogTitle $title, Episode $episode): string
    {
        return (string) $this->withToken($token)
            ->postJson("/api/v1/titles/{$title->slug}/playback-sessions", [
                'episode_id' => $episode->id,
            ])->assertCreated()
            ->json('data.progress_session_token');
    }

    private function progressUrl(CatalogTitle $title, Episode $episode): string
    {
        return "/api/v1/titles/{$title->slug}/episodes/{$episode->id}/progress";
    }
}
