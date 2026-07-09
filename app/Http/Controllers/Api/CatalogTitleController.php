<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CatalogTitleIndexRequest;
use App\Http\Resources\CatalogTitleResource;
use App\Models\CatalogTitle;
use App\Services\Catalog\CatalogApiTitleQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CatalogTitleController extends Controller
{
    public function __construct(
        private readonly CatalogApiTitleQuery $titles,
    ) {}

    public function index(CatalogTitleIndexRequest $request): AnonymousResourceCollection
    {
        return CatalogTitleResource::collection(
            $this->titles->paginatePublished($request->perPage()),
        );
    }

    public function show(CatalogTitle $catalogTitle): CatalogTitleResource
    {
        return new CatalogTitleResource(
            $this->titles->findPublishedForApi($catalogTitle),
        );
    }
}
