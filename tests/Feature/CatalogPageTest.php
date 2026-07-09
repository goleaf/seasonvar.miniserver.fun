<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertDontSeeText('Состояние базы');
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

    public function test_generated_title_search_links_keep_the_current_title_context(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'title' => 'Знахарь',
            'slug' => 'znaxar',
        ]);

        $response = $this->get(route('titles.show', $catalogTitle));

        $response
            ->assertOk()
            ->assertSee(e(route('titles.index', [
                'q' => 'Знахарь смотреть онлайн',
                'title' => 'znaxar',
            ])), false);
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
            'description' => 'В описании встречается слово Знахарь, но это другая карточка.',
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
