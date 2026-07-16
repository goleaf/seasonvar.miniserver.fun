<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\CatalogRecommendationFeedback;
use App\Enums\CatalogWatchStatus;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

final class CatalogTitleUserDataMerger
{
    public function moveTitle(CatalogTitle $duplicate, CatalogTitle $canonical): void
    {
        if ($duplicate->is($canonical)) {
            return;
        }

        $recommendationStateAvailable = Schema::hasColumns('catalog_title_user_states', [
            'recommendation_feedback',
            'recommendation_feedback_version',
            'recommendation_feedback_updated_at',
            'watch_status',
            'watch_status_version',
        ]);
        $semanticTimestampsAvailable = Schema::hasColumns('catalog_title_user_states', [
            'watchlist_updated_at',
            'rating_updated_at',
            'watch_status_updated_at',
        ]);

        CatalogTitleUserState::query()
            ->where('catalog_title_id', $duplicate->id)
            ->eachById(function (CatalogTitleUserState $incoming) use (
                $canonical,
                $recommendationStateAvailable,
                $semanticTimestampsAvailable,
            ): void {
                $existing = CatalogTitleUserState::query()
                    ->where('catalog_title_id', $canonical->id)
                    ->where('user_id', $incoming->user_id)
                    ->first();

                if ($existing === null) {
                    $incoming->forceFill(['catalog_title_id' => $canonical->id])->save();

                    return;
                }

                $useIncomingRating = $this->shouldUseIncomingRating(
                    $existing,
                    $incoming,
                    $semanticTimestampsAvailable,
                );
                $updates = [
                    'in_watchlist' => $existing->in_watchlist || $incoming->in_watchlist,
                    'rating' => $useIncomingRating ? $incoming->rating : $existing->rating,
                    'watchlist_version' => max($existing->watchlist_version, $incoming->watchlist_version),
                    'rating_version' => max($existing->rating_version, $incoming->rating_version),
                ];

                if ($semanticTimestampsAvailable) {
                    $updates['watchlist_updated_at'] = $this->latestTimestamp([
                        $existing->in_watchlist ? $this->signalTimestamp($existing, 'watchlist_updated_at') : null,
                        $incoming->in_watchlist ? $this->signalTimestamp($incoming, 'watchlist_updated_at') : null,
                    ]);
                    $updates['rating_updated_at'] = ($useIncomingRating ? $incoming->rating : $existing->rating) !== null
                        ? $this->signalTimestamp(
                            $useIncomingRating ? $incoming : $existing,
                            'rating_updated_at',
                        )
                        : null;
                }

                if ($recommendationStateAvailable) {
                    $feedback = $this->preferredSignal(
                        $existing,
                        $incoming,
                        'recommendation_feedback',
                        'recommendation_feedback_updated_at',
                        [
                            CatalogRecommendationFeedback::NotInterested->value => 1,
                            CatalogRecommendationFeedback::Blacklisted->value => 2,
                        ],
                    );
                    $status = $this->preferredSignal(
                        $existing,
                        $incoming,
                        'watch_status',
                        $semanticTimestampsAvailable ? 'watch_status_updated_at' : null,
                        [
                            CatalogWatchStatus::Planned->value => 1,
                            CatalogWatchStatus::Watching->value => 2,
                            CatalogWatchStatus::Completed->value => 3,
                            CatalogWatchStatus::Dropped->value => 4,
                        ],
                    );
                    $updates['recommendation_feedback'] = $feedback['value'];
                    $updates['recommendation_feedback_version'] = max(
                        $existing->recommendationFeedbackVersion(),
                        $incoming->recommendationFeedbackVersion(),
                    );
                    $updates['recommendation_feedback_updated_at'] = $feedback['timestamp'];
                    $updates['watch_status'] = $status['value'];
                    $updates['watch_status_version'] = max(
                        $existing->watchStatusVersion(),
                        $incoming->watchStatusVersion(),
                    );

                    if ($semanticTimestampsAvailable) {
                        $updates['watch_status_updated_at'] = $status['timestamp'];
                    }
                }

                $existing->forceFill($updates)->save();
                $incoming->delete();
            });

        EpisodeViewProgress::query()
            ->where('catalog_title_id', $duplicate->id)
            ->update([
                'catalog_title_id' => $canonical->id,
                'updated_at' => now(),
            ]);
    }

