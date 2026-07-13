<?php

namespace Tests\Feature;

use App\DTOs\PlaybackPreferencesData;
use App\Enums\ContentAudience;
use App\Enums\PlaybackAvailability;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\CatalogEntitlementService;
use App\Services\Catalog\CatalogPlaybackSourceResolver;
use App\Services\Media\ExternalPlaylistImporter;
use App\Services\Media\PlaybackSourceUrlGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_responses_include_security_headers(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()')
            ->assertHeader('X-Permitted-Cross-Domain-Policies', 'none');
    }

    public function test_local_filesystem_serving_routes_are_disabled_by_default(): void
    {
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));
    }

    public function test_external_playlist_urls_must_not_target_local_or_private_hosts(): void
    {
        config(['security.external_playlist_enforce_public_dns' => true]);
        $importer = app(ExternalPlaylistImporter::class);

        foreach (['http://localhost/list.m3u', 'http://127.0.0.1/list.m3u', 'http://10.0.0.5/list.m3u'] as $url) {
            try {
                $importer->safeExternalUrl($url);
                $this->fail("URL [{$url}] was not blocked.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Этот хост заблокирован.', $exception->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $importer->safeExternalUrl('https://user:secret@example.com/list.m3u');
    }

    public function test_playback_source_requires_a_valid_signature_bound_to_the_current_viewer(): void
    {
        $media = $this->playableMedia();

        $this->get(route('playback.source', ['licensedMedia' => $media, 'viewer' => 0]))
            ->assertForbidden();

        $expiredUrl = URL::temporarySignedRoute('playback.source', now()->subSecond(), [
            'licensedMedia' => $media->id,
            'viewer' => 0,
        ]);
        $this->get($expiredUrl)->assertForbidden();

        $guestUrl = URL::temporarySignedRoute('playback.source', now()->addMinutes(5), [
            'licensedMedia' => $media->id,
            'viewer' => 0,
        ]);

        $this->get($guestUrl)
            ->assertRedirect('https://data00-cdn.11cdn.org/video.m3u8')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->actingAs(User::factory()->create())
            ->get($guestUrl)
            ->assertForbidden();
    }

    public function test_access_status_has_structured_current_and_future_entitlement_denials(): void
    {
        $this->assertSame([
            'ready',
            'authentication_required',
            'plan_required',
            'region_blocked',
            'profile_restricted',
            'concurrency_exceeded',
            'not_yet_published',
            'expired',
            'temporarily_unavailable',
            'not_found',
        ], array_column(PlaybackAvailability::cases(), 'value'));
    }

    public function test_entitlement_service_is_the_structured_boundary_for_queries_and_loaded_releases(): void
    {
        $user = User::factory()->create();
        $public = CatalogTitle::factory()->create();
        $authenticated = CatalogTitle::factory()->create([
            'audience' => ContentAudience::Authenticated,
        ]);
        $future = CatalogTitle::factory()->create([
            'available_from' => now()->addMinute(),
        ]);
        $expired = CatalogTitle::factory()->create([
            'available_until' => now()->subMinute(),
        ]);
        $hidden = CatalogTitle::factory()->create([
            'publication_status' => 'hidden',
        ]);
        $entitlements = app(CatalogEntitlementService::class);

        $this->assertTrue($entitlements->decide(null, $public)->isAllowed());
        $guestDecision = $entitlements->decide(null, $authenticated);
        $this->assertSame(
            PlaybackAvailability::AuthenticationRequired,
            $guestDecision->status,
        );
        $this->assertSame('Для просмотра необходимо войти.', $guestDecision->message);
        $this->assertTrue($entitlements->decide($user, $authenticated)->isAllowed());
        $this->assertSame(PlaybackAvailability::NotYetPublished, $entitlements->decide($user, $future)->status);
        $this->assertSame(PlaybackAvailability::Expired, $entitlements->decide($user, $expired)->status);
        $this->assertSame(PlaybackAvailability::NotFound, $entitlements->decide($user, $hidden)->status);

        $this->assertEqualsCanonicalizing(
            [$public->id],
            CatalogTitle::query()->availableTo(null)->pluck('id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$public->id, $authenticated->id],
            CatalogTitle::query()->availableTo($user)->pluck('id')->all(),
        );

        $this->actingAs($user)
            ->get(route('titles.show', $hidden))
            ->assertNotFound();
    }

    public function test_playback_source_rechecks_parent_and_media_availability_on_direct_access(): void
    {
        $media = $this->playableMedia();
        $url = fn (): string => URL::temporarySignedRoute('playback.source', now()->addMinutes(5), [
            'licensedMedia' => $media->id,
            'viewer' => 0,
        ]);

        $media->catalogTitle()->update(['audience' => ContentAudience::Authenticated]);
        $this->get($url())->assertUnauthorized()->assertSeeText('Для просмотра необходимо войти.');

        $media->catalogTitle()->update([
            'audience' => ContentAudience::Public,
            'available_until' => now()->subMinute(),
        ]);
        $this->get($url())->assertStatus(410)->assertSeeText('Срок доступности видео истёк.');

        $media->catalogTitle()->update(['available_until' => null]);
        $media->update(['published_at' => now()->addMinute()]);
        $this->get($url())->assertStatus(425)->assertSeeText('Видео ещё не опубликовано.');

        $media->update([
            'published_at' => now()->subMinute(),
            'status' => 'unavailable',
            'check_status' => 'unavailable',
            'health_status' => 'unavailable',
        ]);
        $this->get($url())->assertServiceUnavailable()->assertSeeText('Видео временно недоступно.');
    }

    public function test_playback_url_guard_rejects_credentials_private_networks_and_unlisted_hosts(): void
    {
        config()->set('playback.allowed_hosts', ['11cdn.org', '127.0.0.1', '169.254.169.254']);
        config()->set('playback.enforce_public_dns', true);
        $guard = app(PlaybackSourceUrlGuard::class);

        $this->assertNull($guard->safeExternalUrl('https://127.0.0.1/video.m3u8'));
        $this->assertNull($guard->safeExternalUrl('https://169.254.169.254/latest/meta-data'));
        $this->assertNull($guard->safeExternalUrl('https://user:secret@data00-cdn.11cdn.org/video.m3u8'));
        $this->assertNull($guard->safeExternalUrl('http://data00-cdn.11cdn.org/video.m3u8'));
        $this->assertNull($guard->safeExternalUrl('https://evil11cdn.org/video.m3u8'));
    }

    public function test_title_level_playback_resolution_does_not_lazy_load_episode_relation(): void
    {
        Model::preventLazyLoading();

        $title = CatalogTitle::factory()->create();
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => null,
            'episode_id' => null,
            'storage_disk' => 'seasonvar_parsed',
            'path' => 'https://data00-cdn.11cdn.org/title-level.m3u8',
            'playback_url' => 'https://data00-cdn.11cdn.org/title-level.m3u8',
            'format' => 'm3u8',
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'check_status' => 'available',
        ]);
        $newerMedia = LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => null,
            'episode_id' => null,
            'storage_disk' => 'seasonvar_parsed',
            'path' => 'https://data00-cdn.11cdn.org/title-level-newer.m3u8',
            'playback_url' => 'https://data00-cdn.11cdn.org/title-level-newer.m3u8',
            'format' => 'm3u8',
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'check_status' => 'available',
        ]);

        $resolved = app(CatalogPlaybackSourceResolver::class)->resolve(
            $title,
            null,
            null,
            null,
            new PlaybackPreferencesData,
        );

        $this->assertSame(PlaybackAvailability::Ready, $resolved->status);
        $this->assertStringContainsString('/playback/'.$newerMedia->id.'?', (string) $resolved->url);
    }

    public function test_playback_resolution_prefers_matching_available_sources_and_never_crosses_episode_boundaries(): void
    {
        $failedPreferred = $this->playableMedia();
        $failedPreferred->update([
            'quality' => '720p',
            'translation_name' => 'Русский',
            'check_status' => 'unavailable',
            'health_status' => 'unavailable',
        ]);
        $fallback = LicensedMedia::factory()->create([
            'catalog_title_id' => $failedPreferred->catalog_title_id,
            'season_id' => $failedPreferred->season_id,
            'episode_id' => $failedPreferred->episode_id,
            'storage_disk' => 'external_playlist',
            'path' => 'https://data00-cdn.11cdn.org/fallback.m3u8',
            'playback_url' => 'https://data00-cdn.11cdn.org/fallback.m3u8',
            'quality' => '1080p',
            'translation_name' => 'English',
            'format' => 'm3u8',
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'check_status' => 'available',
        ]);
        $matching = LicensedMedia::factory()->create([
            'catalog_title_id' => $failedPreferred->catalog_title_id,
            'season_id' => $failedPreferred->season_id,
            'episode_id' => $failedPreferred->episode_id,
            'storage_disk' => 'seasonvar_parsed',
            'path' => 'https://data00-cdn.11cdn.org/preferred.m3u8',
            'playback_url' => 'https://data00-cdn.11cdn.org/preferred.m3u8',
            'quality' => '720p',
            'translation_name' => 'Русский',
            'format' => 'm3u8',
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'check_status' => 'available',
        ]);
        $episode = Episode::query()->findOrFail($failedPreferred->episode_id);
        $title = CatalogTitle::query()->findOrFail($failedPreferred->catalog_title_id);
        $resolver = app(CatalogPlaybackSourceResolver::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $resolved = $resolver->resolve(
            $title,
            null,
            $episode,
            null,
            new PlaybackPreferencesData(audioLanguage: 'Русский', quality: '720p'),
        );
        $resolutionQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(PlaybackAvailability::Ready, $resolved->status);
        $this->assertLessThanOrEqual(2, $resolutionQueryCount);
        $this->assertStringContainsString('/playback/'.$matching->id.'?', (string) $resolved->url);
        $this->assertStringNotContainsString('/playback/'.$fallback->id.'?', (string) $resolved->url);

        $matching->update([
            'health_status' => 'degraded',
            'check_status' => 'check_failed',
            'last_http_status' => 503,
        ]);
        $degraded = $resolver->resolve(
            $title,
            null,
            $episode,
            $matching->id,
            new PlaybackPreferencesData,
        );

        $this->assertSame(PlaybackAvailability::Ready, $degraded->status);

        $blocked = $resolver->resolve(
            $title,
            null,
            $episode,
            $failedPreferred->id,
            new PlaybackPreferencesData,
        );

        $this->assertSame(PlaybackAvailability::TemporarilyUnavailable, $blocked->status);

        $otherEpisodeMedia = $this->playableMedia();
        $crossEpisode = $resolver->resolve(
            $title,
            null,
            $episode,
            $otherEpisodeMedia->id,
            new PlaybackPreferencesData,
        );

        $this->assertSame(PlaybackAvailability::NotFound, $crossEpisode->status);

        $mismatchedSeason = Season::factory()->for(CatalogTitle::factory()->create())->create();
        $mismatchedHierarchy = LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $mismatchedSeason->id,
            'episode_id' => $episode->id,
            'storage_disk' => 'seasonvar_parsed',
            'path' => 'https://data00-cdn.11cdn.org/mismatched.m3u8',
            'playback_url' => 'https://data00-cdn.11cdn.org/mismatched.m3u8',
            'format' => 'm3u8',
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'check_status' => 'available',
        ]);

        $this->assertSame(
            PlaybackAvailability::NotFound,
            $resolver->resolve(
                $title,
                null,
                $episode,
                $mismatchedHierarchy->id,
                new PlaybackPreferencesData,
            )->status,
        );
    }

    private function playableMedia(): LicensedMedia
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->for($title)->create();
        $episode = Episode::factory()->for($season)->create();

        return LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'storage_disk' => 'seasonvar_parsed',
            'path' => 'https://data00-cdn.11cdn.org/video.m3u8',
            'playback_url' => 'https://data00-cdn.11cdn.org/video.m3u8',
            'format' => 'm3u8',
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'check_status' => 'available',
        ]);
    }
}
