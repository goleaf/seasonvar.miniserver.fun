<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\ContentAudience;
use App\Enums\PublicationStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleAlias;
use App\Models\CatalogTitleRating;
use App\Models\CatalogTitleUserState;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogTitleDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_title_detail_and_nested_releases_expose_only_safe_public_fields(): void
    {
        $title = CatalogTitle::factory()->create([
            'slug' => 'api-title',
            'source_url' => 'https://seasonvar.ru/private-title-source',
        ]);
        CatalogTitleAlias::query()->create([
            'catalog_title_id' => $title->id,
            'name' => 'API Alias',
            'name_hash' => hash('sha256', 'api alias'),
            'type' => 'alternate',
            'source' => 'private-provider',
        ]);
        CatalogTitleRating::query()->create([
            'catalog_title_id' => $title->id,
            'provider' => 'imdb',
            'rating' => 8.4,
            'votes' => 1200,
            'raw_value' => 'private-rating-source',
        ]);
        $genre = Genre::query()->create(['name' => 'Детектив', 'slug' => 'detektiv']);
        $title->genres()->attach($genre);

        $season = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'number' => 1,
            'source_url' => 'https://seasonvar.ru/private-season-source',
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 2,
            'source_url' => 'https://seasonvar.ru/private-episode-source',
        ]);
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'path' => 'licensed/private-video.mp4',
            'playback_url' => 'https://media.example.com/private-playback.m3u8',
            'source_url' => 'https://seasonvar.ru/private-media-source',
            'translation_name' => 'AniDub',
            'variant_name' => 'Оригинал',
            'variant_key' => 'original',
            'quality' => '1080p',
            'format' => 'm3u8',
            'duration_seconds' => 1440,
            'published_at' => now(),
        ]);

        $this->getJson('/api/v1/titles/api-title')
            ->assertOk()
            ->assertJsonPath('data.slug', 'api-title')
            ->assertJsonPath('data.aliases.0.name', 'API Alias')
            ->assertJsonPath('data.ratings.0.provider', 'imdb')
            ->assertJsonPath('data.taxonomies.genres.0.slug', 'detektiv')
            ->assertJsonPath('data.counts.seasons', 1)
            ->assertJsonPath('data.counts.episodes', 1)
            ->assertJsonPath('data.primary_action.episode_id', $episode->id)
            ->assertJsonMissingPath('data.user_state')
            ->assertJsonStructure(['data' => ['aliases', 'ratings', 'rating_summary', 'taxonomies', 'counts', 'primary_action', 'links']]);

        $this->getJson('/api/v1/titles/api-title/seasons')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $season->id)
            ->assertJsonPath('data.0.counts.available_episodes', 1);

        $response = $this->getJson("/api/v1/titles/api-title/seasons/{$season->id}/episodes")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $episode->id)
            ->assertJsonPath('data.0.media_profiles.0.id', $media->id)
            ->assertJsonPath('data.0.media_profiles.0.variant_key', 'original')
            ->assertJsonPath('data.0.media_profiles.0.duration_seconds', 1440);

        foreach ([
            'private-title-source',
            'private-season-source',
            'private-episode-source',
            'private-media-source',
            'private-playback',
            'licensed/private-video.mp4',
            'storage_disk',
            'health_status',
            'raw_value',
        ] as $privateValue) {
            $response->assertDontSee($privateValue, false);
        }

        $foreignTitle = CatalogTitle::factory()->create();
        $foreignSeason = Season::factory()->create(['catalog_title_id' => $foreignTitle->id]);

        $this->getJson("/api/v1/titles/api-title/seasons/{$foreignSeason->id}/episodes")
            ->assertNotFound();
    }

    public function test_release_visibility_and_authenticated_audience_follow_optional_token(): void
    {
        $title = CatalogTitle::factory()->create(['slug' => 'audience-title']);
        $publicSeason = $this->createWatchableRelease($title, ContentAudience::Public, 1);
        $authenticatedSeason = $this->createWatchableRelease($title, ContentAudience::Authenticated, 2);
        $this->createWatchableRelease($title, ContentAudience::Public, 3, [
            'publication_status' => PublicationStatus::Hidden,
        ]);
        $this->createWatchableRelease($title, ContentAudience::Public, 4, [
            'available_from' => now()->addDay(),
        ]);
        $deletedSeason = $this->createWatchableRelease($title, ContentAudience::Public, 5);
        $deletedSeason->delete();

        $this->getJson('/api/v1/titles/audience-title/seasons')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $publicSeason->id)
            ->assertJsonMissing(['id' => $authenticatedSeason->id]);

        $authenticatedTitle = CatalogTitle::factory()->create([
            'slug' => 'authenticated-title',
            'audience' => ContentAudience::Authenticated,
        ]);

        $this->getJson('/api/v1/titles/authenticated-title')->assertNotFound();

        $user = User::factory()->create();
        $token = $user->createToken('Android', ['mobile:read'], now()->addDay());

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/titles/audience-title/seasons')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $authenticatedSeason->id]);
        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/titles/authenticated-title')
            ->assertOk();
    }

    public function test_valid_token_adds_only_the_current_users_title_state(): void
    {
        $title = CatalogTitle::factory()->create(['slug' => 'state-title']);
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'in_watchlist' => true,
            'rating' => 9,
        ]);
        CatalogTitleUserState::query()->create([
            'user_id' => $otherUser->id,
            'catalog_title_id' => $title->id,
            'in_watchlist' => false,
            'rating' => 3,
        ]);

        $this->getJson('/api/v1/titles/state-title')
            ->assertOk()
            ->assertJsonMissingPath('data.user_state')
            ->assertJsonPath('data.rating_summary.rating_count', 2);

        $token = $user->createToken('iPhone', ['mobile:read'], now()->addDay());

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/titles/state-title')
            ->assertOk()
            ->assertJsonPath('data.user_state.in_watchlist', true)
            ->assertJsonPath('data.user_state.rating', 9)
            ->assertJsonPath('data.rating_summary.rating_count', 2)
            ->assertJsonMissing(['rating' => 3]);
    }

    public function test_openapi_describes_title_detail_and_nested_release_contracts(): void
    {
        $this->getJson('/api/openapi.json')
            ->assertOk()
            ->assertJsonPath('paths./api/v1/titles/{titleSlug}.get.operationId', 'getCatalogTitle')
            ->assertJsonPath('paths./api/v1/titles/{titleSlug}/seasons.get.operationId', 'getCatalogTitleSeasons')
            ->assertJsonPath('paths./api/v1/titles/{titleSlug}/seasons/{season}/episodes.get.operationId', 'getCatalogSeasonEpisodes')
            ->assertJsonPath('components.schemas.MediaProfile.required.0', 'id')
            ->assertJsonMissingPath('components.schemas.MediaProfile.properties.playback_url')
            ->assertJsonMissingPath('components.schemas.MediaProfile.properties.source_url');
    }

    public function test_title_detail_query_count_is_constant_as_release_count_grows(): void
    {
        $title = CatalogTitle::factory()->create(['slug' => 'detail-budget-title']);
        $this->createWatchableRelease($title, ContentAudience::Public, 1);
        $oneReleaseQueries = $this->captureQueries(
            fn () => $this->getJson('/api/v1/titles/detail-budget-title')->assertOk(),
        );

        foreach (range(2, 20) as $number) {
            $this->createWatchableRelease($title, ContentAudience::Public, $number);
        }

        $twentyReleaseQueries = $this->captureQueries(
            fn () => $this->getJson('/api/v1/titles/detail-budget-title')
                ->assertOk()
                ->assertJsonPath('data.counts.seasons', 20),
        );

        $this->assertLessThanOrEqual($oneReleaseQueries + 2, $twentyReleaseQueries);
    }

    /** @param array<string, mixed> $seasonAttributes */
    private function createWatchableRelease(
        CatalogTitle $title,
        ContentAudience $audience,
        int $number,
        array $seasonAttributes = [],
    ): Season {
        $season = Season::factory()->create(array_merge([
            'catalog_title_id' => $title->id,
            'number' => $number,
            'audience' => $audience,
        ], $seasonAttributes));
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 1,
            'audience' => $audience,
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'audience' => $audience,
            'published_at' => now(),
        ]);

        return $season;
    }

    private function captureQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $callback();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }
}