    public function moveEpisode(
        Episode $duplicate,
        Episode $canonical,
        CatalogTitle $canonicalTitle,
    ): void {
        EpisodeViewProgress::query()
            ->where('episode_id', $duplicate->id)
            ->eachById(function (EpisodeViewProgress $incoming) use ($duplicate, $canonical, $canonicalTitle): void {
                if ($duplicate->is($canonical)) {
                    $incoming->forceFill(['catalog_title_id' => $canonicalTitle->id])->save();

                    return;
                }

                $existing = EpisodeViewProgress::query()
                    ->where('episode_id', $canonical->id)
                    ->where('user_id', $incoming->user_id)
                    ->first();

                if ($existing === null) {
                    $incoming->forceFill([
                        'catalog_title_id' => $canonicalTitle->id,
                        'episode_id' => $canonical->id,
                    ])->save();

                    return;
                }

                $advanced = $this->advancedProgress($existing, $incoming);
                $recent = $incoming->last_watched_at->isAfter($existing->last_watched_at)
                    ? $incoming
                    : $existing;
                $existing->forceFill([
                    'catalog_title_id' => $canonicalTitle->id,
                    'position_seconds' => $advanced->position_seconds,
                    'duration_seconds' => $advanced->duration_seconds,
                    'progress_percent' => $advanced->progress_percent,
                    'licensed_media_id' => $recent->licensed_media_id ?? $advanced->licensed_media_id,
                    'first_started_at' => collect([
                        $existing->first_started_at,
                        $incoming->first_started_at,
                    ])->filter()->min(),
                    'playback_session_id' => $recent->playback_session_id,
                    'playback_event_sequence' => $recent->playback_event_sequence,
                    'completed_at' => collect([
                        $existing->completed_at,
                        $incoming->completed_at,
                    ])->filter()->min(),
                    'last_watched_at' => collect([
                        $existing->last_watched_at,
                        $incoming->last_watched_at,
                    ])->filter()->max(),
                ])->save();
                $incoming->delete();
            });
    }

    private function advancedProgress(
        EpisodeViewProgress $existing,
        EpisodeViewProgress $incoming,
    ): EpisodeViewProgress {
        $existingRank = [
            $existing->completed_at !== null ? 1 : 0,
            (int) ($existing->progress_percent ?? 0),
            (int) $existing->position_seconds,
        ];
        $incomingRank = [
            $incoming->completed_at !== null ? 1 : 0,
            (int) ($incoming->progress_percent ?? 0),
            (int) $incoming->position_seconds,
        ];

        return $incomingRank > $existingRank ? $incoming : $existing;
    }

    private function shouldUseIncomingRating(
        CatalogTitleUserState $existing,
        CatalogTitleUserState $incoming,
        bool $semanticTimestampsAvailable,
    ): bool {
        if ($incoming->rating === null) {
            return false;
        }

        if ($existing->rating === null || $incoming->rating_version > $existing->rating_version) {
            return true;
        }

        if ($incoming->rating_version !== $existing->rating_version) {
            return false;
        }

        $column = $semanticTimestampsAvailable ? 'rating_updated_at' : null;
        $incomingTimestamp = $this->comparisonTimestamp($incoming, $column);
        $existingTimestamp = $this->comparisonTimestamp($existing, $column);

        return $incomingTimestamp !== null
            && ($existingTimestamp === null || $incomingTimestamp->isAfter($existingTimestamp));
    }

    /**
     * @param  array<string, int>  $priorities
     * @return array{value: string|null, timestamp: CarbonInterface|null}
     */
    private function preferredSignal(
        CatalogTitleUserState $existing,
        CatalogTitleUserState $incoming,
        string $column,
        ?string $timestampColumn,
        array $priorities,
    ): array {
        $selected = [
            'value' => null,
            'timestamp' => null,
            'comparison_timestamp' => null,
            'priority' => 0,
        ];

        foreach ([$existing, $incoming] as $state) {
            $rawValue = $state->getRawOriginal($column);
            $value = is_string($rawValue) && isset($priorities[$rawValue]) ? $rawValue : null;
            $priority = $value !== null ? $priorities[$value] : 0;
            $timestamp = $value !== null && $timestampColumn !== null
                ? $this->signalTimestamp($state, $timestampColumn)
                : null;
            $comparisonTimestamp = $value !== null
                ? $this->comparisonTimestamp($state, $timestampColumn)
                : null;
            $isNewer = $comparisonTimestamp !== null
                && ($selected['comparison_timestamp'] === null
                    || $comparisonTimestamp->isAfter($selected['comparison_timestamp']));

            if ($priority > $selected['priority'] || ($priority === $selected['priority'] && $isNewer)) {
                $selected = [
                    'value' => $value,
                    'timestamp' => $timestamp,
                    'comparison_timestamp' => $comparisonTimestamp,
                    'priority' => $priority,
                ];
            }
        }

        return [
            'value' => $selected['value'],
            'timestamp' => $selected['timestamp'],
        ];
    }

    private function signalTimestamp(CatalogTitleUserState $state, string $column): ?CarbonInterface
    {
        $timestamp = $state->getAttribute($column);

        return $timestamp instanceof CarbonInterface ? $timestamp : null;
    }

    private function comparisonTimestamp(
        CatalogTitleUserState $state,
        ?string $semanticColumn,
    ): ?CarbonInterface {
        if ($semanticColumn !== null) {
            $semanticTimestamp = $this->signalTimestamp($state, $semanticColumn);

            if ($semanticTimestamp !== null) {
                return $semanticTimestamp;
            }
        }

        return $state->updated_at instanceof CarbonInterface ? $state->updated_at : null;
    }

    /** @param list<CarbonInterface|null> $timestamps */
    private function latestTimestamp(array $timestamps): ?CarbonInterface
    {
        $latest = null;

        foreach ($timestamps as $timestamp) {
            if ($timestamp !== null && ($latest === null || $timestamp->isAfter($latest))) {
                $latest = $timestamp;
            }
        }

        return $latest;
    }
}
