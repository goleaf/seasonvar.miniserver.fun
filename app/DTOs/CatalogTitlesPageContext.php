<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CatalogSort;
use App\Http\Requests\CatalogTitlesRequest;
use App\Models\CatalogTitle;
use App\Services\Catalog\CatalogTitlesCriteria;
use App\Services\Catalog\Search\CatalogSearchQuery;
use App\View\ViewModels\CatalogTitlesViewModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final readonly class CatalogTitlesPageContext
{
    /**
     * @param  list<int>  $years
     * @param  list<string>  $filterTypes
     * @param  Collection<string, Model>  $activeTaxonomies
     * @param  Collection<string, Collection<int, Model>>  $selectedTaxonomies
     * @param  Collection<string, Collection<int, Model>>  $excludedTaxonomies
     * @param  array<string, string>  $activeFilterSlugs
     * @param  array<string, string>  $invalidFilterSlugs
     * @param  array<string, mixed>  $catalogQueryState
     * @param  array<string, mixed>  $paginationQuery
     */
    public function __construct(
        public CatalogTitlesRequest $request,
        public string $search,
        public CatalogSearchQuery $searchQuery,
        public string $requestedYear,
        public ?int $year,
        public bool $invalidYear,
        public ?CatalogTitle $titleContext,
        public CatalogTitlesCriteria $criteria,
        public array $years,
        public CatalogSort $sortOption,
        public string $sort,
        public int $perPage,
        public array $filterTypes,
        public ?string $routeTaxonomyFilterType,
        public Collection $activeTaxonomies,
        public Collection $selectedTaxonomies,
        public Collection $excludedTaxonomies,
        public array $activeFilterSlugs,
        public array $invalidFilterSlugs,
        public array $catalogQueryState,
        public array $paginationQuery,
        public CatalogTitlesViewModel $filterView,
    ) {}
}
