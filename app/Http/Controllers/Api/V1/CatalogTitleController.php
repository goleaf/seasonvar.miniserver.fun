<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CatalogTitleIndexRequest;
use App\Http\Resources\Api\V1\TitleCardResource;
use App\Services\Catalog\Api\V1\CatalogTitleIndexQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CatalogTitleController extends Controller
{
    public function index(
        CatalogTitleIndexRequest $request,
        CatalogTitleIndexQuery $titles,
    ): AnonymousResourceCollection {
        return TitleCardResource::collection($titles->paginate($request));
    }
}
