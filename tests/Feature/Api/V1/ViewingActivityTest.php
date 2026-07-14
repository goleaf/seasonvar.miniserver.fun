<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ViewingActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_continue_watching_uses_current_and_next_actions_for_owner_only(): void
    {
        $user = User::factory()->unverified()->create();
        $otherUser = User::factory()->create();
        [$currentTitle, $currentEpisodes] = $this->createWatchableTitle('continue-mobile-title');
        [$completedTitle, $completedEpisodes] = $this->createWatchableTitle('next-mobile-title');

        EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $currentTitle->id,
            'episode_id' => $currentEpisodes[0]->id,
            'position_seconds' => 120,
            'duration_seconds' => 600,
            'progress_percent' => 20,
            'first_started_at' => now()->subMinutes(10),
            'last_watched_at' => now(),
        ]);
        EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $completedTitle->id,
            'episode_id' => $completedEpisodes[0]->id,
            'position_seconds' => 600,
            'duration_seconds' => 600,
            'progress_percent' => 100,
            'first_started_at' => now()->subMinutes(20),
            'completed_at' => now()->subMinute(),
            'last_watched_at' => now()->subMinute(),
        ]);
        EpisodeViewProgress::query()->create([
            'user_id' => $otherUser->id,
            'catalog_title_id' => $currentTitle->id,
            'episode_id' => $currentEpisodes[1]->id,
            'position_seconds' => 300,
            'duration_seconds' => 600,
            'progress_percent' => 50,
            'first_started_at' => now(),
            'last_watched_at' => now()->addMinute(),
        ]);

        Sanctum::actingAs($user, ['mobile:read']);

        $this->getJson('/api/v1/me/continue-watching?limit=24')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.action', 'continue')
            ->assertJsonPath('data.0.position_seconds', 120)
            ->assertJsonPath('data.0.progress_percent', 20)
            ->assertJsonPath('data.0.title.slug', 'continue-mobile-title')
            ->assertJsonPath('data.0.episode.id', $currentEpisodes[0]->id)
            ->assertJsonPath('data.1.action', 'next')
            ->assertJsonPath('data.1.position_seconds', 0)
            ->assertJsonPath('data.1.progress_percent', null)
            ->assertJsonPath('data.1.title.slug', 'next-mobile-title')
            ->assertJsonPath('data.1.episode.id', $completedEpisodes[1]->id);

        $this->getJson('/api/v1/me/continue-watching?limit=25')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limit');
    }

    public function test_history_is_paginated_with_accessibility_and_safe_nested_summaries(): void
    {
        $user = User::factory()->unverified()->create();
        $otherUser = User::factory()->create();
        [$title, $episodes] = $this->createWatchableTitle('mobile-history-title');
        [$foreignTitle, $foreignEpisodes] = $this->createWatchableTitle('foreign-mobile-history');
        [$hiddenTitle, $hiddenEpisodes] = $this->createWatchableTitle('hidden-mobile-history');
        $hiddenTitle->update(['is_published' => false]);

        $latest = $this->createProgress($user, $title, $episodes[0], now(), 90, 15);
        $second = $this->createProgress($user, $title, $episodes[1], now()->subMinute(), 180, 30);
        $this->createProgress($user, $hiddenTitle, $hiddenEpisodes[0], now()->subMinutes(2), 45, 8);
        $this->createProgress($otherUser, $foreignTitle, $foreignEpisodes[0], now()->addMinute(), 300, 50);

        Sanctum::actingAs($user, ['mobile:read']);

        $firstPage = $this->getJson('/api/v1/me/history?per_page=1&page=1')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.id', $latest->id)
            ->assertJsonPath('data.0.position_seconds', 90)
            ->assertJsonPath('data.0.duration_seconds', 600)
            ->assertJsonPath('data.0.progress_percent', 15)
            ->assertJsonPath('data.0.completed', false)
            ->assertJsonPath('data.0.is_accessible', true)
            ->assertJsonPath('data.0.title.slug', 'mobile-history-title')
            ->assertJsonPath('data.0.season.id', $episodes[0]->season_id)
            ->assertJsonPath('data.0.episode.id', $episodes[0]->id);

        foreach (['user_id', 'source_url', 'playback_url', 'foreign-mobile-history'] as $privateValue) {
            $firstPage->assertDontSee($privateValue, false);
        }

        $this->getJson('/api/v1/me/history?per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('data.0.id', $second->id);

        $this->getJson('/api/v1/me/history?per_page=3')
            ->assertOk()
            ->assertJsonPath('data.2.title.slug', 'hidden-mobile-history')
            ->assertJsonPath('data.2.is_accessible', false);

        $this->getJson('/api/v1/me/history?per_page=49')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_history_mutations_are_verified_and_owner_scoped(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        [$title, $episodes] = $this->createWatchableTitle('history-delete-title');
        $own = $this->createProgress($user, $title, $episodes[0], now(), 60, 10);
        $other = $this->createProgress($otherUser, $title, $episodes[0], now(), 30, 5);

        Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

        $this->deleteJson("/api/v1/me/history/{$other->id}")
            ->assertNotFound();
        $this->assertModelExists($other);

        $this->deleteJson("/api/v1/me/history/{$own->id}")
            ->assertNoContent()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertModelMissing($own);

        $remaining = $this->createProgress($user, $title, $episodes[1], now(), 120, 20);
        $pageVisit = EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'episode_id' => Episode::factory()->create(['season_id' => $episodes[0]->season_id])->id,
            'position_seconds' => 0,
            'duration_seconds' => 0,
            'first_started_at' => null,
            'last_watched_at' => now(),
        ]);

        $this->deleteJson('/api/v1/me/history')
            ->assertNoContent()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertModelMissing($remaining);
        $this->assertModelExists($pageVisit);
        $this->assertModelExists($other);
    }

    public function test_unverified_user_cannot_delete_history(): void
    {
        $user = User::factory()->unverified()->create();
        [$title, $episodes] = $this->createWatchableTitle('unverified-history-title');
        $progress = $this->createProgress($user, $title, $episodes[0], now(), 60, 10);
        Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);

        $this->deleteJson("/api/v1/me/history/{$progress->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'email_not_verified');

        $this->assertModelExists($progress);
    }

    /** @return array{CatalogTitle, array{Episode, Episode}} */
    private function createWatchableTitle(string $slug): array
    {
        $title = CatalogTitle::factory()->create([
            'slug' => $slug,
            'source_url' => 'https://seasonvar.ru/private-'.$slug,
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'number' => 1,
        ]);
        $episodes = [];

        foreach ([1, 2] as $number) {
            $episode = Episode::factory()->create([
                'season_id' => $season->id,
                'number' => $number,
                'sort_order' => $number,
            ]);
            LicensedMedia::factory()->create([
                'catalog_title_id' => $title->id,
                'season_id' => $season->id,
                'episode_id' => $episode->id,
                'status' => 'published',
                'published_at' => now(),
            ]);
            $episodes[] = $episode;
        }

        return [$title, $episodes];
    }

    private function createProgress(
        User $user,
        CatalogTitle $title,
        Episode $episode,
        mixed $watchedAt,
        int $position,
        int $percent,
    ): EpisodeViewProgress {
        return EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'episode_id' => $episode->id,
            'position_seconds' => $position,
            'duration_seconds' => 600,
            'progress_percent' => $percent,
            'first_started_at' => $watchedAt,
            'last_watched_at' => $watchedAt,
        ]);
    }
}
