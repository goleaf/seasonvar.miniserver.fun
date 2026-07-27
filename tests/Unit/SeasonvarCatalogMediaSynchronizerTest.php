<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\MediaFileSizeCheckStatus;
use App\Enums\MediaHealthStatus;
use App\Models\CatalogTitle;
use App\Models\Season;
use App\Services\Seasonvar\SeasonvarCatalogMediaSynchronizer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SeasonvarCatalogMediaSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'release-calendar.enabled' => false,
            'seasonvar.media_check.enabled' => false,
        ]);
        Http::preventStrayRequests();
    }

    public function test_sync_is_idempotent_deduplicates_candidates_and_preserves_disabled_health(): void
    {
        [$title, $seasons] = $this->titleWithEpisodes(1);
        $candidate = $this->candidate(1);
        $synchronizer = app(SeasonvarCatalogMediaSynchronizer::class);

        $first = $synchronizer->synchronize($title, $seasons, [$candidate, $candidate]);

        $this->assertSame([
            'attached' => 1,
            'updated' => 0,
            'skipped' => 1,
            'failed' => 0,
        ], $first->toArray());
        $media = $title->licensedMedia()->sole();
        $media->forceFill([
            'health_status' => MediaHealthStatus::Disabled,
            'file_size_bytes' => 1_234_567,
            'file_size_checked_at' => now(),
            'file_size_check_status' => MediaFileSizeCheckStatus::Known,
            'file_size_source' => 'content-length',
        ])->saveQuietly();
        $changed = [
            ...$candidate,
            'url' => 'https://media.example.com/show/s01e01-v2-1080p.mp4',
        ];

        $second = $synchronizer->synchronize($title, $seasons, [$changed]);
        $media->refresh();

        Http::assertNothingSent();
        $this->assertSame(1, $second->updated);
        $this->assertSame(MediaHealthStatus::Disabled, $media->health_status);
        $this->assertSame(MediaFileSizeCheckStatus::Pending, $media->file_size_check_status);
        $this->assertNull($media->file_size_bytes);
        $this->assertNull($media->file_size_checked_at);
        $this->assertSame(
            ['attached' => 0, 'updated' => 0, 'skipped' => 1, 'failed' => 0],
            $synchronizer->synchronize($title, $seasons, [$changed])->toArray(),
        );
    }

    public function test_one_thousand_unchanged_candidates_use_a_bounded_identity_query_profile(): void
    {
        [$title, $seasons] = $this->titleWithEpisodes(1_000);
        $candidates = [];

        for ($episode = 1; $episode <= 1_000; $episode++) {
            $candidates[] = $this->candidate($episode);
        }

        $synchronizer = app(SeasonvarCatalogMediaSynchronizer::class);
        $created = $synchronizer->synchronize($title, $seasons, $candidates);
        $queries = 0;

        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries++;
        });

        $unchanged = $synchronizer->synchronize($title, $seasons, $candidates);

        $this->assertSame(1_000, $created->attached);
        $this->assertSame(0, $unchanged->updated);
        $this->assertSame(1_000, $unchanged->skipped);
        $this->assertLessThanOrEqual(20, $queries);
        Http::assertNothingSent();
    }

    /**
     * @return array{CatalogTitle, array<int, Season>}
     */
    private function titleWithEpisodes(int $count): array
    {
        $title = CatalogTitle::factory()->create([
            'source_url_hash' => hash('sha256', 'seasonvar-media-sync-profile'),
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $title->id,
            'number' => 1,
        ]);
        $timestamp = now()->toDateTimeString();
        $rows = [];

        for ($episode = 1; $episode <= $count; $episode++) {
            $rows[] = [
                'season_id' => $season->id,
                'number' => $episode,
                'kind' => 'regular',
                'sort_order' => $episode,
                'publication_status' => 'published',
                'audience' => 'public',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('episodes')->insert($chunk);
        }

        return [$title, [1 => $season]];
    }

    /**
     * @return array{url: string, title: string, season_number: int, episode_number: int, source_url: string, kind: string, storage_disk: string}
     */
    private function candidate(int $episode): array
    {
        return [
            'url' => sprintf('https://media.example.com/show/s01e%04d-1080p.mp4', $episode),
            'title' => "{$episode} серия 1080p",
            'season_number' => 1,
            'episode_number' => $episode,
            'source_url' => "https://seasonvar.ru/playls2/show/episode-{$episode}.txt",
            'kind' => 'file',
            'storage_disk' => 'seasonvar_parsed',
        ];
    }
}
