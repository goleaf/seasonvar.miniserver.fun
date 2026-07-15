<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\DTOs\UserLibrarySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserLibrarySummary */
final class UserLibrarySummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'watchlist_count' => $this->watchlistCount,
            'ratings_count' => $this->ratingsCount,
            'continue_watching_count' => $this->continueWatchingCount,
            'history_count' => $this->historyCount,
            'last_watched_at' => $this->lastWatchedAt?->toJSON(),
            'links' => $this->links,
        ];
    }
}
