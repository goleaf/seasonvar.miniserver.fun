<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class UserLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_libraries_require_a_valid_token_and_disable_shared_cache(): void
    {
        foreach (['/api/v1/me/watchlist', '/api/v1/me/ratings'] as $endpoint) {
            $this->getJson($endpoint)
                ->assertUnauthorized()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeaderMissing('ETag');
            $this->withToken('invalid-mobile-token')
                ->getJson($endpoint)
                ->assertUnauthorized()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeaderMissing('ETag');
        }

        Sanctum::actingAs(User::factory()->unverified()->create(), ['mobile:read']);

        foreach (['/api/v1/me/watchlist', '/api/v1/me/ratings'] as $endpoint) {
            $this->withHeader('Authorization', '')
                ->getJson($endpoint)
                ->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeaderMissing('ETag');
        }
    }

    public function test_watchlist_is_paginated_sorted_and_owner_scoped(): void
    {
        $user = User::factory()->unverified()->create();
        $otherUser = User::factory()->create();
        $baseTime = now()->subMonth();

        foreach (range(1, 25) as $index) {
            $title = CatalogTitle::factory()->create([
                'slug' => "library-title-{$index}",
                'source_url' => "https://seasonvar.ru/private-library-{$index}",
            ]);
            $state = CatalogTitleUserState::query()->create([
                'user_id' => $user->id,
                'catalog_title_id' => $title->id,
                'in_watchlist' => true,
                'rating' => ($index % 10) + 1,
            ]);
            $state->forceFill(['updated_at' => $baseTime->copy()->addMinutes($index)])->saveQuietly();
        }

        $foreignTitle = CatalogTitle::factory()->create(['slug' => 'foreign-library-title']);
        CatalogTitleUserState::query()->create([
            'user_id' => $otherUser->id,
            'catalog_title_id' => $foreignTitle->id,
            'in_watchlist' => true,
            'rating' => 10,
        ]);
        $this->createInaccessibleState($user, 'hidden-library-title', false);
        $deletedTitle = $this->createInaccessibleState($user, 'deleted-library-title');
        $deletedTitle->delete();

        Sanctum::actingAs($user, ['mobile:read']);

        $response = $this->getJson('/api/v1/me/watchlist?per_page=10&page=2')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('data.0.title.slug', 'library-title-15')
            ->assertJsonPath('data.0.state.in_watchlist', true)
            ->assertJsonPath('data.0.state.rating', 6);

        foreach (['user_id', 'source_url', 'seasonvar.ru/private-library', 'foreign-library-title', 'hidden-library-title', 'deleted-library-title'] as $privateValue) {
            $response->assertDontSee($privateValue, false);
        }
    }

    public function test_ratings_returns_only_visible_owner_ratings_and_validates_pagination(): void
    {
        $user = User::factory()->unverified()->create();
        $otherUser = User::factory()->create();
        $ratedTitle = CatalogTitle::factory()->create(['slug' => 'rated-title']);
        $watchlistOnlyTitle = CatalogTitle::factory()->create(['slug' => 'watchlist-only-title']);
        $foreignTitle = CatalogTitle::factory()->create(['slug' => 'foreign-rated-title']);

        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $ratedTitle->id,
            'in_watchlist' => false,
            'rating' => 9,
        ]);
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $watchlistOnlyTitle->id,
            'in_watchlist' => true,
            'rating' => null,
        ]);
        CatalogTitleUserState::query()->create([
            'user_id' => $otherUser->id,
            'catalog_title_id' => $foreignTitle->id,
            'in_watchlist' => false,
            'rating' => 2,
        ]);

        Sanctum::actingAs($user, ['mobile:read']);

        $this->getJson('/api/v1/me/ratings?per_page=50')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title.slug', 'rated-title')
            ->assertJsonPath('data.0.state.in_watchlist', false)
            ->assertJsonPath('data.0.state.rating', 9)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissing(['slug' => 'watchlist-only-title'])
            ->assertJsonMissing(['slug' => 'foreign-rated-title']);

        foreach ([0, 51] as $perPage) {
            $this->getJson("/api/v1/me/ratings?per_page={$perPage}")
                ->assertUnprocessable()
                ->assertJsonValidationErrors('per_page');
        }
    }

    public function test_watchlist_query_count_stays_constant_as_the_page_grows(): void
    {
        $user = User::factory()->create();
        $this->createLibraryState($user, 1);
        Sanctum::actingAs($user, ['mobile:read']);

        $oneItemQueries = $this->captureQueries(
            fn () => $this->getJson('/api/v1/me/watchlist?per_page=20')->assertOk(),
        );

        foreach (range(2, 20) as $index) {
            $this->createLibraryState($user, $index);
        }

        $twentyItemQueries = $this->captureQueries(
            fn () => $this->getJson('/api/v1/me/watchlist?per_page=20')
                ->assertOk()
                ->assertJsonCount(20, 'data'),
        );

        $this->assertLessThanOrEqual($oneItemQueries + 1, $twentyItemQueries);
    }

    public function test_openapi_describes_mobile_watchlist_and_ratings_libraries(): void
    {
        $this->getJson('/api/openapi.json')
            ->assertOk()
            ->assertJsonPath('paths./api/v1/me/watchlist.get.operationId', 'getMobileWatchlist')
            ->assertJsonPath('paths./api/v1/me/ratings.get.operationId', 'getMobileRatings')
            ->assertJsonPath('components.schemas.UserLibraryItem.required.0', 'title')
            ->assertJsonPath('components.schemas.UserLibraryPageResponse.required.2', 'meta');
    }

    private function createInaccessibleState(
        User $user,
        string $slug,
        bool $published = true,
    ): CatalogTitle {
        $title = CatalogTitle::factory()->create([
            'slug' => $slug,
            'is_published' => $published,
        ]);
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'in_watchlist' => true,
            'rating' => 7,
        ]);

        return $title;
    }

    private function createLibraryState(User $user, int $index): void
    {
        $title = CatalogTitle::factory()->create(['slug' => "query-library-title-{$index}"]);
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'in_watchlist' => true,
            'rating' => $index % 10 + 1,
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
