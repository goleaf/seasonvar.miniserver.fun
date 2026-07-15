<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\EpisodeViewProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EpisodeViewProgress */
final class ViewingHistoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $title = $this->catalogTitle;
        $episode = $this->episode;
        $season = $episode?->season;

        return [
            'id' => (int) $this->id,
            'position_seconds' => (int) $this->position_seconds,
            'duration_seconds' => (int) $this->duration_seconds,
            'progress_percent' => $this->progress_percent === null ? null : (int) $this->progress_percent,
            'completed' => $this->completed_at !== null,
            'completed_at' => $this->completed_at?->toJSON(),
            'first_started_at' => $this->first_started_at?->toJSON(),
            'last_watched_at' => $this->last_watched_at->toJSON(),
            'is_accessible' => (bool) $this->resource->getAttribute('is_accessible'),
            'title' => $title === null ? null : [
                'id' => (int) $title->id,
                'slug' => (string) $title->slug,
                'title' => $title->display_title,
                'poster_url' => $title->poster_url,
                'deleted' => $title->deleted_at !== null,
            ],
            'season' => $season === null ? null : [
                'id' => (int) $season->id,
                'number' => (int) $season->number,
                'title' => $season->title,
                'deleted' => $season->deleted_at !== null,
            ],
            'episode' => $episode === null ? null : [
                'id' => (int) $episode->id,
                'season_id' => (int) $episode->season_id,
                'number' => (int) $episode->number,
                'title' => $episode->title,
                'deleted' => $episode->deleted_at !== null,
            ],
        ];
    }
}
