<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Livewire\Library\UserLibraryPage;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class UserLibraryPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_library_routes_are_private_sectioned_and_keep_watching_compatibility(): void
    {
        $this->get('/library')->assertRedirect(route('login'));
        $this->get('/library/history')->assertRedirect(route('login'));
        $this->get('/watching')->assertRedirect(route('login'));

        $user = User::factory()->create();

        foreach (['watchlist', 'ratings', 'continue-watching', 'history'] as $section) {
            $this->actingAs($user)
                ->get(route('library.section', $section))
                ->assertOk()
                ->assertSeeText('Моя библиотека');
        }

        $this->actingAs($user)
            ->get('/library/unsupported')
            ->assertRedirect(route('home'));
        Livewire::actingAs($user)
            ->test(UserLibraryPage::class, ['section' => 'unsupported'])
            ->assertNotFound();
        $this->get('/watching')
            ->assertRedirect(route('library.section', 'continue-watching'));
    }

    public function test_verified_user_filters_watchlist_and_removes_an_owner_item(): void
    {
        $user = User::factory()->create();
        $wanted = CatalogTitle::factory()->create([
            'title' => 'Нужный сериал',
            'type' => 'serial',
            'year' => 2024,
        ]);
        $other = CatalogTitle::factory()->create([
            'title' => 'Другое аниме',
            'type' => 'anime',
            'year' => 2023,
        ]);
        $this->state($user, $wanted, true, 8);
        $this->state($user, $other, true, 6);

        Livewire::actingAs($user)
            ->test(UserLibraryPage::class, ['section' => 'watchlist'])
            ->set('filters.query', '  Нужный  ')
            ->set('filters.type', 'serial')
            ->set('filters.year', '2024')
            ->call('applyFilters')
            ->assertHasNoErrors()
            ->assertSet('filters.query', 'Нужный')
            ->assertSeeHtml('data-library-watchlist-list')
            ->assertSeeHtml('data-ui-poster-layout="list"')
            ->assertSeeText('Нужный сериал')
            ->assertDontSeeText('Другое аниме')
            ->call('setWatchlist', $wanted->id, false)
            ->assertHasNoErrors()
            ->assertDontSeeText('Нужный сериал')
            ->assertSet('status', 'Закладка удалена. Прогресс, статус и коллекции сохранены.');

        $this->assertDatabaseHas('catalog_title_user_states', [
            'user_id' => $user->id,
            'catalog_title_id' => $wanted->id,
            'in_watchlist' => false,
        ]);
    }

    public function test_ratings_use_validated_sort_and_owner_safe_mutations(): void
    {
        $user = User::factory()->create();
        $foreign = User::factory()->create();
        $first = CatalogTitle::factory()->create(['title' => 'Первая оценка']);
        $second = CatalogTitle::factory()->create(['title' => 'Вторая оценка']);
        $foreignTitle = CatalogTitle::factory()->create(['title' => 'Чужая оценка']);
        $this->state($user, $first, false, 4);
        $this->state($user, $second, false, 9);
        $this->state($foreign, $foreignTitle, false, 10);

        $component = Livewire::actingAs($user)
            ->test(UserLibraryPage::class, ['section' => 'ratings'])
            ->set('filters.sort', 'rating')
            ->set('filters.direction', 'desc')
            ->call('applyFilters')
            ->assertHasNoErrors()
            ->assertSeeHtml('data-library-ratings-list')
            ->assertSeeHtml('data-ui-poster-layout="list"')
            ->assertDontSeeText('Чужая оценка');

        $this->assertLessThan(
            strpos($component->html(), 'Первая оценка'),
            strpos($component->html(), 'Вторая оценка'),
        );

        $component
            ->call('setRating', $first->id, 10)
            ->assertHasNoErrors()
            ->assertSet('status', 'Оценка сохранена.')
            ->call('setRating', $first->id, null)
            ->assertDontSeeText('Первая оценка');

        $this->assertDatabaseHas('catalog_title_user_states', [
            'user_id' => $user->id,
            'catalog_title_id' => $first->id,
            'in_watchlist' => false,
            'rating' => null,
        ]);
    }

    public function test_each_library_list_uses_its_own_paginator_name(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 13) as $index) {
            $title = CatalogTitle::factory()->create(['title' => "Тайтл {$index}"]);
            $this->state($user, $title, true, ($index % 10) + 1);
        }

        Livewire::actingAs($user)
            ->test(UserLibraryPage::class, ['section' => 'watchlist'])
            ->call('setPage', 2, 'watchlistPage')
            ->assertSet('paginators.watchlistPage', 2);

        Livewire::actingAs($user)
            ->test(UserLibraryPage::class, ['section' => 'ratings'])
            ->call('setPage', 2, 'ratingsPage')
            ->assertSet('paginators.ratingsPage', 2);
    }

    public function test_continue_watching_and_history_are_owner_scoped_and_history_actions_are_safe(): void
    {
        $user = User::factory()->create();
        $foreign = User::factory()->create();
        [$title, $episode] = $this->watchableTitle('library-activity-owner', 'Просмотр владельца');
        [$foreignTitle, $foreignEpisode] = $this->watchableTitle('library-activity-foreign', 'Чужой просмотр');
        $ownProgress = $this->progress($user, $title, $episode);
        $foreignProgress = $this->progress($foreign, $foreignTitle, $foreignEpisode);

        Livewire::actingAs($user)
            ->test(UserLibraryPage::class, ['section' => 'continue-watching'])
            ->assertSeeHtml('data-library-continue-list')
            ->assertSeeHtml('data-ui-poster-layout="list"')
            ->assertSeeHtml('object-contain')
            ->assertSeeText('Просмотр владельца')
            ->assertDontSeeText('Чужой просмотр');

        Livewire::actingAs($user)
            ->test(UserLibraryPage::class, ['section' => 'history'])
            ->assertSeeHtml('data-library-history-list')
            ->assertSeeHtml('data-ui-poster-layout="compact"')
            ->assertSeeText('Просмотр владельца')
            ->assertDontSeeText('Чужой просмотр')
            ->call('removeHistoryItem', $foreignProgress->id)
            ->assertNotFound();

        Livewire::actingAs($user)
            ->test(UserLibraryPage::class, ['section' => 'history'])
            ->call('removeHistoryItem', $ownProgress->id)
            ->assertHasNoErrors()
            ->assertSet('status', 'Запись удалена из истории.')
            ->assertDontSeeText('Просмотр владельца');

        $this->assertDatabaseMissing('episode_view_progress', ['id' => $ownProgress->id]);
        $this->assertDatabaseHas('episode_view_progress', ['id' => $foreignProgress->id]);
    }

    private function state(User $user, CatalogTitle $title, bool $watchlist, ?int $rating): void
    {
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'in_watchlist' => $watchlist,
            'rating' => $rating,
        ]);
    }

    /** @return array{CatalogTitle, Episode} */
    private function watchableTitle(string $slug, string $titleText): array
    {
        $title = CatalogTitle::factory()->create([
            'slug' => $slug,
            'title' => $titleText,
            'poster_url' => 'https://media.example.com/'.$slug.'.jpg',
        ]);
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

    private function progress(User $user, CatalogTitle $title, Episode $episode): EpisodeViewProgress
    {
        return EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'episode_id' => $episode->id,
            'position_seconds' => 120,
            'duration_seconds' => 600,
            'progress_percent' => 20,
            'first_started_at' => now(),
            'last_watched_at' => now(),
        ]);
    }
}
