<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CatalogDirectoryItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $slug = data_get($this->resource, 'slug');
        $year = data_get($this->resource, 'year');

        return [
            'id' => (int) data_get($this->resource, 'id'),
            'name' => (string) data_get($this->resource, 'name'),
            'slug' => is_string($slug) ? $slug : null,
            'year' => is_numeric($year) ? (int) $year : null,
            'titles_count' => (int) data_get($this->resource, 'published_titles_count', 0),
        ];
    }
}
