<?php

declare(strict_types=1);

namespace Tests\Performance;

use App\Models\CatalogTitle;
use App\Models\Season;
use App\Models\Source;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarTitleMerger;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SeasonvarTitleMergeProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_large_season_family_merge_has_a_bounded_query_profile(): void
    {
        [$canonical, $duplicate] = $this->largeSeasonFamily();
        $queries = 0;
        $queryShapes = [];

        DB::listen(static function (QueryExecuted $query) use (&$queries, &$queryShapes): void {
            $queries++;
            $shape = preg_replace('/\\s+/', ' ', $query->sql) ?? $query->sql;
            $queryShapes[$shape] = ($queryShapes[$shape] ?? 0) + 1;
        });

        $startedAt = hrtime(true);
        $result = app(SeasonvarTitleMerger::class)
            ->mergeForCanonicalSlug($canonical->slug);
        $durationMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        $this->assertSame(1, $result['titles']);
        $this->assertSame(30, $result['seasons']);
        $this->assertSame(930, $result['episodes']);
        $this->assertDatabaseMissing('catalog_titles', ['id' => $duplicate->id]);
        $this->assertSame(30, $canonical->fresh()->seasons()->count());
        $this->assertSame(930, $canonical->fresh()->episodes()->count());
        $this->assertSame(957, $canonical->fresh()->licensedMedia()->count());
        $this->assertLessThanOrEqual(
            5_000,
            $queries,
            sprintf(
                'Large merge used %d queries in %.3f ms. Top shapes: %s',
                $queries,
                $durationMilliseconds,
                json_encode(
                    collect($queryShapes)->sortDesc()->take(20)->all(),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
            ),
        );
    }

    /**
     * @return array{CatalogTitle, CatalogTitle}
     */
    private function largeSeasonFamily(): array
    {
        $source = Source::factory()->create(['code' => 'seasonvar']);
        $canonicalPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => 'https://seasonvar.ru/serial-99001-Profil_merge-1-season.html',
        ]);
        $duplicatePage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => 'https://seasonvar.ru/serial-99002-Profil_merge-2-season.html',
        ]);
        $canonical = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $canonicalPage->id,
            'external_id' => '99001',
            'slug' => 'profil-merge',
            'title' => 'Профиль merge',
            'source_url' => $canonicalPage->url,
            'source_url_hash' => hash('sha256', $canonicalPage->url),
        ]);
        $duplicate = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $duplicatePage->id,
            'external_id' => '99002',
            'slug' => 'profil-merge-2',
            'title' => 'Профиль merge',
            'source_url' => $duplicatePage->url,
            'source_url_hash' => hash('sha256', $duplicatePage->url),
        ]);
        $timestamp = now()->toDateTimeString();

        for ($seasonNumber = 1; $seasonNumber <= 30; $seasonNumber++) {
            $seasonUrl = "https://seasonvar.ru/serial-99001-Profil_merge-{$seasonNumber}-season.html";
            $seasonHash = hash('sha256', $seasonUrl);
            $canonicalSeason = Season::factory()->create([
                'catalog_title_id' => $canonical->id,
                'source_page_id' => $canonicalPage->id,
                'number' => $seasonNumber,
                'source_url' => $seasonUrl,
                'source_url_hash' => $seasonHash,
            ]);
            $duplicateSeason = Season::factory()->create([
                'catalog_title_id' => $duplicate->id,
                'source_page_id' => $duplicatePage->id,
                'number' => $seasonNumber,
                'source_url' => $seasonUrl,
                'source_url_hash' => $seasonHash,
            ]);
            $canonicalEpisodes = [];
            $duplicateEpisodes = [];

            for ($episodeNumber = 1; $episodeNumber <= 31; $episodeNumber++) {
                $base = [
                    'number' => $episodeNumber,
                    'kind' => 'regular',
                    'sort_order' => $episodeNumber,
                    'publication_status' => 'published',
                    'audience' => 'public',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
                $canonicalEpisodes[] = [
                    ...$base,
                    'season_id' => $canonicalSeason->id,
                    'source_page_id' => $canonicalPage->id,
                ];
                $duplicateEpisodes[] = [
                    ...$base,
                    'season_id' => $duplicateSeason->id,
                    'source_page_id' => $duplicatePage->id,
                ];
            }

            DB::table('episodes')->insert($canonicalEpisodes);
            DB::table('episodes')->insert($duplicateEpisodes);
            $canonicalEpisodeIds = DB::table('episodes')
                ->where('season_id', $canonicalSeason->id)
                ->orderBy('number')
                ->pluck('id');
            $duplicateEpisodeIds = DB::table('episodes')
                ->where('season_id', $duplicateSeason->id)
                ->orderBy('number')
                ->pluck('id');
            $canonicalMedia = [];
            $duplicateMedia = [];

            foreach ($canonicalEpisodeIds as $offset => $episodeId) {
                $key = "episode-{$seasonNumber}-".($offset + 1);
                $path = "licensed/{$key}.mp4";
                $base = [
                    'title' => $key,
                    'storage_disk' => 'local',
                    'path' => $path,
                    'playback_url' => "https://media.example.test/{$key}.mp4",
                    'status' => 'published',
                    'audience' => 'public',
                    'source_media_key' => $key,
                    'format' => 'mp4',
                    'health_status' => 'active',
                    'has_subtitles' => false,
                    'consecutive_failures' => 0,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
                $canonicalMedia[] = [
                    ...$base,
                    'catalog_title_id' => $canonical->id,
                    'season_id' => $canonicalSeason->id,
                    'episode_id' => $episodeId,
                ];
                $duplicateMedia[] = [
                    ...$base,
                    'catalog_title_id' => $duplicate->id,
                    'season_id' => $duplicateSeason->id,
                    'episode_id' => $duplicateEpisodeIds[$offset],
                ];
            }

            if ($seasonNumber <= 27) {
                $key = "season-{$seasonNumber}";
                $base = [
                    'title' => $key,
                    'storage_disk' => 'local',
                    'path' => "licensed/{$key}.mp4",
                    'playback_url' => "https://media.example.test/{$key}.mp4",
                    'status' => 'published',
                    'audience' => 'public',
                    'source_media_key' => $key,
                    'format' => 'mp4',
                    'health_status' => 'active',
                    'has_subtitles' => false,
                    'consecutive_failures' => 0,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
                $canonicalMedia[] = [
                    ...$base,
                    'catalog_title_id' => $canonical->id,
                    'season_id' => $canonicalSeason->id,
                    'episode_id' => null,
                ];
                $duplicateMedia[] = [
                    ...$base,
                    'catalog_title_id' => $duplicate->id,
                    'season_id' => $duplicateSeason->id,
                    'episode_id' => null,
                ];
            }

            DB::table('licensed_media')->insert($canonicalMedia);
            DB::table('licensed_media')->insert($duplicateMedia);
        }

        return [$canonical, $duplicate];
    }
}
