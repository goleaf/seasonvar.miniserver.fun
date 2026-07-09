<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Season;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeasonvarParsePageCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_parses_requested_page_and_all_detected_seasons_into_one_title(): void
    {
        config(['seasonvar.crawl_delay_seconds' => 0]);
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

        $this->artisan('seasonvar:parse-page', [
            'url' => 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html',
        ])
            ->expectsOutputToContain('[09.07.2026 10:11]')
            ->assertExitCode(0);

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
    }

    public function test_it_imports_m3u_playlist_discovered_in_page_html(): void
    {
        config(['seasonvar.crawl_delay_seconds' => 0]);
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

        $this->artisan('seasonvar:parse-page', [
            'url' => 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html',
            '--page-only' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('licensed_media', [
            'title' => 'Черный список: На кухне S04E02',
            'storage_disk' => 'external_playlist',
            'playback_url' => 'https://media.example.com/kitchen/s04e02.mp4',
            'status' => 'published',
        ]);
    }

    public function test_it_imports_seasonvar_player_playlist_discovered_in_page_html(): void
    {
        config(['seasonvar.crawl_delay_seconds' => 0]);
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

        $this->artisan('seasonvar:parse-page', [
            'url' => 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html',
            '--page-only' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('licensed_media', [
            'title' => '2 серия SD/HD',
            'storage_disk' => 'seasonvar_parsed',
            'playback_url' => 'https://media.example.com/kitchen/s04e02.mp4',
            'status' => 'published',
        ]);
    }

    public function test_it_skips_stale_nested_season_urls_when_parsing_detected_seasons(): void
    {
        config(['seasonvar.crawl_delay_seconds' => 0]);
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

        $this->artisan('seasonvar:parse-page', [
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
