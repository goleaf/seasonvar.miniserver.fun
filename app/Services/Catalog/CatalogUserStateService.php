<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogUserStateSummary;
use App\Enums\CatalogRecommendationFeedback;
use App\Enums\CatalogWatchStatus;
use App\Enums\PlaybackCompletionSource;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\User;
use App\Services\Api\V1\Sync\ApiSyncReadiness;
use App\Services\Api\V1\Sync\UserSyncChangePublisher;
use App\Services\Reviews\ReviewCacheInvalidator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CatalogUserStateService
{
    public function __construct(
        private readonly CatalogTitlePlaybackQuery $playback,
        private readonly CatalogPlaybackProgressSession $progressSessions,
        private readonly CatalogPlaybackCompletionRule $completionRule,
        private readonly ApiSyncReadiness $syncReadiness,
        private readonly UserSyncChangePublisher $syncChanges,
        private readonly ReviewCacheInvalidator $reviewCache,
        private readonly CatalogRecommendationCacheInvalidator $recommendationCache,
        private readonly CatalogWatchStatusTransitionService $watchStatusTransitions,
        private readonly CatalogPersonalUpdateService $personalUpdates,
        private readonly PersonalLibrarySchema $personalLibrarySchema,
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
            watchlistCount: (int) ($aggregate->watchlist_count ?? 0),
            ratingCount: (int) ($aggregate->rating_count ?? 0),
            ratingAverage: $aggregate->rating_average !== null ? (float) $aggregate->rating_average : null,
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

        return __('library.validation.rating', $range);
    }

    public function setWatchlist(User $user, CatalogTitle $catalogTitle, bool $inWatchlist): ?CatalogTitleUserState
    {
        $this->authorizeInteraction($user, $catalogTitle);

        return $this->writeState($user, $catalogTitle, ['in_watchlist' => $inWatchlist], null)['state'];
    }

    public function setRating(User $user, CatalogTitle $catalogTitle, ?int $rating): ?CatalogTitleUserState
    {
        $this->authorizeInteraction($user, $catalogTitle);
        $this->validateRating($rating);

        return $this->writeState($user, $catalogTitle, ['rating' => $rating], null)['state'];
    }

    public function setRecommendationFeedback(
        User $user,
        CatalogTitle $catalogTitle,
        CatalogRecommendationFeedback $feedback,
    ): CatalogTitleUserState {
        $this->authorizeInteraction($user, $catalogTitle);
        $this->assertRecommendationStateSchema();

        $this->hitRecommendationFeedbackLimit($user);

        return $this->writeRecommendationState(
            user: $user,
            catalogTitle: $catalogTitle,
            column: 'recommendation_feedback',
            value: $feedback->value,
            versionColumn: 'recommendation_feedback_version',
            timestamps: ['recommendation_feedback_updated_at' => now()],
        );
    }

    public function undoRecommendationFeedback(User $user, CatalogTitle $catalogTitle): ?CatalogTitleUserState
    {
        $this->authorizeInteraction($user, $catalogTitle);
        $this->assertRecommendationStateSchema();
        $this->hitRecommendationFeedbackLimit($user);

        $state = $this->state($user, $catalogTitle);

        if ($state === null || $state->recommendation_feedback === null) {
            return $state;
        }

        return $this->writeRecommendationState(
            user: $user,
            catalogTitle: $catalogTitle,
            column: 'recommendation_feedback',
            value: null,
            versionColumn: 'recommendation_feedback_version',
            timestamps: ['recommendation_feedback_updated_at' => now()],
        );
    }

    public function setWatchStatus(
        User $user,
        CatalogTitle $catalogTitle,
        ?CatalogWatchStatus $status,
    ): CatalogTitleUserState {
        $this->authorizeInteraction($user, $catalogTitle);
        $this->assertRecommendationStateSchema();

        return $this->writeRecommendationState(
            user: $user,
            catalogTitle: $catalogTitle,
            column: 'watch_status',
            value: $status?->value,
            versionColumn: 'watch_status_version',
            timestamps: $this->semanticTimestamp('watch_status_updated_at'),
        );
    }

    /** @return array{applied: bool, state: CatalogTitleUserState|null, version: int} */
    public function setWatchlistAtVersion(
        User $user,
        CatalogTitle $catalogTitle,
        bool $inWatchlist,
        int $expectedVersion,
    ): array {
        $this->authorizeInteraction($user, $catalogTitle);

        return $this->writeState(
            $user,
            $catalogTitle,
            ['in_watchlist' => $inWatchlist],
            $expectedVersion,
        );
    }

    /** @return array{applied: bool, state: CatalogTitleUserState|null, version: int} */
    public function setRatingAtVersion(
        User $user,
        CatalogTitle $catalogTitle,
        ?int $rating,
        int $expectedVersion,
    ): array {
        $this->authorizeInteraction($user, $catalogTitle);
        $this->validateRating($rating);

        return $this->writeState($user, $catalogTitle, ['rating' => $rating], $expectedVersion);
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

        $shouldSynchronize = false;
        $progress = DB::transaction(function () use (
            $user,
            $catalogTitle,
            $episode,
            $media,
            $session,
            $eventSequence,
            $positionSeconds,
            $mediaDuration,
            $ended,
            &$shouldSynchronize,
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
            $wasMeaningful = $this->isMeaningfulProgress($progress);
            $wasCompleted = $progress->completed_at !== null;
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
            $completionSource = $this->personalLibrarySchema->ready()
                ? $progress->completion_source
                : null;

            if ($completionSource === PlaybackCompletionSource::Anonymous) {
                $completionSource = null;
            }

            if ($completedAt === null && $this->completionRule->isComplete($trustedPosition, $trustedDuration, $ended)) {
                $completedAt = $now;
                $completionSource = PlaybackCompletionSource::Playback;
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
                ...($this->personalLibrarySchema->ready() ? ['completion_source' => $completionSource] : []),
                'last_watched_at' => $now,
            ])->save();
            $this->syncChanges->publishProgress($user, $catalogTitle, $episode->id);

            if ((! $wasMeaningful && $this->isMeaningfulProgress($progress))
                || (! $wasCompleted && $progress->completed_at !== null)) {
                $shouldSynchronize = true;
                $this->recommendationCache->publicSignalsChanged('meaningful-progress');
            }

            return $progress;
        }, attempts: 3);

        if ($shouldSynchronize) {
            $this->synchronizeWatchStatusFromProgress($user, $catalogTitle, $progress);
            $this->personalUpdates->acknowledge($user, $catalogTitle, enforceRateLimit: false);
        }

        return $progress;
    }

    /**
     * @param  list<array{episode_id: int, position: int, duration: int, completed: bool, updated_at: int}>  $entries
     * @return list<int>
     */
    public function migrateAnonymousProgress(User $user, array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        abort_unless($user->hasVerifiedEmail(), 403);
        abort_unless($this->personalLibrarySchema->ready(), 503, __('library.errors.unavailable'));
        $maximumDuration = max(60, min(604800, (int) config('playback.progress.max_duration_seconds', 86400)));
        $positionTolerance = max(0, min(60, (int) config('playback.progress.position_tolerance_seconds', 5)));
        $now = now();
        $oldest = $now->copy()->subDays(30);
        $newest = $now->copy()->addMinutes(5);
        $candidates = collect($entries)
            ->take(50)
            ->map(function (array $entry) use ($maximumDuration, $newest, $oldest, $positionTolerance): ?array {
                $episodeId = $entry['episode_id'];
                $position = $entry['position'];
                $duration = $entry['duration'];
                $updatedAtMilliseconds = $entry['updated_at'];

                if ($episodeId < 1
                    || $position < 1
                    || $position > $maximumDuration
                    || $duration < 0
                    || $duration > $maximumDuration
                    || ($duration > 0 && $position > $duration + $positionTolerance)
                    || $updatedAtMilliseconds < 1) {
                    return null;
                }

                $updatedAt = CarbonImmutable::createFromTimestampUTC($updatedAtMilliseconds / 1000);

                if ($updatedAt->isBefore($oldest) || $updatedAt->isAfter($newest)) {
                    return null;
                }

                return [
                    'episode_id' => $episodeId,
                    'position' => $duration > 0 ? min($position, $duration) : $position,
                    'duration' => $duration,
                    'updated_at' => $updatedAt,
                ];
            })
            ->filter(fn (?array $entry): bool => $entry !== null)
            ->sortByDesc(fn (array $entry): int => $entry['updated_at']->getTimestamp())
            ->unique('episode_id')
            ->values();

        if ($candidates->isEmpty()) {
            return [];
        }

        $episode = new Episode;
        $episodes = $this->playback
            ->watchableEpisodesForVisibleTitles($user)
            ->whereIn($episode->qualifyColumn('id'), $candidates->pluck('episode_id'))
            ->get()
            ->keyBy('id');
        $titles = CatalogTitle::query()
            ->whereKey($episodes->pluck('playback_catalog_title_id')->unique())
            ->get()
            ->keyBy('id');

        /** @var list<EpisodeViewProgress> $changed */
        $changed = DB::transaction(function () use ($candidates, $episodes, $now, $titles, $user): array {
            $existing = EpisodeViewProgress::query()
                ->whereBelongsTo($user)
                ->whereIn('episode_id', $episodes->keys())
                ->lockForUpdate()
                ->get()
                ->keyBy('episode_id');
            $changed = [];

            foreach ($candidates as $candidate) {
                $episode = $episodes->get($candidate['episode_id']);

                if ($episode === null) {
                    continue;
                }

                $title = $titles->get((int) $episode->getAttribute('playback_catalog_title_id'));
                $progress = $existing->get($episode->id);

                if ($title === null
                    || ($progress !== null && $progress->completion_source !== PlaybackCompletionSource::Anonymous)) {
                    continue;
                }

                if ($progress === null) {
                    EpisodeViewProgress::query()->insertOrIgnore([
                        'user_id' => $user->id,
                        'catalog_title_id' => $title->id,
                        'episode_id' => $episode->id,
                        'position_seconds' => 0,
                        'duration_seconds' => 0,
                        'progress_percent' => 0,
                        'first_started_at' => $candidate['updated_at'],
                        'playback_event_sequence' => 0,
                        'completion_source' => PlaybackCompletionSource::Anonymous->value,
                        'last_watched_at' => $candidate['updated_at'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $progress = EpisodeViewProgress::query()
                        ->whereBelongsTo($user)
                        ->where('episode_id', $episode->id)
                        ->lockForUpdate()
                        ->first();

                    if ($progress === null || $progress->completion_source !== PlaybackCompletionSource::Anonymous) {
                        continue;
                    }

                    $existing->put($episode->id, $progress);
                }

                if (! $this->anonymousCandidateAdvances(
                    $progress,
                    $candidate['position'],
                    $candidate['duration'],
                    $candidate['updated_at'],
                )) {
                    continue;
                }

                $progress->forceFill([
                    'catalog_title_id' => $title->id,
                    'position_seconds' => $candidate['position'],
                    'duration_seconds' => $candidate['duration'],
                    'progress_percent' => $this->completionRule->percentage($candidate['position'], $candidate['duration']),
                    'first_started_at' => $progress->first_started_at ?? $candidate['updated_at'],
                    'playback_session_id' => null,
                    'playback_event_sequence' => 0,
                    'completed_at' => null,
                    'completion_source' => PlaybackCompletionSource::Anonymous,
                    'last_watched_at' => $candidate['updated_at'],
                ])->save();
                $this->syncChanges->publishProgress($user, $title, $episode->id);
                $changed[] = $progress->refresh();
            }

            return $changed;
        }, attempts: 3);

        /** @var array<int, list<EpisodeViewProgress>> $changedByTitle */
        $changedByTitle = [];
        foreach ($changed as $progress) {
            $changedByTitle[(int) $progress->catalog_title_id][] = $progress;
        }

        $meaningful = false;

        foreach ($changedByTitle as $catalogTitleId => $titleProgress) {
            $title = $titles->get($catalogTitleId);

            if ($title === null) {
                continue;
            }

            $representative = $titleProgress[0];

            foreach ($titleProgress as $progress) {
                if ($this->isMeaningfulProgress($progress)) {
                    $meaningful = true;
                    $representative = $progress;

                    break;
                }
            }

            $this->synchronizeWatchStatusFromProgress($user, $title, $representative);
            $this->personalUpdates->acknowledge($user, $title, enforceRateLimit: false);
        }

        if ($meaningful) {
            $this->recommendationCache->publicSignalsChanged('anonymous-progress-migration');
        }

        return $episodes->keys()
            ->map(fn (mixed $episodeId): int => (int) $episodeId)
            ->values()
            ->all();
    }

    public function restartProgress(
        User $user,
        CatalogTitle $catalogTitle,
        int $episodeId,
        string $playbackSessionToken,
    ): ?EpisodeViewProgress {
        $this->authorizeInteraction($user, $catalogTitle);
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

        return DB::transaction(function () use ($user, $catalogTitle, $episode, $media, $session): ?EpisodeViewProgress {
            $progress = EpisodeViewProgress::query()
                ->whereBelongsTo($user)
                ->whereBelongsTo($episode)
                ->lockForUpdate()
                ->first();

            if ($progress === null) {
                return null;
            }

            $wasMeaningful = $this->isMeaningfulProgress($progress);
            $progress->forceFill([
                'catalog_title_id' => $catalogTitle->id,
                'licensed_media_id' => $media->id,
                'position_seconds' => 0,
                'progress_percent' => 0,
                'completed_at' => null,
                ...($this->personalLibrarySchema->ready() ? ['completion_source' => null] : []),
                'playback_session_id' => $session->id,
                'playback_event_sequence' => 0,
                'last_watched_at' => now(),
            ])->save();
            $this->syncChanges->publishProgress($user, $catalogTitle, $episode->id);

            if ($wasMeaningful) {
                $this->recommendationCache->publicSignalsChanged('progress-restarted');
            }

            return $progress;
        }, attempts: 3);
    }

    public function synchronizeWatchStatusFromProgress(
        User $user,
        CatalogTitle $catalogTitle,
        EpisodeViewProgress $progress,
    ): void {
        $this->authorizeInteraction($user, $catalogTitle);
        $rawCurrent = $this->state($user, $catalogTitle)?->getRawOriginal('watch_status');
        $current = is_string($rawCurrent) ? CatalogWatchStatus::tryFrom($rawCurrent) : null;
        $next = $this->watchStatusTransitions->afterProgress($user, $catalogTitle, $progress, $current);

        if ($next === $current) {
            return;
        }

        $this->writeRecommendationState(
            user: $user,
            catalogTitle: $catalogTitle,
            column: 'watch_status',
            value: $next?->value,
            versionColumn: 'watch_status_version',
            timestamps: $this->semanticTimestamp('watch_status_updated_at'),
        );
    }

    /**
     * @param  array{in_watchlist: bool}|array{rating: int|null}  $attributes
     * @return array{applied: bool, state: CatalogTitleUserState|null, version: int}
     */
    private function writeState(
        User $user,
        CatalogTitle $catalogTitle,
        array $attributes,
        ?int $expectedVersion,
    ): array {
        return DB::transaction(function () use ($user, $catalogTitle, $attributes, $expectedVersion): array {
            $now = now();
            $column = array_key_first($attributes);
            $value = $attributes[$column];
            $shouldCreate = $column === 'in_watchlist' ? $value === true : $value !== null;
            $versionColumn = $column === 'in_watchlist' ? 'watchlist_version' : 'rating_version';
            $versionsAvailable = $this->syncReadiness->stateVersionsAvailable();
            $state = CatalogTitleUserState::query()
                ->whereBelongsTo($user)
                ->whereBelongsTo($catalogTitle)
                ->lockForUpdate()
                ->first();
            $version = $versionsAvailable && $state !== null
                ? (int) $state->getAttribute($versionColumn)
                : 0;

            if (! $versionsAvailable && $expectedVersion !== null) {
                return ['applied' => false, 'state' => $state, 'version' => 0];
            }

            if ($expectedVersion !== null && $version !== $expectedVersion) {
                return ['applied' => false, 'state' => $state, 'version' => $version];
            }

            if ($state === null && $shouldCreate) {
                CatalogTitleUserState::query()->insertOrIgnore([
                    'user_id' => $user->id,
                    'catalog_title_id' => $catalogTitle->id,
                    'in_watchlist' => false,
                    'rating' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $state = CatalogTitleUserState::query()
                    ->whereBelongsTo($user)
                    ->whereBelongsTo($catalogTitle)
                    ->lockForUpdate()
                    ->firstOrFail();
                $version = $versionsAvailable ? (int) $state->{$versionColumn} : 0;

                if ($expectedVersion !== null && $version !== $expectedVersion) {
                    return ['applied' => false, 'state' => $state, 'version' => $version];
                }
            }

            if ($state === null) {
                return ['applied' => true, 'state' => null, 'version' => 0];
            }

            $currentValue = $column === 'in_watchlist'
                ? (bool) $state->in_watchlist
                : $state->rating;

            if ($currentValue === $value) {
                return ['applied' => true, 'state' => $state, 'version' => $version];
            }

            $updates = [
                $column => $value,
                'updated_at' => $now,
            ];

            if ($versionsAvailable) {
                $updates[$versionColumn] = ++$version;
            }

            $timestampColumn = $column === 'in_watchlist'
                ? 'watchlist_updated_at'
                : 'rating_updated_at';

            if (Schema::hasColumn('catalog_title_user_states', $timestampColumn)) {
                $updates[$timestampColumn] = $now;
            }

            $state->forceFill($updates)->save();
            $this->syncChanges->publishTitleState($user, $catalogTitle);

            if ($column === 'rating') {
                $this->reviewCache->titleChanged((int) $catalogTitle->id, recommendations: true, api: true);
            } elseif ($column === 'in_watchlist') {
                $this->recommendationCache->publicSignalsChanged('watchlist-change');
            }

            return ['applied' => true, 'state' => $state, 'version' => $version];
        }, attempts: 3);
    }

    private function validateRating(?int $rating): void
    {
        $range = $this->ratingRange();

        if ($rating !== null && ($rating < $range['minimum'] || $rating > $range['maximum'])) {
            throw ValidationException::withMessages(['rating' => $this->ratingValidationMessage()]);
        }
    }

    private function isMeaningfulProgress(EpisodeViewProgress $progress): bool
    {
        return $this->watchStatusTransitions->meaningful($progress);
    }

    private function anonymousCandidateAdvances(
        EpisodeViewProgress $progress,
        int $position,
        int $duration,
        CarbonInterface $updatedAt,
    ): bool {
        $candidateRank = [
            $this->completionRule->percentage($position, $duration) ?? 0,
            $position,
        ];
        $currentRank = [
            (int) ($progress->progress_percent ?? 0),
            (int) $progress->position_seconds,
        ];

        if ($candidateRank <= $currentRank) {
            return false;
        }

        return (int) $progress->position_seconds === 0
            || $updatedAt->isAfter($progress->last_watched_at);
    }

    /** @param array<string, mixed> $timestamps */
    private function writeRecommendationState(
        User $user,
        CatalogTitle $catalogTitle,
        string $column,
        ?string $value,
        string $versionColumn,
        array $timestamps = [],
    ): CatalogTitleUserState {
        return DB::transaction(function () use ($catalogTitle, $column, $timestamps, $user, $value, $versionColumn): CatalogTitleUserState {
            $now = now();

            CatalogTitleUserState::query()->insertOrIgnore([
                'user_id' => $user->id,
                'catalog_title_id' => $catalogTitle->id,
                'in_watchlist' => false,
                'rating' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $state = CatalogTitleUserState::query()
                ->whereBelongsTo($user)
                ->whereBelongsTo($catalogTitle)
                ->lockForUpdate()
                ->firstOrFail();
            $current = $state->getRawOriginal($column);

            if ($current === $value) {
                return $state;
            }

            $state->forceFill([
                $column => $value,
                $versionColumn => (int) $state->getAttribute($versionColumn) + 1,
                ...$timestamps,
                'updated_at' => $now,
            ])->save();
            $this->syncChanges->publishTitleState($user, $catalogTitle);

            return $state->refresh();
        }, attempts: 3);
    }

    private function assertRecommendationStateSchema(): void
    {
        if (! Schema::hasColumns('catalog_title_user_states', [
            'recommendation_feedback',
            'recommendation_feedback_version',
            'watch_status',
            'watch_status_version',
        ])) {
            throw ValidationException::withMessages([
                'recommendationFeedback' => __('recommendations.feedback.unavailable'),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function semanticTimestamp(string $column): array
    {
        return Schema::hasColumn('catalog_title_user_states', $column)
            ? [$column => now()]
            : [];
    }

    private function hitRecommendationFeedbackLimit(User $user): void
    {
        $key = 'recommendation-feedback:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 30)) {
            throw ValidationException::withMessages([
                'recommendationFeedback' => __('recommendations.feedback.rate_limited'),
            ]);
        }

        RateLimiter::hit($key, 60);
    }

    private function authorizeInteraction(User $user, CatalogTitle $catalogTitle): void
    {
        Gate::forUser($user)->authorize('interact', $catalogTitle);
    }
}
