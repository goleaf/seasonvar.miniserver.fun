<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogShowRequest;
use App\Models\CatalogTitle;
use App\Services\Catalog\CatalogHomePageBuilder;
use App\Services\Catalog\CatalogStatsPageBuilder;
use App\Services\Catalog\CatalogStatsPosterResponder;
use App\Services\Catalog\CatalogTitlePageBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CatalogController extends Controller
{
    public function __construct(
        private readonly CatalogHomePageBuilder $homePage,
        private readonly CatalogTitlePageBuilder $titlePage,
        private readonly CatalogStatsPageBuilder $statsPage,
    ) {}

    public function index(Request $request): View
    {
        return view('catalog.index', $this->homePage->data($request->user()));
    }

    public function show(CatalogShowRequest $request, CatalogTitle $catalogTitle): View|RedirectResponse
    {
        $requestedSlug = $request->route()?->originalParameter('catalogTitle');

        if (is_string($requestedSlug) && $requestedSlug !== $catalogTitle->slug) {
            return redirect()->route('titles.show', $catalogTitle, 301);
        }

        $seo = $this->titlePage->seo($catalogTitle, $request->user());

        if ($request->hasAny([
            'review',
            'reviewPage',
            'review_sort',
            'review_rating',
            'review_spoiler',
            'review_verified',
        ])) {
            $seo['robots'] = 'noindex,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';
        }

        return view('catalog.show', [
            'title' => $catalogTitle,
            'seo' => $seo,
        ]);
    }

    public function stats(): View
    {
        return view('catalog.stats', [
            'seo' => $this->statsPage->seo(),
        ]);
    }

    public function statsPoster(CatalogTitle $catalogTitle, CatalogStatsPosterResponder $posters): Response
    {
        return $posters->response($catalogTitle);
    }
}
