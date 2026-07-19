<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\UserLibrarySummary;
use App\Models\CatalogTitleUserState;
use App\Models\EpisodePlaybackMarker;
use App\Models\EpisodeViewProgress;
use App\Models\User;
use App\Services\UserPortal\UserPortalCache;
use Illuminate\Support\Carbon;

final readonly class UserLibrarySummaryQuery
{
    public function __construct(
        private CatalogTitleQuery $titles,
        private CatalogViewingActivityQuery $viewingActivity,
        private CatalogPersonalUpdateQuery $personalUpdates,
        private PersonalLibrarySchema $personalLibrarySchema,
        private UserPortalCache $cache,
    ) {}

    public function get(User $user, bool $refresh = false): UserLibrarySummary
    {
        $snapshot = $this->cache->remember(
            $user,
            'library-summary',
            ['locale' => app()->getLocale()],
            fn (): array => $this->snapshot($user),
            $refresh,
        );

        return new UserLibrarySummary(
            watchlistCount: (int) ($snapshot['watchlist_count'] ?? 0),
            ratingsCount: (int) ($snapshot['ratings_count'] ?? 0),
            continueWatchingCount: (int) ($snapshot['continue_watching_count'] ?? 0),
            historyCount: (int) ($snapshot['history_count'] ?? 0),
            lastWatchedAt: $this->lastWatchedAt($snapshot['last_watched_at'] ?? null),
            links: [
                'self' => route('api.v1.me.library.summary'),
                'watchlist' => route('api.v1.me.watchlist.index'),
                'ratings' => route('api.v1.me.ratings.index'),
                'continue_watching' => route('api.v1.me.continue-watching.index'),
                'history' => route('api.v1.me.history.index'),
            ],
            sectionCounts: is_array($snapshot['section_counts'] ?? null) ? $snapshot['section_counts'] : [],
        );
    }

    /** @return array<string, mixed> */
    private function snapshot(User $user): array
    {
        $visibleTitleIds = $this->titles->visibleTo($user)->select('id');
        $stateCounts = CatalogTitleUserState::query()
            ->whereBelongsTo($user)
            ->whereIn('catalog_title_id', clone $visibleTitleIds)
            ->selectRaw('coalesce(sum(case when in_watchlist = 1 then 1 else 0 end), 0) as watchlist_count')
            ->selectRaw('coalesce(sum(case when rating is not null then 1 else 0 end), 0) as ratings_count')
            ->selectRaw("coalesce(sum(case when watch_status = 'planned' then 1 else 0 end), 0) as planned_count")
            ->selectRaw("coalesce(sum(case when watch_status = 'watching' then 1 else 0 end), 0) as watching_count")
            ->selectRaw("coalesce(sum(case when watch_status = 'paused' then 1 else 0 end), 0) as paused_count")
            ->selectRaw("coalesce(sum(case when watch_status = 'completed' then 1 else 0 end), 0) as completed_count")
            ->selectRaw("coalesce(sum(case when watch_status = 'dropped' then 1 else 0 end), 0) as dropped_count")
            ->selectRaw("coalesce(sum(case when recommendation_feedback = 'not_interested' then 1 else 0 end), 0) as not_interested_count")
            ->selectRaw("coalesce(sum(case when recommendation_feedback = 'blacklisted' then 1 else 0 end), 0) as blacklisted_count")
            ->first();
        $history = EpisodeViewProgress::query()
            ->whereBelongsTo($user)
            ->whereNotNull('first_started_at')
            ->whereIn('catalog_title_id', clone $visibleTitleIds)
            ->selectRaw('count(*) as history_count, max(last_watched_at) as last_watched_at')
            ->first();
        $updatesBase = CatalogTitleUserState::query()
            ->whereBelongsTo($user)
            ->whereIn('catalog_title_id', clone $visibleTitleIds)
            ->where(function ($state): void {
                $state->where('in_watchlist', true)->orWhereNotNull('watch_status');
            })
            ->where(function ($state): void {
                $state->whereNull('recommendation_feedback')
                    ->orWhereNotIn('recommendation_feedback', ['not_interested', 'blacklisted']);
            });
        $updateEligibleCount = (clone $updatesBase)->count();
        $withUpdates = $this->personalUpdates->constrain(clone $updatesBase, $user, true)->count();
        $markerCount = $this->personalLibrarySchema->ready()
            ? EpisodePlaybackMarker::query()
                ->whereBelongsTo($user)
                ->whereIn('catalog_title_id', clone $visibleTitleIds)
                ->count()
            : 0;

        return [
            'watchlist_count' => (int) ($stateCounts?->getAttribute('watchlist_count') ?? 0),
            'ratings_count' => (int) ($stateCounts?->getAttribute('ratings_count') ?? 0),
            'continue_watching_count' => $this->viewingActivity->continueWatching($user, 24)->count(),
            'history_count' => (int) ($history?->getAttribute('history_count') ?? 0),
            'last_watched_at' => $this->lastWatchedAt($history?->getAttribute('last_watched_at'))?->toAtomString(),
            'section_counts' => [
                'planned' => (int) ($stateCounts?->getAttribute('planned_count') ?? 0),
                'watching' => (int) ($stateCounts?->getAttribute('watching_count') ?? 0),
                'paused' => (int) ($stateCounts?->getAttribute('paused_count') ?? 0),
                'completed' => (int) ($stateCounts?->getAttribute('completed_count') ?? 0),
                'dropped' => (int) ($stateCounts?->getAttribute('dropped_count') ?? 0),
                'not-interested' => (int) ($stateCounts?->getAttribute('not_interested_count') ?? 0),
                'blacklisted' => (int) ($stateCounts?->getAttribute('blacklisted_count') ?? 0),
                'with-updates' => $withUpdates,
                'without-updates' => max(0, $updateEligibleCount - $withUpdates),
                'markers' => $markerCount,
            ],
        ];
    }

    private function lastWatchedAt(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }
}
