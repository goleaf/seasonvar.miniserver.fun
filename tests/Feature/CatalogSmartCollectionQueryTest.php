<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CatalogCollectionData;
use App\DTOs\CatalogCollectionItemCriteria;
use App\DTOs\CatalogSmartCollectionRules;
use App\Enums\CatalogCollectionMode;
use App\Enums\CatalogCollectionSort;
use App\Enums\CatalogCollectionVisibility;
use App\Enums\CatalogWatchStatus;
use App\Enums\MediaHealthStatus;
use App\Enums\ReleaseKind;
use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleStatus;
use App\Models\Actor;
use App\Models\CatalogCollection;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRating;
use App\Models\CatalogTitleUserState;
use App\Models\Country;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\ReleaseScheduleEntry;
use App\Models\Season;
use App\Models\User;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Collections\CatalogCollectionService;
use App\Services\Collections\CatalogSmartCollectionQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogSmartCollectionQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_combined_catalog_personal_and_media_rules_return_only_the_exact_title(): void
    {
        $owner = User::factory()->create();
        $country = Country::query()->create(['name' => 'Южная Корея', 'slug' => 'iuznaia-koreia']);
        $genre = Genre::query()->create(['name' => 'Криминал', 'slug' => 'kriminal']);
        $matching = $this->title('Точное совпадение', 2025, $country, $genre, 8.4);
        $withoutSubtitles = $this->title('Без субтитров', 2025, $country, $genre, 9.1);
        $alreadyWatched = $this->title('Уже смотрели', 2025, $country, $genre, 8.8);

        $this->completedSeason($matching, 8);
        $this->completedSeason($withoutSubtitles, 8);
        $watchedEpisode = $this->completedSeason($alreadyWatched, 8);
        $matchingMedia = $this->media($matching, subtitles: true, minutes: 60);
        $this->media($withoutSubtitles, subtitles: false, minutes: 50);
        $this->media($alreadyWatched, subtitles: true, minutes: 45);
        EpisodeViewProgress::query()->create([
            'user_id' => $owner->id,
            'catalog_title_id' => $alreadyWatched->id,
            'episode_id' => $watchedEpisode->id,
            'position_seconds' => 30,
            'duration_seconds' => 3_600,
            'last_watched_at' => now(),
        ]);
        $collection = $this->collection($owner, [
            'country_slug' => 'iuznaia-koreia',
            'genre_slug' => 'kriminal',
            'imdb_min' => 8,
            'completion' => 'completed',
            'unwatched' => true,
            'has_subtitles' => true,
            'max_episode_minutes' => 60,
        ]);

        $items = app(CatalogCollectionQuery::class)->items(
            $collection,
            $owner,
            new CatalogCollectionItemCriteria(sort: CatalogCollectionSort::Rating),
        );

        $this->assertSame([$matching->id], collect($items->items())->pluck('id')->all());

        $matchingMedia->forceFill(['health_status' => MediaHealthStatus::Unavailable])->save();
        $this->assertSame(0, app(CatalogCollectionQuery::class)->items(
            $collection->refresh(),
            $owner,
            new CatalogCollectionItemCriteria,
        )->total());
    }

    public function test_actor_library_watch_status_age_and_video_rules_use_owner_state_only(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $actor = Actor::query()->create(['name' => 'Любимый актёр', 'slug' => 'favorite-actor']);
        $matching = CatalogTitle::factory()->create(['title' => 'Брошен давно']);
        $recent = CatalogTitle::factory()->create(['title' => 'Брошен недавно']);
        $matching->actors()->attach($actor);
        $recent->actors()->attach($actor);
        $this->media($matching, subtitles: false, minutes: 40);
        $this->media($recent, subtitles: false, minutes: 40);
        CatalogTitleUserState::query()->create([
            'user_id' => $owner->id,
            'catalog_title_id' => $matching->id,
            'in_watchlist' => true,
            'watch_status' => CatalogWatchStatus::Dropped,
            'watch_status_updated_at' => now()->subDays(120),
        ])->forceFill(['updated_at' => now()->subDays(120)])->save();
        CatalogTitleUserState::query()->create([
            'user_id' => $owner->id,
            'catalog_title_id' => $recent->id,
            'in_watchlist' => true,
            'watch_status' => CatalogWatchStatus::Dropped,
            'watch_status_updated_at' => now()->subDays(20),
        ]);
        CatalogTitleUserState::query()->create([
            'user_id' => $other->id,
            'catalog_title_id' => $recent->id,
            'in_watchlist' => true,
            'watch_status' => CatalogWatchStatus::Dropped,
            'watch_status_updated_at' => now()->subDays(200),
        ]);
        $collection = $this->collection($owner, [
            'actor_slug' => 'favorite-actor',
            'in_library' => true,
            'unwatched' => true,
            'watch_status' => 'dropped',
            'watch_status_older_days' => 90,
            'video_available' => true,
        ]);

        $items = app(CatalogCollectionQuery::class)->items(
            $collection,
            $owner,
            new CatalogCollectionItemCriteria,
        );

        $this->assertSame([$matching->id], collect($items->items())->pluck('id')->all());
    }

    public function test_year_episode_count_ongoing_and_new_episode_rules_compose_and_auto_update(): void
    {
        $owner = User::factory()->create();
        $matching = CatalogTitle::factory()->create([
            'title' => 'Продолжается',
            'year' => 2025,
        ]);
        Season::factory()->for($matching)->create([
            'number' => 1,
            'kind' => ReleaseKind::Regular,
            'episodes_released' => 3,
            'episodes_total' => 8,
        ]);
        $state = CatalogTitleUserState::query()->create([
            'user_id' => $owner->id,
            'catalog_title_id' => $matching->id,
            'in_watchlist' => true,
        ]);
        $state->forceFill(['updated_at' => now()->subWeek()])->save();
        $collection = $this->collection($owner, [
            'year_from' => 2024,
            'year_to' => 2026,
            'completion' => 'ongoing',
            'episodes_max' => 4,
            'in_library' => true,
            'has_new_episodes' => true,
        ]);

        $this->assertSame(0, app(CatalogCollectionQuery::class)->items(
            $collection,
            $owner,
            new CatalogCollectionItemCriteria,
        )->total());

        ReleaseScheduleEntry::query()->create([
            'logical_key' => 'smart-release-'.$matching->id,
            'entry_type' => ReleaseScheduleEntryType::EpisodeRelease,
            'status' => ReleaseScheduleStatus::Released,
            'catalog_title_id' => $matching->id,
            'is_public' => true,
            'released_at' => now(),
        ]);

        $items = app(CatalogCollectionQuery::class)->items(
            $collection,
            $owner,
            new CatalogCollectionItemCriteria,
        );

        $this->assertSame([$matching->id], collect($items->items())->pluck('id')->all());
        $this->assertTrue($state->exists);
    }

    public function test_runtime_filters_sort_and_pagination_compose_with_smart_rules(): void
    {
        $owner = User::factory()->create();
        $country = Country::query()->create(['name' => 'Южная Корея', 'slug' => 'iuznaia-koreia']);
        $crime = Genre::query()->create(['name' => 'Криминал', 'slug' => 'kriminal']);
        $comedy = Genre::query()->create(['name' => 'Комедия', 'slug' => 'komediia']);

        foreach (range(1, 8) as $number) {
            $title = CatalogTitle::factory()->create([
                'title' => sprintf('Корейский криминал %02d', $number),
                'year' => 2025,
            ]);
            $title->countries()->attach($country);
            $title->genres()->attach($crime);
        }

        $excluded = CatalogTitle::factory()->create([
            'title' => 'Корейская комедия',
            'year' => 2025,
        ]);
        $excluded->countries()->attach($country);
        $excluded->genres()->attach($comedy);
        $collection = $this->collection($owner, ['country_slug' => 'iuznaia-koreia']);

        $page = app(CatalogCollectionQuery::class)->items(
            $collection,
            $owner,
            new CatalogCollectionItemCriteria(
                search: 'Корейский',
                genre: 'kriminal',
                year: 2025,
                sort: CatalogCollectionSort::Title,
                perPage: 6,
            ),
        );

        $this->assertSame(8, $page->total());
        $this->assertSame(2, $page->lastPage());
        $this->assertSame([
            'Корейский криминал 01',
            'Корейский криминал 02',
            'Корейский криминал 03',
            'Корейский криминал 04',
            'Корейский криминал 05',
            'Корейский криминал 06',
        ], collect($page->items())->pluck('title')->all());

        $options = app(CatalogCollectionQuery::class)->filterOptions($collection, $owner);
        $this->assertSame(['komediia', 'kriminal'], $options['genres']->pluck('slug')->all());
        $this->assertSame([2025], $options['years']->all());
    }

    public function test_sqlite_plan_uses_existing_indexes_without_a_global_season_scan(): void
    {
        $owner = User::factory()->create();
        $collection = $this->collection($owner, [
            'country_slug' => 'iuznaia-koreia',
            'genre_slug' => 'kriminal',
            'actor_slug' => 'favorite-actor',
            'imdb_min' => 8,
            'completion' => 'completed',
            'episodes_max' => 10,
            'in_library' => true,
            'unwatched' => true,
            'watch_status' => 'dropped',
            'watch_status_older_days' => 90,
            'has_subtitles' => true,
            'max_episode_minutes' => 60,
            'video_available' => true,
        ]);
        $query = app(CatalogSmartCollectionQuery::class)->titleIds($collection, $owner);
        $details = collect(DB::select(
            'EXPLAIN QUERY PLAN '.$query->toSql(),
            $query->getBindings(),
        ))->map(static fn (object $row): string => (string) $row->detail)->implode(' ');

        foreach ([
            'catalog_title_country',
            'catalog_title_genre',
            'catalog_title_actor',
            'catalog_title_ratings_catalog_title_id_provider_unique',
            'seasons_publication_lookup_idx',
            'catalog_user_state_user_title_unique',
            'episode_progress_user_title_recent_idx',
            'licensed_media_publication_lookup_idx',
        ] as $expectedIndexOrTable) {
            $this->assertStringContainsString($expectedIndexOrTable, $details);
        }

        $this->assertStringNotContainsString('SCAN seasons', $details);
    }

    private function collection(User $owner, array $rules): CatalogCollection
    {
        return app(CatalogCollectionService::class)->create(
            $owner,
            new CatalogCollectionData(
                name: 'Динамическая подборка',
                description: null,
                visibility: CatalogCollectionVisibility::Private,
                sortMode: CatalogCollectionSort::RecentlyUpdated,
                mode: CatalogCollectionMode::Smart,
                smartRules: CatalogSmartCollectionRules::fromInput($rules),
            ),
        );
    }

    private function title(
        string $name,
        int $year,
        Country $country,
        Genre $genre,
        float $rating,
    ): CatalogTitle {
        $title = CatalogTitle::factory()->create(['title' => $name, 'year' => $year]);
        $title->countries()->attach($country);
        $title->genres()->attach($genre);
        CatalogTitleRating::query()->create([
            'catalog_title_id' => $title->id,
            'provider' => 'imdb',
            'rating' => $rating,
            'votes' => 1_000,
        ]);

        return $title;
    }

    private function completedSeason(CatalogTitle $title, int $episodes): Episode
    {
        $season = Season::factory()->for($title)->create([
            'number' => 1,
            'kind' => ReleaseKind::Regular,
            'episodes_released' => $episodes,
            'episodes_total' => $episodes,
        ]);

        return Episode::factory()->for($season)->create(['number' => 1]);
    }

    private function media(
        CatalogTitle $title,
        bool $subtitles,
        int $minutes,
    ): LicensedMedia {
        return LicensedMedia::factory()->for($title)->create([
            'status' => 'published',
            'published_at' => now(),
            'has_subtitles' => $subtitles,
            'duration_seconds' => $minutes * 60,
            'health_status' => MediaHealthStatus::Active,
        ]);
    }
}
