<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\CatalogPrimaryAction;
use App\DTOs\CatalogUserStateSummary;
use App\Models\CatalogTitleUserState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class UserTitleStateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $storedState = $this->resource['state'] ?? null;
        $state = $storedState instanceof CatalogTitleUserState ? $storedState : null;
        /** @var CatalogUserStateSummary $summary */
        $summary = $this->resource['summary'];
        /** @var array{minimum: int, maximum: int} $ratingRange */
        $ratingRange = $this->resource['rating_range'];
        /** @var CatalogPrimaryAction $primaryAction */
        $primaryAction = $this->resource['primary_action'];

        return [
            'in_watchlist' => $state instanceof CatalogTitleUserState && $state->in_watchlist,
            'rating' => $state?->rating,
            'watch_status' => $state?->watch_status?->value,
            'recommendation_feedback' => $state?->recommendation_feedback?->value,
            'versions' => [
                'watchlist' => $state?->watchlistVersion() ?? 0,
                'rating' => $state?->ratingVersion() ?? 0,
                'watch_status' => $state?->watchStatusVersion() ?? 0,
                'recommendation_feedback' => $state?->recommendationFeedbackVersion() ?? 0,
            ],
            'aggregate' => [
                'watchlist_count' => $summary->watchlistCount,
                'rating_count' => $summary->ratingCount,
                'rating_average' => $summary->ratingAverage,
            ],
            'rating_range' => $ratingRange,
            'primary_action' => [
                'type' => $primaryAction->type,
                'label' => $primaryAction->label,
                'season_id' => $primaryAction->seasonId,
                'episode_id' => $primaryAction->episodeId,
                'media_id' => $primaryAction->mediaId,
                'position_seconds' => $primaryAction->positionSeconds,
                'playable' => $primaryAction->isPlayable(),
            ],
        ];
    }
}
