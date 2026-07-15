<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\CatalogTitle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CatalogTitle */
final class CatalogCollectionItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->display_title,
            'original_title' => $this->display_original_title,
            'year' => $this->year === null ? null : (int) $this->year,
            'poster_url' => $this->poster_url,
            'position' => (int) $this->getAttribute('collection_position'),
            'added_at' => $this->getAttribute('collection_added_at')?->toJSON(),
            'web_url' => route('titles.show', $this->resource),
        ];
    }
}
