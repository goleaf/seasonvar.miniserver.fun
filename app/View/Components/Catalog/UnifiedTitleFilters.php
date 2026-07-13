<?php

declare(strict_types=1);

namespace App\View\Components\Catalog;

use App\View\ViewModels\CatalogTitlesViewModel;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

final class UnifiedTitleFilters extends Component
{
    /** @var array<string, mixed> */
    public readonly array $facetData;

    public readonly CatalogTitlesViewModel $filterView;

    public readonly bool $facetsLoaded;

    /**
     * @param  array<string, mixed>  $data
     * @param  array{actor?: string, director?: string}  $optionSearch
     */
    public function __construct(
        array $data = [],
        public readonly array $optionSearch = [],
        ?CatalogTitlesViewModel $filterView = null,
        bool $loading = false,
    ) {
        $resolvedFilterView = $filterView ?? ($data['filterView'] ?? null);

        if (! $resolvedFilterView instanceof CatalogTitlesViewModel) {
            throw new \InvalidArgumentException('Unified catalog filters require a catalog filter view model.');
        }

        $this->facetData = $data;
        $this->filterView = $resolvedFilterView;
        $this->facetsLoaded = ! $loading && $data !== [];
    }

    public function render(): View
    {
        return view('components.catalog.unified-title-filters');
    }
}
