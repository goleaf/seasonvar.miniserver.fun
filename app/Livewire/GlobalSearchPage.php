<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Http\Requests\GlobalSearchRequest;
use App\Models\User;
use App\Services\Catalog\Search\GlobalSearchPageQuery;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class GlobalSearchPage extends Component
{
    #[Locked]
    public string $query = '';

    public function mount(GlobalSearchRequest $request): void
    {
        $this->query = $request->queryValue();
    }

    public function render(GlobalSearchPageQuery $search): View
    {
        $viewer = auth()->user();
        $results = $search->search($this->query, $viewer instanceof User ? $viewer : null);
        $routeLocale = request()->route('locale');
        $localized = is_string($routeLocale)
            && in_array($routeLocale, (array) config('catalog-collections.supported_locales', []), true);
        $searchRouteName = $localized ? 'localized.search.index' : 'search.index';
        $searchRouteParameters = $localized ? ['locale' => $routeLocale] : [];
        $searchUrl = route($searchRouteName, $searchRouteParameters);
        $seo = [
            'title' => $this->query === ''
                ? __('catalog.global_search.title')
                : __('catalog.global_search.title_query', ['query' => $this->query]),
            'description' => __('catalog.global_search.description'),
            'canonical' => $searchUrl,
            'robots' => 'noindex,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1',
            'breadcrumbs' => [
                ['name' => __('catalog.navigation.home'), 'url' => route('home')],
                ['name' => __('catalog.global_search.title'), 'url' => $searchUrl],
            ],
        ];

        return view('search.index', [
            'query' => $this->query,
            'searchRouteName' => $searchRouteName,
            'searchRouteParameters' => $searchRouteParameters,
            'searchUrl' => $searchUrl,
            ...$results,
        ])->extends('layouts.app', [
            'title' => $seo['title'],
            'seo' => $seo,
        ])->section('content');
    }
}
