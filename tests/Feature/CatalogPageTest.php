<?php

namespace Tests\Feature;

use App\Livewire\StatsDashboard;
use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleAlias;
use App\Models\CatalogTitleRecommendation;
use App\Models\Country;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\SeasonvarImportEvent;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use App\Models\User;
use App\Services\Catalog\CatalogStatsPageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertDontSeeText('Состояние базы');
        $response->assertSeeText('Сейчас можно смотреть');
        $response->assertDontSeeText('Быстрый выбор');
    }

    public function test_home_page_lists_country_filters_without_four_item_cap(): void
    {
        $catalogTitle = CatalogTitle::factory()->create();

        collect(range(1, 6))->each(function (int $number) use ($catalogTitle): void {
            $country = Country::query()->create([
                'name' => 'Страна '.$number,
                'slug' => 'strana-'.$number,
            ]);

            $catalogTitle->countries()->attach($country->id);
        });

        $response = $this->get(route('home'));

        $response
            ->assertOk()
            ->assertSeeText('Страны')
            ->assertDontSeeText('Фильтр сериалов')
            ->assertSeeText('Страна 1')
            ->assertSeeText('Страна 5')
            ->assertSeeText('Страна 6');
    }

    public function test_stats_page_shows_database_statistics_without_raw_source_urls(): void
    {
        $sourceUrl = 'https://seasonvar.ru/serial-777-Skrytyj_url-1-season.html';
        $sourcePageUrl = 'https://seasonvar.ru/serial-778-Skrytyj_source_page-1-season.html';
        $runArgumentUrl = 'https://seasonvar.ru/serial-779-Skrytyj_argument-1-season.html';
        $eventContextUrl = 'https://seasonvar.ru/serial-780-Skrytyj_context-1-season.html';
        $posterUrl = 'https://media.example.com/private-poster.jpg';
        $mediaPath = 'https://media.example.com/private-video.m3u8';
        $mediaPlaybackUrl = 'https://cdn.example.com/private-playback.m3u8';
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Статистический сериал',
            'slug' => 'statisticheskii-serial',
            'year' => 2024,
            'poster_url' => $posterUrl,
            'source_url' => $sourceUrl,
            'source_url_hash' => hash('sha256', $sourceUrl),
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 1,
            'episodes_total' => 8,
            'episodes_released' => 2,
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 1,
            'released_at' => now(),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'title' => 'Видео 1080p',
            'path' => $mediaPath,
            'playback_url' => $mediaPlaybackUrl,
            'source_url' => 'https://seasonvar.ru/video-private-source.html',
            'quality' => '1080p',
            'format' => 'm3u8',
            'status' => 'published',
            'check_status' => 'available',
            'checked_at' => now(),
            'published_at' => now(),
        ]);
        $sourcePage = SourcePage::factory()->create([
            'url' => $sourcePageUrl,
            'url_hash' => hash('sha256', $sourcePageUrl),
            'parse_status' => 'failed',
            'import_status' => 'failed',
            'missing_data_flags' => ['video'],
            'retry_after_at' => now()->addHour(),
            'last_crawled_at' => now()->subDays(45),
        ]);
        $genreId = DB::table('genres')->insertGetId([
            'name' => 'Тестовый жанр',
            'slug' => 'testovyi-zhanr',
            'source_url' => 'https://seasonvar.ru/genre-private-source.html',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('catalog_title_genre')->insert([
            'catalog_title_id' => $catalogTitle->id,
            'genre_id' => $genreId,
        ]);
        $run = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'status' => 'failed',
            'argument' => $runArgumentUrl,
            'force' => true,
            'cycles' => 2,
            'discovered' => 12,
            'stored' => 10,
            'selected' => 7,
            'parsed' => 4,
            'failed' => 1,
            'media_attached' => 2,
            'media_updated' => 3,
            'media_skipped' => 5,
            'media_failed' => 6,
            'summary' => [
                'last_discovery' => [
                    'discovered' => 12,
                    'stored' => 10,
                    'cleaned' => 1,
                ],
                'last_source_status_backfill' => [
                    'selected' => 3,
                    'backfilled' => 2,
                ],
                'last_media_metadata_backlog' => [
                    'media_checked' => 3,
                    'media_updated' => 2,
                ],
                'last_media_source_key_backlog' => [
                    'media_checked' => 4,
                    'media_updated' => 1,
                ],
                'last_media_backlog' => [
                    'media_checked' => 5,
                    'media_available' => 4,
                    'media_unavailable' => 1,
                ],
                'last_relation_cleanup' => [
                    'records_removed' => 2,
                    'links_removed' => 3,
                    'legacy_records_removed' => 1,
                    'legacy_links_removed' => 1,
                ],
                'last_merge' => [
                    'titles' => 1,
                    'seasons' => 2,
                    'episodes' => 3,
                ],
                'last_url' => [
                    'url' => $runArgumentUrl,
                    'parsed' => 1,
                    'failed' => 0,
                ],
            ],
            'last_error' => 'raw stack trace secret',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now(),
        ]);
        SeasonvarImportEvent::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'source_page_id' => $sourcePage->id,
            'catalog_title_id' => $catalogTitle->id,
            'event' => 'parse_failed',
            'level' => 'error',
            'context' => ['url' => $eventContextUrl, 'secret' => 'private context secret'],
        ]);

        $this->actingAs(User::factory()->create());

        $response = $this->get(route('stats'));

        $response
            ->assertOk()
            ->assertSeeLivewire('stats-dashboard')
            ->assertSee('wire:poll.1s="refreshStats"', false)
            ->assertSee('/vendor/livewire/livewire.js?id=', false)
            ->assertSeeText('Сводка каталога')
            ->assertSeeText('Данные обновляются каждую секунду')
            ->assertSeeText('Показано:')
            ->assertSeeText('Сериалов каталога')
            ->assertSeeText('Сезоны и серии')
            ->assertSeeText('Видео')
            ->assertSeeText('Страницы и ссылки')
            ->assertSeeText('Индексов базы')
            ->assertSeeText('Проверка важных индексов')
            ->assertSeeText('Лента по опубликованным сериалам')
            ->assertSeeText('Индексы разделов')
            ->assertDontSeeText('Оптимизация БД')
            ->assertDontSeeText('Аудит ожидаемых индексов')
            ->assertDontSeeText('Индексы таблиц')
            ->assertSeeText('Разделы портала')
            ->assertSeeText('Внутренние ссылки')
            ->assertSeeText('Поля со ссылками')
            ->assertSeeText('Последние обновленные сериалы')
            ->assertSeeText('Требуют внимания')
            ->assertSeeText('Готовность по данным')
            ->assertSeeText('Качество сериалов')
            ->assertSeeText('Без опубликованного видео')
            ->assertSeeText('Временные срезы')
            ->assertSeeText('Последние запуски обновления')
            ->assertSeeText('Циклы')
            ->assertSeeText('Поиск')
            ->assertSeeText('Обслуживание')
            ->assertSeeText('Принудительно')
            ->assertSeeText('Статусы страниц: 2 / 3')
            ->assertSeeText('Данные видео: 2 / 3')
            ->assertSeeText('Ключи видео: 1 / 4')
            ->assertSeeText('Проверка видео: 5, доступно 4, недоступно 1')
            ->assertSeeText('Справочники: удалено записей 3, связей 4')
            ->assertSeeText('Объединение: 1 / 2 / 3')
            ->assertSeeText('Некорректные ссылки: 1')
            ->assertSeeText('Разделы базы')
            ->assertSeeText('#'.$run->id)
            ->assertSee(e(route('stats.poster', $catalogTitle)), false)
            ->assertDontSeeText('Сводка каталога смотреть онлайн')
            ->assertDontSee('Route', false)
            ->assertDontSee('URI', false)
            ->assertDontSee('titles.show', false)
            ->assertDontSee('/titles/{catalogTitle}', false)
            ->assertDontSee('catalog_titles.source_url', false)
            ->assertDontSee('licensed_media.playback_url', false)
            ->assertDontSee('catalog_titles_published_year_idx', false)
            ->assertDontSee('licensed_media_status_title_episode_idx', false)
            ->assertDontSee('catalog_titles', false)
            ->assertDontSee('parse_failed', false)
            ->assertDontSee($sourceUrl, false)
            ->assertDontSee($sourcePageUrl, false)
            ->assertDontSee($runArgumentUrl, false)
            ->assertDontSee($eventContextUrl, false)
            ->assertDontSee($posterUrl, false)
            ->assertDontSee($mediaPath, false)
            ->assertDontSee($mediaPlaybackUrl, false)
            ->assertDontSee('https://seasonvar.ru/video-private-source.html', false)
            ->assertDontSee('https://seasonvar.ru/genre-private-source.html', false)
            ->assertDontSee('raw stack trace secret', false)
            ->assertDontSee('private context secret', false);

        Livewire::test(StatsDashboard::class)
            ->call('refreshStats')
            ->assertSee('Сводка каталога')
            ->assertDontSee($sourceUrl)
            ->assertDontSee($sourcePageUrl)
            ->assertDontSee($runArgumentUrl)
            ->assertDontSee($eventContextUrl)
            ->assertDontSee($posterUrl)
            ->assertDontSee($mediaPath)
            ->assertDontSee($mediaPlaybackUrl)
            ->assertDontSee('raw stack trace secret')
            ->assertDontSee('private context secret');
    }

    public function test_stats_poster_proxy_is_public_and_does_not_require_raw_url_in_page(): void
    {
        Http::preventStrayRequests();

        $posterUrl = 'https://media.example.com/private-poster.jpg';
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Постер статистики',
            'slug' => 'poster-statistiki',
            'poster_url' => $posterUrl,
        ]);

        Http::fake([
            $posterUrl => Http::response('fake-image-body', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $response = $this->get(route('stats.poster', $catalogTitle));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertSee('fake-image-body', false);
    }

    public function test_stats_issue_rows_merge_multiple_issue_categories(): void
    {
        $withoutPoster = CatalogTitle::factory()->create([
            'title' => 'Статистика без постера',
            'slug' => 'statistika-bez-postera',
            'poster_url' => null,
            'description' => 'Описание есть.',
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $withoutPoster->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $withoutDescription = CatalogTitle::factory()->create([
            'title' => 'Статистика без описания',
            'slug' => 'statistika-bez-opisaniya',
            'poster_url' => 'https://media.example.com/without-description.jpg',
            'description' => null,
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $withoutDescription->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $data = app(CatalogStatsPageBuilder::class)->data();

        $issueTitles = collect($data['statsIssueRows'])->pluck('title');

        $this->assertContains('Статистика без постера', $issueTitles);
        $this->assertContains('Статистика без описания', $issueTitles);
    }

    public function test_titles_page_shows_posters_without_cropping_in_equal_size_area(): void
    {
        CatalogTitle::factory()->create([
            'title' => 'Тестовый сериал',
            'slug' => 'testovyi-serial',
            'poster_url' => 'https://media.example.com/poster.jpg',
        ]);

        $response = $this->get(route('titles.index'));

        $response
            ->assertOk()
            ->assertSee('aspect-[2/3]', false)
            ->assertSee('object-contain', false)
            ->assertDontSee('object-cover transition group-hover:scale-[1.02]', false);
    }

    public function test_public_catalog_pages_do_not_render_decorative_panel_subtitles(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Тестовый сериал без приписок',
            'slug' => 'testovyi-serial-bez-pripisok',
            'poster_url' => 'https://media.example.com/poster.jpg',
        ]);

        $hiddenPhrases = [
            'Справа показано число совпадений с текущими фильтрами.',
            'Свежие сериалы с постерами и основными счетчиками.',
            'Последние опубликованные видео и серии.',
            'Выберите серию в блоке сезонов. Плеер покажет доступное видео этой серии.',
            'Крупные кнопки подходят для телефона, планшета и ТВ.',
        ];

        foreach ([route('home'), route('titles.index'), route('titles.show', $catalogTitle)] as $url) {
            $response = $this->get($url)->assertOk();

            foreach ($hiddenPhrases as $phrase) {
                $response->assertDontSeeText($phrase);
            }

            foreach ([
                'id="table-of-contents"',
                'id="seo-summary"',
                'id="key-topics"',
                'id="semantic-glossary"',
                'id="long-tail-queries"',
                'id="popular-searches"',
                'id="quick-answers"',
            ] as $hiddenBlock) {
                $response->assertDontSee($hiddenBlock, false);
            }
        }
    }

    public function test_generated_title_search_blocks_are_hidden_on_public_title_page(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Знахарь',
            'slug' => 'znaxar',
        ]);

        $response = $this->get(route('titles.show', $catalogTitle));

        $response
            ->assertOk()
            ->assertSeeText('Знахарь')
            ->assertDontSee('id="popular-searches"', false)
            ->assertDontSee('id="semantic-clusters"', false)
            ->assertDontSee('id="long-tail-queries"', false);
    }

    public function test_title_scoped_catalog_search_stays_on_one_title(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Знахарь',
            'slug' => 'znaxar',
        ]);
        CatalogTitle::factory()->create([
            'title' => 'Другой сериал',
            'slug' => 'drugoi-serial',
            'description' => 'В описании встречается слово Знахарь, но это другой сериал.',
        ]);

        $response = $this->get(route('titles.index', [
            'q' => 'смотреть онлайн',
            'title' => $catalogTitle->slug,
        ]));

        $response
            ->assertOk()
            ->assertSeeText('Знахарь')
            ->assertSee('name="title"', false)
            ->assertSee('value="znaxar"', false)
            ->assertSee('title=znaxar', false)
            ->assertDontSeeText('Другой сериал');
    }

    public function test_catalog_search_prefers_exact_cyrillic_title_over_broad_description_matches(): void
    {
        CatalogTitle::factory()->create([
            'title' => 'Знахарь',
            'slug' => 'znaxar',
        ]);
        CatalogTitle::factory()->create([
            'title' => 'Другой сериал',
            'slug' => 'drugoi-serial',
            'description' => 'В описании встречается слово Знахарь, но название не совпадает.',
        ]);

        $response = $this->get(route('titles.index', [
            'q' => 'сериал знахарь описание жанры',
        ]));

        $response
            ->assertOk()
            ->assertSeeText('Знахарь')
            ->assertDontSeeText('Другой сериал');
    }

    public function test_catalog_search_uses_title_aliases_before_broad_description_matches(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'The Witcher',
            'slug' => 'the-witcher',
            'description' => 'Основной сериал без кириллического названия.',
        ]);
        CatalogTitleAlias::query()->create([
            'catalog_title_id' => $catalogTitle->id,
            'name' => 'Ведьмак',
            'name_hash' => hash('sha256', Str::lower('Ведьмак')),
            'type' => 'alternative',
            'source' => 'test',
        ]);
        CatalogTitle::factory()->create([
            'title' => 'Другой фэнтези сериал',
            'slug' => 'drugoi-fentezi-serial',
            'description' => 'В описании случайно встречается слово Ведьмак, но это другой сериал.',
        ]);

        $response = $this->get(route('titles.index', [
            'q' => 'сериал ведьмак смотреть онлайн',
        ]));

        $response
            ->assertOk()
            ->assertSeeText('The Witcher')
            ->assertDontSeeText('Другой фэнтези сериал');
    }

    public function test_catalog_search_keeps_taxonomy_name_matches_after_query_optimization(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Сериал с актером',
            'slug' => 'serial-s-akterom',
        ]);
        $actor = Actor::query()->create([
            'name' => 'Иван Петров',
            'slug' => 'ivan-petrov',
        ]);
        $catalogTitle->actors()->attach($actor->id);
        CatalogTitle::factory()->create([
            'title' => 'Сериал без актера',
            'slug' => 'serial-bez-aktera',
            'description' => 'Описание без фамилии искомого актера.',
        ]);

        $response = $this->get(route('titles.index', [
            'q' => 'Петров',
        ]));

        $response
            ->assertOk()
            ->assertSeeText('Сериал с актером')
            ->assertDontSeeText('Сериал без актера');
    }

    public function test_invalid_year_filter_does_not_fall_back_to_full_catalog(): void
    {
        CatalogTitle::factory()->create([
            'title' => 'Видимый сериал',
            'slug' => 'vidimyi-serial',
            'year' => 2024,
        ]);

        $response = $this->get(route('titles.index', ['year' => 'abcd']));

        $response
            ->assertOk()
            ->assertSeeText('Год: abcd не найден')
            ->assertDontSeeText('Видимый сериал');
    }

    public function test_title_page_uses_readable_related_lists_without_country_section(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Черная лагуна',
            'slug' => 'cernaia-lagunael-internado',
            'year' => 2007,
        ]);
        $genre = Genre::query()->create([
            'name' => 'Детектив',
            'slug' => 'detektiv',
        ]);
        $country = Country::query()->create([
            'name' => 'Испания',
            'slug' => 'ispaniia',
        ]);
        $catalogTitle->genres()->attach($genre->id);
        $catalogTitle->countries()->attach($country->id);

        $genreTitle = CatalogTitle::factory()->create([
            'title' => 'Очень длинное название похожего сериала с переносом текста',
            'original_title' => 'Long Original Related Series Title',
            'slug' => 'ocen-dlinnoe-nazvanie',
            'year' => 2008,
            'indexed_at' => now(),
        ]);
        $genreTitle->genres()->attach($genre->id);
        $genreSeason = Season::factory()->create([
            'catalog_title_id' => $genreTitle->id,
            'number' => 2,
        ]);
        Episode::factory()
            ->count(3)
            ->sequence(['number' => 1], ['number' => 2], ['number' => 3])
            ->create([
                'season_id' => $genreSeason->id,
            ]);

        CatalogTitle::factory()->create([
            'title' => 'Похожий сериал того же года',
            'slug' => 'poxozij-serial-togo-ze-goda',
            'year' => 2007,
            'indexed_at' => now()->subMinute(),
        ]);

        $countryTitle = CatalogTitle::factory()->create([
            'title' => 'Похожий только по стране',
            'slug' => 'poxozij-tolko-po-strane',
            'year' => 2010,
            'indexed_at' => now()->subMinutes(2),
        ]);
        $countryTitle->countries()->attach($country->id);

        $response = $this->get(route('titles.show', $catalogTitle));

        $response
            ->assertOk()
            ->assertSeeText('Советуем посмотреть')
            ->assertSeeText('По похожим жанрам')
            ->assertSeeText('За 2007 год')
            ->assertSeeText('Очень длинное название похожего сериала с переносом текста')
            ->assertSeeText('Long Original Related Series Title')
            ->assertSee('break-words', false)
            ->assertDontSeeText('Похожие сериалы пока не подобраны')
            ->assertDontSeeText('Еще из жанра')
            ->assertDontSeeText('Еще из страны')
            ->assertDontSeeText('Еще за 2007 год');
    }

    public function test_title_page_uses_precomputed_recommendations_in_rank_order(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Главный рекомендательный сериал',
            'slug' => 'glavnyi-rekomendatelnyi-serial',
        ]);
        $genre = Genre::query()->create([
            'name' => 'Детектив',
            'slug' => 'detektiv-rekomendacij',
        ]);
        $firstRecommendation = CatalogTitle::factory()->create([
            'title' => 'Первый точный совет',
            'slug' => 'pervyi-tocnyi-sovet',
        ]);
        $secondRecommendation = CatalogTitle::factory()->create([
            'title' => 'Второй точный совет',
            'slug' => 'vtoroi-tocnyi-sovet',
        ]);
        $catalogTitle->genres()->attach($genre->id);
        $firstRecommendation->genres()->attach($genre->id);
        $secondRecommendation->genres()->attach($genre->id);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $firstRecommendation->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $secondRecommendation->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        CatalogTitleRecommendation::query()->create([
            'catalog_title_id' => $catalogTitle->id,
            'recommended_title_id' => $secondRecommendation->id,
            'score' => 720,
            'rank' => 2,
            'reasons' => ['genre' => ['count' => 1, 'score' => 520]],
            'computed_at' => now(),
        ]);
        CatalogTitleRecommendation::query()->create([
            'catalog_title_id' => $catalogTitle->id,
            'recommended_title_id' => $firstRecommendation->id,
            'score' => 840,
            'rank' => 1,
            'reasons' => ['genre' => ['count' => 1, 'score' => 520]],
            'computed_at' => now(),
        ]);

        $response = $this->get(route('titles.show', $catalogTitle));

        $response
            ->assertOk()
            ->assertSeeText('Советуем посмотреть')
            ->assertSeeText('Ближайшие совпадения')
            ->assertSeeText('Жанр')
            ->assertDontSeeText('По жанрам')
            ->assertSeeTextInOrder([
                'Первый точный совет',
                'Второй точный совет',
            ]);
    }

    public function test_title_page_limits_stale_precomputed_recommendations(): void
    {
        config(['seasonvar.recommendations.max_per_title' => 3]);

        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Главный сериал с лишними советами',
            'slug' => 'glavnyi-serial-s-lishnimi-sovetami',
        ]);

        collect(range(1, 5))->each(function (int $rank) use ($catalogTitle): void {
            $recommendedTitle = CatalogTitle::factory()->create([
                'title' => 'Лишний совет '.$rank,
                'slug' => 'lishnii-sovet-'.$rank,
            ]);

            LicensedMedia::factory()->create([
                'catalog_title_id' => $recommendedTitle->id,
                'status' => 'published',
                'published_at' => now(),
            ]);

            CatalogTitleRecommendation::query()->create([
                'catalog_title_id' => $catalogTitle->id,
                'recommended_title_id' => $recommendedTitle->id,
                'score' => 1000 - $rank,
                'rank' => $rank,
                'reasons' => ['genre' => ['count' => 1, 'score' => 500]],
                'computed_at' => now(),
            ]);
        });

        $response = $this->get(route('titles.show', $catalogTitle));

        $response
            ->assertOk()
            ->assertSeeText('Лишний совет 1')
            ->assertSeeText('Лишний совет 2')
            ->assertSeeText('Лишний совет 3')
            ->assertDontSeeText('Лишний совет 4')
            ->assertDontSeeText('Лишний совет 5');
    }

    public function test_title_page_renders_when_recommendation_table_is_missing(): void
    {
        Schema::partialMock()
            ->shouldReceive('hasTable')
            ->with('catalog_title_recommendations')
            ->once()
            ->andReturnFalse();

        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Сериал без таблицы рекомендаций',
            'slug' => 'serial-bez-tablicy-rekomendacij',
            'year' => 2024,
        ]);

        $this->get(route('titles.show', $catalogTitle))
            ->assertOk()
            ->assertSeeText('Сериал без таблицы рекомендаций');
    }

    public function test_title_page_renders_selected_episode_media_state(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Видео сериал',
            'slug' => 'video-serial',
            'poster_url' => 'https://media.example.com/poster.jpg',
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 1,
            'title' => 'Сезон 1',
            'episodes_released' => 2,
            'episodes_total' => 8,
            'translation_name' => 'Дубляж',
            'latest_episode_released_at' => now(),
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 2,
            'title' => 'Вторая серия',
        ]);
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'title' => 'Видео 720p',
            'path' => 'https://media.example.com/video.m3u8',
            'quality' => '720p',
            'translation_name' => 'Дубляж',
            'format' => 'm3u8',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $response = $this->get(route('titles.show', [
            'catalogTitle' => $catalogTitle,
            'episode' => $episode->id,
            'media' => $media->id,
        ]));

        $response
            ->assertOk()
            ->assertSeeText('Выбрана 2 серия')
            ->assertSeeText('720P / Дубляж / M3U8')
            ->assertSee('data-hls-src="https://media.example.com/video.m3u8"', false)
            ->assertSee('type="application/x-mpegURL"', false);
    }
}
