<?php

namespace Tests\Unit;

use App\Services\Seasonvar\SeasonvarCatalogParser;
use App\Services\Seasonvar\SeasonvarUrl;
use Tests\TestCase;

class SeasonvarCatalogParserTest extends TestCase
{
    public function test_it_extracts_episodes_from_seasonvar_page_script(): void
    {
        $parser = app(SeasonvarCatalogParser::class);

        $data = $parser->parse(
            <<<'HTML'
            <html>
                <head><title>6 кадров 2 сезон смотреть онлайн</title></head>
                <body>
                    <h1>6 кадров 2 сезон</h1>
                    <div class="pgs-seaslist">
                        <a href="/serial-1276--6_kadrov-1-season.html">1 сезон</a>
                        <a href="/serial-1277--6_kadrov-2-season.html">2 сезон</a>
                    </div>
                    <script>
                        var arEpisodes = [{"1_seriya":{"n":"1","next":"2"},"":{"next":"1"},"2_seriya":{"n":"2"}}];
                    </script>
                </body>
            </html>
            HTML,
            'https://seasonvar.ru/serial-1277--6_kadrov-2-season.html',
        );

        $this->assertSame(2, $data['current_season_number']);
        $this->assertCount(2, $data['episodes']);
        $this->assertSame([
            'season_number' => 2,
            'number' => 1,
            'title' => '1 серия',
            'source_url' => 'https://seasonvar.ru/serial-1277--6_kadrov-2-season.html#1_seriya',
        ], $data['episodes'][0]);
        $this->assertSame('https://seasonvar.ru/serial-1276--6_kadrov-1-season.html', $data['seasons'][0]['source_url']);
    }

    public function test_it_extracts_public_media_candidates_from_page_scripts(): void
    {
        $parser = app(SeasonvarCatalogParser::class);

        $data = $parser->parse(
            <<<'HTML'
            <html>
                <head><title>6 кадров 2 сезон смотреть онлайн</title></head>
                <body>
                    <h1>6 кадров 2 сезон</h1>
                    <script>
                        var arEpisodes = [{"1_seriya":{"n":"1","next":"2"},"2_seriya":{"n":"2"}}];
                        var visibleFiles = ["https://media.example.com/files/6-kadrov-s02e02.mp4"];
                    </script>
                </body>
            </html>
            HTML,
            'https://seasonvar.ru/serial-1277--6_kadrov-2-season.html',
        );

        $this->assertCount(1, $data['media']);
        $this->assertSame('https://media.example.com/files/6-kadrov-s02e02.mp4', $data['media'][0]['url']);
        $this->assertSame(2, $data['media'][0]['season_number']);
        $this->assertSame(2, $data['media'][0]['episode_number']);
        $this->assertSame('file', $data['media'][0]['kind']);
    }

    public function test_it_extracts_seasonvar_player_playlist_candidates_from_page_script(): void
    {
        $parser = app(SeasonvarCatalogParser::class);

        $data = $parser->parse(
            <<<'HTML'
            <html>
                <head><title>Черный список. На кухне 4 сезон смотреть онлайн</title></head>
                <body>
                    <h1>Черный список. На кухне 4 сезон</h1>
                    <script>
                        var arEpisodes = [{"1_seriya":{"n":"1"}}];
                        var pl = {'0': "/playls2/4f13936c7df78ae9b28a2767bbea63a5/trans/47915/plist.txt?time=1783594881"};
                    </script>
                </body>
            </html>
            HTML,
            'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html',
        );

        $this->assertCount(1, $data['media']);
        $this->assertSame('https://seasonvar.ru/playls2/4f13936c7df78ae9b28a2767bbea63a5/trans/47915/plist.txt?time=1783594881', $data['media'][0]['url']);
        $this->assertSame(4, $data['media'][0]['season_number']);
        $this->assertNull($data['media'][0]['episode_number']);
        $this->assertSame('seasonvar_playlist', $data['media'][0]['kind']);
    }

    public function test_it_normalizes_root_relative_urls_against_the_site_origin(): void
    {
        $url = app(SeasonvarUrl::class);

        $normalized = $url->normalize(
            '/serial-1276--6_kadrov-1-season.html',
            'https://seasonvar.ru/serial-1277--6_kadrov-2-season.html',
        );

        $this->assertSame('https://seasonvar.ru/serial-1276--6_kadrov-1-season.html', $normalized);
    }
}
