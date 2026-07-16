<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GlobalSearchRequest;
use App\Services\Catalog\Search\GlobalSearchPageQuery;
use Illuminate\View\View;

final class GlobalSearchController extends Controller
{
    public function __invoke(GlobalSearchRequest $request, GlobalSearchPageQuery $search): View
    {
        $query = $request->queryValue();
        $results = $search->search($query, $request->user());
        $routeLocale = $request->route('locale');
        $localized = is_string($routeLocale)
            && in_array($routeLocale, (array) config('catalog-collections.supported_locales', []), true);
        $searchRouteName = $localized ? 'localized.search.index' : 'search.index';
        $searchRouteParameters = $localized ? ['locale' => $routeLocale] : [];
        $searchUrl = route($searchRouteName, $searchRouteParameters);

        return view('search.index', [
            'query' => $query,
            'searchRouteName' => $searchRouteName,
            'searchRouteParameters' => $searchRouteParameters,
            'searchUrl' => $searchUrl,
            ...$results,
            'seo' => [
                'title' => $query === ''
                    ? __('catalog.global_search.title')
                    : __('catalog.global_search.title_query', ['query' => $query]),
                'description' => __('catalog.global_search.description'),
                'canonical' => $searchUrl,
                'robots' => 'noindex,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1',
                'breadcrumbs' => [
                    ['name' => __('catalog.navigation.home'), 'url' => route('home')],
                    ['name' => __('catalog.global_search.title'), 'url' => $searchUrl],
                ],
            ],
        ]);
    }
}
