<?php

namespace Tests\Unit;

use App\Models\Genre;
use App\View\ViewModels\CatalogTitlesViewModel;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class CatalogTitlesViewModelTest extends TestCase
{
    public function test_alphabet_groups_expose_individual_latin_letters_and_legacy_copy(): void
    {
        $viewModel = new CatalogTitlesViewModel(
            search: '',
            sort: 'updated',
            year: null,
            requestedYear: '',
            invalidYear: false,
            activeTaxonomies: collect(),
            selectedTaxonomies: collect(),
            activeFilterSlugs: [],
            invalidFilterSlugs: [],
            titleContext: null,
            catalogQueryState: ['letter' => 'latin'],
        );

        $this->assertSame(range('A', 'Z'), $viewModel->alphabetGroups['latin']);
        $this->assertSame('Латиница A–Z', $viewModel->advancedFilterChips()[0]['value']);
        $this->assertTrue($viewModel->isActiveLetter('latin'));
    }

    public function test_sort_labels_use_clear_russian_interface_copy(): void
    {
        $viewModel = new CatalogTitlesViewModel(
            search: '',
            sort: 'updated',
            year: null,
            requestedYear: '',
            invalidYear: false,
            activeTaxonomies: collect(),
            selectedTaxonomies: collect(),
            activeFilterSlugs: [],
            invalidFilterSlugs: [],
            titleContext: null,
        );

        $this->assertSame([
            'relevance' => 'По релевантности',
            'updated' => 'Недавно обновлённые',
            'year_desc' => 'Год: новые сначала',
            'year_asc' => 'Год: старые сначала',
            'episodes_desc' => 'Серий: больше сначала',
            'seasons_desc' => 'Сезонов: больше сначала',
            'with_video' => 'Видео: больше сначала',
            'title_asc' => 'Название: А–Я',
            'title_desc' => 'Название: Я–А',
            'kinopoisk_desc' => 'Рейтинг КиноПоиска',
            'imdb_desc' => 'Рейтинг IMDb',
            'popularity_desc' => 'По популярности',
        ], $viewModel->sortLabels);
    }

    public function test_catalog_output_controls_group_sorting_and_preserve_view_state(): void
    {
        $viewModel = new CatalogTitlesViewModel(
            search: '',
            sort: 'popularity_desc',
            year: null,
            requestedYear: '',
            invalidYear: false,
            activeTaxonomies: collect(),
            selectedTaxonomies: collect(),
            activeFilterSlugs: [],
            invalidFilterSlugs: [],
            titleContext: null,
            view: 'list',
            catalogQueryState: [
                'view' => 'list',
                'genre' => ['drama'],
                'per_page' => 48,
            ],
        );

        $this->assertSame(
            ['popularity_desc', 'updated', 'year_desc', 'imdb_desc'],
            array_keys($viewModel->primarySortOptions()),
        );
        $this->assertSame(
            ['relevance', 'title_asc', 'title_desc', 'seasons_desc', 'episodes_desc', 'with_video', 'year_asc', 'kinopoisk_desc'],
            array_keys($viewModel->secondarySortOptions()),
        );
        $this->assertSame('По популярности', $viewModel->currentSortLabel());
        $this->assertTrue($viewModel->isActiveView('list'));
        $this->assertSame([
            'genre' => ['drama'],
            'per_page' => 48,
            'sort' => 'popularity_desc',
        ], $viewModel->viewQuery('grid'));
        $this->assertSame([
            ['value' => 'grid', 'label' => 'Сетка', 'icon' => 'fa-solid fa-table-cells-large'],
            ['value' => 'list', 'label' => 'Список', 'icon' => 'fa-solid fa-list'],
        ], $viewModel->viewOptions());
    }

    public function test_primary_and_selected_filter_groups_are_expanded(): void
    {
        $actor = new Genre([
            'name' => 'Стивен Рут',
            'slug' => 'stephen-root',
        ]);
        $viewModel = new CatalogTitlesViewModel(
            search: '',
            sort: 'updated',
            year: null,
            requestedYear: '',
            invalidYear: false,
            activeTaxonomies: collect(),
            selectedTaxonomies: collect(['actor' => collect([$actor])]),
            activeFilterSlugs: [],
            invalidFilterSlugs: [],
            titleContext: null,
        );

        $this->assertTrue($viewModel->isPrimaryFilterType('genre'));
        $this->assertTrue($viewModel->isPrimaryFilterType('country'));
        $this->assertTrue($viewModel->isFilterGroupExpanded('actor'));
        $this->assertFalse($viewModel->isFilterGroupExpanded('studio'));
    }

    public function test_summary_filter_chips_combine_year_and_rating_pairs(): void
    {
        $viewModel = new CatalogTitlesViewModel(
            search: '',
            sort: 'updated',
            year: null,
            requestedYear: '',
            invalidYear: false,
            activeTaxonomies: collect(),
            selectedTaxonomies: collect(),
            activeFilterSlugs: [],
            invalidFilterSlugs: [],
            titleContext: null,
            catalogQueryState: [
                'year_from' => '2020',
                'year_to' => '2026',
                'rating_source' => 'imdb',
                'rating_min' => '8',
            ],
        );

        $this->assertSame([
            [
                'keys' => ['year_from', 'year_to'],
                'label' => '2020–2026',
                'icon' => 'fa-solid fa-calendar-days',
            ],
            [
                'keys' => ['rating_source', 'rating_min'],
                'label' => 'IMDb от 8',
                'icon' => 'fa-solid fa-star',
            ],
        ], $viewModel->summaryFilterChips());
    }

    public function test_search_and_filter_reset_queries_preserve_only_relevant_state(): void
    {
        $genre = new Genre([
            'name' => 'Драма',
            'slug' => 'drama',
        ]);
        $viewModel = new CatalogTitlesViewModel(
            search: 'Знахарь',
            sort: 'year_desc',
            year: 2019,
            requestedYear: '2019',
            invalidYear: false,
            activeTaxonomies: collect(['genre' => $genre]),
            selectedTaxonomies: collect(['genre' => collect([$genre])]),
            activeFilterSlugs: ['genre' => 'drama'],
            invalidFilterSlugs: [],
            titleContext: null,
            selectedFilterSlugs: ['genre' => ['drama']],
        );

        $this->assertSame([
            'genre' => ['drama'],
            'sort' => 'year_desc',
            'year' => 2019,
        ], $viewModel->withoutSearchQuery);
        $this->assertSame([
            'q' => 'Знахарь',
            'sort' => 'year_desc',
        ], $viewModel->withoutFiltersQuery);
    }

    public function test_active_filter_count_includes_relation_fixed_list_and_scalar_groups(): void
    {
        $genre = new Genre([
            'name' => 'Драма',
            'slug' => 'drama',
        ]);
        $viewModel = new CatalogTitlesViewModel(
            search: '',
            sort: 'updated',
            year: null,
            requestedYear: '',
            invalidYear: false,
            activeTaxonomies: collect(['genre' => $genre]),
            selectedTaxonomies: collect(['genre' => collect([$genre])]),
            activeFilterSlugs: ['genre' => 'drama'],
            invalidFilterSlugs: [],
            titleContext: null,
            selectedFilterSlugs: ['genre' => ['drama']],
            catalogQueryState: [
                'genre' => ['drama'],
                'year' => ['2024', '2025'],
                'publication_type' => ['serial', 'anime'],
                'subtitles' => ['available'],
                'quality' => ['1080p', '720p'],
                'video' => 'available',
            ],
        );

        $this->assertSame(9, $viewModel->activeFilterCount());
    }

    public function test_advanced_filter_count_and_reset_query_cover_only_exact_selection_state(): void
    {
        $viewModel = new CatalogTitlesViewModel(
            search: 'Мамочка',
            sort: 'year_desc',
            year: null,
            requestedYear: '',
            invalidYear: false,
            activeTaxonomies: collect(),
            selectedTaxonomies: collect(),
            activeFilterSlugs: [],
            invalidFilterSlugs: [],
            titleContext: null,
            catalogQueryState: [
                'q' => 'Мамочка',
                'genre' => ['comedy'],
                'year_from' => '2010',
                'rating_min' => '7.5',
                'quality' => ['1080p', '720p'],
                'letter' => 'М',
                'sort' => 'year_desc',
            ],
        );

        $this->assertSame(4, $viewModel->advancedFilterCount());
        $this->assertTrue($viewModel->hasAdvancedFilters());
        $this->assertSame([
            'q' => 'Мамочка',
            'genre' => ['comedy'],
            'letter' => 'М',
            'sort' => 'year_desc',
        ], $viewModel->advancedFiltersResetQuery());
    }

    public function test_maximum_catalog_year_is_prepared_outside_blade(): void
    {
        Date::setTestNow('2026-07-13 12:00:00');

        try {
            $viewModel = new CatalogTitlesViewModel(
                search: '',
                sort: 'updated',
                year: null,
                requestedYear: '',
                invalidYear: false,
                activeTaxonomies: collect(),
                selectedTaxonomies: collect(),
                activeFilterSlugs: [],
                invalidFilterSlugs: [],
                titleContext: null,
            );

            $this->assertSame(2027, $viewModel->maximumCatalogYear());
        } finally {
            Date::setTestNow();
        }
    }
}
