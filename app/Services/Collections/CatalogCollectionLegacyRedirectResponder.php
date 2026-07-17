<?php

declare(strict_types=1);

namespace App\Services\Collections;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final readonly class CatalogCollectionLegacyRedirectResponder
{
    public function __construct(private CatalogCollectionResolver $resolver) {}

    public function response(string $collectionSlug): RedirectResponse
    {
        $collection = $this->resolver->resolve($collectionSlug)['collection'];
        Gate::authorize('view', $collection);

        return redirect()->route('collections.show', [
            'collectionSlug' => $collection->slug,
        ], 301);
    }
}
