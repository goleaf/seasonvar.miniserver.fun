<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogPrimaryAction;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\User;

class CatalogPrimaryActionResolver
{
    public function __construct(
        private readonly CatalogTitlePlaybackQuery $playback,
        private readonly CatalogPlaybackCompletionRule $completionRule,
    ) {}

    public function resolve(CatalogTitle $catalogTitle, ?User $user): CatalogPrimaryAction
    {
        if ($user !== null) {
            $progress = EpisodeViewProgress::query()
                ->whereBelongsTo($user)
                ->whereBelongsTo($catalogTitle)
                ->whereIn('episode_id', $this->playback->watchableEpisodes($catalogTitle, $user)->select((new Episode)->qualifyColumn('id')))
                ->latest('last_watched_at')
                ->latest()
                ->first();

            if ($progress !== null && ($progress->completed_at === null || $this->completionRule->isInProgress(
                $progress->position_seconds,
                $progress->duration_seconds,
            ))) {
                $episode = $this->playback->watchableEpisode($catalogTitle, $user, $progress->episode_id);

                if ($episode !== null) {
                    $label = __('catalog.player.continue_episode', ['number' => $episode->number]);

                    return $this->episodeAction('continue', $label, $catalogTitle, $user, $episode, $progress->position_seconds);
                }
            }

            if ($progress !== null && $progress->completed_at !== null) {
                $completedEpisode = $this->playback->watchableEpisode($catalogTitle, $user, $progress->episode_id);
                $nextEpisode = $completedEpisode !== null
                    ? $this->playback->nextWatchableEpisode($catalogTitle, $user, $completedEpisode)
                    : null;

                if ($nextEpisode !== null) {
                    $label = __('catalog.player.next_episode', ['number' => $nextEpisode->number]);

                    return $this->episodeAction('next', $label, $catalogTitle, $user, $nextEpisode);
                }

                $firstEpisode = $this->playback->firstWatchableEpisode($catalogTitle, $user);

                if ($firstEpisode !== null) {
                    return $this->episodeAction('replay', __('catalog.player.replay'), $catalogTitle, $user, $firstEpisode);
                }
            }
        }

        $firstEpisode = $this->playback->firstWatchableEpisode($catalogTitle, $user);

        if ($firstEpisode !== null) {
            $label = __('catalog.player.start_episode', ['number' => $firstEpisode->number]);

            return $this->episodeAction('start', $label, $catalogTitle, $user, $firstEpisode);
        }

        $titleMedia = $this->playback->titleMedia($catalogTitle, $user);

        if ($titleMedia !== null) {
            return new CatalogPrimaryAction(
                type: 'title-media',
                label: __('catalog.player.watch_available'),
                mediaId: $titleMedia->id,
            );
        }

        return new CatalogPrimaryAction(type: 'unavailable', label: __('catalog.player.unavailable'));
    }

    private function episodeAction(
        string $type,
        string $label,
        CatalogTitle $catalogTitle,
        ?User $user,
        Episode $episode,
        int $positionSeconds = 0,
    ): CatalogPrimaryAction {
        $media = $this->playback->bestMediaForEpisode($catalogTitle, $user, $episode);

        return new CatalogPrimaryAction(
            type: $type,
            label: $label,
            seasonId: $episode->season_id,
            episodeId: $episode->id,
            mediaId: $media?->id,
            positionSeconds: $positionSeconds,
        );
    }
}
