<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogWatchStatus;
use App\Models\CatalogCollection;
use App\Models\CatalogCollectionItem;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\Season;
use App\Models\User;
use App\Services\Collections\Quality\CatalogCollectionEngagementQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogCollectionEngagementQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_engagement_is_aggregated_as_distinct_anonymous_user_title_pairs(): void
    {
        $collection = CatalogCollection::query()->create([
            'public_id' => (string) Str::uuid(),
            'name' => 'Сигналы качества',
            'slug' => 'quality-signals',
        ]);
        $title = CatalogTitle::factory()->create();
        $otherTitle = CatalogTitle::factory()->create();
        CatalogCollectionItem::query()->create([
            'catalog_collection_id' => $collection->id,
            'catalog_title_id' => $title->id,
            'position' => 1,
        ]);
        $user = User::factory()->create();
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $title->id,
            'in_watchlist' => true,
            'watch_status' => CatalogWatchStatus::Completed,
        ]);
        CatalogTitleUserState::query()->create([
            'user_id' => $user->id,
            'catalog_title_id' => $otherTitle->id,
            'in_watchlist' => true,
            'watch_status' => CatalogWatchStatus::Completed,
        ]);
        $season = Season::factory()->for($title)->create();
        $episodes = Episode::factory()->count(2)->for($season)->sequence(
            ['number' => 1],
            ['number' => 2],
        )->create();

        foreach ($episodes as $episode) {
            EpisodeViewProgress::query()->create([
                'user_id' => $user->id,
                'catalog_title_id' => $title->id,
                'episode_id' => $episode->id,
                'position_seconds' => 1_200,
                'duration_seconds' => 1_200,
                'completed_at' => now(),
                'last_watched_at' => now(),
            ]);
        }

        $result = app(CatalogCollectionEngagementQuery::class)
            ->forCollections([$collection->id]);

        self::assertSame([
            'save_count' => 1,
            'completion_count' => 1,
            'return_count' => 1,
        ], $result[$collection->id]);
        self::assertArrayNotHasKey('user_ids', $result[$collection->id]);
        self::assertArrayNotHasKey($otherTitle->id, $result);
    }

    public function test_empty_collection_list_does_not_query_or_expose_state(): void
    {
        self::assertSame(
            [],
            app(CatalogCollectionEngagementQuery::class)->forCollections([]),
        );
    }
}
