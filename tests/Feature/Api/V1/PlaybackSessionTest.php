<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\ContentAudience;
use App\Enums\MediaHealthStatus;
use App\Enums\PublicationStatus;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlaybackSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_creates_safe_public_session_with_exact_preferences_and_navigation(): void
    {
        [$title, $season, $episodes] = $this->createGraph('public-playback');
        $this->createMedia($title, $season, $episodes[0], '720p', 'm3u8', 'standard');
        $preferred = $this->createMedia($title, $season, $episodes[0], '1080p', 'mp4', 'original');
        $this->createMedia($title, $season, $episodes[1], '1080p', 'mp4', 'original');

        $response = $this->postJson("/api/v1/titles/{$title->slug}/playback-sessions", [
            'episode_id' => $episodes[0]->id,
            'variant' => 'original',
            'quality' => '1080p',
            'format' => 'mp4',
        ])->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.media.id', $preferred->id)
            ->assertJsonPath('data.media.quality', '1080p')
            ->assertJsonPath('data.media.format', 'mp4')
            ->assertJsonPath('data.navigation.next.id', $episodes[1]->id)
            ->assertJsonMissingPath('data.media.playback_url')
            ->assertJsonMissingPath('data.media.source_url')
            ->assertJsonMissingPath('data.progress_session_token');

        $playbackUrl = $response->json('data.playback_url');
        $this->assertIsString($playbackUrl);
        $this->assertStringStartsWith(url('/api/v1/playback/'), $playbackUrl);

        foreach ([
            'https://data00-cdn.11cdn.org/',
            'source_url',
            'storage_disk',
            'seasonvar_parsed',
        ] as $secret) {
            $response->assertDontSee($secret, false);
        }
    }

    public function test_authenticated_audience_and_progress_token_follow_mobile_identity(): void
    {
        [$title, $season, $episodes] = $this->createGraph('authenticated-playback', ContentAudience::Authenticated);
        $this->createMedia($title, $season, $episodes[0], '1080p', 'm3u8', 'original');

        $this->postJson("/api/v1/titles/{$title->slug}/playback-sessions", [
            'episode_id' => $episodes[0]->id,
        ])->assertUnauthorized()
            ->assertJsonPath('code', 'authentication_required');

        $this->withToken('invalid-mobile-token')
            ->postJson("/api/v1/titles/{$title->slug}/playback-sessions", [
                'episode_id' => $episodes[0]->id,
            ])->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');

        $verified = User::factory()->create();
        $verifiedToken = $verified->createToken('Verified phone', ['mobile:read', 'mobile:write'], now()->addDay());
        $this->withToken($verifiedToken->plainTextToken)
            ->postJson("/api/v1/titles/{$title->slug}/playback-sessions", [
                'episode_id' => $episodes[0]->id,
            ])->assertCreated()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.progress_session_token', fn (string $token): bool => $token !== '');

        $unverified = User::factory()->unverified()->create();
        $unverifiedToken = $unverified->createToken('Unverified phone', ['mobile:read'], now()->addDay());
        $this->withToken($unverifiedToken->plainTextToken)
            ->postJson("/api/v1/titles/{$title->slug}/playback-sessions", [
                'episode_id' => $episodes[0]->id,
            ])->assertCreated()
            ->assertJsonMissingPath('data.progress_session_token');
    }

    public function test_foreign_hidden_future_and_failed_releases_fail_closed(): void
    {
        [$title, $season, $episodes] = $this->createGraph('playback-boundary');
        $media = $this->createMedia($title, $season, $episodes[0], '1080p', 'm3u8', 'original');
        [$foreignTitle, $foreignSeason, $foreignEpisodes] = $this->createGraph('foreign-playback');
        $foreignMedia = $this->createMedia($foreignTitle, $foreignSeason, $foreignEpisodes[0], '1080p', 'm3u8', 'original');

        $this->postJson("/api/v1/titles/{$title->slug}/playback-sessions", [
            'episode_id' => $foreignEpisodes[0]->id,
        ])->assertNotFound();
        $this->postJson("/api/v1/titles/{$title->slug}/playback-sessions", [
            'episode_id' => $episodes[0]->id,
            'media_id' => $foreignMedia->id,
        ])->assertNotFound();

        $hidden = CatalogTitle::factory()->create([
            'slug' => 'hidden-playback',
            'publication_status' => PublicationStatus::Hidden,
        ]);
        $this->postJson("/api/v1/titles/{$hidden->slug}/playback-sessions")
            ->assertNotFound();

        $future = CatalogTitle::factory()->create([
            'slug' => 'future-playback',
            'available_from' => now()->addDay(),
        ]);
        $this->postJson("/api/v1/titles/{$future->slug}/playback-sessions")
            ->assertStatus(425)
            ->assertJsonPath('code', 'not_yet_published');

        $this->createMedia($title, $season, $episodes[0], '720p', 'm3u8', 'fallback');
        $media->update(['health_status' => MediaHealthStatus::Unavailable]);
        $this->postJson("/api/v1/titles/{$title->slug}/playback-sessions", [
            'episode_id' => $episodes[0]->id,
            'media_id' => $media->id,
        ])->assertServiceUnavailable()
            ->assertJsonPath('code', 'temporarily_unavailable');
    }

    public function test_playback_preferences_are_strictly_validated_without_global_id_exists_rules(): void
    {
        [$title] = $this->createGraph('validated-playback');

        $this->postJson("/api/v1/titles/{$title->slug}/playback-sessions", [
            'episode_id' => 0,
            'media_id' => -1,
            'variant' => str_repeat('x', 121),
            'audio_language' => str_repeat('x', 81),
            'quality' => '16k',
            'format' => 'avi',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'episode_id',
                'media_id',
                'variant',
                'audio_language',
                'quality',
                'format',
            ]);
    }

    /** @return array{CatalogTitle, Season, array{Episode, Episode}} */
    private function createGraph(string $slug, ContentAudience $audience = ContentAudience::Public): array
    {
        $title = CatalogTitle::factory()->create([
            'slug' => $slug,
            'audience' => $audience,
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'number' => 1,
            'audience' => $audience,
        ]);
        $episodes = [];

        foreach ([1, 2] as $number) {
            $episodes[] = Episode::factory()->create([
                'season_id' => $season->id,
                'number' => $number,
                'sort_order' => $number,
                'audience' => $audience,
            ]);
        }

        return [$title, $season, $episodes];
    }

    private function createMedia(
        CatalogTitle $title,
        Season $season,
        Episode $episode,
        string $quality,
        string $format,
        string $variant,
    ): LicensedMedia {
        return LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'storage_disk' => 'seasonvar_parsed',
            'path' => "https://data00-cdn.11cdn.org/{$title->slug}-{$episode->id}-{$quality}.{$format}",
            'playback_url' => "https://data00-cdn.11cdn.org/{$title->slug}-{$episode->id}-{$quality}.{$format}",
            'status' => 'published',
            'published_at' => now(),
            'audience' => $title->audience,
            'quality' => $quality,
            'format' => $format,
            'variant_name' => ucfirst($variant),
            'variant_key' => $variant,
            'duration_seconds' => 600,
        ]);
    }
}
