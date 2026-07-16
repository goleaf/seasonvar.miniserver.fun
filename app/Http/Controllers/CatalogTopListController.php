<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CatalogTopListCategory;
use App\Models\User;
use App\Services\Catalog\CatalogTopListPageBuilder;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class CatalogTopListController extends Controller
{
    public function __construct(private readonly CatalogTopListPageBuilder $page) {}

    public function show(Request $request, CatalogTopListCategory $category): View
    {
        return $this->view($request, $category, false);
    }

    public function localized(Request $request, string $locale, CatalogTopListCategory $category): View
    {
        return $this->view($request, $category, true);
    }

    private function view(Request $request, CatalogTopListCategory $category, bool $localizedAlias): View
    {
        $viewer = $request->user();

        return view('catalog.top-list', $this->page->data(
            $category,
            $viewer instanceof User ? $viewer : null,
            $localizedAlias,
        ));
    }
}
