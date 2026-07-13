<?php

namespace Tests\Unit;

use App\Models\Genre;
use App\View\ViewModels\CatalogTitlesViewModel;
use Tests\TestCase;

class CatalogTitlesViewModelTest extends TestCase
{
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
}
