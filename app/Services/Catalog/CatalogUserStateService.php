<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\EpisodeViewProgress;
use App\Models\User;

class CatalogUserStateService
{
    public function __construct(private readonly CatalogTitlePlaybackQuery $playback) {}

    public function state(User $user, CatalogTitle $catalogTitle): ?CatalogTitleUserState
    {
        return CatalogTitleUserState::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($catalogTitle)
            ->first();
    }

    public function toggleWatchlist(User $user, CatalogTitle $catalogTitle): CatalogTitleUserState
    {
        $state = CatalogTitleUserState::query()->firstOrNew([
            'user_id' => $user->id,
            'catalog_title_id' => $catalogTitle->id,
        ]);
        $state->in_watchlist = ! (bool) $state->in_watchlist;
        $state->save();

        return $state;
    }

    public function setRating(User $user, CatalogTitle $catalogTitle, ?int $rating): CatalogTitleUserState
    {
        return CatalogTitleUserState::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'catalog_title_id' => $catalogTitle->id,
            ],
            ['rating' => $rating],
        );
    }

    public function recordProgress(
        User $user,
        CatalogTitle $catalogTitle,
        int $episodeId,
        int $positionSeconds,
        int $durationSeconds,
        bool $completed,
    ): EpisodeViewProgress {
        $episode = $this->playback->watchableEpisode($catalogTitle, $user, $episodeId);

        abort_if($episode === null, 404);

        $positionSeconds = max(0, min($positionSeconds, $durationSeconds));
        $completionAt = $durationSeconds > 30
            ? min($durationSeconds - 15, (int) floor($durationSeconds * 0.95))
            : (int) floor($durationSeconds * 0.95);
        $completionThreshold = $durationSeconds > 0
            && $positionSeconds >= max(1, $completionAt);

        return EpisodeViewProgress::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'episode_id' => $episode->id,
            ],
            [
                'catalog_title_id' => $catalogTitle->id,
                'position_seconds' => $positionSeconds,
                'duration_seconds' => $durationSeconds,
                'completed_at' => $completed || $completionThreshold ? now() : null,
                'last_watched_at' => now(),
            ],
        );
    }
}
