<?php

namespace App\Http\Resources;

use App\Models\CatalogTitle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CatalogTitle
 */
class CatalogTitleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'original_title' => $this->original_title,
            'type' => $this->type,
            'year' => $this->year,
            'description' => $this->description,
            'poster_url' => $this->poster_url,
            'indexed_at' => $this->indexed_at?->toJSON(),
            'counts' => [
                'seasons' => $this->whenCounted('seasons'),
                'episodes' => $this->whenCounted('episodes'),
                'published_media' => $this->whenCounted('publishedLicensedMedia'),
            ],
            'taxonomies' => [
                'genres' => CatalogTaxonomyResource::collection($this->whenLoaded('genres')),
                'countries' => CatalogTaxonomyResource::collection($this->whenLoaded('countries')),
                'actors' => CatalogTaxonomyResource::collection($this->whenLoaded('actors')),
                'directors' => CatalogTaxonomyResource::collection($this->whenLoaded('directors')),
                'age_ratings' => CatalogTaxonomyResource::collection($this->whenLoaded('ageRatings')),
                'translations' => CatalogTaxonomyResource::collection($this->whenLoaded('translations')),
                'statuses' => CatalogTaxonomyResource::collection($this->whenLoaded('statuses')),
                'networks' => CatalogTaxonomyResource::collection($this->whenLoaded('networks')),
                'studios' => CatalogTaxonomyResource::collection($this->whenLoaded('studios')),
                'tags' => CatalogTaxonomyResource::collection($this->whenLoaded('tags')),
            ],
            'seasons' => SeasonResource::collection($this->whenLoaded('seasons')),
            'links' => [
                'self' => route('api.titles.show', $this->resource),
                'web' => route('titles.show', $this->resource),
            ],
        ];
    }
}
