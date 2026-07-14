<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\CatalogDirectoryDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CatalogDirectoryDefinition */
final class CatalogDirectoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'path' => $this->path,
            'title' => $this->title,
            'description' => $this->description,
            'item_label' => $this->itemLabel,
            'filter_type' => $this->filterType?->value,
            'supports_alphabet' => $this->supportsAlphabet,
            'default_per_page' => $this->perPage,
            'links' => [
                'self' => url('/api/v1/catalog/directories/'.$this->key),
                'web' => route($this->indexRouteName),
            ],
        ];
    }
}
