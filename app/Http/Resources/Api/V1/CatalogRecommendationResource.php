<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\CatalogRecommendationItem;
use App\Services\Catalog\CatalogRecommendationPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CatalogRecommendationItem */
final class CatalogRecommendationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'rank' => (int) $this->rank,
            'reasons' => app(CatalogRecommendationPresenter::class)->explanations($this->explanations),
            'title' => new TitleCardResource($this->title),
        ];
    }
}
