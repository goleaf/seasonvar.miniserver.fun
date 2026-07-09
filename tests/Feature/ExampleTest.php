<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
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
}
