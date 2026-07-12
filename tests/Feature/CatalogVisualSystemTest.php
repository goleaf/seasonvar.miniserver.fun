<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Genre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class CatalogVisualSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_shell_has_accessible_landmarks_and_current_navigation(): void
    {
        $response = $this->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('href="#main-content"', false)
            ->assertSee('data-site-header', false)
            ->assertSee('aria-label="Поиск по всему каталогу"', false)
            ->assertSee('aria-label="Основная навигация"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('data-site-footer', false)
            ->assertSee('<main id="main-content"', false);
    }

    public function test_home_starts_with_search_hero_before_metrics(): void
    {
        $response = $this->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('data-home-hero', false)
            ->assertSee('aria-label="Поиск на главной"', false)
            ->assertSee('data-home-metrics', false)
            ->assertSeeInOrder(['data-home-hero', 'data-home-metrics'], false);
    }

    public function test_title_page_places_player_before_secondary_reference_metadata(): void
    {
        $title = CatalogTitle::factory()->create();
        $response = $this->get(route('titles.show', $title));

        $response
            ->assertOk()
            ->assertSee('data-title-hero', false)
            ->assertSeeInOrder(['data-title-hero', 'id="player"', 'data-title-reference'], false);
    }

    public function test_home_and_title_pages_each_render_only_the_layout_main_landmark(): void
    {
        $title = CatalogTitle::factory()->create();
        $homeResponse = $this->get(route('home'));
        $titleResponse = $this->get(route('titles.show', $title));

        $homeResponse->assertOk();
        $titleResponse->assertOk();

        $this->assertSame([
            'home' => 1,
            'title' => 1,
        ], [
            'home' => substr_count($homeResponse->getContent(), '<main'),
            'title' => substr_count($titleResponse->getContent(), '<main'),
        ], 'Catalog pages should rely on the single main landmark from the layout shell.');
    }

    public function test_title_surfaces_use_one_title_link_and_keep_relation_links_accessible(): void
    {
        $title = CatalogTitle::factory()->create();
        $genre = Genre::query()->create([
            'name' => 'Детектив',
            'slug' => 'detektiv',
        ]);

        $title->genres()->attach($genre);
        $title->load(['genres', 'countries', 'seasons']);

        $cardHtml = Blade::render('<x-title-card :title="$title" />', ['title' => $title]);
        $rowHtml = Blade::render('<x-title-list-row :title="$title" />', ['title' => $title]);
        $showUrl = route('titles.show', $title);
        $genreUrl = route('titles.taxonomy', ['type' => 'genre', 'taxonomy' => $genre->slug]);

        $this->assertSame([
            'card' => 1,
            'row' => 1,
        ], [
            'card' => substr_count($cardHtml, 'href="'.$showUrl.'"'),
            'row' => substr_count($rowHtml, 'href="'.$showUrl.'"'),
        ]);
        $this->assertStringContainsString('data-catalog-card', $cardHtml);
        $this->assertStringContainsString('catalog-card', $cardHtml);
        $this->assertStringContainsString('href="'.$genreUrl.'"', $cardHtml);
        $this->assertStringContainsString('href="'.$genreUrl.'"', $rowHtml);
    }

    public function test_catalog_pagination_is_russian_responsive_and_light_only(): void
    {
        CatalogTitle::factory()->count(30)->create();

        $response = $this->get(route('titles.index'));

        $response
            ->assertOk()
            ->assertSeeText('Показано 1–24 из 30')
            ->assertSeeText('Назад')
            ->assertSeeText('Вперед')
            ->assertSee('aria-current="page"', false)
            ->assertDontSeeText('pagination.previous')
            ->assertDontSeeText('pagination.next')
            ->assertDontSee('dark:', false);
    }

    public function test_catalog_exposes_livewire_controls_loading_feedback_and_stable_rows(): void
    {
        CatalogTitle::factory()->create();
        CatalogTitle::factory()->count(24)->create();

        $this->get(route('titles.index'))
            ->assertOk()
            ->assertSee('wire:model.live.debounce.650ms="filters.search"', false)
            ->assertSee('wire:submit="applyFilters"', false)
            ->assertSee('wire:loading.delay', false)
            ->assertDontSee('wire:loading.delay.flex', false)
            ->assertSee('wire:target="filters.search,applySearch,applyFilters,sortBy,setView,setPerPage,setLetter,resetGroup,resetAdvanced,clearSearch,resetAll,previousPage,nextPage,gotoPage"', false)
            ->assertSee('wire:loading', false)
            ->assertSee('wire:key="catalog-title-', false)
            ->assertSee('wire:click="nextPage(\'page\')"', false);
    }

    public function test_title_poster_uses_non_cropping_fit_by_default(): void
    {
        $title = CatalogTitle::factory()->make([
            'poster_url' => 'https://media.example.com/poster.jpg',
        ]);

        $html = Blade::render('<x-title-poster :title="$title" />', ['title' => $title]);

        $this->assertStringContainsString('object-contain', $html);
        $this->assertStringNotContainsString('object-cover', $html);
    }
}
