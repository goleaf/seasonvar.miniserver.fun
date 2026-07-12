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

    public function test_it_keeps_only_direct_season_links_and_sanitizes_suspicious_season_titles(): void
    {
        $parser = app(SeasonvarCatalogParser::class);

        $data = $parser->parse(
            <<<'HTML'
            <html>
                <head><title>Американский папаша 6 сезон смотреть онлайн</title></head>
                <body>
                    <h1>Американский папаша 6 сезон</h1>
                    <div class="pgs-seaslist">
                        <a href="/serial-1750--Amerikanskij_papasha-_pszhdcp-6-sezon.html">... главный герой пытается решить очень сложную проблему. Это длинное описание не является названием сезона.</a>
                        <a href="/serial-50000-Sluchajnaya_stranica.html">... описание чужой страницы, которое нельзя сохранять как сезон</a>
                    </div>
                    <script>
                        var arEpisodes = [{"1_seriya":{"n":"1"}}];
                    </script>
                </body>
            </html>
            HTML,
            'https://seasonvar.ru/serial-1750--Amerikanskij_papasha-_pszhdcp-6-sezon.html',
        );

        $this->assertSame(6, $data['current_season_number']);
        $this->assertCount(1, $data['seasons']);
        $this->assertSame(6, $data['seasons'][0]['number']);
        $this->assertSame('Сезон 6', $data['seasons'][0]['title']);
        $this->assertSame('https://seasonvar.ru/serial-1750--Amerikanskij_papasha-_pszhdcp-6-sezon.html', $data['seasons'][0]['source_url']);
    }

    public function test_it_collects_all_country_values_and_rejects_invalid_translation_values(): void
    {
        $parser = app(SeasonvarCatalogParser::class);

        $data = $parser->parse(
            <<<'HTML'
            <html>
                <head><title>Тестовый сериал смотреть онлайн</title></head>
                <body>
                    <h1>Тестовый сериал</h1>
                    <div class="pgs-sinfo_list">
                        Жанр: Драма
                        Страна: США, Канада
                        Перевод: LostFilm, 2020, США, версия США, рус., финал сезона
                    </div>
                    <div class="pgs-sinfo_list">
                        Страна: Россия
                        Озвучка: NewStudio
                    </div>
                    <div class="pgs-seaslist">
                        <a href="/serial-50000-Test-1-season.html">1 сезон (NewStudio)</a>
                        <a href="/serial-50000-Test-2-season.html">2 сезон (2020)</a>
                    </div>
                    <script>
                        var arEpisodes = [{"1_seriya":{"n":"1"}}];
                    </script>
                </body>
            </html>
            HTML,
            'https://seasonvar.ru/serial-50000-Test-1-season.html',
        );

        $taxonomies = collect($data['taxonomies'])->groupBy('type');

        $this->assertSame(['США', 'Канада', 'Россия'], $taxonomies->get('country')->pluck('name')->values()->all());
        $this->assertSame(['LostFilm', 'NewStudio'], $taxonomies->get('translation')->pluck('name')->values()->all());
    }

    public function test_it_extracts_extended_metadata_only_from_trusted_official_nodes(): void
    {
        $parser = app(SeasonvarCatalogParser::class);

        $data = $parser->parse(
            <<<'HTML'
            <html>
                <head>
                    <title>Доверенный сериал смотреть онлайн</title>
                    <script type="application/ld+json">
                        {"@context":"https://schema.org","@type":"TVSeries","name":"Доверенный сериал","productionCompany":{"@type":"Organization","name":"Bones"}}
                    </script>
                </head>
                <body>
                    <h1>Доверенный сериал</h1>
                    <div class="pgs-sinfo_list">
                        Страна: Тайвань, Армения, Исландия, Чехословакия, Филиппины
                        Статус: идет
                        Телеканал: Пятница
                        Студии: A-1 Pictures
                    </div>
                    <div class="pgs-sinfo_list">Статус: Рекомендовано!</div>
                    <ul class="pgs-trans">
                        <li data-click="translate">HDRuDub</li>
                        <li data-click="translate">NewStudio</li>
                        <li data-click="translate">LostFilm</li>
                    </ul>
                    <p itemprop="description">Официальное описание.
                    Студия: J.C.Staff</p>
                    <div class="b-taglist"><a href="/tag/netflix">Netflix</a></div>
                    <div class="svc_comment">Канал: TV Tokyo</div>
                    <div class="pgs-review-post">Статус: завершен. Этот отзыв достаточно длинный, но не является метаданными.</div>
                </body>
            </html>
            HTML,
            'https://seasonvar.ru/serial-52000-Trusted-1-season.html',
        );

        $taxonomies = collect($data['taxonomies'])->groupBy('type');

        $this->assertSame(['Тайвань', 'Армения', 'Исландия', 'Чехословакия', 'Филиппины'], $taxonomies->get('country')->pluck('name')->values()->all());
        $this->assertSame(['RuDub', 'NewStudio', 'LostFilm'], $taxonomies->get('translation')->pluck('name')->values()->all());
        $this->assertSame(['Выходит'], $taxonomies->get('status')->pluck('name')->values()->all());
        $this->assertSame(['Пятница', 'Netflix'], $taxonomies->get('network')->pluck('name')->values()->all());
        $this->assertSame(['Bones', 'A-1 Pictures', 'J.C.Staff'], $taxonomies->get('studio')->pluck('name')->values()->all());
        $this->assertFalse($taxonomies->get('network')->contains('name', 'TV Tokyo'));
        $this->assertFalse($taxonomies->get('status')->contains('name', 'Завершён'));
    }

    public function test_it_processes_multiple_translation_markers_episode_numbers_and_media_urls(): void
    {
        $parser = app(SeasonvarCatalogParser::class);

        $data = $parser->parse(
            <<<'HTML'
            <html>
                <head><title>Много данных смотреть онлайн</title></head>
                <body>
                    <h1>Много данных</h1>
                    <div class="pgs-seaslist">
                        <a href="/serial-53000-Multiple-1-season.html">1 сезон (NewStudio) (LostFilm)</a>
                    </div>
                    <script>
                        window.fallbackEpisodes = [{"n":"1"},{"n":"2"}];
                        window.visibleFiles = ["https://media.example.com/multiple/s01e01.mp4", "https://media.example.com/multiple/s01e02.mp4"];
                    </script>
                </body>
            </html>
            HTML,
            'https://seasonvar.ru/serial-53000-Multiple-1-season.html',
        );

        $this->assertSame(['NewStudio', 'LostFilm'], collect($data['taxonomies'])->where('type', 'translation')->pluck('name')->values()->all());
        $this->assertSame([1, 2], collect($data['episodes'])->pluck('number')->values()->all());
        $this->assertSame([
            'https://media.example.com/multiple/s01e01.mp4',
            'https://media.example.com/multiple/s01e02.mp4',
        ], collect($data['media'])->pluck('url')->values()->all());
    }

    public function test_it_builds_recommendation_signals_from_source_metadata(): void
    {
        $parser = app(SeasonvarCatalogParser::class);

        $data = $parser->parse(
            <<<'HTML'
            <html>
                <head><title>Точный детектив смотреть онлайн</title></head>
                <body>
                    <h1>Точный детектив</h1>
                    <div class="pgs-sinfo_list">
                        Вышел: 2021
                        Жанр: Детектив
                        Страна: Испания
                        КиноПоиск: 8.2 (123 голоса)
                    </div>
                    <div class="pgs-seaslist">
                        <a href="/serial-51000-Tochnyj_detektiv-1-season.html">1 сезон</a>
                    </div>
                    <script>
                        var arEpisodes = [{"1_seriya":{"n":"1"}}];
                    </script>
                </body>
            </html>
            HTML,
            'https://seasonvar.ru/serial-51000-Tochnyj_detektiv-1-season.html',
        );

        $signals = collect($data['recommendation_signals']);

        $this->assertNotNull($signals->first(fn (array $signal): bool => $signal['source'] === 'seasonvar_info'
            && $signal['signal_type'] === 'taxonomy_genre'
            && $signal['signal_value'] === 'Детектив'
            && $signal['weight'] === 120));
        $this->assertNotNull($signals->first(fn (array $signal): bool => $signal['signal_type'] === 'rating'
            && $signal['signal_key'] === 'kinopoisk'
            && $signal['weight'] > 180));
        $this->assertNotNull($signals->first(fn (array $signal): bool => $signal['signal_type'] === 'release_year'
            && $signal['signal_key'] === '2021'
            && $signal['weight'] === 25));
        $this->assertCount(3, $signals->where('signal_type', 'page_quality'));
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
