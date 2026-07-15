<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SearchSuggestionResource extends JsonResource
{
    /** @return array<string, int|string|null> */
    public function toArray(Request $request): array
    {
        return [
            'type' => (string) data_get($this->resource, 'type'),
            'public_id' => data_get($this->resource, 'public_id'),
            'label' => (string) data_get($this->resource, 'label'),
            'slug' => (string) data_get($this->resource, 'slug'),
            'title_slug' => data_get($this->resource, 'title_slug'),
            'count' => (int) data_get($this->resource, 'count', 0),
        ];
    }
}
