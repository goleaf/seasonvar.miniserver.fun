<?php

namespace Tests\Unit;

use App\DTOs\Seasonvar\SeasonvarCatalogData;
use App\Enums\SeasonvarPageType;
use App\Services\Seasonvar\SeasonvarCatalogParser;
use App\Services\Seasonvar\SeasonvarUrl;
use Tests\TestCase;

class SeasonvarCatalogParserTest extends TestCase
{
    public function test_it_classifies_publication_type_from_explicit_source_genres(): void
    {
        $parser = app(SeasonvarCatalogParser::class);
        $cases = [
            'реалити-шоу, документальные' => 'show',
            'аниме, реалити-шоу' => 'anime',
            'документальные' => 'documentary',
            'драмы' => 'serial',
        ];

        foreach ($cases as $genres => $expectedType) {
            $parsed = $parser->parse(
                <<<HTML
                <html>
                    <head><title>Проверка типа смотреть онлайн</title></head>
                    <body>
                        <h1>Проверка типа</h1>
                        <div class="pgs-sinfo_list">Жанр: <span itemprop="genre">{$genres}</span></div>
                    </body>
                </html>
                HTML,
                'https://seasonvar.ru/serial-55000-Proverka-tipa.html',
            );

            $this->assertSame($expectedType, $parsed['type'], $genres);
            $this->assertSame($expectedType, SeasonvarCatalogData::fromParsed($parsed)->type, $genres);
        }
    }

    public function test_it_accepts_a_real_source_title_longer_than_255_characters(): void
    {
        $title = str_repeat('Очень длинное название аниме ', 12);
        $parser = app(SeasonvarCatalogParser::class);

        $parsed = $parser->parse(
            <<<HTML
            <html>
                <head><title>{$title}</title></head>
                <body>
                    <h1>{$title}</h1>
                    <div class="pgs-sinfo_list">Жанр: <span itemprop="genre">аниме</span></div>
                </body>
            </html>
            HTML,
            'https://seasonvar.ru/serial-55001-Dlinnoe-nazvanie.html',
        );

        $this->assertGreaterThan(255, mb_strlen($parsed['title']));
        $this->assertSame('anime', SeasonvarCatalogData::fromParsed($parsed)->type);
    }

    public function test_it_recognizes_rights_holder_region_blocking_from_the_player_message(): void
    {
        $parser = app(SeasonvarCatalogParser::class);

        $data = $parser->parse(
            <<<'HTML'
            <html>
                <head><title>Именно так 1 сезон смотреть онлайн</title></head>
                <body>
                    <h1>Именно так/Aynen Aynen</h1>
                    <div class="pgs-seaslist">
                        <a href="/serial-24845-Imenno_tak_psvtbam.html">1 сезон</a>
                    </div>
                    <div class="pgs-player-block">
                        По просьбе правообладателя, сезон заблокирован для вашей страны.
                    </div>
                </body>
            </html>
            HTML,
            'https://seasonvar.ru/serial-24845-Imenno_tak_psvtbam.html',
        );

        $this->assertSame(
            'region_blocked',
            $data['parse_meta']['provider_availability_status'] ?? null,
        );
    }

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

    public function test_it_extracts_the_live_episode_availability_line_from_a_season_link(): void
    {
        $parser = app(SeasonvarCatalogParser::class);

        $data = $parser->parse(
            <<<'HTML'
            <html>
                <head><title>Вестис смотреть онлайн</title></head>
                <body>
                    <h1>Сериал Вестис/The Westies</h1>
                    <div class="pgs-seaslist">
                        <ul class="tabs-result">
                            <li class="act">
                                <h2>
                                    <a href="/serial-49406-Vestis.html">
                                        &gt;&gt;&gt; Сериал Вестис/The Westies
                                        <span>(19.07.2026 3 серия (Coldfilm) из 8)</span>
                                    </a>
                                </h2>
                            </li>
                        </ul>
                    </div>
                    <script>
                        var arEpisodes = [{"1_seriya":{"n":"1","next":"2"},"2_seriya":{"n":"2","next":"3"},"3_seriya":{"n":"3"}}];
                    </script>
                </body>
            </html>
            HTML,
            'https://seasonvar.ru/serial-49406-Vestis.html',
        );

        $season = collect($data['seasons'])->firstWhere('number', 1);

        $this->assertSame('2026-07-19', $season['latest_episode_released_at']);
        $this->assertSame(3, $season['episodes_released']);
        $this->assertSame(8, $season['episodes_total']);
        $this->assertSame('Coldfilm', $season['translation_name']);
        $this->assertSame('19.07.2026 3 серия (Coldfilm) из 8', $season['release_status_text']);
    }

