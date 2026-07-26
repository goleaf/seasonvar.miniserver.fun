<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogContinueWatchingItem;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\CatalogViewingActivityQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogViewingActivityQueryPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_continue_watching_bounds_watchable_episodes_to_the_owner_title_seasons(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $continuingTitle = CatalogTitle::factory()->create();
        $continuingSeason = Season::factory()->for($continuingTitle)->create([
            'number' => 1,
        ]);
        $continuingEpisode = $this->createPlayableEpisode(
            $continuingTitle,
            $continuingSeason,
            1,
        );
        EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $continuingTitle->id,
            'episode_id' => $continuingEpisode->id,
            'position_seconds' => 120,
            'duration_seconds' => 600,
            'progress_percent' => 20,
            'first_started_at' => now()->subMinutes(20),
            'last_watched_at' => now()->subMinutes(10),
        ]);

        $completedTitle = CatalogTitle::factory()->create();
        $completedSeason = Season::factory()->for($completedTitle)->create([
            'number' => 1,
        ]);
        $completedEpisode = $this->createPlayableEpisode(
            $completedTitle,
            $completedSeason,
            1,
        );
        $nextEpisode = $this->createPlayableEpisode(
            $completedTitle,
            $completedSeason,
            2,
        );
        EpisodeViewProgress::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $completedTitle->id,
            'episode_id' => $completedEpisode->id,
            'position_seconds' => 600,
            'duration_seconds' => 600,
            'progress_percent' => 100,
            'first_started_at' => now()->subMinutes(15),
            'completed_at' => now()->subMinutes(5),
            'last_watched_at' => now()->subMinutes(5),
        ]);

        $foreignTitle = CatalogTitle::factory()->create();
        $foreignSeason = Season::factory()->for($foreignTitle)->create();
        $foreignEpisode = $this->createPlayableEpisode(
            $foreignTitle,
            $foreignSeason,
            1,
        );
        EpisodeViewProgress::query()->create([
            'user_id' => $otherUser->id,
            'catalog_title_id' => $foreignTitle->id,
            'episode_id' => $foreignEpisode->id,
            'position_seconds' => 60,
            'duration_seconds' => 600,
            'progress_percent' => 10,
            'first_started_at' => now(),
            'last_watched_at' => now(),
        ]);

        $sequenceQuery = null;

        DB::listen(function (QueryExecuted $query) use (&$sequenceQuery): void {
            if ($sequenceQuery === null
                && str_contains(strtolower($query->sql), 'watchable_episode_sequence')) {
                $sequenceQuery = $query;
            }
        });

        $items = app(CatalogViewingActivityQuery::class)
            ->continueWatching($user)
            ->keyBy(fn (CatalogContinueWatchingItem $item): int => $item->title->id);
        $continuingItem = $items->get($continuingTitle->id);
        $completedItem = $items->get($completedTitle->id);

        $this->assertCount(2, $items);
        $this->assertFalse($items->has($foreignTitle->id));
        $this->assertInstanceOf(CatalogContinueWatchingItem::class, $continuingItem);
        $this->assertSame('continue', $continuingItem->actionType);
        $this->assertSame($continuingEpisode->id, $continuingItem->episode->id);
        $this->assertInstanceOf(CatalogContinueWatchingItem::class, $completedItem);
        $this->assertSame('next', $completedItem->actionType);
        $this->assertSame($nextEpisode->id, $completedItem->episode->id);
        $this->assertInstanceOf(QueryExecuted::class, $sequenceQuery);

        $normalizedSql = Str::of($sequenceQuery->sql)
            ->lower()
            ->squish()
            ->toString();

        $this->assertMatchesRegularExpression(
            '/"episodes"\."season_id" in \(select "id" from "seasons" where .*"catalog_title_id" in \(/',
            $normalizedSql,
        );

        $plan = collect(DB::select(
            'EXPLAIN QUERY PLAN '.$sequenceQuery->sql,
            $sequenceQuery->bindings,
        ))->pluck('detail')->implode("\n");

        $this->assertStringContainsString('episodes_publication_lookup_idx', $plan);
        $this->assertStringContainsString('seasons_publication_lookup_idx', $plan);
    }

    private function createPlayableEpisode(
        CatalogTitle $catalogTitle,
        Season $season,
        int $number,
    ): Episode {
        $episode = Episode::factory()->for($season)->create([
            'number' => $number,
            'sort_order' => $number,
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        return $episode;
    }
}
