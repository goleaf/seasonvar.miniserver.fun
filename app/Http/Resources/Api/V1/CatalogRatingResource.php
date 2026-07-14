<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\CatalogTitleRating;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CatalogTitleRating */
final class CatalogRatingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'provider' => (string) $this->provider,
            'rating' => $this->rating === null ? null : (float) $this->rating,
            'votes' => $this->votes === null ? null : (int) $this->votes,
        ];
    }
}
