<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendationSignal;
use App\Models\Genre;
use App\Models\Season;
use App\Models\SeasonvarImportEvent;
use App\Models\Source;
use App\Services\Seasonvar\SeasonvarCatalogParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeasonvarParsePageCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'seasonvar.crawl_delay_seconds' => 0,
            'seasonvar.media_check.enabled' => false,
        ]);
    }

    public function test_it_parses_requested_page_and_all_detected_seasons_into_one_title(): void
    {
        $this->travelTo('2026-07-09 10:11:00');
        Http::preventStrayRequests();

        Http::fake([
            'seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html' => Http::response($this->seasonPageHtml(4, [
                1 => 'Пробуждение',
                2 => 'Проверка',
            ], ['https://media.example.com/kitchen/cernyi-spisok-na-kuxne-s04e02.mp4'])),
            'seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html' => Http::response($this->seasonPageHtml(1, [
                1 => 'Начало',
            ])),
        ]);

        $this->artisan('seasonvar:import', [
            'url' => 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html',
        ])
            ->expectsOutputToContain('[09.07.2026 10:11]')
            ->assertExitCode(0);

        $catalogTitle = CatalogTitle::query()->where('external_id', '47915')->firstOrFail();

        $this->assertDatabaseHas('catalog_titles', [
            'title' => 'Черный список: На кухне',
            'external_id' => '47915',
        ]);
        $this->assertDatabaseHas('genres', [
            'name' => 'Кулинария',
        ]);
        $this->assertDatabaseHas('countries', [
            'name' => 'Россия',
        ]);
        $this->assertDatabaseHas('translations', [
            'name' => 'Оригинал',
        ]);
        $this->assertDatabaseHas('seasons', ['number' => 1]);
        $this->assertDatabaseHas('seasons', ['number' => 4]);
        $this->assertDatabaseHas('episodes', [
            'number' => 1,
            'title' => 'Начало',
        ]);
        $this->assertDatabaseHas('episodes', [
            'number' => 2,
            'title' => 'Проверка',
        ]);
        $this->assertDatabaseHas('licensed_media', [
            'storage_disk' => 'seasonvar_parsed',
            'playback_url' => 'https://media.example.com/kitchen/cernyi-spisok-na-kuxne-s04e02.mp4',
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('source_pages', [
            'url' => 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html',
            'parse_status' => 'parsed',
        ]);
        $this->assertDatabaseHas('source_pages', [
            'url' => 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html',
            'parse_status' => 'parsed',
        ]);
        $this->assertDatabaseHas('catalog_title_recommendation_signals', [
            'catalog_title_id' => $catalogTitle->id,
            'source' => 'seasonvar_info',
            'signal_type' => 'taxonomy_genre',
            'signal_value' => 'Кулинария',
            'weight' => 120,
        ]);
        $this->assertDatabaseHas('catalog_title_recommendation_signals', [
            'catalog_title_id' => $catalogTitle->id,
            'source' => 'seasonvar_info',
            'signal_type' => 'release_year',
            'signal_key' => '2024',
            'weight' => 25,
        ]);
        $this->assertSame(3, CatalogTitleRecommendationSignal::query()
            ->where('catalog_title_id', $catalogTitle->id)
            ->where('signal_type', 'page_quality')
            ->count());
    }

    public function test_it_imports_m3u_playlist_discovered_in_page_html(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html' => Http::response($this->seasonPageHtml(4, [
                2 => 'Проверка',
            ], ['https://playlist.example.com/kitchen.m3u'])),
            'playlist.example.com/*' => Http::response(<<<'M3U'
                #EXTM3U
                #EXTINF:-1,Черный список: На кухне S04E02
                https://media.example.com/kitchen/s04e02.mp4
                M3U),
        ]);

        $this->artisan('seasonvar:import', [
            'url' => 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('seasons', ['number' => 4]);
        $this->assertDatabaseHas('episodes', ['number' => 2]);
        $this->assertSame([], SeasonvarImportEvent::query()
            ->where('level', 'error')
            ->get(['event', 'context'])
            ->toArray());
        $this->assertSame([], SeasonvarImportEvent::query()
            ->where('event', 'like', 'seasonvar-media-%')
            ->get(['event', 'context'])
            ->toArray());
        $this->assertDatabaseHas('licensed_media', [
            'title' => 'Черный список: На кухне S04E02',
            'storage_disk' => 'external_playlist',
            'playback_url' => 'https://media.example.com/kitchen/s04e02.mp4',
            'status' => 'published',
        ]);
    }

    public function test_it_imports_seasonvar_player_playlist_discovered_in_page_html(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html' => Http::response($this->seasonPageHtml(4, [
                2 => 'Проверка',
            ], seasonvarPlaylists: ['/playls2/hash/trans/47915/plist.txt?time=1783594881'])),
            'seasonvar.ru/playls2/hash/trans/47915/plist.txt?time=1783594881' => Http::response(json_encode([
                [
                    'title' => '2 серия SD/HD<br>',
                    'file' => $this->encodedSeasonvarPlayerFile('//media.example.com/kitchen/s04e02.mp4'),
                    'id' => '2',
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ]);

        $this->artisan('seasonvar:import', [
            'url' => 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('licensed_media', [
            'title' => '2 серия SD/HD',
            'storage_disk' => 'seasonvar_parsed',
            'playback_url' => 'https://media.example.com/kitchen/s04e02.mp4',
            'quality' => '720p',
            'status' => 'published',
        ]);
    }

    public function test_it_attaches_normalized_media_translations_and_preserves_an_existing_relation_source_url(): void
    {
        Http::preventStrayRequests();
        $sourceUrl = 'https://seasonvar.ru/genre/kulinarija';
        Genre::query()->create([
            'name' => 'Кулинария',
            'slug' => 'kulinarija',
            'source_url' => $sourceUrl,
        ]);

        Http::fake([
            'seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html' => Http::response($this->seasonPageHtml(4, [
                1 => 'Пробуждение',
                2 => 'Проверка',
            ], seasonvarPlaylists: ['/playls2/hash/trans/47915/plist.txt'])),
            'seasonvar.ru/playls2/hash/trans/47915/plist.txt' => Http::response(json_encode([
                [
                    'title' => '1 серия HDRuDub',
                    'file' => $this->encodedSeasonvarPlayerFile('//media.example.com/kitchen/s04e01.mp4'),
                    'id' => '1',
                ],
                [
                    'title' => '2 серия HDLostFilm',
                    'file' => $this->encodedSeasonvarPlayerFile('//media.example.com/kitchen/s04e02.mp4'),
                    'id' => '2',
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ]);

        $this->artisan('seasonvar:import', [
            'url' => 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html',
        ])->assertExitCode(0);

        $catalogTitle = CatalogTitle::query()->where('external_id', '47915')->firstOrFail();

        $this->assertSame($sourceUrl, Genre::query()->where('slug', 'kulinarija')->value('source_url'));
        $this->assertEqualsCanonicalizing(
            ['Оригинал', 'RuDub', 'LostFilm'],
            $catalogTitle->translations()->pluck('name')->all(),
        );
    }

    public function test_it_checks_media_availability_outside_the_catalog_database_transaction(): void
    {
        Http::preventStrayRequests();
        config([
            'seasonvar.media_check.enabled' => true,
            'seasonvar.media_check.retries' => 1,
        ]);
        $transactionLevelBeforeImport = DB::transactionLevel();
        $mediaRequestTransactionLevels = [];
        $observedRequestUrls = [];
        $pageHtml = $this->seasonPageHtml(4, [
            2 => 'Проверка',
        ], ['https://media.example.com/kitchen/s04e02.mp4']);
        $parsed = app(SeasonvarCatalogParser::class)->parse(
            $pageHtml,
            'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html',
        );
        $this->assertSame(['https://media.example.com/kitchen/s04e02.mp4'], collect($parsed['media'])->pluck('url')->all());

        Http::fake(function (Request $request) use (&$mediaRequestTransactionLevels, &$observedRequestUrls, $pageHtml): \Illuminate\Http\Client\Response {
            $observedRequestUrls[] = $request->url();

            if ($request->url() === 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html') {
                return Http::response($pageHtml);
            }

            if ($request->url() === 'https://media.example.com/kitchen/s04e02.mp4') {
                $mediaRequestTransactionLevels[] = DB::transactionLevel();

                return Http::response('', 206);
            }

            return Http::response('', 404);
        });

        $this->artisan('seasonvar:import', [
            'url' => 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('licensed_media', [
            'playback_url' => 'https://media.example.com/kitchen/s04e02.mp4',
        ]);
        $this->assertSame(['https://media.example.com/kitchen/s04e02.mp4'], $observedRequestUrls);
        $this->assertSame([$transactionLevelBeforeImport], $mediaRequestTransactionLevels);
    }

    public function test_it_saves_trailer_media_without_episode_number(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html' => Http::response($this->seasonPageHtml(4, [
                2 => 'Проверка',
            ], seasonvarPlaylists: ['/playls2/hash/trans%D0%A2%D1%80%D0%B5%D0%B9%D0%BB%D0%B5%D1%80%D1%8B/47915/plist.txt?time=1783594881'])),
            'seasonvar.ru/playls2/hash/trans%D0%A2%D1%80%D0%B5%D0%B9%D0%BB%D0%B5%D1%80%D1%8B/47915/plist.txt?time=1783594881' => Http::response(json_encode([
                [
                    'title' => 'Трейлер',
                    'file' => $this->encodedSeasonvarPlayerFile('//media.example.com/kitchen/trailers/cernyi-spisok-na-kuxne.mp4'),
                    'id' => '',
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ]);

        $this->artisan('seasonvar:import', [
            'url' => 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('licensed_media', [
            'title' => 'Трейлер',
            'storage_disk' => 'seasonvar_parsed',
            'playback_url' => 'https://media.example.com/kitchen/trailers/cernyi-spisok-na-kuxne.mp4',
            'episode_id' => null,
            'format' => 'mp4',
            'status' => 'published',
        ]);
    }

    public function test_it_imports_hls_master_playlist_variants_for_detected_episode(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html' => Http::response($this->seasonPageHtml(4, [
                2 => 'Проверка',
            ], ['https://media.example.com/kitchen/master.m3u8'])),
            'media.example.com/kitchen/master.m3u8' => Http::response(<<<'M3U'
                #EXTM3U
                #EXT-X-STREAM-INF:BANDWIDTH=5000000,RESOLUTION=1920x1080
                s04e02-1080.m3u8
                #EXT-X-STREAM-INF:BANDWIDTH=2500000,RESOLUTION=1280x720
                s04e02-720.m3u8
                M3U),
        ]);

        $this->artisan('seasonvar:import', [
            'url' => 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('licensed_media', [
            'playback_url' => 'https://media.example.com/kitchen/s04e02-1080.m3u8',
            'quality' => '1080p',
            'format' => 'm3u8',
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('licensed_media', [
            'playback_url' => 'https://media.example.com/kitchen/s04e02-720.m3u8',
            'quality' => '720p',
            'format' => 'm3u8',
            'status' => 'published',
        ]);
    }

    public function test_it_skips_stale_nested_season_urls_when_parsing_detected_seasons(): void
    {
        Http::preventStrayRequests();

        $url = 'https://seasonvar.ru/serial-615--Bez_sleda_pssmtlk-1-season.html';
        $staleUrl = 'https://seasonvar.ru/serial-615--Bez_sleda_pssmtlk-1-season.html/serial-29641-Mariya_Vern_psydwch-8-season.html';
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $catalogTitle = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'external_id' => '615',
            'slug' => 'bez-sledawithout-a-trace',
            'title' => 'Без следа/Without a Trace',
            'source_url' => $url,
            'source_url_hash' => hash('sha256', $url),
        ]);

        Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 8,
            'title' => 'Посторонняя рекомендация',
            'source_url' => $staleUrl,
            'source_url_hash' => hash('sha256', $staleUrl),
        ]);

        Http::fake([
            'seasonvar.ru/serial-615--Bez_sleda_pssmtlk-1-season.html' => Http::response($this->withoutTraceSeasonPageHtml()),
        ]);

        $this->artisan('seasonvar:import', [
            'url' => $url,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('licensed_media', [
            'catalog_title_id' => $catalogTitle->id,
            'playback_url' => 'https://media.example.com/without-a-trace/s01e01.mp4',
            'status' => 'published',
        ]);
        $this->assertDatabaseMissing('source_pages', [
            'url' => $staleUrl,
        ]);
    }

    /**
     * @param  array<int, string>  $episodes
     * @param  list<string>  $mediaUrls
     * @param  list<string>  $seasonvarPlaylists
     */
    private function seasonPageHtml(int $seasonNumber, array $episodes, array $mediaUrls = [], array $seasonvarPlaylists = []): string
    {
        $episodeItems = collect($episodes)
            ->mapWithKeys(fn (string $title, int $number): array => [
                "{$number}_seriya" => ['n' => (string) $number, 'title' => $title],
            ])
            ->all();
        $episodesJson = json_encode([$episodeItems], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $mediaJson = json_encode($mediaUrls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $playlistsJson = json_encode($seasonvarPlaylists, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return <<<HTML
            <html>
                <head>
                    <title>Черный список: На кухне {$seasonNumber} сезон смотреть онлайн</title>
                    <meta name="description" content="Описание передачи">
                </head>
                <body>
                    <h1>Сериал Черный список: На кухне {$seasonNumber} сезон онлайн</h1>
                    <div class="pgs-sinfo_list">
                        Жанр: Кулинария
                        Страна: Россия
                        Вышел: 2024
                        Перевод: Оригинал
                        Статус: идет
                        Канал: Пятница
                    </div>
                    <div class="pgs-seaslist">
                        <a href="/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html">1 сезон (Оригинал)</a>
                        <a href="/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html">4 сезон (Оригинал)</a>
                    </div>
                    <script>
                        var arEpisodes = {$episodesJson};
                        var parsedMedia = {$mediaJson};
                        var parsedSeasonvarPlaylists = {$playlistsJson};
                        var pl = Object.fromEntries(parsedSeasonvarPlaylists.map((url, index) => [index, url]));
                    </script>
                </body>
            </html>
            HTML;
    }

    private function withoutTraceSeasonPageHtml(): string
    {
        return <<<'HTML'
            <html>
                <head>
                    <title>Сериал Без следа 1 сезон Without a Trace смотреть онлайн</title>
                    <meta name="description" content="Описание сериала">
                </head>
                <body>
                    <h1>Сериал Без следа/Without a Trace 1 сезон онлайн</h1>
                    <div class="pgs-sinfo_list">
                        Жанр: Детектив
                        Страна: США
                        Вышел: 2002
                        Перевод: Профессиональный
                    </div>
                    <div class="pgs-seaslist">
                        <a href="/serial-615--Bez_sleda_pssmtlk-1-season.html">Сериал Без следа/Without a Trace 1 сезон</a>
                    </div>
                    <div class="pgs-srecomend">
                        <a href="/serial-29641-Mariya_Vern_psydwch-8-season.html">Посторонняя рекомендация с 8 сезоном</a>
                    </div>
                    <script>
                        var arEpisodes = [{"1_seriya":{"n":"1","title":"Пилот"}}];
                        var parsedMedia = ["https://media.example.com/without-a-trace/s01e01.mp4"];
                    </script>
                </body>
            </html>
            HTML;
    }

    private function encodedSeasonvarPlayerFile(string $url): string
    {
        $encoded = base64_encode($url);

        return '#x'.substr($encoded, 0, 12).'//b2xvbG8='.substr($encoded, 12);
    }
}
