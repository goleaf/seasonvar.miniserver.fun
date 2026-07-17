<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CatalogRecommendationResource;
use App\Models\User;
use App\Services\Catalog\Api\V1\CatalogRecommendationQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class CatalogRecommendationController extends Controller
{
    public function __invoke(
        Request $request,
        string $titleSlug,
        CatalogRecommendationQuery $recommendations,
    ): AnonymousResourceCollection {
        $user = $request->user();

        return CatalogRecommendationResource::collection(
            $recommendations->forTitle($titleSlug, $user instanceof User ? $user : null),
        );
    }
}
