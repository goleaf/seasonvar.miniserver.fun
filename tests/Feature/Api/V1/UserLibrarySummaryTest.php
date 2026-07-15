<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class UserLibrarySummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_library_summary_requires_read_access_and_is_owner_scoped_and_private(): void
    {
        $this->getJson('/api/v1/me/library/summary')
            ->assertUnauthorized()
            ->assertHeader('Cache-Control', 'no-store, private');

        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        [$ownerTitle, $ownerEpisode] = $this->createWatchableTitle('owner-summary-title');
        [$foreignTitle, $foreignEpisode] = $this->createWatchableTitle('foreign-summary-title');
        [$hiddenTitle, $hiddenEpisode] = $this->createWatchableTitle('hidden-summary-title');
        $hiddenTitle->update(['is_published' => false]);

        $this->createState($owner, $ownerTitle, true, 9);
        $this->createState($owner, $hiddenTitle, true, 8);
        $this->createState($foreign, $foreignTitle, true, 7);
        $lastWatchedAt = now()->subMinute()->startOfSecond();
        $this->createProgress($owner, $ownerTitle, $ownerEpisode, $lastWatchedAt);
        $this->createProgress($owner, $hiddenTitle, $hiddenEpisode, now());
        $this->createProgress($foreign, $foreignTitle, $foreignEpisode, now()->addMinute());

        Sanctum::actingAs($owner, ['mobile:read']);

        $response = $this->getJson('/api/v1/me/library/summary')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeaderMissing('ETag')
            ->assertJsonPath('data.watchlist_count', 1)
            ->assertJsonPath('data.ratings_count', 1)
            ->assertJsonPath('data.continue_watching_count', 1)
            ->assertJsonPath('data.history_count', 1)
            ->assertJsonPath('data.last_watched_at', $lastWatchedAt->toJSON())
            ->assertJsonPath('data.links.self', route('api.v1.me.library.summary'))
            ->assertJsonPath('data.links.watchlist', route('api.v1.me.watchlist.index'))
            ->assertJsonPath('data.links.ratings', route('api.v1.me.ratings.index'))
            ->assertJsonPath('data.links.continue_watching', route('api.v1.me.continue-watching.index'))
            ->assertJsonPath('data.links.history', route('api.v1.me.history.index'));

        foreach (['foreign-summary-title', 'hidden-summary-title', 'user_id', 'playback_url'] as $privateValue) {
            $response->assertDontSee($privateValue, false);
        }
    }

    public function test_library_summary_query_budget_stays_constant_as_private_data_grows(): void
    {
        $user = User::factory()->create();
        [$title, $episode] = $this->createWatchableTitle('summary-budget-1');
        $this->createState($user, $title, true, 8);
        $this->createProgress($user, $title, $episode, now());
        Sanctum::actingAs($user, ['mobile:read']);

        $oneItemQueries = $this->captureQueries(
            fn () => $this->getJson('/api/v1/me/library/summary')->assertOk(),
        );

        foreach (range(2, 12) as $index) {
            [$nextTitle, $nextEpisode] = $this->createWatchableTitle("summary-budget-{$index}");
            $this->createState($user, $nextTitle, true, ($index % 10) + 1);
            $this->createProgress($user, $nextTitle, $nextEpisode, now()->subMinutes($index));
        }

        $twelveItemQueries = $this->captureQueries(
            fn () => $this->getJson('/api/v1/me/library/summary')
                ->assertOk()
                ->assertJsonPath('data.watchlist_count', 12)
                ->assertJsonPath('data.history_count', 12),
        );

        $this->assertLessThanOrEqual($oneItemQueries + 1, $twelveItemQueries);
    }

    public function test_library_query_indexes_have_the_expected_columns(): void
    {
        $expected = [
            'catalog_user_state_watchlist_order_idx' => ['user_id', 'in_watchlist', 'updated_at', 'id'],
            'catalog_user_state_updated_order_idx' => ['user_id', 'updated_at', 'id'],
            'catalog_user_state_rating_order_idx' => ['user_id', 'rating', 'updated_at', 'id'],
        ];

        $indexes = collect(Schema::getIndexes('catalog_title_user_states'))->keyBy('name');

        foreach ($expected as $name => $columns) {
            $this->assertTrue($indexes->has($name), "Missing library index: {$name}");
            $this->assertSame($columns, $indexes->get($name)['columns']);
        }
    }

    public function test_openapi_describes_library_summary_and_filters(): void
    {
        $document = $this->getJson('/api/openapi.json')->assertOk();

        $document
            ->assertJsonPath('paths./api/v1/me/library/summary.get.operationId', 'getMobileLibrarySummary')
            ->assertJsonPath('paths./api/v1/me/library/summary.get.security.0.bearerAuth', [])
            ->assertJsonPath('components.schemas.UserLibrarySummary.properties.watchlist_count.type', 'integer')
            ->assertJsonPath('components.parameters.LibraryQuery.name', 'q')
            ->assertJsonPath('components.parameters.LibraryType.schema.enum.2', 'anime')
            ->assertJsonPath('components.parameters.LibraryDirection.schema.enum.1', 'desc')
            ->assertJsonPath('paths./api/v1/me/watchlist.get.parameters.0.$ref', '#/components/parameters/LibraryQuery')
            ->assertJsonPath('paths./api/v1/me/ratings.get.parameters.3.$ref', '#/components/parameters/LibraryRatingSort')
            ->assertJsonPath('paths./api/v1/me/library/summary.get.responses.429.$ref', '#/components/responses/TooManyRequests');
    }

    /** @return array{CatalogTitle, Episode} */
    private function createWatchableTitle(string $slug): array
    {
        $title = CatalogTitle::factory()->create(['slug' => $slug]);
        $season = Season::factory()->for($title, 'catalogTitle')->create(['number' => 1]);
        $episode = Episode::factory()->for($season)->create(['number' => 1]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        return [$title, $episode];
    }

    private function createState(User $user, CatalogTitle $title, bool $watchlist, ?int $rating): void
    {
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'in_watchlist' => $watchlist,
            'rating' => $rating,
        ]);
    }

    private function createProgress(User $user, CatalogTitle $title, Episode $episode, mixed $watchedAt): void
    {
        EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'episode_id' => $episode->id,
            'position_seconds' => 120,
            'duration_seconds' => 600,
            'progress_percent' => 20,
            'first_started_at' => $watchedAt,
            'last_watched_at' => $watchedAt,
        ]);
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
