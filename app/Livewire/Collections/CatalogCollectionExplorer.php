<?php

declare(strict_types=1);

namespace App\Livewire\Collections;

use App\Livewire\Concerns\InteractsWithPaginationIslands;
use App\Models\CatalogCollectionCategory;
use App\Services\Collections\CatalogCollectionCategoryQuery;
use App\Services\Collections\CatalogCollectionQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class CatalogCollectionExplorer extends Component
{
    use InteractsWithPaginationIslands;
    use WithPagination;

    #[Url(as: 'collections_q', history: true, except: '')]
    public string $search = '';

    #[Url(as: 'collections_sort', history: true, except: 'featured')]
    public string $sort = 'featured';

    #[Url(as: 'collections_category', history: true, except: '')]
    public string $category = '';

    #[Url(as: 'collections_subcategory', history: true, except: '')]
    public string $subcategory = '';

    public function mount(CatalogCollectionCategoryQuery $categories): void
    {
        $this->normalize($categories);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'sort'], true)) {
            $this->normalize();
            $this->resetPage(pageName: 'collectionsPage');
        }
    }

    public function updatedCategory(CatalogCollectionCategoryQuery $categories): void
    {
        $this->subcategory = '';
        $this->normalize($categories);
        $this->resetPage(pageName: 'collectionsPage');
    }

    public function updatedSubcategory(CatalogCollectionCategoryQuery $categories): void
    {
        $this->normalize($categories);
        $this->resetPage(pageName: 'collectionsPage');
    }

    public function applySearch(): void
    {
        $this->normalize();
        $this->resetPage(pageName: 'collectionsPage');
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage(pageName: 'collectionsPage');
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->sort = 'featured';
        $this->category = '';
        $this->subcategory = '';
        $this->resetPage(pageName: 'collectionsPage');
    }

    public function selectCategory(
        CatalogCollectionCategoryQuery $categories,
        string $category,
        string $subcategory = '',
    ): void {
        [$this->category, $this->subcategory] = $categories->normalizeDirectorySelection(
            $category,
            $subcategory,
        );
        $this->resetPage(pageName: 'collectionsPage');
    }

    public function render(
        CatalogCollectionQuery $collections,
        CatalogCollectionCategoryQuery $categories,
    ): View {
        $authenticated = Auth::check();
        $directory = $categories->publicDirectoryTree();
        $categoryNavigation = $directory['tree']
            ->map(function (CatalogCollectionCategory $root): array {
                $count = (int) $root->getAttribute('public_branch_collections_count');

                return [
                    'slug' => $root->slug,
                    'label' => $root->display_name,
                    'count' => $count,
                    'is_filterable' => $count > 0,
                    'children' => $root->children
                        ->map(function (CatalogCollectionCategory $child): array {
                            $count = (int) $child->getAttribute('public_collections_count');

                            return [
                                'slug' => $child->slug,
                                'label' => $child->display_name,
                                'count' => $count,
                                'is_filterable' => $count > 0,
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        return view('livewire.collections.catalog-collection-explorer', [
            'collections' => $collections->publicDirectory(
                search: $this->search,
                sort: $this->sort,
                perPage: 12,
                category: $this->category !== '' ? $this->category : null,
                subcategory: $this->subcategory !== '' ? $this->subcategory : null,
            ),
            'categoryNavigation' => $categoryNavigation,
            'uncategorizedCount' => $directory['uncategorized'],
            'showUncategorizedFilter' => $directory['uncategorized'] > 0
                || $this->category === 'uncategorized',
            'totalCount' => $directory['total'],
            'hasActiveFilters' => $this->search !== ''
                || $this->sort !== 'featured'
                || $this->category !== ''
                || $this->subcategory !== '',
            'sortOptions' => [
                'featured' => __('collections.directory.sort_featured'),
                'recent' => __('collections.directory.sort_recent'),
                'title' => __('collections.directory.sort_title'),
            ],
            'collectionAction' => [
                'url' => $authenticated ? route('collections.mine') : route('login'),
                'icon' => $authenticated ? 'fa-solid fa-folder-open' : 'fa-solid fa-right-to-bracket',
                'label' => $authenticated ? __('collections.navigation.my_collections') : __('collections.actions.create'),
            ],
        ]);
    }

    private function normalize(?CatalogCollectionCategoryQuery $categories = null): void
    {
        $this->search = Str::limit(Str::squish($this->search), 100, '');
        $this->sort = in_array($this->sort, ['featured', 'recent', 'title'], true) ? $this->sort : 'featured';

        if ($categories !== null) {
            [$this->category, $this->subcategory] = $categories->normalizeDirectorySelection(
                $this->category,
                $this->subcategory,
            );
        }
    }
}
