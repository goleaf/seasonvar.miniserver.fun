<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Catalog\CatalogStatsPageBuilder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogStatsQueryConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_builder_preserves_exact_values_while_consolidating_repeated_queries(): void
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->for($title)->create();
        $firstEpisode = Episode::factory()->for($season)->create();
        $secondEpisode = Episode::factory()->for($season)->create();
        Episode::factory()->for($season)->create();

        $sharedUrl = 'https://media.example.com/shared.mp4';

        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $firstEpisode->id,
            'path' => $sharedUrl,
            'playback_url' => $sharedUrl,
            'source_url' => 'https://seasonvar.ru/source-one.html',
            'status' => 'published',
            'published_at' => now(),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $firstEpisode->id,
            'path' => $sharedUrl,
            'playback_url' => $sharedUrl,
            'source_url' => 'https://seasonvar.ru/source-one.html',
            'status' => 'published',
            'published_at' => now(),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $secondEpisode->id,
            'path' => 'licensed/relative.mp4',
            'playback_url' => null,
            'source_url' => 'http://seasonvar.ru/source-two.html',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = Str::squish($query->sql);
        });

        $data = app(CatalogStatsPageBuilder::class)->data();

        /** @var Collection<int, array{label: string, count: int}> $internalLinks */
        $internalLinks = $data['internalLinkRows'];
        /** @var Collection<int, array{label: string, filled_display: string, unique_display: string, absolute_display: string, empty_display: string}> $externalUrls */
        $externalUrls = $data['externalUrlFieldRows'];

        $linksByLabel = $internalLinks->keyBy('label');
        $urlsByLabel = $externalUrls->keyBy('label');

        $this->assertSame(2, $linksByLabel->get('Выбор видео на странице сериала')['count']);
        $this->assertSame(2, $linksByLabel->get('Выбор серии на странице сериала')['count']);
        $this->assertSame([
            'filled_display' => '3',
            'unique_display' => '2',
            'absolute_display' => '2',
            'empty_display' => '0',
        ], $this->urlValues($urlsByLabel, 'Ссылка на видео'));
        $this->assertSame([
            'filled_display' => '2',
            'unique_display' => '1',
            'absolute_display' => '2',
            'empty_display' => '1',
        ], $this->urlValues($urlsByLabel, 'Ссылка воспроизведения'));
        $this->assertSame([
            'filled_display' => '3',
            'unique_display' => '2',
            'absolute_display' => '3',
            'empty_display' => '0',
        ], $this->urlValues($urlsByLabel, 'Источник видео'));

        $this->assertCount(1, $this->queriesContainingAll($queries, [
            'video_count',
            'episode_count',
        ]));
        $this->assertCount(1, $this->queriesContainingAll($queries, [
            'path_distinct',
            'playback_url_distinct',
            'source_url_distinct',
        ]));
        $this->assertCount(1, $this->queriesContainingAll($queries, [
            'from sqlite_schema as schema',
            'join pragma_index_list(schema.name)',
            'join pragma_index_info(indexes.name)',
        ]));
        $this->assertCount(1, $this->queriesContainingAll($queries, [
            ' union all ',
            'from "migrations"',
        ]));
        $this->assertCount(1, $this->queriesContainingAll($queries, [
            'select count(*) as "aggregate" from "catalog_titles" where not exists',
            'from "licensed_media"',
        ]));
        $this->assertCount(1, $this->queriesContainingAll($queries, [
            'select count(*) as "aggregate" from "episodes" where not exists',
            'from "licensed_media"',
        ]));
        $this->assertSame([], array_values(array_filter(
            $queries,
            fn (string $sql): bool => str_starts_with($sql, 'PRAGMA index_list(')
                || str_starts_with($sql, 'PRAGMA index_info('),
        )));
        $this->assertSame([], $this->queriesContainingAll($queries, [
            'select count(*) as "aggregate"',
            'from "migrations"',
        ]));
    }

    /**
     * @param  Collection<int|string, array{label: string, filled_display: string, unique_display: string, absolute_display: string, empty_display: string}>  $rows
     * @return array{filled_display: string, unique_display: string, absolute_display: string, empty_display: string}
     */
    private function urlValues(Collection $rows, string $label): array
    {
        $row = $rows->get($label);

        return [
            'filled_display' => $row['filled_display'],
            'unique_display' => $row['unique_display'],
            'absolute_display' => $row['absolute_display'],
            'empty_display' => $row['empty_display'],
        ];
    }

    /**
     * @param  list<string>  $queries
     * @param  list<string>  $needles
     * @return list<string>
     */
    private function queriesContainingAll(array $queries, array $needles): array
    {
        return array_values(array_filter(
            $queries,
            fn (string $sql): bool => collect($needles)
                ->every(fn (string $needle): bool => str_contains(Str::lower($sql), Str::lower($needle))),
        ));
    }
}
