<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogTopListFilters;
use App\Enums\CatalogTopListCategory;
use App\Models\User;
use Illuminate\Support\Number;

final class CatalogTopListPageBuilder
{
    public function __construct(
        private readonly CatalogTopListQuery $query,
        private readonly CatalogTopListSeoBuilder $seo,
        private readonly CatalogTopListFilterOptions $filterOptions,
    ) {}

    /** @return array<string, mixed> */
    public function data(
        CatalogTopListCategory $category,
        ?User $viewer,
        bool $localizedAlias,
        CatalogTopListFilters $filters,
    ): array {
        $items = $this->query->items($category, $viewer, $filters);
        $resetUrl = $this->categoryUrl($category, $localizedAlias);

        return [
            'category' => $category,
            'categoryLinks' => collect(CatalogTopListCategory::cases())
                ->map(fn (CatalogTopListCategory $item): array => [
                    'label' => $item->label(),
                    'description' => $item->description(),
                    'icon' => $item->icon(),
                    'url' => $this->categoryUrl($item, $localizedAlias, $filters->query()),
                    'active' => $item === $category,
                ]),
            'items' => $items,
            'podiumItems' => $items->take(3),
            'rankedItems' => $items->slice(3)->values(),
            'formattedCount' => (string) Number::format($items->count(), locale: app()->currentLocale()),
            'filterForm' => [
                'action' => $resetUrl,
                'resetUrl' => $resetUrl,
                'yearFrom' => $filters->yearFrom,
                'yearTo' => $filters->yearTo,
                'country' => $filters->country,
                'countries' => $this->filterOptions->countries(),
                'genre' => $filters->genre,
                'genres' => $this->filterOptions->genres(),
                'maximumYear' => (int) now()->format('Y') + 1,
                'active' => $filters->active(),
            ],
            'emptyState' => $filters->active()
                ? [
                    'title' => __('top_lists.empty_filtered_title'),
                    'description' => __('top_lists.empty_filtered_description'),
                    'action' => __('top_lists.empty_filtered_action'),
                    'url' => $resetUrl,
                    'icon' => 'fa-solid fa-filter-circle-xmark',
                ]
                : [
                    'title' => __('top_lists.empty_title'),
                    'description' => __('top_lists.empty_description'),
                    'action' => __('top_lists.empty_action'),
                    'url' => route('titles.index'),
                    'icon' => 'fa-solid fa-trophy',
                ],
            'seo' => $this->seo->build($category, $items, $localizedAlias, $filters),
        ];
    }

    /** @param array{year_from?: int, year_to?: int, country?: string, genre?: string} $query */
    private function categoryUrl(
        CatalogTopListCategory $category,
        bool $localizedAlias,
        array $query = [],
    ): string {
        if ($localizedAlias) {
            return route('localized.top.show', array_merge([
                'locale' => app()->currentLocale(),
                'category' => $category->value,
            ], $query));
        }

        return route('top.show', array_merge([
            'category' => $category->value,
        ], $query));
    }
}
