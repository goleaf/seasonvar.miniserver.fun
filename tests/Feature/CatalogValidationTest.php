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
}
