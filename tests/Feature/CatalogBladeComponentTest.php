<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogBladeComponentTest extends TestCase
{
    use RefreshDatabase;

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
        LicensedMedia::factory()->create([
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
            ]).'#player'), false)
            ->assertSee('aria-current="true"', false)
            ->assertSeeText('2 серия')
            ->assertSeeText('видео')
            ->assertSeeText('плеер готов')
            ->assertSeeText('1 файлов');
    }
}
