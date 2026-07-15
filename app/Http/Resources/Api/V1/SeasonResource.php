<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Season;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Season */
final class SeasonResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $media = $this->resource->relationLoaded('licensedMedia')
            ? $this->resource->getRelation('licensedMedia')
            : collect();

        return [
            'id' => (int) $this->id,
            'number' => (int) $this->number,
            'kind' => $this->kind->value,
            'title' => $this->title,
            'latest_episode_released_at' => $this->latest_episode_released_at?->toDateString(),
            'episodes_released' => $this->episodes_released === null ? null : (int) $this->episodes_released,
            'episodes_total' => $this->episodes_total === null ? null : (int) $this->episodes_total,
            'translation' => $this->translation_name,
            'counts' => [
                'available_episodes' => (int) $this->resource->getAttribute('available_episodes_count'),
                'media_profiles' => (int) $this->resource->getAttribute('available_media_count'),
            ],
            'media_profiles' => MediaProfileResource::collection($media),
            'links' => [
                'episodes' => url(sprintf(
                    '/api/v1/titles/%s/seasons/%d/episodes',
                    $this->resource->getAttribute('api_title_slug'),
                    $this->id,
                )),
            ],
        ];
    }
}
