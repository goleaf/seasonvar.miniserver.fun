<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\PlaybackCompletionSource;
use App\Models\CatalogTitle;
use App\Models\EpisodePlaybackMarker;
use App\Models\EpisodeViewProgress;
use App\Models\User;
use App\Services\Api\V1\Sync\UserSyncChangePublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class CatalogManualPlaybackService
{
    public function __construct(
        private CatalogTitlePlaybackQuery $playback,
        private CatalogUserStateService $userState,
        private UserSyncChangePublisher $syncChanges,
        private CatalogRecommendationCacheInvalidator $recommendationCache,
        private PersonalLibrarySchema $schema,
    ) {}

    public function setWatched(
        User $user,
        CatalogTitle $catalogTitle,
        int $episodeId,
        bool $watched,
    ): ?EpisodeViewProgress {
        $this->assertReady();
        $episode = $this->playback->watchableEpisode($catalogTitle, $user, $episodeId);

        abort_if($episode === null, 404);
        Gate::forUser($user)->authorize('interact', $catalogTitle);
        $this->hitLimit($user, 'watched', 30);

        $changed = false;
        $progress = DB::transaction(function () use ($user, $catalogTitle, $episode, $watched, &$changed): ?EpisodeViewProgress {
            $now = now();

            if ($watched) {
                EpisodeViewProgress::query()->insertOrIgnore([
                    'user_id' => $user->id,
                    'catalog_title_id' => $catalogTitle->id,
                    'episode_id' => $episode->id,
                    'position_seconds' => 0,
                    'duration_seconds' => 0,
                    'progress_percent' => null,
                    'playback_event_sequence' => 0,
                    'last_watched_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $progress = EpisodeViewProgress::query()
                ->whereBelongsTo($user)
                ->whereBelongsTo($episode)
                ->lockForUpdate()
                ->first();

            if ($progress === null) {
                return null;
            }

            if ($watched && $progress->completed_at === null) {
                $progress->forceFill([
                    'catalog_title_id' => $catalogTitle->id,
                    'completed_at' => $now,
                    'completion_source' => PlaybackCompletionSource::Manual,
                    'last_watched_at' => $now,
                ])->save();
                $changed = true;
            } elseif (! $watched && $progress->completion_source === PlaybackCompletionSource::Manual) {
                $progress->forceFill([
                    'completed_at' => null,
                    'completion_source' => null,
                ])->save();
                $changed = true;
            }

            if ($changed) {
                $this->syncChanges->publishProgress($user, $catalogTitle, $episode->id);
            }

            return $progress->refresh();
        }, attempts: 3);

        if ($progress !== null && $changed) {
            $this->userState->synchronizeWatchStatusFromProgress($user, $catalogTitle, $progress);
            $this->recommendationCache->publicSignalsChanged('manual-episode-completion');
        }

        return $progress;
    }

    public function saveMarker(
        User $user,
        CatalogTitle $catalogTitle,
        int $episodeId,
        int $positionSeconds,
    ): EpisodePlaybackMarker {
        $this->assertReady();
        $episode = $this->playback->watchableEpisode($catalogTitle, $user, $episodeId);

        abort_if($episode === null, 404);
        Gate::forUser($user)->authorize('interact', $catalogTitle);
        $positionSeconds = $this->validatedPosition($user, $episode->id, $positionSeconds);
        $this->hitLimit($user, 'marker', 30);

        return DB::transaction(function () use ($user, $catalogTitle, $episode, $positionSeconds): EpisodePlaybackMarker {
            $now = now();

            EpisodePlaybackMarker::query()->insertOrIgnore([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'catalog_title_id' => $catalogTitle->id,
                'episode_id' => $episode->id,
                'position_seconds' => $positionSeconds,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $marker = EpisodePlaybackMarker::query()
                ->whereBelongsTo($user)
                ->whereBelongsTo($episode)
                ->lockForUpdate()
                ->firstOrFail();

            if ($marker->position_seconds === $positionSeconds) {
                return $marker;
            }

            $marker->forceFill([
                'catalog_title_id' => $catalogTitle->id,
                'position_seconds' => $positionSeconds,
                'version' => $marker->version + 1,
            ])->save();

            return $marker;
        }, attempts: 3);
    }

    public function deleteMarker(User $user, string $publicId): void
    {
        $this->assertReady();
        $this->hitLimit($user, 'marker', 30);

        DB::transaction(function () use ($user, $publicId): void {
            $marker = EpisodePlaybackMarker::query()
                ->whereBelongsTo($user)
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->first();

            if ($marker === null) {
                return;
            }

            Gate::forUser($user)->authorize('delete', $marker);
            $marker->delete();
        }, attempts: 3);
    }

    public function markerFor(
        User $user,
        CatalogTitle $catalogTitle,
        int $episodeId,
        ?string $publicId = null,
    ): ?EpisodePlaybackMarker {
        if (! $this->schema->ready()) {
            return null;
        }

        $query = EpisodePlaybackMarker::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($catalogTitle)
            ->where('episode_id', $episodeId);

        if ($publicId !== null) {
            if (! Str::isUuid($publicId)) {
                return null;
            }

            $query->where('public_id', $publicId);
        }

        $marker = $query->first();

        if ($marker !== null) {
            Gate::forUser($user)->authorize('view', $marker);
        }

        return $marker;
    }

    public function progress(User $user, CatalogTitle $catalogTitle, int $episodeId): ?EpisodeViewProgress
    {
        return EpisodeViewProgress::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($catalogTitle)
            ->where('episode_id', $episodeId)
            ->first();
    }

    private function validatedPosition(User $user, int $episodeId, int $positionSeconds): int
    {
        $maximum = max(60, min(604800, (int) config('playback.progress.max_duration_seconds', 86400)));

        if ($positionSeconds < 0 || $positionSeconds > $maximum) {
            throw ValidationException::withMessages([
                'marker' => __('library.errors.marker_position'),
            ]);
        }

        $knownDuration = (int) (EpisodeViewProgress::query()
            ->whereBelongsTo($user)
            ->where('episode_id', $episodeId)
            ->value('duration_seconds') ?? 0);

        return $knownDuration > 0 ? min($positionSeconds, $knownDuration) : $positionSeconds;
    }

    private function assertReady(): void
    {
        if (! $this->schema->ready()) {
            throw ValidationException::withMessages([
                'marker' => __('library.errors.unavailable'),
            ]);
        }
    }

    private function hitLimit(User $user, string $action, int $maximum): void
    {
        $key = "personal-library:{$action}:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, $maximum)) {
            throw ValidationException::withMessages([
                'marker' => __('library.errors.rate_limited'),
            ]);
        }

        RateLimiter::hit($key, 60);
    }
}
