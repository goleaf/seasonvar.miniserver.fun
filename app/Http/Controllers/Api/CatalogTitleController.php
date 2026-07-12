<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CatalogTitleIndexRequest;
use App\Http\Resources\CatalogTitleResource;
use App\Models\CatalogTitle;
use App\Services\Catalog\CatalogApiTitleQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CatalogTitleController extends Controller
{
    public function __construct(
        private readonly CatalogApiTitleQuery $titles,
    ) {}

    public function index(CatalogTitleIndexRequest $request): AnonymousResourceCollection
    {
        return CatalogTitleResource::collection(
            $this->titles->paginateVisible($request->perPage(), $request->user()),
        );
    }

    public function show(Request $request, CatalogTitle $catalogTitle): CatalogTitleResource
    {
        return new CatalogTitleResource(
            $this->titles->findVisibleForApi($catalogTitle, $request->user()),
        );
    }
}
