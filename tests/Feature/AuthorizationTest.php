<?php

namespace Tests\Feature;

use App\Livewire\CatalogTitlePlayer;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalog_pages_remain_available_to_guests(): void
    {
        $this->get(route('home'))->assertOk();
        $this->get(route('titles.index'))->assertOk();
    }

    public function test_guest_can_view_catalog_stats(): void
    {
        $this
            ->get(route('stats'))
            ->assertOk();
    }

    public function test_unverified_web_user_can_read_but_sees_verification_prompt_and_cannot_mutate(): void
    {
        $user = User::factory()->unverified()->create();
        $title = CatalogTitle::factory()->create();

        Livewire::actingAs($user)
            ->test(CatalogTitlePlayer::class, ['catalogTitleId' => $title->id])
            ->assertSeeText('Подтвердите электронную почту')
            ->assertSee(route('verification.notice'), false)
            ->call('setWatchlist', true)
            ->assertForbidden();

        $this->assertDatabaseMissing('catalog_title_user_states', [
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
        ]);
    }

    public function test_history_policy_requires_verified_owner_for_each_mutation(): void
    {
        $unverified = User::factory()->unverified()->create();
        $verified = User::factory()->create();
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->for($title, 'catalogTitle')->create();
        $episode = Episode::factory()->for($season)->create();
        $progress = EpisodeViewProgress::query()->create([
            'user_id' => $unverified->id,
            'catalog_title_id' => $title->id,
            'episode_id' => $episode->id,
            'position_seconds' => 10,
            'duration_seconds' => 100,
            'first_started_at' => now(),
            'last_watched_at' => now(),
        ]);

        $this->assertFalse(Gate::forUser($unverified)->allows('delete', $progress));
        $this->assertFalse(Gate::forUser($unverified)->allows('deleteAny', EpisodeViewProgress::class));
        $this->assertFalse(Gate::forUser($verified)->allows('delete', $progress));

        $progress->forceFill(['user_id' => $verified->id])->save();

        $this->assertTrue(Gate::forUser($verified)->allows('delete', $progress));
        $this->assertTrue(Gate::forUser($verified)->allows('deleteAny', EpisodeViewProgress::class));
    }

    public function test_authenticated_catalog_cards_receive_owner_state_with_constant_query_budget(): void
    {
        $user = User::factory()->create();
        $foreign = User::factory()->create();
        $first = $this->createPersonalTitle($user, 1);
        CatalogTitleUserState::query()->create([
            'user_id' => $foreign->id,
            'catalog_title_id' => $first->id,
            'in_watchlist' => false,
            'rating' => 2,
        ]);
        $this->actingAs($user);

        $oneItemQueries = $this->captureQueries(
            fn () => $this->get(route('titles.index', ['per_page' => 24]))
                ->assertOk()
                ->assertSee('data-user-card-state', false)
                ->assertSee('data-user-in-watchlist="1"', false)
                ->assertSee('data-user-rating="8"', false)
                ->assertSee('data-user-progress="20"', false)
                ->assertSeeText('Продолжить просмотр'),
        );

        foreach (range(2, 12) as $index) {
            $this->createPersonalTitle($user, $index);
        }

        $twelveItemQueries = $this->captureQueries(
            fn () => $this->get(route('titles.index', ['per_page' => 24]))
                ->assertOk()
                ->assertSeeText('Персональная карточка 12'),
        );

        $this->assertLessThanOrEqual($oneItemQueries + 1, $twelveItemQueries);
    }

    private function createPersonalTitle(User $user, int $index): CatalogTitle
    {
        $title = CatalogTitle::factory()->create([
            'title' => "Персональная карточка {$index}",
            'slug' => "personal-card-{$index}",
        ]);
        $season = Season::factory()->for($title, 'catalogTitle')->create(['number' => 1]);
        $episode = Episode::factory()->for($season)->create(['number' => 1]);
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'in_watchlist' => true,
            'rating' => 8,
        ]);
        EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'episode_id' => $episode->id,
            'position_seconds' => 120,
            'duration_seconds' => 600,
            'progress_percent' => 20,
            'first_started_at' => now(),
            'last_watched_at' => now(),
        ]);

        return $title;
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
