<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\CatalogTopListCategory;
use App\Models\User;
use Illuminate\Support\Number;

final class CatalogTopListPageBuilder
{
    public function __construct(
        private readonly CatalogTopListQuery $query,
        private readonly CatalogTopListSeoBuilder $seo,
    ) {}

    /** @return array<string, mixed> */
    public function data(
        CatalogTopListCategory $category,
        ?User $viewer,
        bool $localizedAlias,
    ): array {
        $items = $this->query->items($category, $viewer);

        return [
            'category' => $category,
            'categoryLinks' => collect(CatalogTopListCategory::cases())
                ->map(fn (CatalogTopListCategory $item): array => [
                    'label' => $item->label(),
                    'description' => $item->description(),
                    'icon' => $item->icon(),
                    'url' => $this->categoryUrl($item, $localizedAlias),
                    'active' => $item === $category,
                ]),
            'items' => $items,
            'podiumItems' => $items->take(3),
            'rankedItems' => $items->slice(3)->values(),
            'formattedCount' => (string) Number::format($items->count(), locale: app()->currentLocale()),
            'seo' => $this->seo->build($category, $items, $localizedAlias),
        ];
    }

    private function categoryUrl(CatalogTopListCategory $category, bool $localizedAlias): string
    {
        if ($localizedAlias) {
            return route('localized.top.show', [
                'locale' => app()->currentLocale(),
                'category' => $category->value,
            ]);
        }

        return route('top.show', ['category' => $category->value]);
    }
}
