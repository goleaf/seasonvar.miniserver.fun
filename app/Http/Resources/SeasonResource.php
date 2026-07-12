<?php

namespace App\Http\Resources;

use App\Models\Season;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Season
 */
class SeasonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'kind' => $this->kind->value,
            'title' => $this->title,
            'latest_episode_released_at' => $this->latest_episode_released_at?->toDateString(),
            'episodes_released' => $this->episodes_released,
            'episodes_total' => $this->episodes_total,
            'translation_name' => $this->translation_name,
            'episodes' => EpisodeResource::collection($this->whenLoaded('episodes')),
        ];
    }
}
