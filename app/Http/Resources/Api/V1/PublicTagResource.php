<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tag */
final class PublicTagResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => (string) $this->public_id,
            'code' => $this->code,
            'type' => $this->type->value,
            'name' => (string) $this->name,
            'slug' => (string) $this->slug,
            'description' => $this->localizedDescription(),
            'serial_count' => $this->when(
                $this->resource->hasAttribute('public_titles_count'),
                fn (): int => (int) $this->public_titles_count,
            ),
            'aliases' => $this->whenLoaded('aliases', fn (): array => $this->aliases->pluck('name')->values()->all()),
            'links' => [
                'web' => route('titles.taxonomy', ['type' => 'tag', 'taxonomy' => $this->slug]),
                'self' => route('api.v1.tags.show', ['tagSlug' => $this->slug]),
            ],
        ];
    }
}
