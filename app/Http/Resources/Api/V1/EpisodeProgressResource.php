<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\EpisodeViewProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EpisodeViewProgress */
final class EpisodeProgressResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'catalog_title_id' => (int) $this->catalog_title_id,
            'episode_id' => (int) $this->episode_id,
            'position_seconds' => (int) $this->position_seconds,
            'duration_seconds' => (int) $this->duration_seconds,
            'progress_percent' => $this->progress_percent === null ? null : (int) $this->progress_percent,
            'first_started_at' => $this->first_started_at?->toJSON(),
            'last_watched_at' => $this->last_watched_at->toJSON(),
            'completed' => $this->completed_at !== null,
            'completed_at' => $this->completed_at?->toJSON(),
        ];
    }
}
