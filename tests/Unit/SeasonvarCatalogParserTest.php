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
