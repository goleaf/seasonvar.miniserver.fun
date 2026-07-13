<?php

namespace Tests\Feature;

use App\Models\Actor;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleRecommendationSignal;
use App\Models\Country;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\SeasonvarImportEvent;
use App\Models\Source;
use App\Models\SourcePage;
use App\Models\Tag;
use App\Services\Seasonvar\SeasonvarCatalogImporter;
use App\Services\Seasonvar\SeasonvarDatabaseTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery;
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
            'publication_status' => 'published',
            'audience' => 'public',
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
        $this->assertDatabaseHas('seasons', [
            'number' => 1,
            'kind' => 'regular',
            'sort_order' => 1,
            'publication_status' => 'published',
        ]);
        $this->assertDatabaseHas('seasons', [
            'number' => 4,
            'kind' => 'regular',
            'sort_order' => 4,
            'publication_status' => 'published',
        ]);
        $this->assertDatabaseHas('episodes', [
            'number' => 1,
            'title' => 'Начало',
            'kind' => 'regular',
            'sort_order' => 1,
            'publication_status' => 'published',
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
        $this->assertDatabaseHas('catalog_title_search_documents', [
            'catalog_title_id' => $catalogTitle->id,
            'normalized_title_key' => 'черный список на кухне',
        ]);
        $this->assertSame(3, CatalogTitleRecommendationSignal::query()
            ->where('catalog_title_id', $catalogTitle->id)
            ->where('signal_type', 'page_quality')
            ->count());

        $sourcePages = SourcePage::query()
            ->whereIn('url', [
                'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html',
                'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html',
            ])
            ->get()
            ->keyBy('url');

        $seasonFourPage = $sourcePages->get('https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html');
        $seasonOnePage = $sourcePages->get('https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html');

        $this->assertNotNull($seasonFourPage);
        $this->assertNotNull($seasonOnePage);
        $this->assertSame($seasonOnePage->missing_data_flags, $seasonFourPage->missing_data_flags);
        $this->assertNotContains('seasons_without_episodes', $seasonFourPage->missing_data_flags);
    }

    public function test_it_imports_more_episodes_than_a_single_sqlite_upsert_can_bind(): void
    {
        Http::preventStrayRequests();

        $episodes = collect(range(1, 2600))
            ->mapWithKeys(fn (int $number): array => [$number => "{$number} серия"])
            ->all();

        Http::fake([
            'seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html' => Http::response($this->seasonPageHtml(4, $episodes)),
            'seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-1-season.html' => Http::response($this->seasonPageHtml(1, [])),
        ]);

        $this->artisan('seasonvar:import', [
            'url' => 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html',
        ])->assertExitCode(0);

        $catalogTitle = CatalogTitle::query()->where('external_id', '47915')->firstOrFail();

        $this->assertSame(2600, Episode::query()
            ->whereHas('season', fn ($query) => $query->where('catalog_title_id', $catalogTitle->id))
            ->count());
        $this->assertDatabaseHas('episodes', [
            'season_id' => $catalogTitle->seasons()->where('number', 4)->sole()->id,
            'number' => 2600,
            'title' => '2600 серия',
        ]);
    }

    public function test_it_keeps_distinct_season_provider_ids_inside_one_catalog_title(): void
    {
        Http::preventStrayRequests();

        $seasonOneUrl = 'https://seasonvar.ru/serial-7780-Mamochka_psxtsdh.html';
        $seasonTwoUrl = 'https://seasonvar.ru/serial-10781-Mamochka_pszlnxu-2-season.html';
        $seasonSixUrl = 'https://seasonvar.ru/serial-20002-Mamochka_psgyjds-6-season.html';
        $seasonUrls = [
            1 => $seasonOneUrl,
            2 => $seasonTwoUrl,
            6 => $seasonSixUrl,
        ];

        Http::fake([
            $seasonSixUrl => Http::response($this->seasonvarFamilyPageHtml(6, $seasonUrls, [
                1 => 'Шестой сезон',
            ])),
            $seasonOneUrl => Http::response($this->seasonvarFamilyPageHtml(1, $seasonUrls, [
                1 => 'Первый сезон',
            ])),
            $seasonTwoUrl => Http::response($this->seasonvarFamilyPageHtml(2, $seasonUrls, [
                1 => 'Второй сезон',
            ])),
        ]);

        $this->artisan('seasonvar:import', [
            'url' => $seasonSixUrl,
        ])->assertExitCode(0);

        $this->assertSame(1, CatalogTitle::query()->where('title', 'Мамочка/Mom')->count());

        $catalogTitle = CatalogTitle::query()->where('title', 'Мамочка/Mom')->firstOrFail();
        $catalogTitle->load('seasons.episodes');

        $this->assertSame([1, 2, 6], $catalogTitle->seasons->sortBy('number')->pluck('number')->values()->all());
        $this->assertSame(
            [1 => 1, 2 => 1, 6 => 1],
            $catalogTitle->seasons
                ->sortBy('number')
                ->mapWithKeys(fn (Season $season): array => [$season->number => $season->episodes->count()])
                ->all(),
        );
    }

    public function test_it_prefers_the_single_most_complete_existing_title_when_duplicate_season_urls_exist(): void
    {
        Http::preventStrayRequests();

        $seasonOneUrl = 'https://seasonvar.ru/serial-7780-Mamochka_psxtsdh.html';
        $seasonSixUrl = 'https://seasonvar.ru/serial-20002-Mamochka_psgyjds-6-season.html';
        $seasonSevenUrl = 'https://seasonvar.ru/serial-23554-Mamochka_pshevik-7-season.html';
        $seasonUrls = [
            1 => $seasonOneUrl,
            6 => $seasonSixUrl,
            7 => $seasonSevenUrl,
        ];

        $source = Source::factory()->create(['code' => 'seasonvar']);

        $pages = collect($seasonUrls)
            ->mapWithKeys(fn (string $url, int $number): array => [
                $number => SourcePage::factory()->create([
                    'source_id' => $source->id,
                    'url' => $url,
                    'url_hash' => hash('sha256', $url),
                ]),
            ]);

        $completeTitle = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $pages[6]->id,
            'external_id' => '20002',
            'slug' => 'mamockamom-complete',
            'title' => 'Мамочка/Mom',
            'source_url' => $seasonSixUrl,
            'source_url_hash' => hash('sha256', $seasonSixUrl),
        ]);
        $duplicateTitle = CatalogTitle::factory()->create([
            'source_id' => $source->id,
            'source_page_id' => $pages[7]->id,
            'external_id' => '23554',
            'slug' => 'mamockamom-duplicate',
            'title' => 'Мамочка/Mom',
            'source_url' => $seasonSevenUrl,
            'source_url_hash' => hash('sha256', $seasonSevenUrl),
        ]);

        foreach ($seasonUrls as $number => $url) {
            Season::factory()->create([
                'catalog_title_id' => $completeTitle->id,
                'source_page_id' => $pages[$number]->id,
                'number' => $number,
                'source_url' => $url,
                'source_url_hash' => hash('sha256', $url),
            ]);
        }

        Season::factory()->create([
            'catalog_title_id' => $duplicateTitle->id,
            'source_page_id' => $pages[7]->id,
            'number' => 7,
            'source_url' => $seasonSevenUrl,
            'source_url_hash' => hash('sha256', $seasonSevenUrl),
        ]);

        Http::fake([
            $seasonSevenUrl => Http::response($this->seasonvarFamilyPageHtml(7, $seasonUrls, [
                1 => 'Седьмой сезон',
            ])),
            $seasonOneUrl => Http::response($this->seasonvarFamilyPageHtml(1, $seasonUrls, [
                1 => 'Первый сезон',
            ])),
            $seasonSixUrl => Http::response($this->seasonvarFamilyPageHtml(6, $seasonUrls, [
                1 => 'Шестой сезон',
            ])),
        ]);

        $this->artisan('seasonvar:import', [
            'url' => $seasonSevenUrl,
            '--force' => true,
        ])->assertExitCode(0);

        $completeTitle->refresh()->load('seasons.episodes');
        $duplicateTitle->refresh()->load('seasons.episodes');

        $this->assertSame(3, $completeTitle->seasons->count());
        $this->assertSame(
            [1 => 1, 6 => 1, 7 => 1],
            $completeTitle->seasons
                ->sortBy('number')
                ->mapWithKeys(fn (Season $season): array => [$season->number => $season->episodes->count()])
                ->all(),
        );
        $this->assertSame(0, $duplicateTitle->seasons->sum(fn (Season $season): int => $season->episodes->count()));
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

    public function test_it_ignores_the_volatile_playlist_time_when_identifying_existing_media(): void
    {
        Http::preventStrayRequests();

        $mediaUrl = 'https://media.example.com/kitchen/s04e02.mp4';
        $playlistBody = json_encode([
            [
                'title' => '2 серия SD/HD<br>',
                'file' => $this->encodedSeasonvarPlayerFile('//media.example.com/kitchen/s04e02.mp4'),
                'id' => '2',
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        Http::fake([
            'seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html' => Http::sequence()
                ->push($this->seasonPageHtml(
                    4,
                    [2 => 'Проверка'],
                    seasonvarPlaylists: ['/playls2/hash/trans/47915/plist.txt?time=1783594881'],
                ))
                ->push($this->seasonPageHtml(
                    4,
                    [2 => 'Проверка'],
                    seasonvarPlaylists: ['/playls2/hash/trans/47915/plist.txt?time=1783594882'],
                )),
            'seasonvar.ru/playls2/hash/trans/47915/plist.txt?time=1783594881' => Http::response($playlistBody),
            'seasonvar.ru/playls2/hash/trans/47915/plist.txt?time=1783594882' => Http::response($playlistBody),
        ]);

        $this->artisan('seasonvar:import', [
            'url' => 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html',
        ])->assertExitCode(0);

        $media = LicensedMedia::query()->where('playback_url', $mediaUrl)->sole();
        $originalUpdatedAt = $media->updated_at;
        $this->travel(1)->minute();

        $this->artisan('seasonvar:import', [
            'url' => 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html',
            '--force' => true,
        ])->assertExitCode(0);

        $media->refresh();

        $this->assertSame($originalUpdatedAt?->toDateTimeString(), $media->updated_at?->toDateTimeString());
        $this->assertSame('https://seasonvar.ru/playls2/hash/trans/47915/plist.txt', $media->source_url);
        $this->assertSame(1, LicensedMedia::query()->where('playback_url', $mediaUrl)->count());
    }

    public function test_it_imports_nested_seasonvar_player_playlist_folders(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'seasonvar.ru/serial-7762-Poka_prohodit_zhizn_psfhncj.html' => Http::response($this->seasonPageHtml(1, [
                1 => '1 серия',
                101 => '101 серия',
            ], seasonvarPlaylists: ['/playls2/hash/trans/7762/plist.txt?time=1783891671'])),
            'seasonvar.ru/playls2/hash/trans/7762/plist.txt?time=1783891671' => Http::response(json_encode([
                [
                    'title' => '1-100 серия',
                    'folder' => [
                        [
                            'title' => '1 серия SD<br>',
                            'file' => $this->encodedSeasonvarPlayerFile('//media.example.com/mientras-haya-vida/s01e01.mp4'),
                            'id' => '1',
                        ],
                    ],
                ],
                [
                    'title' => '101-187 серия',
                    'folder' => [
                        [
                            'title' => '101 серия SD<br>',
                            'file' => $this->encodedSeasonvarPlayerFile('//media.example.com/mientras-haya-vida/s01e101.mp4'),
                            'id' => '101',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ]);

        $this->artisan('seasonvar:import', [
            'url' => 'https://seasonvar.ru/serial-7762-Poka_prohodit_zhizn_psfhncj.html',
        ])->assertExitCode(0);

        $catalogTitle = CatalogTitle::query()->where('external_id', '7762')->firstOrFail();
        $episodeIds = Episode::query()
            ->whereHas('season', fn ($query) => $query->where('catalog_title_id', $catalogTitle->id))
            ->pluck('id', 'number');

        $this->assertDatabaseHas('licensed_media', [
            'catalog_title_id' => $catalogTitle->id,
            'episode_id' => $episodeIds->get(1),
            'playback_url' => 'https://media.example.com/mientras-haya-vida/s01e01.mp4',
            'status' => 'published',
        ]);
        $this->assertDatabaseHas('licensed_media', [
            'catalog_title_id' => $catalogTitle->id,
            'episode_id' => $episodeIds->get(101),
            'playback_url' => 'https://media.example.com/mientras-haya-vida/s01e101.mp4',
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
        $url = 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html';
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $page = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'content_hash' => null,
            'parse_status' => 'pending',
        ]);
        $transactionLevelBeforeImport = DB::transactionLevel();
        $mediaRequestTransactionLevels = [];
        $pageHtml = $this->seasonPageHtml(4, [
            2 => 'Проверка',
        ], ['https://media.example.com/kitchen/s04e02.mp4']);

        Http::fake(function (Request $request) use (&$mediaRequestTransactionLevels, $pageHtml, $url) {
            if ($request->url() === $url) {
                return Http::response($pageHtml);
            }

            if ($request->url() === 'https://media.example.com/kitchen/s04e02.mp4') {
                $mediaRequestTransactionLevels[] = DB::transactionLevel();

                return Http::response('', 206);
            }

            return Http::response('', 404);
        });

        $result = app(SeasonvarCatalogImporter::class)->parsePage($page);

        $this->assertSame(1, $result['media_attached']);
        $this->assertDatabaseHas('licensed_media', [
            'playback_url' => 'https://media.example.com/kitchen/s04e02.mp4',
        ]);
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

    public function test_changed_source_page_updates_poster(): void
    {
        $this->travelTo('2026-07-10 08:00:00');
        Http::preventStrayRequests();
        $url = 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html';
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $page = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'serial',
            'parse_status' => 'pending',
            'content_hash' => null,
        ]);
        Http::fake([
            $url => Http::sequence()
                ->push($this->seasonPageHtml(4, [1 => 'Пилот'], posterUrl: 'https://img.example.com/old.jpg'))
                ->push($this->seasonPageHtml(4, [1 => 'Пилот'], posterUrl: 'https://img.example.com/new.jpg')),
        ]);
        $importer = app(SeasonvarCatalogImporter::class);

        $importer->parsePage($page);
        $firstChangedAt = $page->fresh()->last_changed_at;
        $firstImportedAt = $page->fresh()->last_imported_at;
        $this->travel(25)->hours();
        $importer->parsePage($page->fresh());

        $freshPage = $page->fresh();

        $this->assertSame(
            'https://img.example.com/new.jpg',
            CatalogTitle::query()->where('external_id', '47915')->value('poster_url'),
        );
        Http::assertSentCount(2);
        $this->assertTrue($freshPage->last_changed_at->greaterThan($firstChangedAt));
        $this->assertTrue($freshPage->last_imported_at->greaterThan($firstImportedAt));
    }

    public function test_repeat_import_is_idempotent_and_preserves_editorial_visibility_and_fields(): void
    {
        Http::preventStrayRequests();
        $url = 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html';
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $page = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'serial',
            'parse_status' => 'pending',
        ]);
        $providerHtml = $this->seasonPageHtml(
            4,
            [1 => 'Пилот'],
            ['https://media.example.com/kitchen/s04e01.mp4'],
            posterUrl: 'https://img.example.com/provider.jpg',
        );
        Http::fake([
            $url => Http::sequence()->push($providerHtml)->push($providerHtml),
        ]);
        $importer = app(SeasonvarCatalogImporter::class);

        $importer->parsePage($page);

        $title = CatalogTitle::query()->where('external_id', '47915')->firstOrFail();
        $season = $title->seasons()->where('number', 4)->firstOrFail();
        $episode = $season->episodes()->where('number', 1)->firstOrFail();
        $media = LicensedMedia::query()->where('episode_id', $episode->id)->firstOrFail();
        $title->update([
            'title' => 'Редакционное название',
            'description' => 'Редакционное описание',
            'poster_url' => 'https://img.example.com/editorial.jpg',
            'is_published' => false,
            'publication_status' => 'hidden',
            'audience' => 'authenticated',
        ]);
        $season->update([
            'publication_status' => 'hidden',
            'audience' => 'authenticated',
        ]);
        $episode->update([
            'publication_status' => 'hidden',
            'audience' => 'authenticated',
        ]);
        $media->delete();
        $title->delete();

        $importer->parsePage($page->fresh(), force: true);

        $title = CatalogTitle::withTrashed()->findOrFail($title->id);
        $this->assertTrue($title->trashed());
        $this->assertSame('Редакционное название', $title->title);
        $this->assertSame('Редакционное описание', $title->description);
        $this->assertSame('https://img.example.com/editorial.jpg', $title->poster_url);
        $this->assertFalse($title->is_published);
        $this->assertSame('hidden', $title->publication_status->value);
        $this->assertSame('authenticated', $title->audience->value);
        $this->assertSame('hidden', $season->fresh()->publication_status->value);
        $this->assertSame('authenticated', $season->fresh()->audience->value);
        $this->assertSame('hidden', $episode->fresh()->publication_status->value);
        $this->assertSame('authenticated', $episode->fresh()->audience->value);
        $this->assertTrue(LicensedMedia::withTrashed()->findOrFail($media->id)->trashed());
        $this->assertDatabaseCount('catalog_titles', 1);
        $this->assertDatabaseCount('seasons', 2);
        $this->assertDatabaseCount('episodes', 1);
        $this->assertDatabaseCount('catalog_title_genre', 1);
        $this->assertDatabaseCount('catalog_title_country', 1);
        $this->assertDatabaseCount('licensed_media', 1);
    }

    public function test_partial_provider_page_does_not_remove_previous_recommendation_signals(): void
    {
        Http::preventStrayRequests();
        $url = 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html';
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $page = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'serial',
            'parse_status' => 'pending',
        ]);
        $completeHtml = $this->seasonPageHtml(4, [1 => 'Пилот']);
        $partialHtml = preg_replace(
            '/<div class="pgs-sinfo_list">.*?<\/div>/s',
            '',
            $completeHtml,
        );
        $this->assertIsString($partialHtml);
        Http::fake([
            $url => Http::sequence()->push($completeHtml)->push($partialHtml),
        ]);
        $importer = app(SeasonvarCatalogImporter::class);

        $importer->parsePage($page);
        $title = CatalogTitle::query()->where('external_id', '47915')->firstOrFail();
        $this->assertDatabaseHas('catalog_title_recommendation_signals', [
            'catalog_title_id' => $title->id,
            'signal_type' => 'taxonomy_genre',
            'signal_value' => 'Кулинария',
        ]);

        $importer->parsePage($page->fresh(), force: true);

        $this->assertDatabaseHas('catalog_title_recommendation_signals', [
            'catalog_title_id' => $title->id,
            'signal_type' => 'taxonomy_genre',
            'signal_value' => 'Кулинария',
        ]);
    }

    public function test_invalid_provider_payload_is_rejected_before_catalog_writes(): void
    {
        Http::preventStrayRequests();
        $url = 'https://seasonvar.ru/serial-99999-empty-1-season.html';
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $page = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'serial',
            'parse_status' => 'pending',
        ]);
        Http::fake([$url => Http::response('<html><body></body></html>')]);

        try {
            app(SeasonvarCatalogImporter::class)->parsePage($page);
            $this->fail('Некорректный provider payload должен быть отклонен.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('title', $exception->errors());
        }

        $this->assertDatabaseCount('catalog_titles', 0);
        $this->assertSame('pending', $page->fresh()->parse_status);
    }

    public function test_equivalent_person_names_share_canonical_identity_across_provider_urls(): void
    {
        Http::preventStrayRequests();
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $firstUrl = 'https://seasonvar.ru/serial-47915-first-1-season.html';
        $secondUrl = 'https://seasonvar.ru/serial-77777-second-1-season.html';
        $firstPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $firstUrl,
            'url_hash' => hash('sha256', $firstUrl),
            'page_type' => 'serial',
            'parse_status' => 'pending',
        ]);
        $secondPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $secondUrl,
            'url_hash' => hash('sha256', $secondUrl),
            'page_type' => 'serial',
            'parse_status' => 'pending',
        ]);
        $firstHtml = str_replace(
            '</body>',
            '<a href="/actor/1001-aleksandr-ivanov">Александр Иванов</a></body>',
            $this->seasonPageHtml(1, [1 => 'Пилот']),
        );
        $secondHtml = str_replace(
            '</body>',
            '<a href="/actor/2002-aleksandr-ivanov">Александр Иванов</a></body>',
            $this->seasonPageHtml(1, [1 => 'Пилот']),
        );
        Http::fake([
            $firstUrl => Http::response($firstHtml),
            $secondUrl => Http::response($secondHtml),
        ]);
        $importer = app(SeasonvarCatalogImporter::class);

        $importer->parsePage($firstPage);
        $importer->parsePage($secondPage);

        $this->assertSame(2, CatalogTitle::query()->count());
        $actor = Actor::query()->where('name', 'Александр Иванов')->sole();

        $this->assertSame('https://seasonvar.ru/actor/1001-aleksandr-ivanov', $actor->source_url);
        $this->assertEqualsCanonicalizing(
            CatalogTitle::query()->pluck('id')->all(),
            $actor->catalogTitles()->pluck('catalog_titles.id')->all(),
        );
    }

    public function test_importer_uses_retrying_transaction_for_catalog_writes(): void
    {
        Http::preventStrayRequests();
        $url = 'https://seasonvar.ru/serial-47915-CHernyj_spisok_Na_kuhne-4-season.html';
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $page = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'serial',
            'parse_status' => 'pending',
        ]);
        Http::fake([
            $url => Http::response($this->seasonPageHtml(4, [1 => 'Пилот'])),
        ]);
        $transactions = Mockery::mock(SeasonvarDatabaseTransaction::class);
        $transactions->shouldReceive('run')
            ->once()
            ->withArgs(function ($callback, int $attempts, int $delay, $progress): bool {
                return is_callable($callback)
                    && $attempts === 5
                    && $delay === 250
                    && $progress === null;
            })
            ->andReturnUsing(fn ($callback) => $callback());
        $this->app->instance(SeasonvarDatabaseTransaction::class, $transactions);

        app(SeasonvarCatalogImporter::class)->parsePage($page);

        $this->assertSame('parsed', $page->fresh()->parse_status);
    }

    public function test_metadata_taxonomy_pages_are_idempotent_and_queue_only_bounded_valid_serial_links(): void
    {
        Http::preventStrayRequests();
        config([
            'seasonvar.page_types.actor.enabled' => true,
            'seasonvar.page_types.actor.automatic' => true,
            'seasonvar.import.max_linked_serial_urls' => 2,
        ]);
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $url = 'https://seasonvar.ru/actor/1001-aleksandr-ivanov';
        $otherPersonUrl = 'https://seasonvar.ru/actor/2002-aleksandr-ivanov';
        Actor::query()->create([
            'name' => 'Александр Иванов',
            'slug' => 'aleksandr-ivanov',
            'source_url' => $otherPersonUrl,
        ]);
        $page = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'actor',
            'parse_status' => 'pending',
        ]);
        $html = <<<'HTML'
            <html>
                <head>
                    <title>Александр Иванов — сериалы</title>
                    <link rel="canonical" href="https://seasonvar.ru/actor/1001-aleksandr-ivanov">
                </head>
                <body>
                    <h1>Александр Иванов</h1>
                    <div data-source-count="3"></div>
                    <a href="/serial-100-Pervyj-1-season.html">Первый</a>
                    <a href="https://www.seasonvar.ru//serial-100-Pervyj-1-season.html#episode">Дубликат</a>
                    <a href="/serial-200-Vtoroj-1-season.html">Второй</a>
                    <a href="/serial-300-Tretij-1-season.html">Третий сверх лимита</a>
                    <a href="https://example.test/serial-400.html">Чужой хост</a>
                    <a href="/player.php?token=secret">Player</a>
                </body>
            </html>
            HTML;
        Http::fake([$url => Http::response($html, 200, ['ETag' => '"actor-v1"'])]);

        $importer = app(SeasonvarCatalogImporter::class);
        $first = $importer->parsePage($page);
        $this->assertNull($first['catalog_title']);
        $this->assertDatabaseCount('actors', 1);
        $this->assertDatabaseHas('actors', [
            'name' => 'Александр Иванов',
            'source_url' => $otherPersonUrl,
        ]);
        $this->assertSame(2, SourcePage::query()->where('page_type', 'serial')->count());
        $this->assertDatabaseHas('source_pages', [
            'url' => 'https://seasonvar.ru/serial-100-Pervyj-1-season.html',
            'parse_status' => 'pending',
        ]);
        $this->assertDatabaseHas('source_pages', [
            'url' => 'https://seasonvar.ru/serial-200-Vtoroj-1-season.html',
            'parse_status' => 'pending',
        ]);
        $this->assertDatabaseMissing('source_pages', [
            'url' => 'https://seasonvar.ru/serial-300-Tretij-1-season.html',
        ]);

        Http::fake([$url => Http::response($html, 200, ['ETag' => '"actor-v1"'])]);
        $importer->parsePage($page->fresh(), force: true);

        $this->assertDatabaseCount('actors', 1);
        $this->assertSame(2, SourcePage::query()->where('page_type', 'serial')->count());
        $this->assertDatabaseHas('source_pages', [
            'id' => $page->id,
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'etag' => '"actor-v1"',
        ]);
        $this->assertTrue(SeasonvarImportEvent::query()
            ->where('source_page_id', $page->id)
            ->where('event', 'seasonvar-taxonomy-duplicate-prevented')
            ->exists());
    }

    public function test_metadata_taxonomy_identity_normalizes_unicode_case_punctuation_and_preserves_source_url(): void
    {
        Http::preventStrayRequests();
        config([
            'seasonvar.page_types.genre.enabled' => true,
            'seasonvar.page_types.country.enabled' => true,
            'seasonvar.page_types.tag.enabled' => true,
        ]);
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $cases = [
            ['genre', Genre::class, 'https://seasonvar.ru/genre/tele-shou', 'ТЁЛЕ — ШОУ!', 'теле шоу', null],
            ['country', Country::class, 'https://seasonvar.ru/country/rossiya', '  РОССИЯ. ', 'россия', 'https://seasonvar.ru/country/rossiya'],
            ['tag', Tag::class, 'https://seasonvar.ru/tag/semeynoe', 'СЕМЁЙНОЕ!', 'семейное', null],
        ];

        foreach ($cases as [$type, $modelClass, $url, $incomingName, $existingName, $existingSourceUrl]) {
            $existing = $modelClass::query()->create([
                'name' => $existingName,
                'slug' => str($existingName)->replace('ё', 'е')->slug()->toString(),
                'source_url' => $existingSourceUrl,
            ]);
            $page = SourcePage::factory()->create([
                'source_id' => $source->id,
                'url' => $url,
                'url_hash' => hash('sha256', $url),
                'page_type' => $type,
                'parse_status' => 'pending',
            ]);
            Http::fake([$url => Http::response("<html><head><title>{$incomingName}</title></head><body><h1>{$incomingName}</h1></body></html>")]);

            app(SeasonvarCatalogImporter::class)->parsePage($page);

            $this->assertSame(1, $modelClass::query()->count());
            $this->assertSame($existing->id, $modelClass::query()->sole()->id);
            $this->assertSame($url, $modelClass::query()->sole()->source_url);
            $this->assertSame('parsed', $page->fresh()->parse_status);
        }

        $this->assertDatabaseCount('catalog_titles', 0);
    }

    public function test_rss_is_only_a_freshness_signal_and_normalizes_existing_serial_pages(): void
    {
        Http::preventStrayRequests();
        config([
            'seasonvar.page_types.rss.enabled' => true,
            'seasonvar.page_types.rss.automatic' => true,
            'seasonvar.import.max_linked_serial_urls' => 5,
        ]);
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $serialUrl = 'https://seasonvar.ru/serial-615--Bez_sleda_pssmtlk-1-season.html';
        $serialPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $serialUrl,
            'url_hash' => hash('sha256', $serialUrl),
            'page_type' => 'serial',
            'parse_status' => 'parsed',
            'import_status' => 'parsed',
            'retry_after_at' => now()->addDay(),
        ]);
        $rssUrl = 'https://seasonvar.ru/rss.php';
        $rssPage = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $rssUrl,
            'url_hash' => hash('sha256', $rssUrl),
            'page_type' => 'rss',
            'parse_status' => 'pending',
        ]);
        $rss = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel>
                <item><link>{$serialUrl}?utm_source=rss</link></item>
                <item><link>https://www.seasonvar.ru//serial-615--Bez_sleda_pssmtlk-1-season.html#fresh</link></item>
                <item><link>https://example.test/serial-1.html</link></item>
            </channel></rss>
            XML;
        Http::fake([$rssUrl => Http::response($rss, 200, ['Last-Modified' => 'Mon, 13 Jul 2026 10:00:00 GMT'])]);

        app(SeasonvarCatalogImporter::class)->parsePage($rssPage);

        $this->assertDatabaseCount('catalog_titles', 0);
        $this->assertSame(2, SourcePage::query()->count());
        $this->assertNull($serialPage->fresh()->retry_after_at);
        $this->assertSame('pending', $serialPage->fresh()->import_status);
        $this->assertSame('parsed', $rssPage->fresh()->parse_status);
    }

    public function test_static_and_search_pages_are_not_requested_or_published_by_metadata_pipeline(): void
    {
        Http::preventStrayRequests();
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);

        foreach ([
            ['static', 'https://seasonvar.ru/st/online_besplatno.html'],
            ['search', 'https://seasonvar.ru/search.php'],
        ] as [$type, $url]) {
            $page = SourcePage::factory()->create([
                'source_id' => $source->id,
                'url' => $url,
                'url_hash' => hash('sha256', $url),
                'page_type' => $type,
                'parse_status' => 'pending',
            ]);

            $result = app(SeasonvarCatalogImporter::class)->parsePage($page);

            $this->assertNull($result['catalog_title']);
            $this->assertSame('skipped', $page->fresh()->import_status);
            $this->assertSame('pending', $page->fresh()->parse_status);
        }

        $this->assertDatabaseCount('catalog_titles', 0);
        Http::assertNothingSent();
    }

    public function test_metadata_pages_use_conditional_requests_without_reimporting_unchanged_taxonomy(): void
    {
        Http::preventStrayRequests();
        config(['seasonvar.page_types.actor.enabled' => true]);
        $source = Source::factory()->create([
            'code' => 'seasonvar',
            'base_url' => 'https://seasonvar.ru',
            'crawl_delay_seconds' => 0,
        ]);
        $url = 'https://seasonvar.ru/actor/1001-aleksandr-ivanov';
        $page = SourcePage::factory()->create([
            'source_id' => $source->id,
            'url' => $url,
            'url_hash' => hash('sha256', $url),
            'page_type' => 'actor',
            'parse_status' => 'pending',
        ]);
        $html = '<html><head><title>Александр Иванов</title></head><body><h1>Александр Иванов</h1></body></html>';
        Http::fake([$url => Http::sequence()
            ->push($html, 200, [
                'ETag' => '"actor-v1"',
                'Last-Modified' => 'Mon, 13 Jul 2026 10:00:00 GMT',
            ])
            ->push('', 304)]);
        $importer = app(SeasonvarCatalogImporter::class);
        $importer->parsePage($page);

        $this->assertDatabaseCount('actors', 1);
        $this->assertDatabaseCount('source_page_snapshots', 1);
        $snapshot = $page->snapshots()->sole();
        $this->assertStringContainsString('structured_fields', $snapshot->html);
        $this->assertStringNotContainsString('Александр Иванов', $snapshot->html);

        $importer->parsePage($page->fresh());

        $this->assertDatabaseCount('actors', 1);
        $this->assertDatabaseCount('source_page_snapshots', 1);
        $this->assertSame(304, $page->fresh()->http_status);
        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('If-None-Match', '"actor-v1"')
                && $request->hasHeader('If-Modified-Since', 'Mon, 13 Jul 2026 10:00:00 GMT');
        });
    }

    /**
     * @param  array<int, string>  $episodes
     * @param  list<string>  $mediaUrls
     * @param  list<string>  $seasonvarPlaylists
     */
    private function seasonPageHtml(
        int $seasonNumber,
        array $episodes,
        array $mediaUrls = [],
        array $seasonvarPlaylists = [],
        ?string $posterUrl = null,
    ): string {
        $episodeItems = collect($episodes)
            ->mapWithKeys(fn (string $title, int $number): array => [
                "{$number}_seriya" => ['n' => (string) $number, 'title' => $title],
            ])
            ->all();
        $episodesJson = json_encode([$episodeItems], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $mediaJson = json_encode($mediaUrls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $playlistsJson = json_encode($seasonvarPlaylists, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $posterMeta = $posterUrl === null ? '' : '<meta property="og:image" content="'.$posterUrl.'">';

        return <<<HTML
            <html>
                <head>
                    <title>Черный список: На кухне {$seasonNumber} сезон смотреть онлайн</title>
                    <meta name="description" content="Описание передачи">
                    {$posterMeta}
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

    /**
     * @param  array<int, string>  $seasonUrls
     * @param  array<int, string>  $episodes
     */
    private function seasonvarFamilyPageHtml(int $seasonNumber, array $seasonUrls, array $episodes): string
    {
        $seasonLinks = collect($seasonUrls)
            ->map(fn (string $url, int $number): string => '<h2><a href="'.parse_url($url, PHP_URL_PATH).'">Сериал Мамочка/Mom '.$number.' сезон </a></h2>')
            ->implode("\n");
        $episodeItems = collect($episodes)
            ->mapWithKeys(fn (string $title, int $number): array => [
                "{$number}_seriya" => ['n' => (string) $number, 'title' => $title],
            ])
            ->all();
        $episodesJson = json_encode(['0' => $episodeItems], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return <<<HTML
            <html>
                <head>
                    <title>Сериал Мамочка {$seasonNumber} сезон Mom смотреть онлайн бесплатно!</title>
                    <meta name="description" content="Описание сезона {$seasonNumber}">
                </head>
                <body>
                    <h1 class="pgs-sinfo-title">Сериал Мамочка/Mom {$seasonNumber} сезон онлайн</h1>
                    <div class="pgs-sinfo_list">
                        Жанр: Комедия
                        Страна: США
                        Вышел: 2017
                    </div>
                    <div class="pgs-seaslist">
                        {$seasonLinks}
                    </div>
                    <script>
                        var pl = {'0': "/playls2/hash/trans/20002/plist.txt?time=1783917461"};
                        var arEpisodes = {$episodesJson};
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
