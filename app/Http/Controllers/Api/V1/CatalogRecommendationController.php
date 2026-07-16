<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CatalogRecommendationResource;
use App\Services\Catalog\Api\V1\CatalogRecommendationQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CatalogRecommendationController extends Controller
{
    public function __invoke(
        string $titleSlug,
        CatalogRecommendationQuery $recommendations,
    ): AnonymousResourceCollection {
        return CatalogRecommendationResource::collection(
            $recommendations->forTitle($titleSlug, null),
        );
    }
}
