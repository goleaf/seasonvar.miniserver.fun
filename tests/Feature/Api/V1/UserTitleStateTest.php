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
}
