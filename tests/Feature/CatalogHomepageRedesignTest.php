<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\LicensedMedia;
use App\Models\ReleaseScheduleEntry;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogHomepageRedesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_homepage_uses_the_compact_discovery_order_without_personal_sections(): void
    {
        $response = $this->get(route('home'))->assertOk();
        $html = $response->getContent();

        $this->assertSectionOrder($html, [
            'statistics',
            'trending',
            'latest-updates',
            'new-titles',
            'watch-now',
            'featured-collections',
            'catalog-facets',
        ]);
        $response
            ->assertSee('data-home-metrics-compact', false)
            ->assertSee('data-home-metrics-mobile-last', false)
            ->assertDontSee('data-home-section="continue-watching"', false)
            ->assertDontSee('data-home-section="library-updates"', false)
            ->assertDontSee('data-home-section="personal-recommendations"', false)
            ->assertDontSee('data-home-section="account-tools"', false);
    }

    public function test_authenticated_homepage_uses_the_personal_return_order(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('home'))->assertOk();
        $html = $response->getContent();

        $this->assertSectionOrder($html, [
            'continue-watching',
            'library-updates',
            'personal-recommendations',
            'trending',
            'latest-updates',
            'account-tools',
        ]);
        $response
            ->assertDontSee('data-home-section="statistics"', false)
            ->assertDontSee('data-home-section="new-titles"', false)
            ->assertDontSee('data-home-section="watch-now"', false)
            ->assertDontSee('data-home-section="featured-collections"', false)
            ->assertDontSee('data-home-section="catalog-facets"', false);
    }

    public function test_authenticated_homepage_only_renders_the_owners_viewing_and_library_updates(): void
    {
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        [$ownerTitle, $ownerEpisode] = $this->watchableTitle('home-owner-progress', 'Продолжение владельца');
        [$foreignTitle, $foreignEpisode] = $this->watchableTitle('home-foreign-progress', 'Чужое продолжение');
        $this->progress($owner, $ownerTitle, $ownerEpisode);
        $this->progress($foreign, $foreignTitle, $foreignEpisode);
        [$ownerUpdateTitle] = $this->libraryUpdate($owner, 'home-owner-update', 'Обновление библиотеки владельца');
        [$foreignUpdateTitle] = $this->libraryUpdate($foreign, 'home-foreign-update', 'Чужое обновление библиотеки');

        $response = $this->actingAs($owner)->get(route('home'))->assertOk();

        $response
            ->assertSee('data-home-continue-title="'.$ownerTitle->id.'"', false)
            ->assertDontSee('data-home-continue-title="'.$foreignTitle->id.'"', false)
            ->assertSee('data-home-library-update="'.$ownerUpdateTitle->id.'"', false)
            ->assertDontSee('data-home-library-update="'.$foreignUpdateTitle->id.'"', false)
            ->assertSeeText('Продолжение владельца')
            ->assertSeeText('Обновление библиотеки владельца');
    }

    /** @param list<string> $sections */
    private function assertSectionOrder(string $html, array $sections): void
    {
        $positions = collect($sections)->mapWithKeys(function (string $section) use ($html): array {
            $position = strpos($html, 'data-home-section="'.$section.'"');

            $this->assertNotFalse($position, "Homepage section [{$section}] was not rendered.");

            return [$section => $position];
        });

        $this->assertSame(
            $positions->values()->sort()->values()->all(),
            $positions->values()->all(),
            'Homepage sections were not rendered in the required order.',
        );
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

    private function progress(User $user, CatalogTitle $title, Episode $episode): void
    {
        EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'episode_id' => $episode->id,
            'position_seconds' => 120,
            'duration_seconds' => 600,
            'progress_percent' => 20,
            'first_started_at' => now()->subMinute(),
            'last_watched_at' => now(),
        ]);
    }

    /** @return array{CatalogTitle, CatalogTitleUserState} */
    private function libraryUpdate(User $user, string $slug, string $titleText): array
    {
        $title = CatalogTitle::factory()->create([
            'slug' => $slug,
            'title' => $titleText,
        ]);
        $state = CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'in_watchlist' => true,
        ]);
        $state->forceFill(['created_at' => now()->subWeek(), 'updated_at' => now()->subWeek()])->save();
        ReleaseScheduleEntry::query()->create([
            'logical_key' => 'homepage-owner-update-'.$title->id,
            'entry_type' => ReleaseScheduleEntryType::EpisodeRelease,
            'status' => ReleaseScheduleStatus::Released,
            'catalog_title_id' => $title->id,
            'is_public' => true,
            'released_at' => now(),
        ]);

        return [$title, $state];
    }
}
