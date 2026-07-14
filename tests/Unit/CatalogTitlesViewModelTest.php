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
            'updated' => 'Недавно обновленные',
            'year_desc' => 'Год: новые сначала',
            'year_asc' => 'Год: старые сначала',
            'episodes_desc' => 'Серий: больше сначала',
            'seasons_desc' => 'Сезонов: больше сначала',
            'with_video' => 'Видео: больше сначала',
            'title_asc' => 'Название: А-я',
            'title_desc' => 'Название: Я-а',
            'kinopoisk_desc' => 'Рейтинг КиноПоиска',
            'imdb_desc' => 'Рейтинг IMDb',
            'popularity_desc' => 'По популярности',
        ], $viewModel->sortLabels);
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
