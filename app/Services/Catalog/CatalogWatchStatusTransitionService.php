<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\CatalogWatchStatus;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\User;

final readonly class CatalogWatchStatusTransitionService
{
    public function __construct(private CatalogTitlePlaybackQuery $playback) {}

    public function afterProgress(
        User $user,
        CatalogTitle $catalogTitle,
        EpisodeViewProgress $progress,
        ?CatalogWatchStatus $current,
    ): ?CatalogWatchStatus {
        if (in_array($current, [
            CatalogWatchStatus::Paused,
            CatalogWatchStatus::Dropped,
            CatalogWatchStatus::Completed,
        ], true)) {
            return $current;
        }

        $next = $current;

        if ($next === null || $next === CatalogWatchStatus::Planned) {
            if (! $this->meaningful($progress)) {
                return $next;
            }

            $next = CatalogWatchStatus::Watching;
        }

        if ($next === CatalogWatchStatus::Watching
            && $progress->completed_at !== null
            && $this->allWatchableEpisodesCompleted($user, $catalogTitle)) {
            return CatalogWatchStatus::Completed;
        }

        return $next;
    }

    public function meaningful(EpisodeViewProgress $progress): bool
    {
        return $progress->completed_at !== null
            || (int) $progress->position_seconds >= max(1, (int) config('recommendations.meaningful_progress_seconds', 180))
            || (int) $progress->progress_percent >= max(1, (int) config('recommendations.meaningful_progress_percent', 10));
    }

    private function allWatchableEpisodesCompleted(User $user, CatalogTitle $catalogTitle): bool
    {
        $episode = new Episode;
        $missingCompletion = $this->playback
            ->watchableEpisodes($catalogTitle, $user)
            ->whereNotExists(
                EpisodeViewProgress::query()
                    ->where('user_id', $user->id)
                    ->whereColumn('episode_view_progress.episode_id', $episode->qualifyColumn('id'))
                    ->whereNotNull('completed_at')
                    ->selectRaw('1')
                    ->toBase(),
            );

        return ! (clone $missingCompletion)->exists()
            && $this->playback->watchableEpisodes($catalogTitle, $user)->exists();
    }
}
