<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\CatalogTopListFilters;
use App\Enums\CatalogTopListCategory;
use App\Http\Requests\CatalogTopListRequest;
use App\Models\User;
use App\Services\Catalog\CatalogTopListPageBuilder;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class CatalogTopListPage extends Component
{
    #[Locked]
    public string $categoryValue = '';

    #[Locked]
    public ?int $yearFrom = null;

    #[Locked]
    public ?int $yearTo = null;

    #[Locked]
    public ?string $country = null;

    #[Locked]
    public ?string $genre = null;

    #[Locked]
    public bool $localizedAlias = false;

    public function mount(
        CatalogTopListRequest $request,
        CatalogTopListCategory $category,
        ?string $locale = null,
    ): void {
        $filters = $request->filters();
        $this->categoryValue = $category->value;
        $this->yearFrom = $filters->yearFrom;
        $this->yearTo = $filters->yearTo;
        $this->country = $filters->country;
        $this->genre = $filters->genre;
        $this->localizedAlias = $locale !== null;
    }

    public function render(CatalogTopListPageBuilder $page): View
    {
        $viewer = auth()->user();
        $data = $page->data(
            CatalogTopListCategory::from($this->categoryValue),
            $viewer instanceof User ? $viewer : null,
            $this->localizedAlias,
            new CatalogTopListFilters(
                yearFrom: $this->yearFrom,
                yearTo: $this->yearTo,
                country: $this->country,
                genre: $this->genre,
            ),
        );

        return view('livewire.catalog-top-list-page', $data)
            ->extends('layouts.app', [
                'title' => $data['seo']['title'] ?? CatalogTopListCategory::from($this->categoryValue)->title(),
                'seo' => $data['seo'] ?? [],
            ])
            ->section('content');
    }
}
