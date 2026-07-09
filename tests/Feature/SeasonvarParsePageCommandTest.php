<?php

namespace Tests\Feature;

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

    /**
     * @param  array<int, string>  $episodes
     * @param  list<string>  $mediaUrls
     */
    private function seasonPageHtml(int $seasonNumber, array $episodes, array $mediaUrls = []): string
    {
        $episodeItems = collect($episodes)
            ->mapWithKeys(fn (string $title, int $number): array => [
                "{$number}_seriya" => ['n' => (string) $number, 'title' => $title],
            ])
            ->all();
        $episodesJson = json_encode([$episodeItems], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $mediaJson = json_encode($mediaUrls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

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
                    </script>
                </body>
            </html>
            HTML;
    }
}
