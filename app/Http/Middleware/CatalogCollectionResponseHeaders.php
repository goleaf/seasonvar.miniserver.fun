<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Collections\CatalogCollectionResolver;
use App\Services\Collections\CatalogCollectionSeoPresenter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class CatalogCollectionResponseHeaders
{
    public function __construct(
        private CatalogCollectionResolver $resolver,
        private CatalogCollectionQuery $query,
        private CatalogCollectionSeoPresenter $seo,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $slug = $request->route('collectionSlug');

        if (! is_string($slug)) {
            return $response;
        }

        $collection = $this->query->summary($this->resolver->resolve($slug)['collection']);
        $viewer = $request->user();
        $seo = $this->seo->collection(
            $collection,
            $viewer instanceof User ? $viewer : null,
            $request->routeIs('localized.collections.show'),
            $request->query() !== [],
        );

        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        if (str_starts_with((string) ($seo['robots'] ?? ''), 'noindex')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $response;
    }
}
