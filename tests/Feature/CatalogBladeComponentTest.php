<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Country;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\LicensedMedia;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogBladeComponentTest extends TestCase
{
    use RefreshDatabase;

    public function test_title_card_name_is_the_primary_typographic_element(): void
    {
        $title = CatalogTitle::factory()->make([
            'title' => 'Главное название сериала',
            'year' => 2026,
        ]);

        foreach ([
            Blade::render('<x-catalog.title-card :title="$title" layout="list" />', ['title' => $title]),
            Blade::render('<x-catalog.title-card :title="$title" layout="recommendation" />', ['title' => $title]),
        ] as $html) {
            $this->assertMatchesRegularExpression(
                '/<h3[^>]*class="[^"]*text-lg[^"]*font-semibold[^"]*"[^>]*>.*text-slate-900.*Главное название сериала.*<\/h3>/s',
                $html,
            );
            $this->assertStringContainsString('text-xs font-semibold', $html);
        }
    }

    public function test_header_uses_one_desktop_row_one_search_root_and_five_mobile_actions(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();
        $brandPosition = strpos($html, 'data-site-header-brand');
        $navigationPosition = strpos($html, 'data-site-header-primary-navigation');
        $searchPosition = strpos($html, 'data-header-search-autocomplete');
        $actionsPosition = strpos($html, 'data-site-header-actions');

        $this->assertIsInt($brandPosition);
        $this->assertIsInt($navigationPosition);
        $this->assertIsInt($searchPosition);
        $this->assertIsInt($actionsPosition);
        $this->assertLessThan($navigationPosition, $brandPosition);
        $this->assertLessThan($searchPosition, $navigationPosition);
        $this->assertLessThan($actionsPosition, $searchPosition);
        $this->assertSame(1, substr_count($html, 'data-header-search-autocomplete'));
        $this->assertSame(1, substr_count($html, 'data-site-header-actions'));
        $this->assertSame(2, substr_count($html, 'data-mobile-search-action'));
        $this->assertSame(1, substr_count($html, 'data-mobile-bottom-navigation'));
        $this->assertSame(5, substr_count($html, 'data-mobile-navigation-item'));
        $this->assertStringContainsString('>Seasonvar<', $html);
    }

    public function test_title_card_and_list_row_render_only_genre_chips_as_links(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Навигационный сериал',
            'slug' => 'navigacionnyi-serial',
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
        $catalogTitle->load(['genres', 'countries', 'seasons']);

        $cardHtml = Blade::render('<x-catalog.title-card :title="$title" layout="list" />', ['title' => $catalogTitle]);
        $rowHtml = Blade::render('<x-catalog.title-card :title="$title" layout="compact" />', ['title' => $catalogTitle]);

        foreach ([$cardHtml, $rowHtml] as $html) {
            $this->assertStringContainsString('<article', $html);
            $this->assertStringContainsString('data-ui-poster-card', $html);
            $this->assertStringContainsString('data-ui-poster-frame', $html);
            $this->assertStringContainsString('href="'.route('titles.show', $catalogTitle).'"', $html);
            $this->assertStringContainsString('href="'.route('titles.taxonomy', ['type' => 'genre', 'taxonomy' => 'detektiv']).'"', $html);
            $this->assertStringNotContainsString('href="'.route('titles.taxonomy', ['type' => 'country', 'taxonomy' => 'ispaniia']).'"', $html);
        }
    }

    public function test_title_card_renders_details_action_and_only_visible_personal_state(): void
    {
        $title = CatalogTitle::factory()->make([
            'title' => 'Персональное состояние карточки',
            'slug' => 'personal-card-state',
        ]);

        $emptyHtml = Blade::render(
            '<x-catalog.title-card :title="$title" :user-in-watchlist="false" layout="list" />',
            ['title' => $title],
        );
        $ratedHtml = Blade::render(
            '<x-catalog.title-card :title="$title" :user-rating="8" layout="list" />',
            ['title' => $title],
        );

        $this->assertStringNotContainsString('data-user-card-state', $emptyHtml);
        $this->assertStringContainsString('data-title-card-details', $emptyHtml);
        $this->assertStringContainsString('Подробнее', $emptyHtml);
        $this->assertStringContainsString('data-user-card-state', $ratedHtml);
        $this->assertStringContainsString('data-user-rating="8"', $ratedHtml);
        $this->assertStringContainsString('data-title-card-details', $ratedHtml);
    }

    public function test_title_card_does_not_lazy_load_missing_relations(): void
    {
        $catalogTitle = CatalogTitle::factory()->make([
            'title' => 'Карточка без скрытых запросов',
        ]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        Blade::render('<x-catalog.title-card :title="$title" layout="list" />', ['title' => $catalogTitle]);

        $this->assertSame([], $queries);
    }

    public function test_latest_media_card_prepares_episode_metadata_outside_blade(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Новая серия',
            'poster_url' => 'https://media.example.com/latest.jpg',
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 2,
        ]);
        $episode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 7,
        ]);
        $media = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'translation_name' => 'Профессиональный перевод',
            'quality' => '1080p',
            'format' => 'm3u8',
            'published_at' => now()->setDate(2026, 7, 13),
        ])->load(['season', 'episode']);
        $episode->load('season');

        $html = Blade::render(
            '<x-catalog.latest-media-card :title="$title" :episodes="$episodes" :media="$media" />',
            [
                'title' => $catalogTitle,
                'episodes' => collect([$episode]),
                'media' => collect([$media]),
            ],
        );

        $this->assertStringContainsString('data-ui-poster-card', $html);
        $this->assertStringContainsString('data-home-latest-media-group="'.$catalogTitle->id.'"', $html);
        $this->assertStringContainsString('Новая серия', $html);
        $this->assertStringContainsString('Добавлена серия 7', $html);
        $this->assertStringContainsString('1 сезон', $html);
        $this->assertStringContainsString('1 новая серия', $html);
        $this->assertStringContainsString('Смотреть последнюю', $html);
        $this->assertStringContainsString('Показать серии', $html);
        $this->assertStringNotContainsString('Профессиональный перевод', $html);
        $this->assertStringNotContainsString('1080P', $html);
    }

    public function test_public_title_components_separate_matching_original_title_suffix(): void
    {
        $title = CatalogTitle::factory()->create([
            'title' => "Королевские гонки РуПола/RuPaul's Drag Race",
            'original_title' => "RuPaul's Drag Race",
            'poster_url' => 'https://media.example.com/rupaul.jpg',
        ]);

        foreach ([
            Blade::render('<x-catalog.title-card :title="$title" layout="list" />', ['title' => $title]),
            Blade::render('<x-catalog.title-card :title="$title" layout="compact" />', ['title' => $title]),
        ] as $html) {
            $this->assertStringContainsString('Королевские гонки РуПола', $html);
            $this->assertStringContainsString(e("RuPaul's Drag Race"), $html);
            $this->assertStringNotContainsString(e("Королевские гонки РуПола/RuPaul's Drag Race"), $html);
        }

        $showHtml = $this->get(route('titles.show', $title))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<h1[^>]*>.*Королевские гонки РуПола.*<\/h1>/s', $showHtml);
        $this->assertDoesNotMatchRegularExpression('/<h1[^>]*>.*Королевские гонки РуПола\/RuPaul.*<\/h1>/s', $showHtml);
        $this->assertStringContainsString(e("RuPaul's Drag Race"), $showHtml);

        $slashTitle = CatalogTitle::factory()->make([
            'title' => 'Мир/Дружба',
            'original_title' => 'World Friendship',
        ]);
        $slashHtml = Blade::render('<x-catalog.title-card :title="$title" layout="list" />', ['title' => $slashTitle]);

        $this->assertStringContainsString('Мир/Дружба', $slashHtml);
    }

    public function test_taxonomy_links_wrap_long_labels_without_losing_the_touch_target(): void
    {
        $html = Blade::render(
            '<x-ui.taxonomy-chip href="/titles/actor/example">Очень длинное имя участника каталога без сокращения</x-ui.taxonomy-chip>',
        );

        $this->assertStringContainsString('max-w-full', $html);
        $this->assertStringContainsString('min-h-11', $html);
        $this->assertStringContainsString('break-words', $html);
    }

    public function test_title_page_renders_componentized_episode_links_and_status_badges(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Компонентный сериал',
            'slug' => 'komponentnyi-serial',
            'poster_url' => 'https://media.example.com/component-poster.jpg',
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 1,
            'title' => 'Сезон 1',
        ]);
        Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 1,
            'title' => 'Первая серия',
        ]);
        $selectedEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 2,
            'title' => 'Вторая серия',
        ]);
        $selectedMedia = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $selectedEpisode->id,
            'title' => 'Видео 1080p',
            'path' => 'https://media.example.com/component-video.m3u8',
            'quality' => '1080p',
            'format' => 'm3u8',
            'check_status' => 'available',
            'last_successful_check_at' => now(),
            'status' => 'published',
            'published_at' => now(),
        ]);

        $response = $this->get(route('titles.show', [
            'catalogTitle' => $catalogTitle,
            'episode' => $selectedEpisode->id,
        ]));

        $response
            ->assertOk()
            ->assertSee(e(route('titles.show', [
                'catalogTitle' => $catalogTitle,
                'episode' => $selectedEpisode->id,
                'media' => $selectedMedia->id,
                'variant' => 'voiceover-default',
                'quality' => '1080p',
                'format' => 'm3u8',
            ]).'#player'), false)
            ->assertSee('aria-current="true"', false)
            ->assertSeeText('2 серия')
            ->assertSeeText('видео')
            ->assertSeeText('1 видео')
            ->assertSee('grid min-h-16 content-center gap-1 border-b border-slate-200 py-3 last:border-b-0', false)
            ->assertDontSee('grid min-h-16 content-center gap-1 rounded-lg bg-slate-50', false)
            ->assertDontSeeText('плеер готов');
    }

    public function test_title_page_places_season_anchor_on_season_block_with_scroll_offset(): void
    {
        [$catalogTitle, $firstSeason, $secondSeason] = $this->createSeasonAnchorFixture();

        $response = $this->get(route('titles.show', $catalogTitle));

        $response
            ->assertOk()
            ->assertSee('href="'.route('titles.show', [
                'catalogTitle' => $catalogTitle,
                'season' => $secondSeason->id,
            ]).'#seasons"', false)
            ->assertSee('aria-label="Доступные сезоны"', false)
            ->assertDontSeeText('Быстрый выбор сезона')
            ->assertSee('id="season-'.$firstSeason->id.'"', false)
            ->assertSee('class="scroll-mt-40 p-4 sm:scroll-mt-44 lg:scroll-mt-48"', false)
            ->assertDontSee('id="season-'.$secondSeason->id.'"', false);
    }

    public function test_title_page_honours_selected_season_query_on_the_initial_request(): void
    {
        [$catalogTitle, $firstSeason, $secondSeason] = $this->createSeasonAnchorFixture();
        $selectedSeasonResponse = $this->get(route('titles.show', [
            'catalogTitle' => $catalogTitle,
            'season' => $secondSeason->id,
        ]));

        $selectedSeasonResponse
            ->assertHeader('X-Seasonvar-Page-Cache', 'MISS')
            ->assertOk()
            ->assertSee('id="season-'.$secondSeason->id.'"', false)
            ->assertSee('class="scroll-mt-40 p-4 sm:scroll-mt-44 lg:scroll-mt-48"', false)
            ->assertSeeText('Вторая серия')
            ->assertDontSee('id="season-'.$firstSeason->id.'"', false);
    }

    public function test_title_page_rejects_invalid_review_and_comment_query_identifiers(): void
    {
        $catalogTitle = CatalogTitle::factory()->create();
        $returnUrl = route('titles.show', $catalogTitle);

        $this->from($returnUrl)
            ->get($returnUrl.'?review=0&comment=not-an-integer')
            ->assertRedirect($returnUrl)
            ->assertSessionHasErrors([
                'review' => 'Номер выбранного отзыва должен быть больше нуля.',
                'comment' => 'Номер выбранного комментария должен быть числом.',
            ]);
    }

    public function test_title_page_groups_playback_options_and_preserves_selected_variant_between_episodes(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Теория большого взрыва',
            'slug' => 'teoriia-bolshogo-vzryva',
            'poster_url' => 'https://media.example.com/big-bang-poster.jpg',
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 1,
            'title' => 'Сезон 1',
        ]);
        $firstEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 1,
            'title' => 'Пилот',
        ]);
        $secondEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 2,
            'title' => 'Гипотеза',
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $firstEpisode->id,
            'title' => '1 серия SD/FullHDКураж-Бамбей',
            'path' => 'https://media.example.com/big-bang-s01e01-voice.mp4',
            'playback_url' => 'https://media.example.com/big-bang-s01e01-voice.mp4',
            'source_url' => 'https://seasonvar.ru/playls2/hash/trans/415/plist.txt',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $firstSubtitleMedia = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $firstEpisode->id,
            'title' => '1 серия SDСубтитры',
            'path' => 'https://media.example.com/big-bang-s01e01-sub.mp4',
            'playback_url' => 'https://media.example.com/big-bang-s01e01-sub.mp4',
            'source_url' => 'https://seasonvar.ru/playls2/hash/trans%D0%A1%D1%83%D0%B1%D1%82%D0%B8%D1%82%D1%80%D1%8B/415/plist.txt',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $secondVoiceMedia = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $secondEpisode->id,
            'title' => '2 серия SD/FullHDКураж-Бамбей',
            'path' => 'https://media.example.com/big-bang-s01e02-voice.mp4',
            'playback_url' => 'https://media.example.com/big-bang-s01e02-voice.mp4',
            'source_url' => 'https://seasonvar.ru/playls2/hash/trans/415/plist.txt',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $secondSubtitleMedia = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $secondEpisode->id,
            'title' => '2 серия SDСубтитры',
            'path' => 'https://media.example.com/big-bang-s01e02-sub.mp4',
            'playback_url' => 'https://media.example.com/big-bang-s01e02-sub.mp4',
            'source_url' => 'https://seasonvar.ru/playls2/hash/trans%D0%A1%D1%83%D0%B1%D1%82%D0%B8%D1%82%D1%80%D1%8B/415/plist.txt',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $response = $this->get(route('titles.show', [
            'catalogTitle' => $catalogTitle,
            'episode' => $firstEpisode->id,
            'media' => $firstSubtitleMedia->id,
        ]));

        $response
            ->assertOk()
            ->assertSee('data-player-context-bar', false)
            ->assertSee('data-player-context-control="translation"', false)
            ->assertSeeText('Перевод')
            ->assertSeeText('Субтитры')
            ->assertSeeText('Кураж-Бамбей')
            ->assertSee(e(route('titles.show', [
                'catalogTitle' => $catalogTitle,
                'episode' => $secondEpisode->id,
                'variant' => 'subtitles-subtitry',
                'quality' => '480p',
                'format' => 'mp4',
            ]).'#player'), false);

        $variantResponse = $this->get(route('titles.show', [
            'catalogTitle' => $catalogTitle,
            'episode' => $secondEpisode->id,
            'variant' => 'subtitles-subtitry',
        ]));

        $variantResponse
            ->assertOk()
            ->assertSee('/playback/'.$secondSubtitleMedia->id.'?', false)
            ->assertDontSee('/playback/'.$secondVoiceMedia->id.'?', false)
            ->assertDontSee('https://media.example.com/big-bang-s01e02-sub.mp4', false)
            ->assertDontSee('https://media.example.com/big-bang-s01e02-voice.mp4', false);
    }

    public function test_title_page_shows_livewire_variant_loading_and_preserves_profile_query_for_episode_navigation(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Бухта вдов',
            'slug' => 'buxta-vdovwidows-bay',
            'poster_url' => 'https://media.example.com/widows-bay-poster.jpg',
        ]);
        $season = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 1,
            'title' => 'Сезон 1',
        ]);
        $firstEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 1,
            'title' => '1 серия',
        ]);
        $secondEpisode = Episode::factory()->create([
            'season_id' => $season->id,
            'number' => 2,
            'title' => '2 серия',
        ]);
        $selectedMedia = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $firstEpisode->id,
            'title' => '1 серия RuDub 480p',
            'path' => 'https://media.example.com/widows-bay-s01e01-rudub-480.mp4',
            'playback_url' => 'https://media.example.com/widows-bay-s01e01-rudub-480.mp4',
            'quality' => '480p',
            'format' => 'mp4',
            'variant_type' => 'voiceover',
            'variant_name' => 'RuDub',
            'variant_key' => 'voiceover-rudub',
            'status' => 'published',
            'published_at' => now(),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $firstEpisode->id,
            'title' => '1 серия LostFilm 1080p',
            'path' => 'https://media.example.com/widows-bay-s01e01-lostfilm-1080.mp4',
            'playback_url' => 'https://media.example.com/widows-bay-s01e01-lostfilm-1080.mp4',
            'quality' => '1080p',
            'format' => 'mp4',
            'variant_type' => 'voiceover',
            'variant_name' => 'LostFilm',
            'variant_key' => 'voiceover-lostfilm',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $secondEpisodePreferredMedia = LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $secondEpisode->id,
            'title' => '2 серия RuDub 1080p',
            'path' => 'https://media.example.com/widows-bay-s01e02-rudub-1080.mp4',
            'playback_url' => 'https://media.example.com/widows-bay-s01e02-rudub-1080.mp4',
            'quality' => '1080p',
            'format' => 'mp4',
            'variant_type' => 'voiceover',
            'variant_name' => 'RuDub',
            'variant_key' => 'voiceover-rudub',
            'status' => 'published',
            'published_at' => now(),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $season->id,
            'episode_id' => $secondEpisode->id,
            'title' => '2 серия LostFilm 480p',
            'path' => 'https://media.example.com/widows-bay-s01e02-lostfilm-480.mp4',
            'playback_url' => 'https://media.example.com/widows-bay-s01e02-lostfilm-480.mp4',
            'quality' => '480p',
            'format' => 'mp4',
            'variant_type' => 'voiceover',
            'variant_name' => 'LostFilm',
            'variant_key' => 'voiceover-lostfilm',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->get(route('titles.show', [
            'catalogTitle' => $catalogTitle,
            'episode' => $firstEpisode->id,
            'media' => $selectedMedia->id,
            'variant' => 'voiceover-rudub',
            'quality' => '480p',
            'format' => 'mp4',
        ]))
            ->assertOk()
            ->assertSee('wire:loading.delay.flex', false)
            ->assertSee('wire:target="selectMedia"', false)
            ->assertSee('grid min-h-20 content-center gap-1 rounded-lg', false)
            ->assertSeeText('Переключаем вариант…')
            ->assertSeeText('Обновляем серии под выбранный вариант…')
            ->assertDontSeeText('1 серия 1 серия')
            ->assertDontSeeText('2 серия 2 серия')
            ->assertSee(e(route('titles.show', [
                'catalogTitle' => $catalogTitle,
                'episode' => $secondEpisode->id,
                'variant' => 'voiceover-rudub',
                'quality' => '480p',
                'format' => 'mp4',
            ]).'#player'), false);

        $this->get(route('titles.show', [
            'catalogTitle' => $catalogTitle,
            'episode' => $secondEpisode->id,
            'variant' => 'voiceover-rudub',
            'quality' => '480p',
            'format' => 'mp4',
        ]))
            ->assertOk()
            ->assertSee('/playback/'.$secondEpisodePreferredMedia->id.'?', false);
    }

    /**
     * @return array{CatalogTitle, Season, Season}
     */
    private function createSeasonAnchorFixture(): array
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Якорный сериал',
            'slug' => 'iakornyi-serial',
        ]);
        $firstSeason = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 1,
            'title' => 'Сезон 1',
        ]);
        $secondSeason = Season::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'number' => 2,
            'title' => 'Сезон 2',
        ]);
        $firstEpisode = Episode::factory()->create([
            'season_id' => $firstSeason->id,
            'number' => 1,
            'title' => 'Первая серия',
        ]);
        $secondEpisode = Episode::factory()->create([
            'season_id' => $secondSeason->id,
            'number' => 1,
            'title' => 'Вторая серия',
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $firstSeason->id,
            'episode_id' => $firstEpisode->id,
            'status' => 'published',
            'published_at' => now(),
        ]);
        LicensedMedia::factory()->create([
            'catalog_title_id' => $catalogTitle->id,
            'season_id' => $secondSeason->id,
            'episode_id' => $secondEpisode->id,
            'status' => 'published',
            'published_at' => now(),
        ]);

        return [$catalogTitle, $firstSeason, $secondSeason];
    }
}
