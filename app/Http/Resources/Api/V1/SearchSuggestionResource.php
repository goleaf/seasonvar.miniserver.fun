<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class SearchSuggestionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => data_get($this->resource, 'id'),
            'type' => (string) data_get($this->resource, 'type'),
            'group' => data_get($this->resource, 'group'),
            'public_id' => data_get($this->resource, 'public_id'),
            'label' => (string) data_get($this->resource, 'label'),
            'original_title' => data_get($this->resource, 'original_title'),
            'slug' => (string) data_get($this->resource, 'slug'),
            'title_slug' => data_get($this->resource, 'title_slug'),
            'url' => data_get($this->resource, 'url'),
            'meta' => data_get($this->resource, 'meta'),
            'poster_url' => data_get($this->resource, 'poster_url'),
            'year' => data_get($this->resource, 'year'),
            'seasons_count' => data_get($this->resource, 'seasons_count'),
            'episodes_count' => data_get($this->resource, 'episodes_count'),
            'content_type' => data_get($this->resource, 'content_type'),
            'count' => (int) data_get($this->resource, 'count', 0),
        ];
    }
}