    public function test_it_keeps_the_canonical_current_season_when_the_list_only_links_other_seasons(): void
    {
        $parser = app(SeasonvarCatalogParser::class);

        $data = $parser->parse(
            <<<'HTML'
            <html>
                <head><title>Цени каждый день смотреть онлайн</title></head>
                <body>
                    <h1>Цени каждый день/Cherish the Day</h1>
                    <div class="pgs-seaslist">
                        <a href="/serial-24914-TCeni_kazhdyj_den__psakpir-2-season.html">2 сезон</a>
                    </div>
                    <script>
                        var arEpisodes = [{"1_seriya":{"n":"1","next":"2"},"2_seriya":{"n":"2"}}];
                    </script>
                </body>
            </html>
            HTML,
            'https://seasonvar.ru/serial-24914-TCeni_kazhdyj_den__psakpir.html',
        );

        $this->assertSame(1, $data['current_season_number']);
        $this->assertSame([1, 2], collect($data['seasons'])->pluck('number')->all());
        $this->assertSame('Сезон 1', $data['seasons'][0]['title']);
        $this->assertSame(
            'https://seasonvar.ru/serial-24914-TCeni_kazhdyj_den__psakpir.html',
            $data['seasons'][0]['source_url'],
        );
        $this->assertSame([1, 1], collect($data['episodes'])->pluck('season_number')->all());
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
                        Страна: Тайвань, Армения, Исландия, Чехословакия, Филиппины, Голландия
                        Статус: идет
                        Телеканал: Пятница
                        Студии: A-1 Pictures
                    </div>
                    <div class="pgs-sinfo_list">Статус: Рекомендовано!</div>
                    <div class="pgs-sinfo_list">Статус: анонс</div>
                    <ul class="pgs-trans">
                        <li data-click="translate">HDRuDub</li>
                        <li data-click="translate">NewStudio</li>
                        <li data-click="translate">LostFilm</li>
                    </ul>
                    <p itemprop="description">Официальное описание.<br>Студия: J.C.Staff</p>
                    <div class="b-taglist"><a href="/tag/netflix">Netflix</a></div>
                    <div class="svc_comment">Канал: TV Tokyo</div>
                    <div class="pgs-review-post">Статус: завершен. Этот отзыв достаточно длинный, но не является метаданными.</div>
                </body>
            </html>
            HTML,
            'https://seasonvar.ru/serial-52000-Trusted-1-season.html',
        );

        $taxonomies = collect($data['taxonomies'])->groupBy('type');

        $this->assertSame(['Тайвань', 'Армения', 'Исландия', 'Чехословакия', 'Филиппины', 'Нидерланды'], $taxonomies->get('country')->pluck('name')->values()->all());
        $this->assertSame(['RuDub', 'NewStudio', 'LostFilm'], $taxonomies->get('translation')->pluck('name')->values()->all());
        $this->assertSame(['Выходит', 'Анонсирован'], $taxonomies->get('status')->pluck('name')->values()->all());
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

    public function test_it_keeps_canonical_metadata_without_building_generic_recommendation_signals(): void
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

        $this->assertSame([], $data['recommendation_signals']);
        $this->assertSame(2021, $data['year']);
        $this->assertNotNull(collect($data['taxonomies'])->first(
            fn (array $taxonomy): bool => $taxonomy['type'] === 'genre' && $taxonomy['name'] === 'Детектив',
        ));
        $this->assertNotNull(collect($data['taxonomies'])->first(
            fn (array $taxonomy): bool => $taxonomy['type'] === 'country' && $taxonomy['name'] === 'Испания',
        ));
        $this->assertNotNull(collect($data['ratings'])->first(
            fn (array $rating): bool => $rating['provider'] === 'kinopoisk'
                && $rating['rating'] === 8.2
                && $rating['votes'] === 123,
        ));
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

    public function test_catalog_url_boundary_accepts_only_https_seasonvar_pages(): void
    {
        $url = app(SeasonvarUrl::class);

        $this->assertTrue($url->isAllowed('https://seasonvar.ru/serial-1276--6_kadrov-1-season.html'));
        $this->assertFalse($url->isAllowed('http://seasonvar.ru/serial-1276--6_kadrov-1-season.html'));
    }

