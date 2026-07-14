<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\MobilePlaybackGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class PlaybackDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_mobile_delivery_redirects_to_provider_only_at_delivery_time(): void
    {
        [$title, $episode, $media] = $this->createPlayableGraph('delivery-ready');
        $session = $this->postJson("/api/v1/titles/{$title->slug}/playback-sessions", [
            'episode_id' => $episode->id,
        ])->assertCreated();

        $session->assertDontSee($media->playback_url, false);
        $playbackUrl = (string) $session->json('data.playback_url');

        $this->get($playbackUrl)
            ->assertRedirect($media->playback_url)
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_unsigned_missing_tampered_and_wrong_media_grants_fail_closed(): void
    {
        [$title, $episode, $media] = $this->createPlayableGraph('delivery-grants');
        [, , $otherMedia] = $this->createPlayableGraph('delivery-other');
        $sessionUrl = (string) $this->postJson("/api/v1/titles/{$title->slug}/playback-sessions", [
            'episode_id' => $episode->id,
        ])->assertCreated()->json('data.playback_url');
        parse_str((string) parse_url($sessionUrl, PHP_URL_QUERY), $query);
        $grant = (string) ($query['grant'] ?? '');

        $this->getJson("/api/v1/playback/{$media->id}?grant=".urlencode($grant))
            ->assertForbidden()
            ->assertJsonPath('code', 'forbidden');

        $missingGrantUrl = URL::temporarySignedRoute(
            'api.v1.playback.source',
            now()->addMinute(),
            ['licensedMedia' => $media->id],
        );
        $this->getJson($missingGrantUrl)->assertForbidden();

        $tamperedGrantUrl = URL::temporarySignedRoute(
            'api.v1.playback.source',
            now()->addMinute(),
            ['licensedMedia' => $media->id, 'grant' => substr($grant, 0, -1).'x'],
        );
        $this->getJson($tamperedGrantUrl)->assertForbidden();

        $wrongMediaUrl = URL::temporarySignedRoute(
            'api.v1.playback.source',
            now()->addMinute(),
            ['licensedMedia' => $otherMedia->id, 'grant' => $grant],
        );
        $this->getJson($wrongMediaUrl)->assertForbidden();

        $expiredUrl = URL::temporarySignedRoute(
            'api.v1.playback.source',
            now()->subSecond(),
            ['licensedMedia' => $media->id, 'grant' => $grant],
        );
        $this->getJson($expiredUrl)->assertForbidden();
    }

    public function test_deleted_user_and_revoked_entitlement_fail_at_second_boundary(): void
    {
        [$title, $episode, $media] = $this->createPlayableGraph('delivery-revocation');
        $user = User::factory()->create();
        $token = $user->createToken('Delivery phone', ['mobile:read'], now()->addDay());
        $authenticatedUrl = (string) $this->withToken($token->plainTextToken)
            ->postJson("/api/v1/titles/{$title->slug}/playback-sessions", [
                'episode_id' => $episode->id,
            ])->assertCreated()
            ->json('data.playback_url');

        $user->delete();

        $this->withHeader('Authorization', '')
            ->getJson($authenticatedUrl)
            ->assertForbidden()
            ->assertDontSee($media->playback_url, false);

        $guestGrant = app(MobilePlaybackGrant::class)->issue(null, $media, now()->addMinutes(5));
        $guestUrl = URL::temporarySignedRoute(
            'api.v1.playback.source',
            now()->addMinutes(5),
            ['licensedMedia' => $media->id, 'grant' => $guestGrant],
        );
        $title->update(['is_published' => false]);

        $this->get($guestUrl)
            ->assertNotFound()
            ->assertDontSee($media->playback_url, false);
    }

    public function test_openapi_describes_opaque_signed_delivery(): void
    {
        $this->getJson('/api/openapi.json')
            ->assertOk()
            ->assertJsonPath('paths./api/v1/playback/{licensedMedia}.get.operationId', 'deliverMobilePlaybackSource')
            ->assertJsonPath('paths./api/v1/playback/{licensedMedia}.get.parameters.1.name', 'grant');
    }

    /** @return array{CatalogTitle, Episode, LicensedMedia} */
    private function createPlayableGraph(string $slug): array
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

        return [$title, $episode, $media];
    }
}
