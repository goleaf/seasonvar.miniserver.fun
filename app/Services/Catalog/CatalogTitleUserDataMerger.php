<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;

final class CatalogTitleUserDataMerger
{
    public function moveTitle(CatalogTitle $duplicate, CatalogTitle $canonical): void
    {
        if ($duplicate->is($canonical)) {
            return;
        }

        CatalogTitleUserState::query()
            ->where('catalog_title_id', $duplicate->id)
            ->eachById(function (CatalogTitleUserState $incoming) use ($canonical): void {
                $existing = CatalogTitleUserState::query()
                    ->where('catalog_title_id', $canonical->id)
                    ->where('user_id', $incoming->user_id)
                    ->first();

                if ($existing === null) {
                    $incoming->forceFill(['catalog_title_id' => $canonical->id])->save();

                    return;
                }

                $useIncomingRating = $incoming->rating !== null
                    && ($existing->rating === null
                        || $incoming->rating_version > $existing->rating_version
                        || ($incoming->rating_version === $existing->rating_version
                            && $incoming->updated_at?->isAfter($existing->updated_at) === true));
                $existing->forceFill([
                    'in_watchlist' => $existing->in_watchlist || $incoming->in_watchlist,
                    'rating' => $useIncomingRating ? $incoming->rating : $existing->rating,
                    'watchlist_version' => max($existing->watchlist_version, $incoming->watchlist_version),
                    'rating_version' => max($existing->rating_version, $incoming->rating_version),
                ])->save();
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
}
