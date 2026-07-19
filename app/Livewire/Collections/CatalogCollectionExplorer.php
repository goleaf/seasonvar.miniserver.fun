<?php

declare(strict_types=1);

namespace App\Livewire\Collections;

use App\Services\Collections\CatalogCollectionQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class CatalogCollectionExplorer extends Component
{
    use WithPagination;

    #[Url(as: 'collections_q', history: true, except: '')]
    public string $search = '';

    #[Url(as: 'collections_sort', history: true, except: 'featured')]
    public string $sort = 'featured';

    public function mount(): void
    {
        $this->normalize();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'sort'], true)) {
            $this->normalize();
            $this->resetPage(pageName: 'collectionsPage');
        }
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

    public function render(CatalogCollectionQuery $collections): View
    {
        $authenticated = Auth::check();

        return view('livewire.collections.catalog-collection-explorer', [
            'collections' => $collections->publicDirectory($this->search, $this->sort, 12),
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

    private function normalize(): void
    {
        $this->search = Str::limit(Str::squish($this->search), 100, '');
        $this->sort = in_array($this->sort, ['featured', 'recent', 'title'], true) ? $this->sort : 'featured';
    }
}
