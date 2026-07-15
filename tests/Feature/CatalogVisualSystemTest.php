<?php

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\Genre;
use App\Models\Season;
use App\Models\User;
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
            ->assertSee('aria-label="Поиск по названию"', false)
            ->assertSee('placeholder="Название сериала"', false)
            ->assertSee('aria-label="Основная навигация"', false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('data-site-footer', false)
            ->assertSee('<main id="main-content"', false);
    }

    public function test_shell_navigation_receives_prepared_audience_and_permission_links(): void
    {
        config(['seasonvar.admin_emails' => ['admin@example.com']]);
        $viewer = User::factory()->create(['email' => 'viewer@example.com']);
        $admin = User::factory()->create(['email' => 'admin@example.com']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('href="'.route('register').'"', false)
            ->assertDontSee('href="'.route('library.index').'"', false)
            ->assertDontSee('href="'.route('admin.imports').'"', false);

        $this->actingAs($viewer)->get(route('home'))
            ->assertOk()
            ->assertSee('href="'.route('library.index').'"', false)
            ->assertSee('href="'.route('profile.security').'"', false)
            ->assertDontSee('href="'.route('login').'"', false)
            ->assertDontSee('href="'.route('admin.imports').'"', false);

        $this->actingAs($admin)->get(route('home'))
            ->assertOk()
            ->assertSee('href="'.route('admin.imports').'"', false);
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

    public function test_home_latest_updates_uses_one_grouped_list_without_a_duplicate_feed(): void
    {
        $latestWithoutPoster = CatalogTitle::factory()->create([
            'title' => 'Последний тайтл без постера',
            'poster_url' => null,
            'indexed_at' => now(),
        ]);
        $latestSeason = Season::factory()->create([
            'catalog_title_id' => $latestWithoutPoster->id,
            'number' => 2,
        ]);
        Episode::factory()->create([
            'season_id' => $latestSeason->id,
            'number' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $previousTitle = CatalogTitle::factory()->create([
            'title' => 'Предыдущий тайтл с постером',
            'poster_url' => 'https://media.example.com/previous.jpg',
            'indexed_at' => now()->subDay(),
        ]);
        $previousSeason = Season::factory()->create([
            'catalog_title_id' => $previousTitle->id,
            'number' => 1,
        ]);
        Episode::factory()->create([
            'season_id' => $previousSeason->id,
            'number' => 1,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $response = $this->get(route('home'));

        $response
            ->assertOk()
            ->assertSee('data-home-latest-updates-list', false)
            ->assertSee('data-ui-poster-layout="list"', false)
            ->assertSeeText($latestWithoutPoster->title)
            ->assertDontSee('data-home-latest-updates-grid', false)
            ->assertDontSeeText('Лента обновлений по датам');
    }

    public function test_catalog_heading_does_not_repeat_the_generated_collection_explanation(): void
    {
        CatalogTitle::factory()->count(2)->create();

        $this->get(route('titles.index'))
            ->assertOk()
            ->assertDontSeeText('сериалов в подборке')
            ->assertDontSeeText('Текстовый поиск проверяет только основное, оригинальное и альтернативные названия; остальные параметры задаются отдельными фильтрами.');
    }

    public function test_directory_results_use_one_divided_list_instead_of_card_columns(): void
    {
        $genre = Genre::query()->create([
            'name' => 'Исторический детектив',
            'slug' => 'istoricheskii-detektiv',
        ]);
        CatalogTitle::factory()->create()->genres()->attach($genre);

        $response = $this->get(route('genres.index'));

        $response
            ->assertOk()
            ->assertSee('data-directory-results-list', false)
            ->assertSee('divide-y divide-slate-200', false)
            ->assertSeeText('Исторический детектив')
            ->assertDontSee('sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-6', false)
            ->assertDontSee('min-h-28', false);
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
        $this->assertSame(4, substr_count($navigation[1], 'data-title-quick-link'));
        $this->assertSame(4, substr_count($navigation[1], 'min-h-11'));
        $this->assertStringContainsString('href="#reviews"', $navigation[1]);
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

    public function test_public_views_only_define_bounded_vertical_internal_scroll_containers(): void
    {
        $forbiddenClasses = [
            'overflow-auto',
            'overflow-scroll',
            'overflow-x-auto',
            'overflow-x-scroll',
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
                if (str_contains($content, $class)) {
                    $violations[] = $file->getRelativePathname().': '.$class;
                }
            }

            preg_match_all('/class="[^"]*overflow-y-auto[^"]*"/', $content, $verticalScrollContainers);

            foreach ($verticalScrollContainers[0] as $container) {
                if (! str_contains($container, 'max-h-[') && preg_match('/\bmax-h-\d+\b/', $container) !== 1) {
                    $violations[] = $file->getRelativePathname().': unbounded overflow-y-auto';
                }
            }
        }

        $this->assertSame([], $violations, 'Внутренняя вертикальная прокрутка допустима только в явно ограниченном по высоте блоке.');
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

        $cardHtml = Blade::render('<x-catalog.title-card :title="$title" layout="list" />', ['title' => $title]);
        $rowHtml = Blade::render('<x-catalog.title-card :title="$title" layout="compact" />', ['title' => $title]);
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
        $this->assertStringContainsString('data-pagination-control', $content);
        $this->assertStringContainsString('data-pagination-scroll-to="[data-catalog-results]"', $content);
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
        $this->assertStringContainsString('wire:target="filters.search,applySearch,applyFilters,sortBy,setPerPage,setLetter,resetGroup,resetAdvanced,resetAdvancedFilters,clearSearch,resetAll,previousPage,nextPage,gotoPage"', $content);
        $this->assertStringContainsString('wire:loading', $content);
        $this->assertStringContainsString('wire:key="catalog-title-', $content);
        $this->assertStringContainsString('wire:click.prevent="nextPage(\'page\')"', $content);
        $this->assertStringContainsString(
            'wire:loading.delay wire:target="filters.search,applySearch,applyFilters,sortBy,setPerPage,setLetter,resetGroup,resetAdvanced,resetAdvancedFilters,clearSearch,resetAll,previousPage,nextPage,gotoPage" class="hidden absolute',
            $content,
        );
    }

    public function test_catalog_search_ui_automatically_loads_unified_filter_island(): void
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
        $this->assertStringContainsString('aria-label="Поиск по названию"', $content);
        $this->assertStringContainsString('id="site-search"', $content);
        $this->assertStringContainsString('id="catalog-search"', $content);
        $this->assertStringNotContainsString('<dialog', $content);
        $this->assertSame(1, substr_count($content, 'id="catalog-filters"'));
        $this->assertStringContainsString('id="catalog-filters"', $content);
        $this->assertStringContainsString('data-catalog-unified-filters', $content);
        $this->assertStringNotContainsString('data-catalog-filter-dialog', $content);
        $this->assertStringNotContainsString('data-catalog-filter-dialog-open', $content);
        $this->assertStringNotContainsString('data-catalog-filter-dialog-close', $content);
        $this->assertStringNotContainsString('max-h-dvh', $content);
        $this->assertStringNotContainsString('overflow-y-auto', $content);
        $this->assertStringNotContainsString('lg:grid-cols-[260px_minmax(0,1fr)]', $content);
        $this->assertStringNotContainsString('data-catalog-mobile-view-controls', $content);
        $this->assertStringNotContainsString('data-catalog-view-option', $content);
        $this->assertStringContainsString('data-catalog-mobile-page-size-controls', $content);
        $this->assertStringNotContainsString('setView', $content);
        $this->assertStringContainsString('data-catalog-results-list', $content);
        $this->assertStringContainsString('wire:click.prevent="setPerPage(48)"', $content);
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
        $this->assertStringContainsString('data-catalog-filter-groups', $filterTemplate);
        $this->assertStringContainsString('columns-1', $filterTemplate);
        $this->assertStringContainsString('break-inside-avoid rounded-control border border-slate-200 bg-white p-3', $filterTemplate);
        $this->assertStringContainsString('<span>Годы</span>', $filterTemplate);
        $this->assertStringContainsString('<span>Тип публикации</span>', $filterTemplate);
        $this->assertStringContainsString('<span>Субтитры</span>', $filterTemplate);
        $this->assertStringContainsString('@foreach ($filterView->typeLabels as $filterType => $label)', $filterTemplate);
        $this->assertStringNotContainsString('<form', $filterTemplate);
        $this->assertStringContainsString('wire:model.live="filters.{{ $filterType }}"', $filterTemplate);
        $this->assertStringContainsString('wire:loading.delay', $filterTemplate);
        $this->assertStringNotContainsString('wire:loading.delay.flex', $filterTemplate);
        $this->assertDoesNotMatchRegularExpression('/wire:model\.live=.*@checked/m', $filterTemplate);
        $this->assertSame(4, substr_count($filterTemplate, 'wire:replace.self'));
        $this->assertStringContainsString('Обновляем подборку', $filterTemplate);

        $catalogTemplate = file_get_contents(resource_path('views/catalog/titles.blade.php'));

        $this->assertIsString($catalogTemplate);
        $this->assertSame(3, substr_count($catalogTemplate, "@island(name: 'catalog-live', with: \$this->catalogPage)"));

        $unifiedFilterTemplate = file_get_contents(resource_path('views/components/catalog/unified-title-filters.blade.php'));

        $this->assertIsString($unifiedFilterTemplate);
        $this->assertStringContainsString('wire:island="catalog-live"', $unifiedFilterTemplate);

        $deferredIslandPosition = strpos($catalogTemplate, "@island(name: 'catalog-live', defer: true)");

        $this->assertIsInt($deferredIslandPosition);

        $templateBeforeDeferredIsland = substr($catalogTemplate, 0, $deferredIslandPosition);

        $this->assertSame(
            substr_count($templateBeforeDeferredIsland, '@island('),
            substr_count($templateBeforeDeferredIsland, '@endisland'),
            'Отложенный island фасетов не должен быть вложен в другой island с тем же именем.',
        );
        $this->assertSame(4, substr_count($catalogTemplate, "@island(name: 'catalog-live'"));
    }

    public function test_catalog_unified_filters_open_for_any_active_filter(): void
    {
        $genre = Genre::query()->create([
            'name' => 'Драма',
            'slug' => 'drama',
        ]);
        $title = CatalogTitle::factory()->create();
        $title->genres()->attach($genre);

        $content = $this->get(route('titles.index', ['genre' => ['drama']]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<details[^>]*id="catalog-filters"[^>]*open/s', $content);
        $this->assertStringContainsString('data-catalog-filter-count', $content);
    }

    public function test_catalog_unified_filters_are_open_without_active_filters(): void
    {
        CatalogTitle::factory()->create();

        $content = $this->get(route('titles.index'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<details[^>]*id="catalog-filters"[^>]*open/s', $content);
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
        $this->assertStringContainsString('Уточните годы, тип, жанры, страны, актёров, рейтинг и доступность видео', $content);
        $this->assertStringContainsString('Показать результаты', $content);
        $this->assertStringContainsString('Сбросить фильтры', $content);
        $this->assertStringContainsString('wire:model.live="filters.updated"', $content);
        $this->assertStringContainsString('wire:model.live="filters.ratingSource"', $content);
        $this->assertStringContainsString('wire:model.live="filters.video"', $content);
        $this->assertStringContainsString('wire:model.live="filters.qualities"', $content);

        $template = file_get_contents(resource_path('views/components/catalog/unified-title-filters.blade.php'));

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

    public function test_unified_filter_form_does_not_duplicate_visible_query_keys(): void
    {
        CatalogTitle::factory()->create();

        $content = $this->get(route('titles.index', ['year_from' => 2020]))
            ->assertOk()
            ->getContent();

        $matched = preg_match('/<details[^>]*id="catalog-filters"[^>]*>.*?<\/details>/s', $content, $filters);

        $this->assertSame(1, $matched);
        $this->assertSame(1, substr_count($filters[0], 'name="year_from"'));
    }

    public function test_catalog_frontend_script_contract_keeps_people_keyboard_support_without_dialog_code(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString('AbortController', $script);
        $this->assertStringNotContainsString('showModal()', $script);
        $this->assertStringContainsString("case 'ArrowDown'", $script);
        $this->assertStringContainsString("case 'ArrowUp'", $script);
        $this->assertStringContainsString("case 'Enter'", $script);
        $this->assertStringContainsString("case 'Escape'", $script);
        $this->assertStringNotContainsString('returnFocus', $script);
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

    public function test_recommendation_poster_uses_an_uncropped_portrait_frame(): void
    {
        $html = Blade::render(
            '<x-ui.poster-card src="https://media.example.com/poster.jpg" alt="Постер" layout="recommendation">Описание</x-ui.poster-card>',
        );

        $this->assertStringContainsString('aspect-[2/3]', $html);
        $this->assertStringContainsString('object-contain object-center', $html);
        $this->assertStringNotContainsString('aspect-[16/10]', $html);
        $this->assertStringNotContainsString('object-cover', $html);
        $this->assertStringNotContainsString('scale-[1.02]', $html);
    }

    public function test_title_recommendations_use_one_divided_list_instead_of_columns(): void
    {
        $detail = File::get(resource_path('views/livewire/catalog-title-detail.blade.php'));

        $this->assertStringContainsString('data-recommendation-list', $detail);
        $this->assertStringContainsString('divide-y divide-slate-200', $detail);
        $this->assertStringNotContainsString('lg:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]', $detail);
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
