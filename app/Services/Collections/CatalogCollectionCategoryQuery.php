<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class CatalogCollectionCategoryQuery
{
    /** @return Collection<int, CatalogCollectionCategory> */
    public function activeTree(?int $includeCategoryId = null): Collection
    {
        return CatalogCollectionCategory::query()
            ->select(['id', 'public_id', 'parent_id', 'slug', 'position', 'is_active'])
            ->whereNull('parent_id')
            ->where(function (Builder $query) use ($includeCategoryId): void {
                $query->where('is_active', true);

                if ($includeCategoryId !== null) {
                    $query
                        ->orWhere('catalog_collection_categories.id', $includeCategoryId)
                        ->orWhereHas('children', fn (Builder $children): Builder => $children
                            ->whereKey($includeCategoryId));
                }
            })
            ->with([
                'translations' => fn (HasMany $query): HasMany => $query
                    ->select(['id', 'catalog_collection_category_id', 'locale', 'name'])
                    ->whereIn('locale', $this->locales()),
                'children' => function (HasMany $query) use ($includeCategoryId): void {
                    $query
                        ->select(['id', 'public_id', 'parent_id', 'slug', 'position', 'is_active'])
                        ->where(function (Builder $query) use ($includeCategoryId): void {
                            $query->where('is_active', true);

                            if ($includeCategoryId !== null) {
                                $query->orWhere('catalog_collection_categories.id', $includeCategoryId);
                            }
                        })
                        ->with([
                            'translations' => fn (HasMany $query): HasMany => $query
                                ->select(['id', 'catalog_collection_category_id', 'locale', 'name'])
                                ->whereIn('locale', $this->locales()),
                        ]);
                },
            ])
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, CatalogCollectionCategory> */
    public function administrationTree(): Collection
    {
        return CatalogCollectionCategory::query()
            ->select(['id', 'public_id', 'parent_id', 'slug', 'position', 'is_active'])
            ->whereNull('parent_id')
            ->withCount('collections')
            ->with([
                'translations' => fn (HasMany $query): HasMany => $query
                    ->select(['id', 'catalog_collection_category_id', 'locale', 'name'])
                    ->whereIn('locale', $this->locales()),
                'children' => fn (HasMany $query): HasMany => $query
                    ->select(['id', 'public_id', 'parent_id', 'slug', 'position', 'is_active'])
                    ->withCount('collections')
                    ->with([
                        'translations' => fn (HasMany $query): HasMany => $query
                            ->select(['id', 'catalog_collection_category_id', 'locale', 'name'])
                            ->whereIn('locale', $this->locales()),
                    ]),
            ])
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{
     *     tree: Collection<int, CatalogCollectionCategory>,
     *     uncategorized: int,
     *     total: int
     * }
     */
    public function publicDirectoryTree(): array
    {
        $tree = $this->activeTree();
        $counts = [];
        $uncategorized = 0;
        $total = 0;
        $rows = CatalogCollection::query()
            ->publiclyListed()
            ->toBase()
            ->select('catalog_collection_category_id')
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('catalog_collection_category_id')
            ->get();

        foreach ($rows as $row) {
            $count = max(0, (int) $row->aggregate);
            $total += $count;
            $categoryId = $row->catalog_collection_category_id;

            if ($categoryId === null) {
                $uncategorized = $count;

                continue;
            }

            $counts[(int) $categoryId] = $count;
        }

        foreach ($tree as $root) {
            $branchCount = $counts[$root->id] ?? 0;
            $root->setAttribute('public_collections_count', $branchCount);

            foreach ($root->children as $child) {
                $childCount = $counts[$child->id] ?? 0;
                $child->setAttribute('public_collections_count', $childCount);
                $branchCount += $childCount;
            }

            $root->setAttribute('public_branch_collections_count', $branchCount);
        }

        return compact('tree', 'uncategorized', 'total');
    }

    /** @return array{0: string, 1: string} */
    public function normalizeDirectorySelection(?string $category, ?string $subcategory): array
    {
        $category = is_string($category) ? Str::lower(trim($category)) : '';
        $subcategory = is_string($subcategory) ? Str::lower(trim($subcategory)) : '';

        if ($category === 'uncategorized') {
            return ['uncategorized', ''];
        }

        if (! $this->validSlug($category)) {
            return ['', ''];
        }

        $tree = $this->activeTree();
        $root = $tree->firstWhere('slug', $category);

        if (! $root instanceof CatalogCollectionCategory) {
            return ['', ''];
        }

        if ($subcategory === '') {
            return [$category, ''];
        }

        return $root->children->contains('slug', $subcategory)
            ? [$category, $subcategory]
            : [$category, ''];
    }

    /** @return list<string> */
    private function locales(): array
    {
        return array_values(array_unique([
            app()->currentLocale(),
            (string) config('catalog-collections.default_locale', 'ru'),
        ]));
    }

    private function validSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,119}$/D', $slug) === 1;
    }
}
