<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_filter_request_rejects_malformed_filter_slug(): void
    {
        $this
            ->from(route('titles.index'))
            ->get(route('titles.index', ['genre' => 'Bad Slug']))
            ->assertRedirect(route('titles.index'))
            ->assertSessionHasErrors('genre');
    }

    public function test_catalog_show_request_rejects_invalid_selected_episode_and_media_ids(): void
    {
        $catalogTitle = CatalogTitle::factory()->create([
            'slug' => 'validaciya-video',
            'title' => 'Валидация видео',
        ]);
        $showUrl = route('titles.show', $catalogTitle);

        $this
            ->from($showUrl)
            ->get(route('titles.show', [
                'catalogTitle' => $catalogTitle,
                'episode' => 'bad',
                'media' => 0,
            ]))
            ->assertRedirect($showUrl)
            ->assertSessionHasErrors(['episode', 'media']);
    }

    public function test_valid_but_unknown_taxonomy_filter_does_not_fall_back_to_full_catalog(): void
    {
        CatalogTitle::factory()->create([
            'slug' => 'vidimyi-serial',
            'title' => 'Видимый сериал',
        ]);

        $this
            ->get(route('titles.taxonomy', ['type' => 'genre', 'taxonomy' => 'neizvestnyi-zhanr']))
            ->assertOk()
            ->assertSeeText('Ничего не найдено.')
            ->assertDontSeeText('Видимый сериал');
    }
}
