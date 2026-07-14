<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\CatalogTitleRecommendation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CatalogTitleRecommendation */
final class CatalogRecommendationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'rank' => (int) $this->rank,
            'reasons' => $this->resource->reasonLabels(),
            'title' => new TitleCardResource($this->recommendedTitle),
        ];
    }
}
