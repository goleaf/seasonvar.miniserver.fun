<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Services\Tags\TagResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

final readonly class CatalogDirectoryRedirectResponder
{
    public function __construct(
        private CatalogDirectoryRegistry $registry,
        private CatalogDirectoryQuery $directories,
        private TagResolver $tags,
    ) {}

    public function response(Request $request, string $value): RedirectResponse
    {
        $directory = $this->registry->find($request->route('directory'));
        abort_if($directory === null, 404);

        if ($directory->isYear()) {
            abort_unless($this->directories->detailExists($directory, $value), 404);

            return redirect()->route('titles.year', ['year' => (int) $value], 301);
        }

        if ($directory->filterType?->value === 'tag') {
            $resolved = $this->tags->resolvePublic($value);
            abort_if($resolved === null, 404);
            $url = route('titles.taxonomy', [
                'type' => 'tag',
                'taxonomy' => $resolved->tag->slug,
            ]);

            return redirect()->to($this->withQuery($url, $request), 301);
        }

        abort_unless($this->directories->detailExists($directory, $value), 404);

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
