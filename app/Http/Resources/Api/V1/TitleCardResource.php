<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\CatalogTaxonomyResource;
use App\Models\CatalogTitle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin CatalogTitle */
final class TitleCardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'slug' => (string) $this->slug,
            'title' => $this->display_title,
            'original_title' => $this->display_original_title,
            'type' => (string) $this->type,
            'year' => $this->year === null ? null : (int) $this->year,
            'description' => is_string($this->description)
                ? Str::limit($this->description, 320)
                : null,
            'poster_url' => $this->poster_url,
            'indexed_at' => $this->indexed_at?->toJSON(),
            'counts' => [
                'seasons' => $this->whenCounted('seasons'),
                'episodes' => $this->whenCounted('episodes'),
                'published_media' => $this->when(
                    $this->resource->hasAttribute('published_media_count'),
                    fn (): int => (int) $this->resource->getAttribute('published_media_count'),
                ),
            ],
            'taxonomies' => [
                'genres' => CatalogTaxonomyResource::collection($this->whenLoaded('genres')),
                'countries' => CatalogTaxonomyResource::collection($this->whenLoaded('countries')),
                'age_ratings' => CatalogTaxonomyResource::collection($this->whenLoaded('ageRatings')),
                'translations' => CatalogTaxonomyResource::collection($this->whenLoaded('translations')),
                'tags' => CatalogTaxonomyResource::collection($this->whenLoaded('tags')),
            ],
            'links' => [
                'self' => url('/api/v1/titles/'.$this->slug),
                'web' => route('titles.show', $this->resource),
            ],
        ];
    }
}
