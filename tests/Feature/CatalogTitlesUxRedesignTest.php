<?php

namespace Tests\Feature;

use App\Enums\CatalogView;
use App\Http\Requests\Api\V1\CatalogTitleIndexRequest;
use App\Http\Requests\CatalogTitlesRequest;
use App\Livewire\CatalogSeries;
use App\Models\CatalogTitle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogTitlesUxRedesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_request_normalizes_and_preserves_the_view_without_exposing_it_to_api(): void
    {
        $request = CatalogTitlesRequest::create('/titles', 'GET', [
            'view' => 'list',
            'genre' => ['drama'],
            'sort' => 'popularity_desc',
            'per_page' => 48,
        ]);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        $this->assertSame(CatalogView::List, $request->view());
        $this->assertSame('list', $request->catalogQueryState()['view']);

        $apiRequest = CatalogTitleIndexRequest::create('/api/v1/titles', 'GET', [
            'view' => 'list',
        ]);
        $apiRequest->setContainer(app())->setRedirector(app('redirect'));

        $this->assertArrayNotHasKey('view', $apiRequest->rules());
    }

    public function test_malformed_view_falls_back_to_grid_without_leaking_array_state(): void
    {
        $request = CatalogTitlesRequest::create('/titles', 'GET', [
            'view' => ['list'],
        ]);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        $this->assertSame(CatalogView::Grid, $request->view());
        $this->assertArrayNotHasKey('view', $request->catalogQueryState());
    }

    public function test_default_catalog_renders_requested_desktop_and_mobile_information_architecture(): void
    {
        CatalogTitle::factory()->create([
            'title' => 'Короткая карточка каталога',
            'slug' => 'korotkaya-kartochka-kataloga',
            'description' => 'Описание не должно загружаться и показываться в grid.',
        ]);

        $response = $this->get(route('titles.index'));

        $response
            ->assertOk()
            ->assertSee('data-catalog-page-header', false)
            ->assertSee('data-catalog-desktop-layout', false)
            ->assertSee('data-catalog-filter-sidebar', false)
            ->assertSee('data-catalog-mobile-filter-trigger', false)
            ->assertSee('data-catalog-mobile-filter-page', false)
            ->assertSee('data-catalog-output-controls', false)
            ->assertSee('data-catalog-primary-sorts', false)
            ->assertSee('data-catalog-secondary-sorts', false)
            ->assertSee('data-catalog-alphabet-menu', false)
            ->assertSee('data-catalog-active-filters', false)
            ->assertSee('data-catalog-view-option="grid"', false)
            ->assertSee('data-ui-poster-layout="grid"', false)
            ->assertDontSeeText('Описание не должно загружаться и показываться в grid.');
    }

    public function test_list_view_is_real_and_preserves_filter_sort_and_page_size_state(): void
    {
        CatalogTitle::factory()->create([
            'title' => 'Строчный каталог',
            'slug' => 'strochnyi-katalog',
            'description' => 'Описание доступно только в строчном виде.',
            'year' => 2022,
        ]);

        $response = $this->get(route('titles.index', [
            'view' => 'list',
            'year_from' => 2020,
            'sort' => 'year_desc',
            'per_page' => 48,
        ]));

        $response
            ->assertOk()
            ->assertSee('data-catalog-view-option="list"', false)
            ->assertSee('aria-current="true"', false)
            ->assertSee('data-ui-poster-layout="list"', false)
            ->assertSeeText('Описание доступно только в строчном виде.')
            ->assertSee('year_from=2020', false)
            ->assertSee('sort=year_desc', false)
            ->assertSee('per_page=48', false);
    }

    public function test_livewire_view_switch_resets_pagination_and_rejects_invalid_state(): void
    {
        CatalogTitle::factory()->count(30)->create();

        $component = Livewire::withQueryParams([
            'page' => 2,
        ])->test(CatalogSeries::class)
            ->call('setView', 'list')
            ->assertSet('filters.view', 'list')
            ->assertSet('paginators.page', 1)
            ->call('setView', ['grid'])
            ->assertHasErrors('view')
            ->assertSet('filters.view', 'list');

        $component
            ->call('setView', 'grid')
            ->assertHasNoErrors('view')
            ->assertSet('filters.view', 'grid');
    }

    public function test_sort_menu_keeps_all_existing_values_in_primary_or_secondary_groups(): void
    {
        $content = $this->get(route('titles.index', [
            'sort' => 'popularity_desc',
        ]))->assertOk()->getContent();

        foreach ([
            'popularity_desc',
            'updated',
            'year_desc',
            'imdb_desc',
            'relevance',
            'title_asc',
            'title_desc',
            'seasons_desc',
            'episodes_desc',
            'with_video',
            'year_asc',
            'kinopoisk_desc',
        ] as $sort) {
            $this->assertStringContainsString('data-catalog-sort-value="'.$sort.'"', $content);
        }

        $this->assertMatchesRegularExpression(
            '/data-catalog-sort-current[^>]*>.*По популярности/s',
            $content,
        );
    }

    public function test_active_filter_toolbar_exposes_condition_count_and_combined_range_chips(): void
    {
        $content = $this->get(route('titles.index', [
            'year_from' => 2020,
            'year_to' => 2026,
            'rating_source' => 'imdb',
            'rating_min' => 8,
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('data-active-filter-count="4"', $content);
        $this->assertStringContainsString('2020–2026', $content);
        $this->assertStringContainsString('IMDb от 8', $content);
        $this->assertStringContainsString('Сбросить всё', $content);
    }
}
