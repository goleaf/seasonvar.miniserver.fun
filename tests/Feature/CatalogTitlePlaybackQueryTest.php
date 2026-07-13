<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Catalog\CatalogTitlePlaybackQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
