<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\UserLibrarySummary;
use App\Models\CatalogTitleUserState;
use App\Models\EpisodeViewProgress;
use App\Models\User;
use Illuminate\Support\Carbon;

final readonly class UserLibrarySummaryQuery
{
    public function __construct(
        private CatalogTitleQuery $titles,
        private CatalogViewingActivityQuery $viewingActivity,
    ) {}

    public function get(User $user): UserLibrarySummary
    {
        $visibleTitleIds = $this->titles->visibleTo($user)->select('id');
        $stateCounts = CatalogTitleUserState::query()
            ->whereBelongsTo($user)
            ->whereIn('catalog_title_id', clone $visibleTitleIds)
            ->selectRaw('coalesce(sum(case when in_watchlist = 1 then 1 else 0 end), 0) as watchlist_count')
            ->selectRaw('coalesce(sum(case when rating is not null then 1 else 0 end), 0) as ratings_count')
            ->first();
        $history = EpisodeViewProgress::query()
            ->whereBelongsTo($user)
            ->whereNotNull('first_started_at')
            ->whereIn('catalog_title_id', clone $visibleTitleIds)
            ->selectRaw('count(*) as history_count, max(last_watched_at) as last_watched_at')
            ->first();

        return new UserLibrarySummary(
            watchlistCount: (int) ($stateCounts?->getAttribute('watchlist_count') ?? 0),
            ratingsCount: (int) ($stateCounts?->getAttribute('ratings_count') ?? 0),
            continueWatchingCount: $this->viewingActivity->continueWatching($user, 24)->count(),
            historyCount: (int) ($history?->getAttribute('history_count') ?? 0),
            lastWatchedAt: $this->lastWatchedAt($history?->getAttribute('last_watched_at')),
            links: [
                'self' => route('api.v1.me.library.summary'),
                'watchlist' => route('api.v1.me.watchlist.index'),
                'ratings' => route('api.v1.me.ratings.index'),
                'continue_watching' => route('api.v1.me.continue-watching.index'),
                'history' => route('api.v1.me.history.index'),
            ],
        );
    }

    private function lastWatchedAt(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }
}
