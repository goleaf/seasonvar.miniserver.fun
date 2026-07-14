<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CatalogReviewIndexRequest;
use App\Http\Resources\Api\V1\CatalogReviewResource;
use App\Services\Catalog\Api\V1\CatalogReviewQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CatalogReviewController extends Controller
{
    public function __invoke(
        CatalogReviewIndexRequest $request,
        string $titleSlug,
        CatalogReviewQuery $reviews,
    ): AnonymousResourceCollection {
        return CatalogReviewResource::collection(
            $reviews->forTitle($titleSlug, $request->user(), $request),
        );
    }
}
