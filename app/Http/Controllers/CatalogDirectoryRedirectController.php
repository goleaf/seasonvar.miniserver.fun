<?php

namespace App\Http\Controllers;

use App\Services\Catalog\CatalogDirectoryQuery;
use App\Services\Catalog\CatalogDirectoryRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CatalogDirectoryRedirectController extends Controller
{
    public function __invoke(
        Request $request,
        CatalogDirectoryRegistry $registry,
        CatalogDirectoryQuery $directories,
        string $value,
    ): RedirectResponse {
        $directory = $registry->find($request->route('directory'));
        abort_if($directory === null || ! $directories->detailExists($directory, $value), 404);

        if ($directory->isYear()) {
            return redirect()->route('titles.year', ['year' => (int) $value], 301);
        }

        return redirect()->route('titles.taxonomy', [
            'type' => $directory->filterType?->value,
            'taxonomy' => $value,
        ], 301);
    }
}
