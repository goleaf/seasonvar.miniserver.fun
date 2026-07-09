<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_search_form_filters_results_and_renders_normalized_query(): void
    {
        CatalogTitle::factory()->create([
            'slug' => 'znaxar',
            'title' => 'Знахарь',
            'description' => 'Медицинская драма',
        ]);
        CatalogTitle::factory()->create([
            'slug' => 'drugoi-serial',
            'title' => 'Другой сериал',
            'description' => 'Описание без точного совпадения',
        ]);

        $this
            ->get(route('titles.index', ['q' => '  Знахарь   ']))
            ->assertOk()
            ->assertSeeText('Знахарь')
            ->assertSee('value="Знахарь"', false)
            ->assertDontSeeText('Другой сериал');
    }

    public function test_catalog_search_form_shows_validation_error_and_preserves_old_input(): void
    {
        $longSearch = str_repeat('я', 161);

        $this
            ->followingRedirects()
            ->from(route('titles.index'))
            ->get(route('titles.index', ['q' => $longSearch]))
            ->assertOk()
            ->assertSeeText('Поисковый запрос слишком длинный.')
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('value="'.$longSearch.'"', false);
    }

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
