<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Services\Catalog\CatalogStatsPageBuilder;
use App\Services\Catalog\CatalogStatsSnapshotCache;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CatalogStatsQueryConsolidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_builder_reuses_path_distinct_count_only_after_proving_playback_urls_match(): void
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->for($title)->create();
        $episode = Episode::factory()->for($season)->create();

        foreach ([
            [
                'path' => 'https://media.example.com/shared.mp4',
                'playback_url' => 'https://media.example.com/shared.mp4',
            ],
            [
                'path' => 'https://media.example.com/shared.mp4',
                'playback_url' => 'https://media.example.com/shared.mp4',
            ],
            [
                'path' => '',
                'playback_url' => null,
            ],
            [
                'path' => ' ',
                'playback_url' => ' ',
            ],
        ] as $index => $urls) {
            LicensedMedia::factory()->create([
                'catalog_title_id' => $title->id,
                'season_id' => $season->id,
                'episode_id' => $episode->id,
                'path' => $urls['path'],
                'playback_url' => $urls['playback_url'],
                'source_url' => 'https://seasonvar.ru/source-'.$index.'.html',
                'status' => 'published',
                'published_at' => now(),
            ]);
        }

        $queries = [];
        $primaryBindings = [];

        DB::listen(function (QueryExecuted $query) use (&$primaryBindings, &$queries): void {
            $queries[] = Str::squish($query->sql);

            if (str_contains($query->sql, 'playback_url_path_mismatches')) {
                $primaryBindings = $query->bindings;
            }
        });

        $data = app(CatalogStatsPageBuilder::class)->data();

        /** @var Collection<int, array{label: string, filled_display: string, unique_display: string, absolute_display: string, empty_display: string}> $externalUrls */
        $externalUrls = $data['externalUrlFieldRows'];
        $urlsByLabel = $externalUrls->keyBy('label');

        $expectedValues = [
            'filled_display' => '3',
            'unique_display' => '2',
            'absolute_display' => '2',
            'empty_display' => '1',
        ];

        $this->assertSame($expectedValues, $this->urlValues($urlsByLabel, 'Ссылка на видео'));
        $this->assertSame($expectedValues, $this->urlValues($urlsByLabel, 'Ссылка воспроизведения'));
        $this->assertSame([
            '',
            '',
            'https://%',
            'http://%',
            '',
            'https://%',
            'http://%',
            '',
            '',
            'https://%',
            'http://%',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ], $primaryBindings);
        $this->assertCount(1, $this->queriesContainingAll($queries, [
            'path_distinct',
            'source_url_distinct',
            'playback_url_path_mismatches',
        ]));
        $this->assertSame([], $this->queriesContainingAll($queries, [
            'playback_url_distinct',
        ]));
        $this->assertSame([], $this->queriesContainingAll($queries, [
            'select count(distinct "playback_url") as aggregate',
            'from "licensed_media"',
        ]));
    }

    public function test_stats_builder_falls_back_when_filled_path_and_playback_urls_differ(): void
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->for($title)->create();
        $episode = Episode::factory()->for($season)->create();

        foreach ([
            'https://media.example.com/first.mp4',
            'https://media.example.com/second.mp4',
        ] as $playbackUrl) {
            LicensedMedia::factory()->create([
                'catalog_title_id' => $title->id,
                'season_id' => $season->id,
                'episode_id' => $episode->id,
                'path' => 'https://media.example.com/shared.mp4',
                'playback_url' => $playbackUrl,
                'source_url' => 'https://seasonvar.ru/'.Str::slug($playbackUrl).'.html',
                'status' => 'published',
                'published_at' => now(),
            ]);
        }

        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = Str::squish($query->sql);
        });

        $data = app(CatalogStatsPageBuilder::class)->data();

        /** @var Collection<int, array{label: string, filled_display: string, unique_display: string, absolute_display: string, empty_display: string}> $externalUrls */
        $externalUrls = $data['externalUrlFieldRows'];
        $urlsByLabel = $externalUrls->keyBy('label');

        $this->assertSame('1', $urlsByLabel->get('Ссылка на видео')['unique_display']);
        $this->assertSame('2', $urlsByLabel->get('Ссылка воспроизведения')['unique_display']);
        $this->assertCount(1, $this->queriesContainingAll($queries, [
            'playback_url_path_mismatches',
        ]));
        $this->assertCount(1, $this->queriesContainingAll($queries, [
            'count(distinct "playback_url")',
            'from "licensed_media"',
        ]));
    }

    public function test_invalidated_snapshot_rebuild_resets_request_local_stats(): void
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->for($title)->create();
        $episode = Episode::factory()->for($season)->create();
        $cache = app(CatalogStatsSnapshotCache::class);

        $before = $cache->snapshot();

        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'path' => 'https://media.example.com/new.mp4',
            'playback_url' => 'https://media.example.com/new.mp4',
            'source_url' => 'https://seasonvar.ru/new-source.html',
            'status' => 'published',
            'published_at' => now(),
        ]);
        DB::statement('CREATE INDEX catalog_stats_rebuild_probe_idx ON licensed_media (status)');

        $cache->forget();
        $after = $cache->snapshot();

        $this->assertSame(0, $this->internalLinkCount($before['data'], 'Выбор видео на странице сериала'));
        $this->assertSame(1, $this->internalLinkCount($after['data'], 'Выбор видео на странице сериала'));
        $this->assertSame(1, $this->externalUrlFilledCount($after['data'], 'Ссылка на видео'));
        $this->assertSame(1, $this->headlineValue($after['data'], 'Видео-ссылок'));
        $this->assertSame(1, $this->summaryValue($before['data'], 'Каталог', 'Без опубликованного видео'));
        $this->assertSame(0, $this->summaryValue($after['data'], 'Каталог', 'Без опубликованного видео'));
        $this->assertSame(1, $this->summaryValue($before['data'], 'Сезоны и серии', 'Серий без опубликованного видео'));
        $this->assertSame(0, $this->summaryValue($after['data'], 'Сезоны и серии', 'Серий без опубликованного видео'));
        $this->assertSame(
            $this->headlineValue($before['data'], 'Индексов базы') + 1,
            $this->headlineValue($after['data'], 'Индексов базы'),
        );
    }

    public function test_stats_builder_preserves_exact_values_while_consolidating_repeated_queries(): void
    {
        $title = CatalogTitle::factory()->create();
        $season = Season::factory()->for($title)->create();
        $firstEpisode = Episode::factory()->for($season)->create(['number' => 1]);
        $secondEpisode = Episode::factory()->for($season)->create(['number' => 2]);
        $draftEpisode = Episode::factory()->for($season)->create(['number' => 3]);

        $sharedUrl = 'https://media.example.com/shared.mp4';

        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $firstEpisode->id,
            'path' => $sharedUrl,
            'playback_url' => $sharedUrl,
            'source_url' => 'https://seasonvar.ru/source-one.html',
            'quality' => '1080p',
            'format' => 'mp4',
            'source_media_key' => 'stats-unified-one',
            'checked_at' => now(),
            'status' => 'published',
            'published_at' => now(),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $draftEpisode->id,
            'path' => 'https://media.example.com/draft.mp4',
            'playback_url' => 'https://media.example.com/draft.mp4',
            'source_url' => 'https://seasonvar.ru/draft-source.html',
            'quality' => '720p',
            'format' => 'mp4',
            'source_media_key' => 'stats-unified-two',
            'checked_at' => now(),
            'status' => 'draft',
            'published_at' => null,
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $firstEpisode->id,
            'path' => $sharedUrl,
            'playback_url' => $sharedUrl,
            'source_url' => 'https://seasonvar.ru/source-one.html',
            'quality' => '1080p',
            'format' => 'mp4',
            'source_media_key' => 'stats-unified-three',
            'checked_at' => now(),
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
            'quality' => '480p',
            'format' => 'mp4',
            'source_media_key' => 'stats-unified-four',
            'checked_at' => now(),
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
            'filled_display' => '4',
            'unique_display' => '3',
            'absolute_display' => '3',
            'empty_display' => '0',
        ], $this->urlValues($urlsByLabel, 'Ссылка на видео'));
        $this->assertSame([
            'filled_display' => '3',
            'unique_display' => '2',
            'absolute_display' => '3',
            'empty_display' => '1',
        ], $this->urlValues($urlsByLabel, 'Ссылка воспроизведения'));
        $this->assertSame([
            'filled_display' => '4',
            'unique_display' => '3',
            'absolute_display' => '4',
            'empty_display' => '0',
        ], $this->urlValues($urlsByLabel, 'Источник видео'));
        foreach ([
            'Связано с сериалом',
            'Связано с сезоном',
            'Связано с серией',
            'С качеством',
            'С форматом',
            'С постоянным ключом',
            'Проверено для просмотра',
        ] as $label) {
            $this->assertSame(4, $this->summaryValue($data, 'Видео', $label));
        }

        $this->assertCount(1, $this->queriesContainingAll($queries, [
            'video_count',
            'episode_count',
        ]));
        $this->assertCount(1, $this->queriesContainingAll($queries, [
            'path_distinct',
            'source_url_distinct',
            'playback_url_path_mismatches',
        ]));
        foreach ([
            ['catalog_titles', '"year"', 'poster_url_distinct'],
            ['seasons', '"episodes_total"', 'source_url_distinct'],
            ['episodes', '"released_at"', 'source_url_distinct'],
            ['licensed_media', '"catalog_title_id"', 'path_distinct'],
            ['source_pages', '"content_hash"', 'url_distinct'],
        ] as [$table, $presenceColumn, $urlDistinctAlias]) {
            $this->assertCount(1, $this->queriesContainingAll($queries, [
                'from "'.$table.'"',
                $presenceColumn,
                $urlDistinctAlias,
            ]));
            $this->assertSame([], array_values(array_filter(
                $queries,
                fn (string $sql): bool => str_contains($sql, 'from "'.$table.'"')
                    && str_contains(Str::lower($sql), 'sum(case when')
                    && str_contains($sql, $presenceColumn)
                    && ! str_contains($sql, $urlDistinctAlias),
            )));
        }
        $this->assertCount(1, $this->queriesContainingAll($queries, [
            'count(distinct "playback_url")',
            'from "licensed_media"',
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
     * @param  array<string, mixed>  $data
     */
    private function internalLinkCount(array $data, string $label): int
    {
        return (int) $this->rowValue($data, 'internalLinkRows', $label, 'count');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function externalUrlFilledCount(array $data, string $label): int
    {
        return (int) str_replace(' ', '', (string) $this->rowValue(
            $data,
            'externalUrlFieldRows',
            $label,
            'filled_display',
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function headlineValue(array $data, string $label): int
    {
        return (int) $this->rowValue($data, 'headlineStats', $label, 'value');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function summaryValue(array $data, string $sectionTitle, string $label): int
    {
        $sections = $data['summarySections'] ?? null;

        if (is_array($sections)) {
            foreach ($sections as $section) {
                if (is_array($section) && ($section['title'] ?? null) === $sectionTitle) {
                    return (int) $this->rowValue($section, 'rows', $label, 'value');
                }
            }
        }

        throw new \RuntimeException('Stats section not found: '.$sectionTitle);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function rowValue(array $data, string $rowsKey, string $label, string $valueKey): mixed
    {
        $rows = $data[$rowsKey] ?? null;

        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (is_array($row) && ($row['label'] ?? null) === $label) {
                    return $row[$valueKey] ?? null;
                }
            }
        }

        throw new \RuntimeException('Stats row not found: '.$label);
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
