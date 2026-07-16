<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CatalogTopListCategory;
use App\Http\Requests\CatalogTopListRequest;
use App\Models\User;
use App\Services\Catalog\CatalogTopListPageBuilder;
use Illuminate\View\View;

final class CatalogTopListController extends Controller
{
    public function __construct(private readonly CatalogTopListPageBuilder $page) {}

    public function show(CatalogTopListRequest $request, CatalogTopListCategory $category): View
    {
        return $this->view($request, $category, false);
    }

    public function localized(
        CatalogTopListRequest $request,
        string $locale,
        CatalogTopListCategory $category,
    ): View {
        return $this->view($request, $category, true);
    }

    private function view(
        CatalogTopListRequest $request,
        CatalogTopListCategory $category,
        bool $localizedAlias,
    ): View {
        $viewer = $request->user();

        return view('catalog.top-list', $this->page->data(
            $category,
            $viewer instanceof User ? $viewer : null,
            $localizedAlias,
            $request->filters(),
        ));
    }
}
