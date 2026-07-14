<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogUserStateSummary;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\EpisodeViewProgress;
use App\Models\User;
use App\Services\Api\V1\Sync\UserSyncChangePublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CatalogUserStateService
{
    public function __construct(
        private readonly CatalogTitlePlaybackQuery $playback,
        private readonly CatalogPlaybackProgressSession $progressSessions,
        private readonly CatalogPlaybackCompletionRule $completionRule,
        private readonly UserSyncChangePublisher $syncChanges,
    ) {}

    public function state(User $user, CatalogTitle $catalogTitle): ?CatalogTitleUserState
    {
        return CatalogTitleUserState::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($catalogTitle)
            ->first();
    }

    public function summary(CatalogTitle $catalogTitle): CatalogUserStateSummary
    {
        $aggregate = CatalogTitleUserState::query()
            ->whereBelongsTo($catalogTitle)
            ->toBase()
            ->selectRaw('COUNT(CASE WHEN in_watchlist = 1 THEN 1 END) AS watchlist_count')
            ->selectRaw('COUNT(rating) AS rating_count')
            ->selectRaw('AVG(rating) AS rating_average')
            ->first();

        return new CatalogUserStateSummary(
            watchlistCount: (int) ($aggregate?->watchlist_count ?? 0),
            ratingCount: (int) ($aggregate?->rating_count ?? 0),
            ratingAverage: $aggregate?->rating_average !== null ? (float) $aggregate->rating_average : null,
        );
    }

    /** @return array{minimum: int, maximum: int} */
    public function ratingRange(): array
    {
        $minimum = max(1, min(255, (int) config('catalog.user_rating.minimum', 1)));
        $maximum = max($minimum, min(255, (int) config('catalog.user_rating.maximum', 10)));

        return ['minimum' => $minimum, 'maximum' => $maximum];
    }

    /** @return list<int> */
    public function ratingOptions(): array
    {
        $range = $this->ratingRange();

        return range($range['minimum'], $range['maximum']);
    }

    public function ratingValidationMessage(): string
    {
        $range = $this->ratingRange();

        return "Оценка должна быть от {$range['minimum']} до {$range['maximum']}.";
    }

    public function setWatchlist(User $user, CatalogTitle $catalogTitle, bool $inWatchlist): ?CatalogTitleUserState
    {
        $this->authorizeInteraction($user, $catalogTitle);

        return $this->writeState($user, $catalogTitle, ['in_watchlist' => $inWatchlist]);
    }

    public function setRating(User $user, CatalogTitle $catalogTitle, ?int $rating): ?CatalogTitleUserState
    {
        $this->authorizeInteraction($user, $catalogTitle);
        $range = $this->ratingRange();

        if ($rating !== null && ($rating < $range['minimum'] || $rating > $range['maximum'])) {
            throw ValidationException::withMessages(['rating' => $this->ratingValidationMessage()]);
        }

        return $this->writeState($user, $catalogTitle, ['rating' => $rating]);
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
        $this->authorizeInteraction($user, $catalogTitle);
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
            $this->syncChanges->publishProgress($user, $catalogTitle, $episode->id);

            return $progress;
        }, attempts: 3);
    }

    /**
     * @param  array{in_watchlist: bool}|array{rating: int|null}  $attributes
     */
    private function writeState(User $user, CatalogTitle $catalogTitle, array $attributes): ?CatalogTitleUserState
    {
        return DB::transaction(function () use ($user, $catalogTitle, $attributes): ?CatalogTitleUserState {
            $now = now();
            $column = array_key_first($attributes);
            $value = $attributes[$column];
            $shouldCreate = $column === 'in_watchlist' ? $value === true : $value !== null;

            if ($shouldCreate) {
                CatalogTitleUserState::query()->insertOrIgnore([
                    'user_id' => $user->id,
                    'catalog_title_id' => $catalogTitle->id,
                    'in_watchlist' => false,
                    'rating' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $state = CatalogTitleUserState::query()
                ->whereBelongsTo($user)
                ->whereBelongsTo($catalogTitle)
                ->lockForUpdate()
                ->first();

            if ($state === null) {
                return null;
            }

            $currentValue = $column === 'in_watchlist'
                ? (bool) $state->in_watchlist
                : $state->rating;

            if ($currentValue === $value) {
                return $state;
            }

            $versionColumn = $column === 'in_watchlist' ? 'watchlist_version' : 'rating_version';
            $state->forceFill([
                $column => $value,
                $versionColumn => (int) $state->{$versionColumn} + 1,
                'updated_at' => $now,
            ])->save();
            $this->syncChanges->publishTitleState($user, $catalogTitle);

            return $state;
        }, attempts: 3);
    }

    private function authorizeInteraction(User $user, CatalogTitle $catalogTitle): void
    {
        Gate::forUser($user)->authorize('interact', $catalogTitle);
    }
}
