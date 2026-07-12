<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\EpisodeViewProgress;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CatalogUserStateService
{
    public function __construct(
        private readonly CatalogTitlePlaybackQuery $playback,
        private readonly CatalogPlaybackProgressSession $progressSessions,
        private readonly CatalogPlaybackCompletionRule $completionRule,
    ) {}

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
        string $playbackSessionToken,
        int $eventSequence,
        int $positionSeconds,
        int $reportedDurationSeconds,
        bool $ended,
    ): ?EpisodeViewProgress {
        $maximumDuration = max(60, min(604800, (int) config('playback.progress.max_duration_seconds', 86400)));
        $positionTolerance = max(0, min(60, (int) config('playback.progress.position_tolerance_seconds', 5)));

        if ($eventSequence < 1
            || $positionSeconds < 0
            || $positionSeconds > $maximumDuration
            || $reportedDurationSeconds < 0
            || $reportedDurationSeconds > $maximumDuration
            || ($reportedDurationSeconds > 0 && $positionSeconds > $reportedDurationSeconds + $positionTolerance)) {
            return null;
        }

        $session = $this->progressSessions->resolve(
            $playbackSessionToken,
            $user,
            $catalogTitle,
            $episodeId,
        );

        if ($session === null) {
            return null;
        }

        $episode = $this->playback->watchableEpisode($catalogTitle, $user, $episodeId);

        abort_if($episode === null, 404);

        $media = $this->playback->findAvailableMedia($catalogTitle, $user, $session->mediaId);

        if ($media === null || (int) $media->episode_id !== $episode->id) {
            return null;
        }

        $mediaDuration = (int) ($media->duration_seconds ?? 0);
        $mediaDuration = $mediaDuration > 0 && $mediaDuration <= $maximumDuration ? $mediaDuration : 0;

        if ($mediaDuration > 0 && $positionSeconds > $mediaDuration + $positionTolerance) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $catalogTitle,
            $episode,
            $media,
            $session,
            $eventSequence,
            $positionSeconds,
            $mediaDuration,
            $ended,
        ): EpisodeViewProgress {
            $now = now();

            EpisodeViewProgress::query()->insertOrIgnore([
                'user_id' => $user->id,
                'catalog_title_id' => $catalogTitle->id,
                'episode_id' => $episode->id,
                'position_seconds' => 0,
                'duration_seconds' => 0,
                'playback_event_sequence' => 0,
                'last_watched_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $progress = EpisodeViewProgress::query()
                ->whereBelongsTo($user)
                ->whereBelongsTo($episode)
                ->lockForUpdate()
                ->firstOrFail();
            $storedSessionId = $progress->playback_session_id;

            if (is_string($storedSessionId) && strcmp($session->id, $storedSessionId) < 0) {
                return $progress;
            }

            if ($storedSessionId === $session->id && $eventSequence <= $progress->playback_event_sequence) {
                return $progress;
            }

            $trustedDuration = $mediaDuration;
            $trustedPosition = $trustedDuration > 0
                ? min($positionSeconds, $trustedDuration)
                : $positionSeconds;
            $completedAt = $progress->completed_at;

            if ($completedAt === null && $this->completionRule->isComplete($trustedPosition, $trustedDuration, $ended)) {
                $completedAt = $now;
            }

            $progress->forceFill([
                'catalog_title_id' => $catalogTitle->id,
                'licensed_media_id' => $media->id,
                'position_seconds' => $trustedPosition,
                'duration_seconds' => $trustedDuration,
                'progress_percent' => $this->completionRule->percentage($trustedPosition, $trustedDuration),
                'first_started_at' => $progress->first_started_at ?? $now,
                'playback_session_id' => $session->id,
                'playback_event_sequence' => $eventSequence,
                'completed_at' => $completedAt,
                'last_watched_at' => $now,
            ])->save();

            return $progress;
        }, attempts: 3);
    }
}
