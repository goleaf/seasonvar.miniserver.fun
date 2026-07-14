<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class UserTitleStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_title_state_requires_a_valid_read_token_and_never_uses_shared_cache(): void
    {
        $title = CatalogTitle::factory()->create(['slug' => 'private-state']);

        $this->getJson("/api/v1/me/titles/{$title->slug}/state")
            ->assertUnauthorized()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeaderMissing('ETag');
        $this->withToken('invalid-mobile-token')
            ->getJson("/api/v1/me/titles/{$title->slug}/state")
            ->assertUnauthorized()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeaderMissing('ETag');

        Sanctum::actingAs(User::factory()->unverified()->create(), ['mobile:read']);

        $response = $this->withHeader('Authorization', '')
            ->getJson("/api/v1/me/titles/{$title->slug}/state")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeaderMissing('ETag');

        foreach (['email', 'password', 'token', 'user_id', 'source_url'] as $privateField) {
            $response->assertDontSee($privateField, false);
        }
    }

    public function test_unverified_user_reads_existing_state_but_cannot_mutate_it(): void
    {
        $user = User::factory()->unverified()->create();
        $title = CatalogTitle::factory()->create(['slug' => 'unverified-state']);
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'in_watchlist' => true,
            'rating' => 8,
        ]);

        Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

        $this->getJson("/api/v1/me/titles/{$title->slug}/state")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.in_watchlist', true)
            ->assertJsonPath('data.rating', 8)
            ->assertJsonPath('data.aggregate.watchlist_count', 1)
            ->assertJsonPath('data.aggregate.rating_count', 1)
            ->assertJsonPath('data.rating_range.minimum', 1)
            ->assertJsonPath('data.rating_range.maximum', 10);

        $this->deleteJson("/api/v1/me/watchlist/{$title->slug}")
            ->assertForbidden()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('code', 'email_not_verified');

        $this->assertTrue($user->catalogTitleStates()->sole()->in_watchlist);
    }

    public function test_unverified_user_cannot_use_any_title_state_mutation(): void
    {
        $user = User::factory()->unverified()->create();
        $title = CatalogTitle::factory()->create(['slug' => 'unverified-mutations']);
        Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

        $responses = [
            $this->putJson("/api/v1/me/watchlist/{$title->slug}"),
            $this->deleteJson("/api/v1/me/watchlist/{$title->slug}"),
            $this->putJson("/api/v1/me/ratings/{$title->slug}", ['rating' => 7]),
            $this->deleteJson("/api/v1/me/ratings/{$title->slug}"),
        ];

        foreach ($responses as $response) {
            $response
                ->assertForbidden()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeaderMissing('ETag')
                ->assertJsonPath('code', 'email_not_verified');
        }

        $this->assertFalse(CatalogTitleUserState::query()->whereBelongsTo($user)->exists());
    }

    public function test_title_state_mutations_require_a_valid_write_token(): void
    {
        $title = CatalogTitle::factory()->create(['slug' => 'write-token-state']);
        $requests = [
            ['PUT', "/api/v1/me/watchlist/{$title->slug}", []],
            ['DELETE', "/api/v1/me/watchlist/{$title->slug}", []],
            ['PUT', "/api/v1/me/ratings/{$title->slug}", ['rating' => 7]],
            ['DELETE', "/api/v1/me/ratings/{$title->slug}", []],
        ];

        foreach ($requests as [$method, $endpoint, $data]) {
            $this->json($method, $endpoint, $data)
                ->assertUnauthorized()
                ->assertHeader('Cache-Control', 'no-store, private');
        }

        foreach ($requests as [$method, $endpoint, $data]) {
            $this->withToken('invalid-mobile-token')
                ->json($method, $endpoint, $data)
                ->assertUnauthorized();
        }

        Sanctum::actingAs(User::factory()->create(), ['mobile:read']);

        foreach ($requests as [$method, $endpoint, $data]) {
            $this->withHeader('Authorization', '')
                ->json($method, $endpoint, $data)
                ->assertForbidden()
                ->assertJsonPath('code', 'forbidden');
        }
    }

    public function test_verified_user_sets_and_clears_watchlist_idempotently(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create(['slug' => 'watchlist-state']);
        Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

        foreach (range(1, 2) as $attempt) {
            $this->putJson("/api/v1/me/watchlist/{$title->slug}")
                ->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertJsonPath('data.in_watchlist', true);
        }

        $this->assertSame(1, CatalogTitleUserState::query()->whereBelongsTo($user)->count());

        foreach (range(1, 2) as $attempt) {
            $this->deleteJson("/api/v1/me/watchlist/{$title->slug}")
                ->assertOk()
                ->assertJsonPath('data.in_watchlist', false);
        }

        $this->assertSame(1, CatalogTitleUserState::query()->whereBelongsTo($user)->count());
        $this->assertFalse(CatalogTitleUserState::query()->whereBelongsTo($user)->sole()->in_watchlist);
    }

    public function test_verified_user_sets_valid_rating_and_can_clear_it(): void
    {
        $user = User::factory()->create();
        $title = CatalogTitle::factory()->create(['slug' => 'rating-state']);
        Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

        foreach ([1, 10] as $rating) {
            $this->putJson("/api/v1/me/ratings/{$title->slug}", ['rating' => $rating])
                ->assertOk()
                ->assertJsonPath('data.rating', $rating);
        }

        foreach ([0, 11, 2.5, 'eight'] as $rating) {
            $this->putJson("/api/v1/me/ratings/{$title->slug}", ['rating' => $rating])
                ->assertUnprocessable()
                ->assertJsonPath('code', 'validation_failed')
                ->assertJsonValidationErrors('rating');
        }

        $this->deleteJson("/api/v1/me/ratings/{$title->slug}")
            ->assertOk()
            ->assertJsonPath('data.rating', null);
    }

    public function test_state_is_owner_scoped_and_hidden_titles_are_not_exposed(): void
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $title = CatalogTitle::factory()->create(['slug' => 'owner-state']);
        $hiddenTitle = CatalogTitle::factory()->create([
            'slug' => 'hidden-state',
            'is_published' => false,
        ]);
        CatalogTitleUserState::query()->create([
            'user_id' => $owner->id,
            'catalog_title_id' => $title->id,
            'in_watchlist' => true,
            'rating' => 3,
        ]);
        CatalogTitleUserState::query()->create([
            'user_id' => $owner->id,
            'catalog_title_id' => $hiddenTitle->id,
            'in_watchlist' => true,
            'rating' => 4,
        ]);

        Sanctum::actingAs($viewer, ['mobile:read', 'mobile:write']);

        $this->getJson("/api/v1/me/titles/{$title->slug}/state")
            ->assertOk()
            ->assertJsonPath('data.in_watchlist', false)
            ->assertJsonPath('data.rating', null)
            ->assertJsonPath('data.aggregate.rating_count', 1)
            ->assertJsonMissing(['rating' => 3]);

        $this->getJson("/api/v1/me/titles/{$hiddenTitle->slug}/state")
            ->assertNotFound();
        $this->putJson("/api/v1/me/watchlist/{$hiddenTitle->slug}")
            ->assertNotFound();
    }

    public function test_openapi_describes_title_state_and_desired_state_mutations(): void
    {
        $this->getJson('/api/openapi.json')
            ->assertOk()
            ->assertJsonPath('paths./api/v1/me/titles/{catalogTitle}/state.get.operationId', 'getMobileTitleState')
            ->assertJsonPath('paths./api/v1/me/watchlist/{catalogTitle}.put.operationId', 'addMobileWatchlistTitle')
            ->assertJsonPath('paths./api/v1/me/watchlist/{catalogTitle}.delete.operationId', 'removeMobileWatchlistTitle')
            ->assertJsonPath('paths./api/v1/me/ratings/{catalogTitle}.put.operationId', 'setMobileTitleRating')
            ->assertJsonPath('paths./api/v1/me/ratings/{catalogTitle}.delete.operationId', 'clearMobileTitleRating')
            ->assertJsonPath('components.schemas.UserTitleState.properties.rating_range.properties.maximum.maximum', 10);
    }
}
