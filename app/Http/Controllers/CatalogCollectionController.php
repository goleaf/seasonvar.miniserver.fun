<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CatalogCollection;
use App\Models\User;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Collections\CatalogCollectionResolver;
use App\Services\Collections\CatalogCollectionSeoPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class CatalogCollectionController extends Controller
{
    public function show(
        Request $request,
        string $collectionSlug,
        CatalogCollectionResolver $resolver,
        CatalogCollectionQuery $query,
        CatalogCollectionSeoPresenter $seo,
    ): Response|RedirectResponse {
        $resolved = $resolver->resolve($collectionSlug);
        $collection = $resolved['collection'];
        Gate::authorize('view', $collection);

        if ($resolved['historical']) {
            return redirect()->route('collections.show', ['collectionSlug' => $collection->slug], 301);
        }

        return $this->view($request, $collection, $query, $seo, false);
    }

    public function localizedShow(
        Request $request,
        string $locale,
        string $collectionSlug,
        CatalogCollectionResolver $resolver,
        CatalogCollectionQuery $query,
        CatalogCollectionSeoPresenter $seo,
    ): Response|RedirectResponse {
        $resolved = $resolver->resolve($collectionSlug);
        $collection = $resolved['collection'];
        Gate::authorize('view', $collection);

        if ($resolved['historical']) {
            return redirect()->route('localized.collections.show', [
                'locale' => $locale,
                'collectionSlug' => $collection->slug,
            ], 301);
        }

        return $this->view($request, $collection, $query, $seo, true);
    }

    public function legacyShow(
        string $collectionSlug,
        CatalogCollectionResolver $resolver,
    ): RedirectResponse {
        $resolved = $resolver->resolve($collectionSlug);
        $collection = $resolved['collection'];
        Gate::authorize('view', $collection);

        return redirect()->route('collections.show', [
            'collectionSlug' => $collection->slug,
        ], 301);
    }

    private function view(
        Request $request,
        CatalogCollection $collection,
        CatalogCollectionQuery $query,
        CatalogCollectionSeoPresenter $seo,
        bool $localizedAlias,
    ): Response {
        $viewer = $request->user();
        $viewer = $viewer instanceof User ? $viewer : null;
        $collection = $query->summary($collection);

        $seoPayload = $seo->collection($collection, $viewer, $localizedAlias, $request->query() !== []);
        $response = response()->view('collections.show', [
            'collection' => $collection,
            'seo' => $seoPayload,
            'interfaceLocale' => app()->currentLocale(),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        if (str_starts_with((string) ($seoPayload['robots'] ?? ''), 'noindex')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $response;
    }
}
