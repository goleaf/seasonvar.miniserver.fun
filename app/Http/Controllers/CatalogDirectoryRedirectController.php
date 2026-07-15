<?php

namespace App\Http\Controllers;

use App\Services\Catalog\CatalogDirectoryQuery;
use App\Services\Catalog\CatalogDirectoryRegistry;
use App\Services\Tags\TagResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class CatalogDirectoryRedirectController extends Controller
{
    public function __invoke(
        Request $request,
        CatalogDirectoryRegistry $registry,
        CatalogDirectoryQuery $directories,
        TagResolver $tags,
        string $value,
    ): RedirectResponse {
        $directory = $registry->find($request->route('directory'));
        abort_if($directory === null, 404);

        if ($directory->isYear()) {
            abort_unless($directories->detailExists($directory, $value), 404);

            return redirect()->route('titles.year', ['year' => (int) $value], 301);
        }

        if ($directory->filterType?->value === 'tag') {
            $resolved = $tags->resolvePublic($value);
            abort_if($resolved === null, 404);

            $url = route('titles.taxonomy', [
                'type' => 'tag',
                'taxonomy' => $resolved->tag->slug,
            ]);

            return redirect()->to($this->withQuery($url, $request), 301);
        }

        abort_unless($directories->detailExists($directory, $value), 404);

        return redirect()->route('titles.taxonomy', [
            'type' => $directory->filterType?->value,
            'taxonomy' => $value,
        ], 301);
    }

    private function withQuery(string $url, Request $request): string
    {
        $query = Arr::except($request->query(), ['_method', '_token']);

        return $query === [] ? $url : $url.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
