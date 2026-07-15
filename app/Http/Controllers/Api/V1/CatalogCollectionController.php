<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\CatalogCollectionItemCriteria;
use App\Enums\CatalogCollectionVisibility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CatalogCollectionIndexRequest;
use App\Http\Requests\Api\V1\CatalogCollectionShowRequest;
use App\Http\Resources\Api\V1\CatalogCollectionItemResource;
use App\Http\Resources\Api\V1\CatalogCollectionResource;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Collections\CatalogCollectionQuery;
use App\Services\Collections\CatalogCollectionResolver;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CatalogCollectionController extends Controller
{
    public function index(
        CatalogCollectionIndexRequest $request,
        CatalogCollectionQuery $collections,
    ): AnonymousResourceCollection {
        return CatalogCollectionResource::collection($collections->publicDirectory(
            $request->search(),
            $request->sort(),
            $request->perPage(),
            'page',
        ));
    }

    public function show(
        CatalogCollectionShowRequest $request,
        string $collectionSlug,
        CatalogCollectionResolver $resolver,
        CatalogCollectionQuery $collections,
    ): AnonymousResourceCollection {
        $resolved = $resolver->resolve($collectionSlug);
        $collection = $collections->summary($resolved['collection']);
        abort_unless(
            $collection->visibility === CatalogCollectionVisibility::Public && $collection->isPubliclyViewable(),
            404,
        );
        $items = $collections->items(
            $collection,
            null,
            new CatalogCollectionItemCriteria(
                sort: $collection->sort_mode,
                perPage: $request->perPage(),
            ),
            pageName: 'page',
        );

        return CatalogCollectionItemResource::collection($items)->additional([
            'collection' => (new CatalogCollectionResource($collection))->resolve($request),
        ]);
    }

    public function forTitle(
        string $titleSlug,
        CatalogTitleQuery $titles,
        CatalogCollectionQuery $collections,
    ): AnonymousResourceCollection {
        $title = $titles->visibleTo(null)
            ->select(['id', 'slug'])
            ->where('slug', $titleSlug)
            ->firstOrFail();

        return CatalogCollectionResource::collection($collections->publicForTitle($title->id, 12));
    }
}
