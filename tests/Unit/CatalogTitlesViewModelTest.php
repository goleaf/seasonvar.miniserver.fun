<?php

namespace Tests\Unit;

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
            activeFilterSlugs: [],
            invalidFilterSlugs: [],
            titleContext: null,
        );

        $this->assertSame([
            'updated' => 'Недавно обновленные',
            'with_video' => 'Видео: больше сначала',
            'episodes_desc' => 'Серий: больше сначала',
            'year_desc' => 'Год: новые сначала',
            'year_asc' => 'Год: старые сначала',
            'title_asc' => 'Название: А-я',
        ], $viewModel->sortLabels);
    }
}
