<?php

declare(strict_types=1);

namespace App\Livewire\Collections;

use App\Livewire\Concerns\InteractsWithCollectionLocale;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Collections\CatalogCollectionSeoPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

final class CatalogCollectionDirectory extends Component
{
    use InteractsWithCollectionLocale;
    use WithPagination;

    #[Url(as: 'q', history: true, except: '')]
    public string $search = '';

    #[Url(history: true, except: 'featured')]
    public string $sort = 'featured';

    protected CatalogCollectionQuery $collections;

    protected CatalogCollectionSeoPresenter $seo;

    public function boot(CatalogCollectionQuery $collections, CatalogCollectionSeoPresenter $seo): void
    {
        $this->collections = $collections;
        $this->seo = $seo;
    }

    public function mount(?string $locale = null): void
    {
        $this->setCollectionLocale($locale);
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

    public function render(): View
    {
        $localizedAlias = request()->routeIs('localized.collections.index');
        $collections = $this->collections->publicDirectory($this->search, $this->sort);
        $query = array_filter([
            'q' => $this->search,
            'sort' => $this->sort !== 'featured' ? $this->sort : null,
            'collectionsPage' => $collections->currentPage() > 1 ? $collections->currentPage() : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
        $isAuthenticated = Auth::check();
        $localeUrls = [];

        foreach ($this->supportedLocales() as $locale) {
            $localeUrls[$locale] = route('localized.collections.index', [
                'locale' => $locale,
                ...$query,
            ]);
        }

        return view('livewire.collections.catalog-collection-directory', [
            'collections' => $collections,
            'sortOptions' => [
                'featured' => __('collections.directory.sort_featured'),
                'recent' => __('collections.directory.sort_recent'),
                'title' => __('collections.directory.sort_title'),
            ],
            'localizedAlias' => $localizedAlias,
            'collectionAction' => [
                'url' => $isAuthenticated ? route('collections.mine') : route('login'),
                'icon' => $isAuthenticated ? 'fa-solid fa-folder-open' : 'fa-solid fa-right-to-bracket',
                'label' => $isAuthenticated
                    ? __('collections.navigation.my_collections')
                    : __('collections.actions.create'),
            ],
            'localeUrls' => $localeUrls,
        ])->extends('layouts.app', [
            'title' => __('collections.directory.title'),
            'seo' => $this->seo->directory(
                $localizedAlias,
                $this->search !== '' || $this->sort !== 'featured' || $collections->currentPage() > 1,
            ),
        ])->section('content');
    }

    private function normalize(): void
    {
        $this->search = Str::limit(Str::squish($this->search), 100, '');
        $this->sort = in_array($this->sort, ['featured', 'recent', 'title'], true)
            ? $this->sort
            : 'featured';
    }

    /** @return list<string> */
    private function supportedLocales(): array
    {
        $configured = config('catalog-collections.supported_locales', ['ru']);

        if (! is_array($configured)) {
            return ['ru'];
        }

        return collect($configured)
            ->filter(fn (mixed $locale): bool => is_string($locale) && $locale !== '')
            ->values()
            ->all();
    }
}
