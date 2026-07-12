<?php

namespace App\Http\Controllers;

use App\Http\Requests\CatalogShowRequest;
use App\Models\CatalogTitle;
use App\Services\Catalog\CatalogHomePageBuilder;
use App\Services\Catalog\CatalogStatsPageBuilder;
use App\Services\Catalog\CatalogStatsPosterResponder;
use App\Services\Catalog\CatalogTitlePageBuilder;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class CatalogController extends Controller
{
    public function __construct(
        private readonly CatalogHomePageBuilder $homePage,
        private readonly CatalogTitlePageBuilder $titlePage,
        private readonly CatalogStatsPageBuilder $statsPage,
    ) {}

    public function index(): View
    {
        return view('catalog.index', $this->homePage->data());
    }

    public function show(CatalogShowRequest $request, CatalogTitle $catalogTitle): View
    {
        return view('catalog.show', $this->titlePage->data($request, $catalogTitle));
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
