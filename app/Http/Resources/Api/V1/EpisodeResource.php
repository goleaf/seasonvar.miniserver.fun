<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Episode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Episode */
final class EpisodeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $media = $this->resource->relationLoaded('licensedMedia')
            ? $this->resource->getRelation('licensedMedia')
            : collect();

        return [
            'id' => (int) $this->id,
            'season_id' => (int) $this->season_id,
            'number' => $this->number === null ? null : (int) $this->number,
            'kind' => $this->kind->value,
            'title' => $this->title,
            'released_at' => $this->released_at?->toDateString(),
            'summary' => $this->summary,
            'counts' => [
                'media_profiles' => $media->count(),
            ],
            'media_profiles' => MediaProfileResource::collection($media),
        ];
    }
}
