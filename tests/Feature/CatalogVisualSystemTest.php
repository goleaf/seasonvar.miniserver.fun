<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Genre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
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

    public function test_site_footer_has_responsive_brand_navigation_and_service_regions(): void
    {
        $response = $this->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('data-site-footer-brand', false)
            ->assertSee('aria-labelledby="footer-catalog-navigation"', false)
            ->assertSee('id="footer-catalog-navigation"', false)
            ->assertSee('aria-labelledby="footer-service-navigation"', false)
            ->assertSee('id="footer-service-navigation"', false)
            ->assertSee('data-site-footer-bottom', false)
            ->assertSeeText('Навигация')
            ->assertSeeText('Служебные страницы')
            ->assertSeeText('К началу страницы')
            ->assertSee('href="'.route('titles.index').'"', false)
            ->assertSee('href="'.route('stats').'"', false)
            ->assertSee('href="'.route('sitemap').'"', false)
            ->assertSee('href="'.route('feed').'"', false)
            ->assertDontSee('aria-label="Техническая навигация"', false);
    }

    public function test_home_starts_with_metrics_without_hero(): void
    {
        $response = $this->get(route('home'));

        $response
            ->assertOk()
            ->assertDontSee('data-home-hero', false)
            ->assertDontSee('aria-label="Поиск на главной"', false)
            ->assertSee('<h1 class="sr-only">Сериалы онлайн</h1>', false)
            ->assertSee('data-home-metrics', false);
    }

    public function test_home_latest_updates_uses_a_five_column_natural_height_responsive_grid(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();

        $matched = preg_match(
            '/<div data-home-latest-updates-grid class="([^"]+)"/',
            $response->getContent(),
            $matches,
        );

        $this->assertSame(1, $matched);

        $classes = explode(' ', $matches[1]);

        foreach ([
            'items-start',
            'sm:grid-cols-2',
            'md:grid-cols-3',
            'lg:grid-cols-4',
            'xl:grid-cols-5',
            '[&>[data-catalog-card]]:h-auto',
        ] as $class) {
            $this->assertContains($class, $classes);
        }

        $this->assertNotContains('auto-rows-fr', $classes);
    }

    public function test_catalog_heading_does_not_repeat_the_generated_collection_explanation(): void
    {
        CatalogTitle::factory()->count(2)->create();

        $this->get(route('titles.index'))
            ->assertOk()
            ->assertDontSeeText('сериалов в подборке')
            ->assertDontSeeText('Выдача учитывает названия, оригинальные названия, алиасы, описания, жанры, страны, актеров и режиссеров.');
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

    public function test_title_page_does_not_render_a_standalone_catalog_relations_panel(): void
    {
        $title = CatalogTitle::factory()->create();
        $genre = Genre::query()->create([
            'name' => 'Детектив',
            'slug' => 'detektiv',
        ]);
        $title->genres()->attach($genre);

        $response = $this->get(route('titles.show', $title));

        $response
            ->assertOk()
            ->assertSee('data-title-reference', false)
            ->assertSeeText('Детектив')
            ->assertDontSeeText('Связи каталога')
            ->assertDontSeeText('Связи не указаны.')
            ->assertDontSee('fa-diagram-project', false);
    }

    public function test_title_quick_navigation_is_flat_and_keeps_large_targets(): void
    {
        $title = CatalogTitle::factory()->create();
        $content = $this->get(route('titles.show', $title))->assertOk()->getContent();

        $matched = preg_match('/<nav aria-label="Быстрые переходы по сериалу"[^>]*>(.*?)<\/nav>/s', $content, $navigation);

        $this->assertSame(1, $matched);
        $this->assertSame(3, substr_count($navigation[1], 'data-title-quick-link'));
        $this->assertSame(3, substr_count($navigation[1], 'min-h-11'));
        $this->assertStringNotContainsString('bg-emerald-700', $navigation[1]);
        $this->assertDoesNotMatchRegularExpression('/\b(?:border|ring|outline)(?:-|\s)/', $navigation[1]);
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

    public function test_public_views_do_not_define_internal_scroll_containers(): void
    {
        $allowedClasses = [
            'catalog/titles.blade.php' => ['overflow-y-auto'],
        ];

        $forbiddenClasses = [
            'overflow-auto',
            'overflow-scroll',
            'overflow-x-auto',
            'overflow-x-scroll',
            'overflow-y-auto',
            'overflow-y-scroll',
            'overscroll-x-contain',
            'overscroll-y-contain',
        ];

        $violations = [];

        foreach (File::allFiles(resource_path('views')) as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            if (! is_string($content)) {
                continue;
            }

            $relativePath = str_replace('\\', '/', $file->getRelativePathname());

            foreach ($forbiddenClasses as $class) {
                if (str_contains($content, $class)
                    && ! in_array($class, $allowedClasses[$relativePath] ?? [], true)) {
                    $violations[] = $file->getRelativePathname().': '.$class;
                }
            }
        }

        $this->assertSame([], $violations, 'Публичный интерфейс не должен создавать прокрутку внутри блоков, кроме явно разрешённого viewport-sized native dialog.');
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

        $cardHtml = Blade::render('<x-catalog.title-card :title="$title" layout="grid" />', ['title' => $title]);
        $rowHtml = Blade::render('<x-catalog.title-card :title="$title" layout="horizontal" />', ['title' => $title]);
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
        $content = $response->assertOk()->getContent();

        $response
            ->assertSeeText('Показано 1–24 из 30')
            ->assertSeeText('Назад')
            ->assertSeeText('Вперед')
            ->assertSee('aria-current="page"', false)
            ->assertDontSeeText('pagination.previous')
            ->assertDontSeeText('pagination.next')
            ->assertDontSee('dark:', false);

        $this->assertStringContainsString('data-catalog-results', $content);
        $this->assertStringContainsString('data-catalog-pagination', $content);
        $this->assertStringContainsString('data-catalog-pagination-control', $content);
        $this->assertDoesNotMatchRegularExpression('/<div[^>]*uppercase[^>]*>Найдено<\/div>/', $content);
    }

    public function test_catalog_exposes_livewire_controls_loading_feedback_and_stable_rows(): void
    {
        CatalogTitle::factory()->create();
        CatalogTitle::factory()->count(24)->create();

        $content = $this->get(route('titles.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('wire:model="filters.search"', $content);
        $this->assertStringNotContainsString('wire:model.live.debounce.650ms="filters.search"', $content);
        $this->assertStringContainsString('wire:submit="applySearch"', $content);
        $this->assertStringContainsString('wire:submit="applyFilters"', $content);
        $this->assertStringContainsString('wire:loading.delay', $content);
        $this->assertStringNotContainsString('wire:loading.delay.flex', $content);
        $this->assertStringContainsString('wire:target="filters.search,applySearch,applyFilters,sortBy,setView,setPerPage,setLetter,resetGroup,resetAdvanced,resetAdvancedFilters,clearSearch,resetAll,previousPage,nextPage,gotoPage"', $content);
        $this->assertStringContainsString('wire:loading', $content);
        $this->assertStringContainsString('wire:key="catalog-title-', $content);
        $this->assertStringContainsString('wire:click="nextPage(\'page\')"', $content);
        $this->assertStringContainsString(
            'wire:loading.delay wire:target="filters.search,applySearch,applyFilters,sortBy,setView,setPerPage,setLetter,resetGroup,resetAdvanced,resetAdvancedFilters,clearSearch,resetAll,previousPage,nextPage,gotoPage" class="hidden absolute',
            $content,
        );
    }

    public function test_catalog_search_ui_automatically_loads_linked_filter_island(): void
    {
        $title = CatalogTitle::factory()->create();

        foreach (range(1, 9) as $number) {
            $genre = Genre::query()->create([
                'name' => 'Жанр '.$number,
                'slug' => 'genre-'.$number,
            ]);

            $title->genres()->attach($genre);
        }

        $content = $this->get(route('titles.index'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($content, 'role="search"'));
        $this->assertStringContainsString('aria-label="Поиск по каталогу"', $content);
        $this->assertStringContainsString('aria-label="Поиск по всему каталогу"', $content);
        $this->assertStringContainsString('id="site-search"', $content);
        $this->assertStringContainsString('id="catalog-search"', $content);
        $this->assertStringContainsString('<dialog', $content);
        $this->assertStringContainsString('id="catalog-filters"', $content);
        $this->assertStringContainsString('data-catalog-filter-dialog', $content);
        $this->assertStringContainsString('data-catalog-filter-dialog-open', $content);
        $this->assertStringContainsString('data-catalog-filter-dialog-close', $content);
        $this->assertStringContainsString('max-h-dvh', $content);
        $this->assertStringContainsString('overflow-y-auto', $content);
        $this->assertStringContainsString('data-catalog-mobile-view-controls', $content);
        $this->assertStringContainsString('data-catalog-mobile-page-size-controls', $content);
        $this->assertStringContainsString('wire:click.prevent="setView(\'grid\')"', $content);
        $this->assertStringContainsString('wire:click.prevent="setPerPage(48)"', $content);
        $this->assertStringContainsString('Фильтры · 0', $content);
        $this->assertStringContainsString('wire:init="__lazyLoadIsland"', $content);
        $this->assertStringContainsString('name=catalog-live', $content);
        $this->assertStringContainsString('data-catalog-facets-loading', $content);
        $this->assertStringContainsString('Загружаем фильтры', $content);
        $this->assertStringNotContainsString('data-catalog-facets-placeholder', $content);
        $this->assertStringNotContainsString('wire:click="loadFacets"', $content);
        $this->assertStringNotContainsString('Показать фильтры', $content);
        $this->assertStringNotContainsString('Найти в группе', $content);
        $this->assertStringNotContainsString('data-catalog-filter-search', $content);
        $this->assertSame(0, substr_count($content, 'data-catalog-people-combobox'));
        $this->assertSame(0, substr_count($content, 'role="combobox"'));
        $this->assertSame(0, substr_count($content, 'role="listbox"'));
        $this->assertStringContainsString('aria-current="true"', $content);
        $this->assertStringNotContainsString('Применить выбранное', $content);
        $this->assertLessThan(250_000, strlen($content));

        $filterTemplate = file_get_contents(resource_path('views/components/catalog/title-filters.blade.php'));

        $this->assertIsString($filterTemplate);
        $this->assertStringContainsString('wire:model.live="filters.{{ $filterType }}"', $filterTemplate);
        $this->assertStringContainsString('wire:loading.delay', $filterTemplate);
        $this->assertStringNotContainsString('wire:loading.delay.flex', $filterTemplate);
        $this->assertDoesNotMatchRegularExpression('/wire:model\.live=.*@checked/m', $filterTemplate);
        $this->assertSame(4, substr_count($filterTemplate, 'wire:replace.self'));
        $this->assertStringContainsString('Обновляем подборку', $filterTemplate);
    }

    public function test_advanced_catalog_filters_use_four_compact_explanatory_groups(): void
    {
        CatalogTitle::factory()->create();
        $content = $this->get(route('titles.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-catalog-advanced-filters', $content);
        $this->assertSame(4, substr_count($content, 'data-catalog-advanced-group='));
        $this->assertStringContainsString('data-catalog-advanced-group="period"', $content);
        $this->assertStringContainsString('data-catalog-advanced-group="volume"', $content);
        $this->assertStringContainsString('data-catalog-advanced-group="rating"', $content);
        $this->assertStringContainsString('data-catalog-advanced-group="video"', $content);
        $this->assertStringContainsString('Точный подбор', $content);
        $this->assertStringContainsString('Уточните период, объём сериала, рейтинг и доступность видео', $content);
        $this->assertStringContainsString('Показать результаты', $content);
        $this->assertStringContainsString('Сбросить точный подбор', $content);
        $this->assertStringContainsString('wire:model.live="filters.updated"', $content);
        $this->assertStringContainsString('wire:model.live="filters.ratingSource"', $content);
        $this->assertStringContainsString('wire:model.live="filters.video"', $content);
        $this->assertStringContainsString('wire:model.live="filters.qualities"', $content);

        $template = file_get_contents(resource_path('views/catalog/titles.blade.php'));

        $this->assertIsString($template);
        $this->assertDoesNotMatchRegularExpression('/wire:model\.live=.*@checked/m', $template);
        $this->assertSame(1, substr_count($template, 'wire:replace.self'));

        foreach (['year_from', 'year_to', 'seasons_min', 'seasons_max', 'episodes_min', 'episodes_max', 'rating_min', 'votes_min'] as $name) {
            $this->assertMatchesRegularExpression('/name="'.preg_quote($name, '/').'"[^>]*class="[^"]*w-full[^"]*sm:w-/s', $content);
        }
    }

    public function test_advanced_filter_get_fallback_preserves_the_active_letter(): void
    {
        CatalogTitle::factory()->create();

        $content = $this->get(route('titles.index', ['letter' => 'М']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/data-catalog-advanced-filters.*<input type="hidden" name="letter" value="М">/s',
            $content,
        );
    }

    public function test_catalog_frontend_script_contract_cancels_stale_people_requests_and_restores_dialog_focus(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString('AbortController', $script);
        $this->assertStringContainsString('showModal()', $script);
        $this->assertStringContainsString("case 'ArrowDown'", $script);
        $this->assertStringContainsString("case 'ArrowUp'", $script);
        $this->assertStringContainsString("case 'Enter'", $script);
        $this->assertStringContainsString("case 'Escape'", $script);
        $this->assertStringContainsString('returnFocus?.focus()', $script);
    }

    public function test_catalog_sort_and_alphabet_links_keep_touch_sized_flat_controls(): void
    {
        CatalogTitle::factory()->create();
        $content = $this->get(route('titles.index'))->assertOk()->getContent();

        preg_match_all('/<a[^>]*data-catalog-(?:sort|alphabet)-option[^>]*class="([^"]+)"[^>]*>/s', $content, $controls);

        $this->assertNotEmpty($controls[1]);

        foreach ($controls[1] as $classes) {
            $this->assertStringContainsString('min-h-11', $classes);
            $this->assertDoesNotMatchRegularExpression('/\b(?:border|ring|outline)(?:-|\s)/', $classes);
        }

        $this->assertStringNotContainsString('min-h-9 min-w-9', $content);
    }

    public function test_title_poster_fills_its_frame_without_an_inner_ring(): void
    {
        $title = CatalogTitle::factory()->make([
            'poster_url' => 'https://media.example.com/poster.jpg',
        ]);

        $html = Blade::render(
            '<x-ui.poster-frame :src="$title->poster_url" :alt="\'Постер \'.$title->display_title" class="aspect-[2/3]" />',
            ['title' => $title],
        );

        $this->assertStringContainsString('data-ui-poster-frame', $html);
        $this->assertStringContainsString('data-ui-poster-image', $html);
        $this->assertStringContainsString('object-cover', $html);
        $this->assertStringContainsString('scale-[1.02]', $html);
        $this->assertStringNotContainsString('object-contain', $html);
        $this->assertStringNotContainsString('ring-1 ring-slate-200', $html);
    }

    public function test_title_recommendation_columns_do_not_stretch_the_featured_card(): void
    {
        $detail = File::get(resource_path('views/livewire/catalog-title-detail.blade.php'));

        $this->assertStringContainsString(
            'grid items-start gap-3 lg:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]',
            $detail,
        );
    }

    public function test_title_player_keeps_personal_controls_below_the_full_width_player(): void
    {
        $view = file_get_contents(resource_path('views/livewire/catalog-title-player.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('data-player-primary', $view);
        $this->assertStringContainsString('data-player-personal', $view);
        $this->assertStringContainsString('sm:grid-cols-2', $view);
        $this->assertStringNotContainsString('lg:grid-cols-[minmax(0,1fr)_minmax(240px,0.45fr)]', $view);
        $this->assertLessThan(
            strpos($view, 'data-player-personal'),
            strpos($view, 'data-player-primary'),
        );
    }
}
