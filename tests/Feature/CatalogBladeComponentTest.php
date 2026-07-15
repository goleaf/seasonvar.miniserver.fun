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

    public function test_title_card_and_list_row_render_relation_chips_as_links(): void
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
            $this->assertStringContainsString('href="'.route('titles.taxonomy', ['type' => 'genre', 'taxonomy' => $genre->slug]).'"', $html);
            $this->assertStringContainsString('href="'.route('titles.taxonomy', ['type' => 'country', 'taxonomy' => $country->slug]).'"', $html);
        }
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
        $this->assertStringContainsString('Сезон 2', $html);
        $this->assertStringContainsString('7 серия', $html);
        $this->assertStringContainsString('Профессиональный перевод / M3U8 / 13.07.2026', $html);
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
            ->assertSee('grid min-h-16 content-center gap-1 rounded-lg bg-slate-50 px-3 py-3', false)
            ->assertDontSeeText('плеер готов');
    }

    public function test_title_page_places_season_anchor_on_season_block_with_scroll_offset(): void
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
            ->assertDontSeeText('Вторая серия');

        $this->get(route('titles.show', [
            'catalogTitle' => $catalogTitle,
            'season' => $secondSeason->id,
        ]))
            ->assertOk()
            ->assertSee('id="season-'.$secondSeason->id.'"', false)
            ->assertSee('class="scroll-mt-40 p-4 sm:scroll-mt-44 lg:scroll-mt-48"', false)
            ->assertSeeText('Вторая серия')
            ->assertDontSeeText('Первая серия');
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
            ->assertSeeText('Настройки просмотра')
            ->assertSeeText('Вариант')
            ->assertSeeText('Субтитры')
            ->assertSeeText('Кураж-Бамбей')
            ->assertSee(e(route('titles.show', [
                'catalogTitle' => $catalogTitle,
                'episode' => $secondEpisode->id,
                'media' => $secondSubtitleMedia->id,
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

    public function test_title_page_shows_livewire_variant_loading_and_episode_profile_labels(): void
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
            ->assertSee('grid min-h-12 grid-cols-[minmax(0,1fr)_auto] content-center items-center', false)
            ->assertSee('grid min-h-20 content-center gap-1 rounded-lg', false)
            ->assertSeeText('Переключаем вариант…')
            ->assertSeeText('Обновляем серии под выбранный вариант…')
            ->assertSeeText('RuDub / 1080P / MP4')
            ->assertDontSeeText('1 серия 1 серия')
            ->assertDontSeeText('2 серия 2 серия')
            ->assertSee(e(route('titles.show', [
                'catalogTitle' => $catalogTitle,
                'episode' => $secondEpisode->id,
                'media' => $secondEpisodePreferredMedia->id,
                'variant' => 'voiceover-rudub',
                'quality' => '1080p',
                'format' => 'mp4',
            ]).'#player'), false);
    }
}
