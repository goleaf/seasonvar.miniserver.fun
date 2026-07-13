<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CatalogPeopleLookupRequest;
use App\Http\Resources\CatalogPersonOptionResource;
use App\Services\Catalog\Search\CatalogPeopleLookup;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CatalogPeopleLookupController extends Controller
{
    public function __invoke(
        CatalogPeopleLookupRequest $request,
        CatalogPeopleLookup $people,
    ): AnonymousResourceCollection {
        return CatalogPersonOptionResource::collection(
            $people->search($request->peopleType(), $request->queryValue(), $request->user()),
        );
    }
}
