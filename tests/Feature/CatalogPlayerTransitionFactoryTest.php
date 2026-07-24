<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\PlaybackPreferencesData;
use App\Enums\ContentAudience;
use App\Enums\ReleaseKind;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\CatalogPlayerTransitionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class CatalogPlayerTransitionFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_episode_page_contains_only_bounded_public_display_data(): void
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->create(['catalog_title_id' => $title->id]);
        $firstEpisode = $this->publishedEpisode($title, $season, 1);
        $secondEpisode = $this->publishedEpisode($title, $season, 2);
        $hiddenEpisode = $this->publishedEpisode($title, $season, 3);
        $hiddenEpisode->update(['publication_status' => 'hidden']);
        $authenticatedEpisode = $this->publishedEpisode($title, $season, 4);
        $authenticatedEpisode->update(['audience' => ContentAudience::Authenticated]);

        $page = app(CatalogPlayerTransitionFactory::class)->episodePage(
            $title,
            null,
            $season,
            1,
            $secondEpisode->id,
        )->toArray();
        $encoded = json_encode($page, JSON_THROW_ON_ERROR);

        self::assertSame(['status', 'season', 'episodes', 'pagination'], array_keys($page));
        self::assertSame($season->id, $page['season']['id']);
        self::assertSame([$firstEpisode->id, $secondEpisode->id], array_column($page['episodes'], 'id'));
        self::assertFalse($page['episodes'][0]['current']);
        self::assertTrue($page['episodes'][1]['current']);
        self::assertNotContains($hiddenEpisode->id, array_column($page['episodes'], 'id'));
        self::assertNotContains($authenticatedEpisode->id, array_column($page['episodes'], 'id'));
        self::assertLessThanOrEqual(24, count($page['episodes']));
        self::assertStringNotContainsString('source', $encoded);
        self::assertStringNotContainsString('playback_url', $encoded);
        self::assertStringNotContainsString('path', $encoded);
        self::assertStringNotContainsString('user_id', $encoded);
        self::assertStringNotContainsString('catalogTitle', $encoded);
    }

    public function test_ready_transition_uses_a_signed_viewer_bound_url_without_exposing_origin_or_user_identity(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create(['id' => 987_654]);
        $otherUser = User::factory()->create();
        $title = CatalogTitle::factory()->create(['title' => 'Тестовый сериал']);
        $season = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'number' => 1,
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 1,
            'title' => 'Начало',
        ]);
        $media = $this->publishedMedia($title, $season, $episode, [
            'playback_url' => 'https://data00-cdn.11cdn.org/private-origin.m3u8',
            'path' => 'https://data00-cdn.11cdn.org/private-origin.m3u8',
            'variant_name' => 'Студия А',
            'variant_key' => 'voiceover-studio-a',
            'quality' => '1080p',
            'format' => 'm3u8',
        ]);

        $transition = app(CatalogPlayerTransitionFactory::class)->prepare(
            $title,
            $user,
            $episode,
            $media->id,
            new PlaybackPreferencesData,
        )->toArray();
        $encoded = json_encode($transition, JSON_THROW_ON_ERROR);

        self::assertSame('ready', $transition['status']);
        self::assertSame($episode->id, $transition['selection']['episodeId']);
        self::assertSame($media->id, $transition['selection']['mediaId']);
        self::assertSame(0, $transition['progress']['sequence']);
        self::assertTrue($transition['progress']['enabled']);
        self::assertNotSame('', $transition['progress']['token']);
        self::assertTrue(URL::hasValidSignature(Request::create($transition['source']['url'])));
        self::assertStringNotContainsString('11cdn.org', $encoded);
        self::assertStringNotContainsString('private-origin', $encoded);
        self::assertStringNotContainsString((string) $user->id, $transition['contextKey']);

        $this->actingAs($user)
            ->get($transition['source']['url'])
            ->assertRedirect('https://data00-cdn.11cdn.org/private-origin.m3u8')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->actingAs($otherUser)
            ->get($transition['source']['url'])
            ->assertForbidden();
    }

    public function test_transition_fails_closed_for_invalid_hierarchy_and_unavailable_releases(): void
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->create(['catalog_title_id' => $title->id]);
        $episode = $this->publishedEpisode($title, $season, 1);
        $otherTitle = CatalogTitle::factory()->create();
        $otherSeason = Season::factory()->create(['catalog_title_id' => $otherTitle->id]);
        $otherEpisode = $this->publishedEpisode($otherTitle, $otherSeason, 1);
        $hiddenEpisode = $this->publishedEpisode($title, $season, 2);
        $hiddenEpisode->update(['publication_status' => 'hidden']);
        $futureEpisode = $this->publishedEpisode($title, $season, 3);
        $futureEpisode->update(['available_from' => now()->addMinute()]);
        $expiredEpisode = $this->publishedEpisode($title, $season, 4);
        $expiredEpisode->update(['available_until' => now()->subMinute()]);
        $authenticatedEpisode = $this->publishedEpisode($title, $season, 5);
        $authenticatedEpisode->update(['audience' => ContentAudience::Authenticated]);
        $failedEpisode = $this->publishedEpisode($title, $season, 6);
        $failedEpisode->licensedMedia()->update(['health_status' => 'unavailable']);
        $sourceLessEpisode = $this->publishedEpisode($title, $season, 7);
        $sourceLessEpisode->licensedMedia()->update(['path' => '', 'playback_url' => null]);
        $otherMedia = $otherEpisode->licensedMedia()->firstOrFail();
        $factory = app(CatalogPlayerTransitionFactory::class);
        $expected = [
            'status' => 'unavailable',
            'message' => __('catalog.player.transition_unavailable'),
        ];

        foreach ([
            [$otherEpisode, null],
            [$episode, $otherMedia->id],
            [$hiddenEpisode, null],
            [$futureEpisode, null],
            [$expiredEpisode, null],
            [$authenticatedEpisode, null],
            [$failedEpisode, null],
            [$sourceLessEpisode, null],
        ] as [$candidateEpisode, $mediaId]) {
            self::assertSame(
                $expected,
                $factory->prepare(
                    $title,
                    null,
                    $candidateEpisode,
                    $mediaId,
                    new PlaybackPreferencesData,
                )->toArray(),
            );
        }
    }

    public function test_preferred_translation_falls_back_without_mutating_preferences_and_explicit_media_wins(): void
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->create(['catalog_title_id' => $title->id]);
        $firstEpisode = Episode::factory()->create(['season_id' => $season->id, 'number' => 1]);
        $studioA = $this->publishedMedia($title, $season, $firstEpisode, [
            'variant_name' => 'Студия А',
            'variant_key' => 'voiceover-studio-a',
        ]);
        $studioB = $this->publishedMedia($title, $season, $firstEpisode, [
            'variant_name' => 'Студия Б',
            'variant_key' => 'voiceover-studio-b',
        ]);
        $secondEpisode = Episode::factory()->create(['season_id' => $season->id, 'number' => 2]);
        $fallback = $this->publishedMedia($title, $season, $secondEpisode, [
            'variant_name' => 'Студия Б',
            'variant_key' => 'voiceover-studio-b',
        ]);
        $preferences = new PlaybackPreferencesData(variant: 'voiceover-studio-a');
        $factory = app(CatalogPlayerTransitionFactory::class);

        $preferredTransition = $factory->prepare($title, null, $firstEpisode, null, $preferences)->toArray();
        $fallbackTransition = $factory->prepare($title, null, $secondEpisode, null, $preferences)->toArray();
        $explicitTransition = $factory->prepare($title, null, $firstEpisode, $studioB->id, $preferences)->toArray();

        self::assertSame($studioA->id, $preferredTransition['selection']['mediaId']);
        self::assertSame($fallback->id, $fallbackTransition['selection']['mediaId']);
        self::assertSame('preferred_translation_unavailable', $fallbackTransition['noticeCode']);
        self::assertSame('voiceover-studio-a', $preferences->variant);
        self::assertSame($studioB->id, $explicitTransition['selection']['mediaId']);
        self::assertNull($explicitTransition['noticeCode']);
    }

    public function test_transition_navigation_crosses_regular_seasons_and_guest_progress_stays_disabled(): void
    {
        app()->setLocale('ru');
        $title = CatalogTitle::factory()->create(['title' => 'Сериал']);
        $firstSeason = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'kind' => ReleaseKind::Regular,
            'number' => 1,
            'sort_order' => 1,
        ]);
        $secondSeason = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'kind' => ReleaseKind::Regular,
            'number' => 2,
            'sort_order' => 2,
        ]);
        $lastInFirstSeason = $this->publishedEpisode($title, $firstSeason, 2);
        $firstInSecondSeason = $this->publishedEpisode($title, $secondSeason, 1);

        $transition = app(CatalogPlayerTransitionFactory::class)->prepare(
            $title,
            null,
            $lastInFirstSeason,
            null,
            new PlaybackPreferencesData,
        )->toArray();

        self::assertSame($firstInSecondSeason->id, $transition['navigation']['next']['id']);
        self::assertSame('2 серия', $transition['labels']['episode']);
        self::assertSame([
            'season',
            'episode',
            'media',
            'variant',
            'quality',
            'format',
        ], array_keys($transition['selection']['query']));
        self::assertArrayNotHasKey('marker', $transition['selection']['query']);
        self::assertFalse($transition['progress']['enabled']);
        self::assertSame('', $transition['progress']['token']);
    }

    private function publishedEpisode(
        CatalogTitle $title,
        Season $season,
        int $number,
        ReleaseKind $kind = ReleaseKind::Regular,
    ): Episode {
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => $number,
            'kind' => $kind,
            'sort_order' => $number,
        ]);
        $this->publishedMedia($title, $season, $episode);

        return $episode;
    }

    /** @param array<string, mixed> $overrides */
    private function publishedMedia(
        CatalogTitle $title,
        Season $season,
        Episode $episode,
        array $overrides = [],
    ): LicensedMedia {
        return LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'storage_disk' => 'external_playlist',
            'path' => 'https://data00-cdn.11cdn.org/player-transition.m3u8',
            'playback_url' => 'https://data00-cdn.11cdn.org/player-transition.m3u8',
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'check_status' => 'available',
            'health_status' => 'active',
            'variant_type' => 'voiceover',
            'variant_name' => 'Студия',
            'variant_key' => 'voiceover-studio',
            'quality' => '1080p',
            'format' => 'm3u8',
            ...$overrides,
        ]);
    }
}
