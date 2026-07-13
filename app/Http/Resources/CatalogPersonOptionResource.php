<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogPersonOptionResource extends JsonResource
{
    /** @return array<string, int|string> */
    public function toArray(Request $request): array
    {
        return [
            'type' => (string) $this->resource->filter_type,
            'slug' => (string) $this->resource->slug,
            'name' => (string) $this->resource->name,
            'count' => (int) $this->resource->public_titles_count,
        ];
    }
}
