<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ContentAudience;
use App\Enums\ReleaseKind;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Catalog\CatalogTitlePlaybackQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogTitlePlaybackQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_watchable_episode_requires_media_to_match_the_complete_release_hierarchy(): void
    {
        [$title, $season, $episode] = $this->releaseHierarchy();
        $otherTitle = CatalogTitle::factory()->create();
        $otherSeason = Season::factory()->create(['catalog_title_id' => $otherTitle->id]);
        $media = $this->publishedMedia($title, $otherSeason, $episode);
        $playback = app(CatalogTitlePlaybackQuery::class);

        $this->assertNull($playback->firstWatchableEpisode($title, null));

        $media->update([
            'catalog_title_id' => $otherTitle->id,
            'season_id' => $season->id,
        ]);

        $this->assertNull($playback->firstWatchableEpisode($title, null));

        $media->update(['catalog_title_id' => $title->id]);

        $this->assertSame($episode->id, $playback->firstWatchableEpisode($title, null)?->id);
    }

    public function test_watchable_episode_preserves_release_media_location_and_health_boundaries(): void
    {
        $mutations = [
            'hidden title' => static fn (CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media): bool => $title->update(['publication_status' => 'hidden']),
            'hidden season' => static fn (CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media): bool => $season->update(['publication_status' => 'hidden']),
            'hidden episode' => static fn (CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media): bool => $episode->update(['publication_status' => 'hidden']),
            'draft media' => static fn (CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media): bool => $media->update(['status' => 'draft']),
            'future media' => static fn (CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media): bool => $media->update(['available_from' => now()->addMinute()]),
            'expired media' => static fn (CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media): bool => $media->update(['available_until' => now()->subMinute()]),
            'failed media' => static fn (CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media): bool => $media->update(['health_status' => 'unavailable']),
            'source-less media' => static fn (CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media): bool => $media->update(['path' => '', 'playback_url' => null]),
            'deleted media' => static fn (CatalogTitle $title, Season $season, Episode $episode, LicensedMedia $media): bool => $media->delete(),
        ];
        $playback = app(CatalogTitlePlaybackQuery::class);

        foreach ($mutations as $case => $mutate) {
            [$title, $season, $episode, $media] = $this->playableHierarchy();

            $this->assertTrue($mutate($title, $season, $episode, $media), $case);
            $this->assertNull($playback->firstWatchableEpisode($title, null), $case);
        }
    }

    public function test_watchable_query_uses_direct_media_hierarchy_correlations_without_nested_release_checks(): void
    {
        $sql = str(app(CatalogTitlePlaybackQuery::class)->watchableEpisodesForVisibleTitles(null)->toSql())
            ->replace(['`', '"'], '')
            ->lower()
            ->squish()
            ->toString();

        $this->assertStringContainsString('licensed_media.episode_id = episodes.id', $sql);
        $this->assertStringContainsString('licensed_media.season_id = episodes.season_id', $sql);
        $this->assertStringContainsString('licensed_media.catalog_title_id = seasons.catalog_title_id', $sql);
        $this->assertStringNotContainsString('season_id is null or exists (select * from seasons where licensed_media.season_id = seasons.id', $sql);
        $this->assertStringNotContainsString('episode_id is null or exists (select * from episodes where licensed_media.episode_id = episodes.id', $sql);
    }

    public function test_title_watchable_query_is_bounded_without_global_release_lists(): void
    {
        $title = CatalogTitle::factory()->create();
        $playback = app(CatalogTitlePlaybackQuery::class);
        $specific = str($playback->watchableEpisodes($title, null)->toSql())->replace(['`', '"'], '')->lower()->squish()->toString();
        $global = str($playback->watchableEpisodesForVisibleTitles(null)->toSql())->replace(['`', '"'], '')->lower()->squish()->toString();

        $this->assertStringContainsString('seasons.catalog_title_id = ?', $specific);
        $this->assertStringNotContainsString('seasons.catalog_title_id in (select', $specific);
        $this->assertStringContainsString('episodes.season_id in (select id from seasons', $specific);
        $this->assertStringContainsString('seasons.catalog_title_id = ?', $specific);
        $this->assertStringContainsString('exists (select 1 from catalog_titles', $global);
        $this->assertStringContainsString('exists (select 1 from seasons where', $global);
        $this->assertStringContainsString('seasons.id = episodes.season_id', $global);
    }

    public function test_episode_navigation_uses_the_loaded_season_lane_without_database_queries(): void
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'number' => 1,
            'sort_order' => 1,
        ]);
        $first = $this->publishedEpisode($title, $season, 1, 1);
        $middle = $this->publishedEpisode($title, $season, 2, 2);
        $last = $this->publishedEpisode($title, $season, 3, 3);
        $special = $this->publishedEpisode($title, $season, 1, 1, ReleaseKind::Special);
        $playback = app(CatalogTitlePlaybackQuery::class);
        $seasons = $playback->seasonSummaries($title, null);
        $episodes = $playback->episodesForSeason($title, $season, null);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $firstNavigation = $playback->episodeNavigation($title, $season, null, $first, $episodes, $seasons);
        $middleNavigation = $playback->episodeNavigation($title, $season, null, $middle, $episodes, $seasons);
        $lastNavigation = $playback->episodeNavigation($title, $season, null, $last, $episodes, $seasons);
        $specialNavigation = $playback->episodeNavigation($title, $season, null, $special, $episodes, $seasons);
        $queryCount = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertNull($firstNavigation->previous);
        $this->assertSame($middle->id, $firstNavigation->next?->id);
        $this->assertSame($first->id, $middleNavigation->previous?->id);
        $this->assertSame($last->id, $middleNavigation->next?->id);
        $this->assertSame($middle->id, $lastNavigation->previous?->id);
        $this->assertNull($lastNavigation->next);
        $this->assertNull($specialNavigation->previous);
        $this->assertNull($specialNavigation->next);
        $this->assertSame(0, $queryCount);
    }

    public function test_episode_navigation_queries_only_the_crossed_season_boundary(): void
    {
        $title = CatalogTitle::factory()->create();
        $firstSeason = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'number' => 1,
            'sort_order' => 1,
        ]);
        $secondSeason = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'number' => 2,
            'sort_order' => 2,
        ]);
        $first = $this->publishedEpisode($title, $firstSeason, 1, 1);
        $last = $this->publishedEpisode($title, $firstSeason, 2, 2);
        $next = $this->publishedEpisode($title, $secondSeason, 1, 1);
        $playback = app(CatalogTitlePlaybackQuery::class);
        $seasons = $playback->seasonSummaries($title, null);
        $firstSeasonEpisodes = $playback->episodesForSeason($title, $firstSeason, null);
        $secondSeasonEpisodes = $playback->episodesForSeason($title, $secondSeason, null);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $forward = $playback->episodeNavigation(
            $title,
            $firstSeason,
            null,
            $last,
            $firstSeasonEpisodes,
            $seasons,
        );
        $forwardQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();

        $backward = $playback->episodeNavigation(
            $title,
            $secondSeason,
            null,
            $next,
            $secondSeasonEpisodes,
            $seasons,
        );
        $backwardQueryCount = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertSame($first->id, $forward->previous?->id);
        $this->assertSame($next->id, $forward->next?->id);
        $this->assertSame($last->id, $backward->previous?->id);
        $this->assertNull($backward->next);
        $this->assertSame(1, $forwardQueryCount);
        $this->assertSame(1, $backwardQueryCount);
    }

    public function test_episode_navigation_rejects_an_episode_outside_the_loaded_season_without_queries(): void
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->create(['catalog_title_id' => $title->id]);
        $loadedEpisode = $this->publishedEpisode($title, $season, 1, 1);
        $outsideEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 2,
            'sort_order' => 2,
        ]);
        $playback = app(CatalogTitlePlaybackQuery::class);
        $seasons = $playback->seasonSummaries($title, null);
        $episodes = collect([$loadedEpisode]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $navigation = $playback->episodeNavigation(
            $title,
            $season,
            null,
            $outsideEpisode,
            $episodes,
            $seasons,
        );
        $queryCount = count(DB::getQueryLog());

        DB::disableQueryLog();

        $this->assertNull($navigation->previous);
        $this->assertNull($navigation->next);
        $this->assertSame(0, $queryCount);
    }

    public function test_episode_page_is_bounded_to_visible_playable_items_without_media_eager_loading(): void
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'number' => 1,
            'sort_order' => 1,
        ]);

        for ($number = 1; $number <= 27; $number++) {
            $this->publishedEpisode($title, $season, $number, $number);
        }

        $hiddenEpisode = $this->publishedEpisode($title, $season, 28, 28);
        $hiddenEpisode->update(['publication_status' => 'hidden']);
        $expiredEpisode = $this->publishedEpisode($title, $season, 29, 29);
        $expiredEpisode->update(['available_until' => now()->subMinute()]);
        $sourceLessEpisode = $this->publishedEpisode($title, $season, 30, 30);
        $sourceLessEpisode->licensedMedia()->update(['path' => '', 'playback_url' => null]);
        $authenticatedEpisode = $this->publishedEpisode($title, $season, 31, 31);
        $authenticatedEpisode->update(['audience' => ContentAudience::Authenticated]);

        $otherTitle = CatalogTitle::factory()->create();
        $otherSeason = Season::factory()->create(['catalog_title_id' => $otherTitle->id]);
        $foreignEpisode = $this->publishedEpisode($otherTitle, $otherSeason, 1, 1);
        $playback = app(CatalogTitlePlaybackQuery::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $firstPage = $playback->episodesForSeasonPage($title, $season, null, 1);
        $queryCount = count(DB::getQueryLog());
        $secondPage = $playback->episodesForSeasonPage($title, $season, null, 2);

        DB::disableQueryLog();

        self::assertCount(24, $firstPage->items());
        self::assertCount(3, $secondPage->items());
        self::assertSame(27, $firstPage->total());
        self::assertSame(2, $firstPage->lastPage());
        self::assertSame(1, $firstPage->currentPage());
        self::assertSame(2, $secondPage->currentPage());
        self::assertNotContains($hiddenEpisode->id, $firstPage->getCollection()->modelKeys());
        self::assertNotContains($expiredEpisode->id, $secondPage->getCollection()->modelKeys());
        self::assertNotContains($sourceLessEpisode->id, $secondPage->getCollection()->modelKeys());
        self::assertNotContains($authenticatedEpisode->id, $secondPage->getCollection()->modelKeys());
        self::assertNotContains($foreignEpisode->id, $secondPage->getCollection()->modelKeys());
        self::assertSame(2, $queryCount);

        foreach ($firstPage as $episode) {
            self::assertSame(1, (int) $episode->getAttribute('available_media_count'));
            self::assertFalse($episode->relationLoaded('licensedMedia'));
        }
    }

    public function test_episode_page_fails_closed_before_querying_for_invalid_hierarchy_or_bounds(): void
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->create(['catalog_title_id' => $title->id]);
        $otherSeason = Season::factory()->create();
        $playback = app(CatalogTitlePlaybackQuery::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $pages = [
            $playback->episodesForSeasonPage($title, $otherSeason, null, 1),
            $playback->episodesForSeasonPage($title, $season, null, 0),
            $playback->episodesForSeasonPage($title, $season, null, -1),
            $playback->episodesForSeasonPage($title, $season, null, 1_000_001),
            $playback->episodesForSeasonPage($title, $season, null, 1, 25),
        ];
        $queryCount = count(DB::getQueryLog());

        DB::disableQueryLog();

        foreach ($pages as $page) {
            self::assertSame([], $page->items());
            self::assertSame(0, $page->total());
        }

        self::assertSame(0, $queryCount);
    }

    public function test_direct_episode_navigation_crosses_only_compatible_release_lanes(): void
    {
        $title = CatalogTitle::factory()->create();
        $firstSeason = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'kind' => ReleaseKind::Regular,
            'number' => 1,
            'sort_order' => 1,
        ]);
        $secondSeason = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'kind' => ReleaseKind::Regular,
            'number' => 2,
            'sort_order' => 2,
        ]);
        $specialSeason = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'kind' => ReleaseKind::Special,
            'number' => 1,
            'sort_order' => 3,
        ]);
        $previousRegular = $this->publishedEpisode($title, $firstSeason, 1, 1);
        $lastRegularInSeasonOne = $this->publishedEpisode($title, $firstSeason, 2, 2);
        $firstRegularInSeasonTwo = $this->publishedEpisode($title, $secondSeason, 1, 1);
        $lastRegular = $this->publishedEpisode($title, $secondSeason, 2, 2);
        $specialInSeasonOne = $this->publishedEpisode($title, $firstSeason, 1, 1, ReleaseKind::Special);
        $specialInSeasonTwo = $this->publishedEpisode($title, $secondSeason, 1, 1, ReleaseKind::Special);
        $incompatibleSpecial = $this->publishedEpisode($title, $specialSeason, 1, 1, ReleaseKind::Special);
        $playback = app(CatalogTitlePlaybackQuery::class);

        $navigation = $playback->navigationForEpisode($title, null, $lastRegularInSeasonOne);
        $specialNavigation = $playback->navigationForEpisode($title, null, $specialInSeasonOne);
        $finalNavigation = $playback->navigationForEpisode($title, null, $lastRegular);

        self::assertSame($previousRegular->id, $navigation->previous?->id);
        self::assertSame($firstRegularInSeasonTwo->id, $navigation->next?->id);
        self::assertNull($specialNavigation->previous);
        self::assertSame($specialInSeasonTwo->id, $specialNavigation->next?->id);
        self::assertNotSame($incompatibleSpecial->id, $specialNavigation->next?->id);
        self::assertSame($firstRegularInSeasonTwo->id, $finalNavigation->previous?->id);
        self::assertNull($finalNavigation->next);
    }

    /** @return array{CatalogTitle, Season, Episode} */
    private function releaseHierarchy(): array
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->create(['catalog_title_id' => $title->id]);
        $episode = Episode::factory()->create(['season_id' => $season->id]);

        return [$title, $season, $episode];
    }

    /** @return array{CatalogTitle, Season, Episode, LicensedMedia} */
    private function playableHierarchy(): array
    {
        [$title, $season, $episode] = $this->releaseHierarchy();

        return [$title, $season, $episode, $this->publishedMedia($title, $season, $episode)];
    }

    private function publishedEpisode(
        CatalogTitle $title,
        Season $season,
        int $number,
        int $sortOrder,
        ReleaseKind $kind = ReleaseKind::Regular,
    ): Episode {
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => $number,
            'kind' => $kind,
            'sort_order' => $sortOrder,
        ]);

        $this->publishedMedia($title, $season, $episode);

        return $episode;
    }

    private function publishedMedia(CatalogTitle $title, Season $season, Episode $episode): LicensedMedia
    {
        return LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'storage_disk' => 'external_playlist',
            'path' => 'https://data00-cdn.11cdn.org/title-playback-query.m3u8',
            'playback_url' => 'https://data00-cdn.11cdn.org/title-playback-query.m3u8',
            'status' => 'published',
            'published_at' => now()->subMinute(),
            'check_status' => 'available',
            'health_status' => 'active',
        ]);
    }
}