    public function test_source_urls_are_canonicalized_and_classified_with_typed_page_types(): void
    {
        $url = app(SeasonvarUrl::class);

        $this->assertSame(
            'https://seasonvar.ru/genre/drama',
            $url->normalize('HTTPS://WWW.SEASONVAR.RU//genre/%64rama/?utm_source=audit#fragment'),
        );
        $this->assertSame(
            'https://seasonvar.ru/?mod=login',
            $url->normalize('https://seasonvar.ru/?token=private&mod=login'),
        );

        $malformed = $url->normalize(
            'https://seasonvar.ru/serial-1-Test.html//serial-2-Other.html',
        );

        $this->assertTrue($url->isMalformedCatalogUrl($malformed));
        $this->assertFalse($url->isAllowed($malformed));

        $cases = [
            'https://seasonvar.ru/serial-1-Test.html' => SeasonvarPageType::Serial,
            'https://seasonvar.ru/actor/ivan-ivanov' => SeasonvarPageType::Actor,
            'https://seasonvar.ru/director/ivan-ivanov' => SeasonvarPageType::Director,
            'https://seasonvar.ru/genre/drama' => SeasonvarPageType::Genre,
            'https://seasonvar.ru/country/rossiya' => SeasonvarPageType::Country,
            'https://seasonvar.ru/tag/netflix' => SeasonvarPageType::Tag,
            'https://seasonvar.ru/translation/lostfilm' => SeasonvarPageType::Translation,
            'https://seasonvar.ru/status/zavershen' => SeasonvarPageType::Status,
            'https://seasonvar.ru/network/ntv' => SeasonvarPageType::Network,
            'https://seasonvar.ru/studio/amediateka' => SeasonvarPageType::Studio,
            'https://seasonvar.ru/st/serials.html' => SeasonvarPageType::StaticPage,
            'https://seasonvar.ru/' => SeasonvarPageType::StaticPage,
            'https://seasonvar.ru/rss.php' => SeasonvarPageType::Rss,
            'https://seasonvar.ru/search/' => SeasonvarPageType::Search,
            'https://seasonvar.ru/sitemap_index.xml' => SeasonvarPageType::Sitemap,
            'https://seasonvar.ru/film-1-Test.html' => SeasonvarPageType::Unknown,
        ];

        foreach ($cases as $sourceUrl => $expectedType) {
            $this->assertSame($expectedType, $url->pageType($sourceUrl), $sourceUrl);
        }
    }

    public function test_it_does_not_treat_serial_urls_with_person_tokens_as_people(): void
    {
        $parser = app(SeasonvarCatalogParser::class);

        $data = $parser->parse(
            <<<'HTML'
            <html>
                <head><title>Тестовый сериал смотреть онлайн</title></head>
                <body>
                    <h1>Тестовый сериал</h1>
                    <a href="/serial-248327-Serial_Akter_2024Actor.html">&gt;&gt;&gt; Сериал Актер (2024)/Actor</a>
                    <a href="/serial-239968-The_Doll_Factory.html">Сериал Фабрика кукол/The Doll Factory</a>
                    <a href="/actor/Adam%20Ian%20Cohen">Adam Ian Cohen</a>
                </body>
            </html>
            HTML,
            'https://seasonvar.ru/serial-50000-Test.html',
        );

        $this->assertSame(
            ['Adam Ian Cohen'],
            collect($data['taxonomies'])
                ->where('type', 'actor')
                ->pluck('name')
                ->values()
                ->all(),
        );
    }

    public function test_it_does_not_emit_aliases_that_repeat_the_displayed_title_or_original_name(): void
    {
        $parser = app(SeasonvarCatalogParser::class);

        $data = $parser->parse(
            <<<'HTML'
            <html>
                <head><title>Пандора (2019)/Pandora смотреть онлайн</title></head>
                <body>
                    <h1>Пандора (2019)/Pandora</h1>
                    <div class="pgs-sinfo_list">
                        Оригинал: Pandora
                        Альтернативное название: Pandora, Пандора (2019)
                    </div>
                </body>
            </html>
            HTML,
            'https://seasonvar.ru/serial-22653-Pandora_2019.html',
        );

        $this->assertSame([], $data['aliases']);
    }
}
