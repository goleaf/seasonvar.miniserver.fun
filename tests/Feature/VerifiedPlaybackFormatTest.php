<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\PlaybackPreferencesData;
use App\Enums\PlaybackAvailability;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Catalog\CatalogPlaybackSourceResolver;
use App\Services\Catalog\CatalogTitlePlaybackQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VerifiedPlaybackFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_established_mp4_remains_playable_before_an_availability_check(): void
    {
        [$title, $episode] = $this->playbackContext();
        $media = $this->media($title, $episode, [
            'format' => 'mp4',
            'check_status' => 'not_checked',
            'last_successful_check_at' => null,
        ]);

        $this->assertTrue(
            LicensedMedia::query()->withoutKnownFailures()->whereKey($media->id)->exists(),
        );
        $this->assertTrue(
            app(CatalogTitlePlaybackQuery::class)
                ->availableMedia($title, null)
                ->whereKey($media->id)
                ->exists(),
        );

        $resolved = app(CatalogPlaybackSourceResolver::class)->resolve(
            $title,
            null,
            $episode,
            $media->id,
            new PlaybackPreferencesData,
        );

        $this->assertSame(PlaybackAvailability::Ready, $resolved->status);
        $this->assertSame('mp4', $resolved->format);
    }

    public function test_unverified_new_formats_are_excluded_before_the_candidate_limit_and_fail_closed_when_requested(): void
    {
        [$title, $episode] = $this->playbackContext();

        LicensedMedia::factory()->count(100)->create([
            'catalog_title_id' => $title->id,
            'season_id' => $episode->season_id,
            'episode_id' => $episode->id,
            'storage_disk' => 'external_playlist',
            'path' => 'https://data00-cdn.11cdn.org/unverified.m3u8',
            'playback_url' => 'https://data00-cdn.11cdn.org/unverified.m3u8',
            'format' => 'm3u8',
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'check_status' => 'not_checked',
            'health_status' => 'active',
            'last_successful_check_at' => null,
        ]);
        $unverified = LicensedMedia::query()->oldest('id')->firstOrFail();
        $fallback = $this->media($title, $episode, [
            'format' => 'mp4',
            'quality' => '720p',
            'check_status' => 'not_checked',
        ]);
        $resolver = app(CatalogPlaybackSourceResolver::class);

        $this->assertFalse(
            LicensedMedia::query()->withoutKnownFailures()->whereKey($unverified->id)->exists(),
        );
        $this->assertFalse(
            app(CatalogTitlePlaybackQuery::class)
                ->availableMedia($title, null)
                ->whereKey($unverified->id)
                ->exists(),
        );

        $automatic = $resolver->resolve(
            $title,
            null,
            $episode,
            null,
            new PlaybackPreferencesData(format: 'm3u8'),
        );
        $requested = $resolver->resolve(
            $title,
            null,
            $episode,
            $unverified->id,
            new PlaybackPreferencesData,
        );

        $this->assertSame(PlaybackAvailability::Ready, $automatic->status);
        $this->assertSame($fallback->id, $automatic->mediaId);
        $this->assertSame(PlaybackAvailability::TemporarilyUnavailable, $requested->status);
        $this->assertSame(
            503,
            $resolver->response($unverified->fresh(), null)->getStatusCode(),
        );
    }

    public function test_successfully_checked_new_format_is_playable(): void
    {
        [$title, $episode] = $this->playbackContext();
        $media = $this->media($title, $episode, [
            'format' => 'm3u8',
            'path' => 'https://data00-cdn.11cdn.org/verified.m3u8',
            'playback_url' => 'https://data00-cdn.11cdn.org/verified.m3u8',
            'check_status' => 'available',
            'last_successful_check_at' => now()->subMinute(),
        ]);
        $resolver = app(CatalogPlaybackSourceResolver::class);

        $this->assertTrue(
            LicensedMedia::query()->withoutKnownFailures()->whereKey($media->id)->exists(),
        );
        $this->assertSame(
            PlaybackAvailability::Ready,
            $resolver->resolve(
                $title,
                null,
                $episode,
                $media->id,
                new PlaybackPreferencesData,
            )->status,
        );
        $this->assertSame(302, $resolver->response($media->fresh(), null)->getStatusCode());
    }

    public function test_degraded_new_format_requires_at_least_one_previous_success(): void
    {
        [$title, $episode] = $this->playbackContext();
        $confirmed = $this->media($title, $episode, [
            'format' => 'm3u8',
            'path' => 'https://data00-cdn.11cdn.org/confirmed-degraded.m3u8',
            'playback_url' => 'https://data00-cdn.11cdn.org/confirmed-degraded.m3u8',
            'check_status' => 'check_failed',
            'health_status' => 'degraded',
            'last_successful_check_at' => now()->subHour(),
        ]);
        $neverConfirmed = $this->media($title, $episode, [
            'format' => 'webm',
            'path' => 'https://data00-cdn.11cdn.org/never-confirmed.webm',
            'playback_url' => 'https://data00-cdn.11cdn.org/never-confirmed.webm',
            'check_status' => 'check_failed',
            'health_status' => 'degraded',
            'last_successful_check_at' => null,
        ]);
        $resolver = app(CatalogPlaybackSourceResolver::class);

        $this->assertTrue(
            LicensedMedia::query()->withoutKnownFailures()->whereKey($confirmed->id)->exists(),
        );
        $this->assertFalse(
            LicensedMedia::query()->withoutKnownFailures()->whereKey($neverConfirmed->id)->exists(),
        );
        $this->assertSame(
            PlaybackAvailability::Ready,
            $resolver->resolve(
                $title,
                null,
                $episode,
                $confirmed->id,
                new PlaybackPreferencesData,
            )->status,
        );
        $this->assertSame(
            PlaybackAvailability::TemporarilyUnavailable,
            $resolver->resolve(
                $title,
                null,
                $episode,
                $neverConfirmed->id,
                new PlaybackPreferencesData,
            )->status,
        );
    }

    /** @return array{CatalogTitle, Episode} */
    private function playbackContext(): array
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->for($title)->create();
        $episode = Episode::factory()->for($season)->create();

        return [$title, Episode::query()->findOrFail($episode->id)];
    }

    /** @param array<string, mixed> $attributes */
    private function media(CatalogTitle $title, Episode $episode, array $attributes = []): LicensedMedia
    {
        return LicensedMedia::factory()->create(array_merge([
            'catalog_title_id' => $title->id,
            'season_id' => $episode->season_id,
            'episode_id' => $episode->id,
            'storage_disk' => 'external_playlist',
            'path' => 'https://data00-cdn.11cdn.org/verified-format.mp4',
            'playback_url' => 'https://data00-cdn.11cdn.org/verified-format.mp4',
            'format' => 'mp4',
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'check_status' => 'not_checked',
            'health_status' => 'active',
            'last_successful_check_at' => null,
        ], $attributes));
    }
}
