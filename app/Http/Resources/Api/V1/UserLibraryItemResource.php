<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\CatalogTitleUserState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CatalogTitleUserState */
final class UserLibraryItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'title' => new TitleCardResource($this->catalogTitle),
            'state' => [
                'in_watchlist' => (bool) $this->in_watchlist,
                'rating' => $this->rating === null ? null : (int) $this->rating,
                'updated_at' => $this->updated_at?->toJSON(),
            ],
        ];
    }
}
