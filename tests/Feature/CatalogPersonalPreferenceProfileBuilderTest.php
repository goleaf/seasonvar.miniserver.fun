<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogPersonalSourceSignal;
use App\Enums\CatalogPersonalEvidence;
use App\Enums\CatalogWatchStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\CatalogPersonalPreferenceProfileBuilder;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogPersonalPreferenceProfileBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_independent_evidence_combines_within_the_per_title_cap(): void
    {
        $user = User::factory()->create();
        [$title, $episodes] = $this->titleWithEpisodes(4);
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'in_watchlist' => true,
            'watchlist_updated_at' => now(),
            'rating' => 10,
            'rating_updated_at' => now(),
        ]);
        $this->progress($user, $title, $episodes[0], 80, now());

        $signal = $this->signalFor($user, $title);

        $this->assertGreaterThan(160, $signal->confidence);
        $this->assertLessThanOrEqual(320, $signal->confidence);
        $this->assertContains(CatalogPersonalEvidence::Watchlist, $signal->evidence);
        $this->assertContains(CatalogPersonalEvidence::Rating, $signal->evidence);
        $this->assertContains(CatalogPersonalEvidence::MeaningfulProgress, $signal->evidence);
    }

    public function test_higher_rating_and_more_recent_progress_contribute_more(): void
    {
        $user = User::factory()->create();
        [$ratingSeven] = $this->titleWithEpisodes(1);
        [$ratingTen] = $this->titleWithEpisodes(1);
        [$oldTitle, $oldEpisodes] = $this->titleWithEpisodes(1);
        [$recentTitle, $recentEpisodes] = $this->titleWithEpisodes(1);

        foreach ([[$ratingSeven, 7], [$ratingTen, 10]] as [$title, $rating]) {
            CatalogTitleUserState::query()->create([
                'user_id' => $user->id,
                'catalog_title_id' => $title->id,
                'rating' => $rating,
                'rating_updated_at' => now(),
            ]);
        }

        $this->progress($user, $oldTitle, $oldEpisodes[0], 70, now()->subYear());
        $this->progress($user, $recentTitle, $recentEpisodes[0], 70, now());
        $profile = app(CatalogPersonalPreferenceProfileBuilder::class)->forUser($user);
        $signals = collect($profile->signals)->keyBy('titleId');

        $this->assertGreaterThan($signals[$ratingSeven->id]->confidence, $signals[$ratingTen->id]->confidence);
        $this->assertGreaterThan($signals[$oldTitle->id]->confidence, $signals[$recentTitle->id]->confidence);
    }

    public function test_completed_depth_requires_more_than_one_episode_for_a_long_title(): void
    {
        $user = User::factory()->create();
        [$shallowTitle, $shallowEpisodes] = $this->titleWithEpisodes(20);
        [$deepTitle, $deepEpisodes] = $this->titleWithEpisodes(20);
        $this->progress($user, $shallowTitle, $shallowEpisodes[0], 100, now(), completed: true);

        foreach (array_slice($deepEpisodes, 0, 10) as $episode) {
            $this->progress($user, $deepTitle, $episode, 100, now(), completed: true);
        }

        $profile = app(CatalogPersonalPreferenceProfileBuilder::class)->forUser($user);
        $signals = collect($profile->signals)->keyBy('titleId');

        $this->assertNotContains(CatalogPersonalEvidence::CompletedDepth, $signals[$shallowTitle->id]->evidence);
        $this->assertContains(CatalogPersonalEvidence::CompletedDepth, $signals[$deepTitle->id]->evidence);
    }

    public function test_missing_semantic_timestamp_uses_legacy_factor_instead_of_updated_at(): void
    {
        $user = User::factory()->create();
        [$legacy] = $this->titleWithEpisodes(1);
        [$recent] = $this->titleWithEpisodes(1);
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $legacy->id,
            'in_watchlist' => true,
            'watchlist_updated_at' => null,
        ]);
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $recent->id,
            'in_watchlist' => true,
            'watchlist_updated_at' => now(),
        ]);

        $profile = app(CatalogPersonalPreferenceProfileBuilder::class)->forUser($user);
        $signals = collect($profile->signals)->keyBy('titleId');

        $this->assertSame(30, $signals[$legacy->id]->confidence);
        $this->assertSame(60, $signals[$recent->id]->confidence);
    }

    public function test_query_count_is_constant_for_small_and_large_profiles(): void
    {
        $small = User::factory()->create();
        $large = User::factory()->create();
        $this->createRatedTitles($small, 2);
        $this->createRatedTitles($large, 40);
        $builder = app(CatalogPersonalPreferenceProfileBuilder::class);
        $builder->forUser(User::factory()->create());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $builder->forUser($small);
        $smallCount = count(DB::getQueryLog());
        DB::flushQueryLog();
        $builder->forUser($large);
        $largeCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($smallCount + 1, $largeCount);
        $this->assertLessThanOrEqual(8, $largeCount);
    }

    /** @return array{CatalogTitle, list<Episode>} */
    private function titleWithEpisodes(int $count): array
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->for($title)->create();
        $episodes = Episode::factory()
            ->count($count)
            ->for($season)
            ->sequence(fn (Sequence $sequence): array => ['number' => $sequence->index + 1])
            ->create()
            ->all();

        return [$title, $episodes];
    }

    private function progress(
        User $user,
        CatalogTitle $title,
        Episode $episode,
        int $percent,
        mixed $watchedAt,
        bool $completed = false,
    ): void {
        EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'episode_id' => $episode->id,
            'position_seconds' => $percent * 10,
            'duration_seconds' => 1_000,
            'progress_percent' => $percent,
            'completed_at' => $completed ? $watchedAt : null,
            'last_watched_at' => $watchedAt,
        ]);
    }

    private function signalFor(User $user, CatalogTitle $title): CatalogPersonalSourceSignal
    {
        $signal = collect(app(CatalogPersonalPreferenceProfileBuilder::class)->forUser($user)->signals)
            ->firstWhere('titleId', $title->id);

        $this->assertInstanceOf(CatalogPersonalSourceSignal::class, $signal);

        return $signal;
    }

    private function createRatedTitles(User $user, int $count): void
    {
        CatalogTitle::factory()->count($count)->create()->each(function (CatalogTitle $title) use ($user): void {
            CatalogTitleUserState::query()->create([
                'user_id' => $user->id,
                'catalog_title_id' => $title->id,
                'rating' => 10,
                'rating_updated_at' => now(),
                'watch_status' => CatalogWatchStatus::Watching,
                'watch_status_updated_at' => now(),
            ]);
        });
    }
}
