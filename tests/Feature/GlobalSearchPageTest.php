<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PublicationStatus;
use App\Livewire\GlobalSearchPage;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

final class GlobalSearchPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_search_route_is_owned_by_full_page_livewire(): void
    {
        $this->assertSame(
            GlobalSearchPage::class,
            Route::getRoutes()->getByName('search.index')?->getActionName(),
        );

        Livewire::withQueryParams(['q' => '  тест  '])
            ->test(GlobalSearchPage::class)
            ->assertSet('query', 'тест')
            ->assertSee('тест');
    }

    public function test_global_search_shows_rich_public_titles_and_portal_results(): void
    {
        $title = CatalogTitle::factory()->create([
            'title' => 'Северный детектив',
            'slug' => 'severnyi-detektiv',
            'poster_url' => 'https://images.example.com/north-detective.jpg',
            'year' => 2023,
        ]);
        $season = Season::factory()->for($title)->create();
        Episode::factory()->for($season)->create(['number' => 1]);
        Episode::factory()->for($season)->create(['number' => 2]);
        $genre = Genre::query()->create([
            'name' => 'Северный детектив',
            'slug' => 'severnyi-detektiv',
        ]);
        $title->genres()->attach($genre);
        CatalogTitle::factory()->create([
            'title' => 'Северный детектив скрытый',
            'publication_status' => PublicationStatus::Hidden,
        ]);

        $this->get('/search?q=Северный')
            ->assertOk()
            ->assertSeeText('Результаты поиска')
            ->assertSeeText('Северный детектив')
            ->assertSee('https://images.example.com/north-detective.jpg', false)
            ->assertSeeText('1 сезон')
            ->assertSeeText('2 серии')
            ->assertSee(route('titles.taxonomy', ['type' => 'genre', 'taxonomy' => $genre->slug]), false)
            ->assertDontSeeText('Северный детектив скрытый');
    }

    public function test_global_search_has_an_empty_prompt_and_header_uses_it_as_fallback(): void
    {
        $this->get('/search')
            ->assertOk()
            ->assertSeeText('Введите не менее двух символов')
            ->assertSee('action="'.route('search.index').'"', false);
    }

    public function test_home_structured_search_action_uses_the_global_search_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(route('search.index').'?q={search_term_string}', false);
    }
}
