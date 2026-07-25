<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\CatalogCollection;
use App\Models\CatalogCollectionCategory;
use App\Services\Collections\CatalogCollectionCategoryQuery;

trait InteractsWithCatalogCollectionCategory
{
    public string $categoryRootPublicId = '';

    public string $categoryPublicId = '';

    public function updatedCategoryRootPublicId(): void
    {
        $this->categoryPublicId = '';
        $this->resetValidation(['categoryRootPublicId', 'categoryPublicId']);
    }

    protected function fillCategorySelection(CatalogCollection $collection): void
    {
        $collection->loadMissing([
            'category:id,public_id,parent_id,slug,position,is_active',
            'category.parent:id,public_id,parent_id,slug,position,is_active',
        ]);
        $category = $collection->category;

        if (! $category instanceof CatalogCollectionCategory) {
            $this->resetCategorySelection();

            return;
        }

        if ($category->parent instanceof CatalogCollectionCategory) {
            $this->categoryRootPublicId = $category->parent->public_id;
            $this->categoryPublicId = $category->public_id;

            return;
        }

        $this->categoryRootPublicId = $category->public_id;
        $this->categoryPublicId = '';
    }

    protected function selectedCategoryPublicId(): ?string
    {
        if ($this->categoryPublicId !== '') {
            return $this->categoryPublicId;
        }

        return $this->categoryRootPublicId !== '' ? $this->categoryRootPublicId : null;
    }

    protected function resetCategorySelection(): void
    {
        $this->categoryRootPublicId = '';
        $this->categoryPublicId = '';
    }

    /**
     * @return array{
     *     categoryRootOptions: list<array{value: string, label: string}>,
     *     categoryChildOptions: list<array{value: string, label: string}>,
     *     categoryAssignmentArchived: bool
     * }
     */
    protected function categorySelectionViewData(
        CatalogCollectionCategoryQuery $categories,
        ?CatalogCollectionCategory $current = null,
    ): array {
        $tree = $categories->activeTree($current?->id);
        $root = $tree->firstWhere('public_id', $this->categoryRootPublicId);

        return [
            'categoryRootOptions' => $tree
                ->map(fn (CatalogCollectionCategory $category): array => [
                    'value' => $category->public_id,
                    'label' => $category->display_name,
                ])
                ->values()
                ->all(),
            'categoryChildOptions' => $root instanceof CatalogCollectionCategory
                ? $root->children
                    ->map(fn (CatalogCollectionCategory $category): array => [
                        'value' => $category->public_id,
                        'label' => $category->display_name,
                    ])
                    ->values()
                    ->all()
                : [],
            'categoryAssignmentArchived' => $current instanceof CatalogCollectionCategory
                && (! $current->is_active
                    || ($current->parent instanceof CatalogCollectionCategory && ! $current->parent->is_active)),
        ];
    }
}
