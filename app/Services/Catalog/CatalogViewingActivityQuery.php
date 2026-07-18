<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogContinueWatchingItem;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\Season;
use App\Models\User;
use App\Services\Auth\AccountDateTimeFormatter;
use App\Services\Auth\AccountSettingsService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CatalogViewingActivityQuery
{
    public function __construct(
        private readonly CatalogTitlePlaybackQuery $playback,
        private readonly CatalogPlaybackCompletionRule $completionRule,
        private readonly AccountSettingsService $accountSettings,
        private readonly AccountDateTimeFormatter $dateTimes,
        private readonly PlaybackTimeFormatter $times,
    ) {}

    /** @return Collection<int, CatalogContinueWatchingItem> */
    public function continueWatching(User $user, int $limit = 12): Collection
    {
        $limit = max(1, min(24, $limit));
        $progress = new EpisodeViewProgress;
        $episode = new Episode;
        $season = new Season;
        $progressTable = $progress->getTable();
        $episodeTable = $episode->getTable();
        $seasonTable = $season->getTable();

        $rankedActivity = EpisodeViewProgress::query()
            ->whereBelongsTo($user)
            ->whereNotNull('first_started_at')
            ->select([
                'id',
                'catalog_title_id',
                'episode_id',
                'position_seconds',
                'duration_seconds',
                'progress_percent',
                'completed_at',
                'last_watched_at',
            ])
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY {$progressTable}.catalog_title_id ORDER BY {$progressTable}.last_watched_at DESC, {$progressTable}.id DESC) AS activity_rank");

        $latestActivity = DB::query()
            ->fromSub($rankedActivity->toBase(), 'ranked_viewing_activity')
            ->where('activity_rank', 1);

        $episodeOrder = implode(', ', [
            "{$seasonTable}.sort_order",
            "CASE WHEN {$seasonTable}.number IS NULL THEN 1 ELSE 0 END",
            "COALESCE({$seasonTable}.number, 0)",
            "{$seasonTable}.id",
            "{$episodeTable}.sort_order",
            "CASE WHEN {$episodeTable}.number IS NULL THEN 1 ELSE 0 END",
            "COALESCE({$episodeTable}.number, 0)",
            "{$episodeTable}.id",
        ]);
        $selected = collect();
        $activityOffset = 0;
        $candidateBatchSize = max(96, $limit * 4);

        while ($selected->count() < $limit) {
            $activityRows = (clone $latestActivity)
                ->orderByDesc('last_watched_at')
                ->orderByDesc('id')
                ->offset($activityOffset)
                ->limit($candidateBatchSize)
                ->get();

            if ($activityRows->isEmpty()) {
                break;
            }

            $catalogTitleIds = $activityRows->pluck('catalog_title_id')->map(fn (mixed $id): int => (int) $id)->all();
            $episodeIds = $activityRows->pluck('episode_id')->map(fn (mixed $id): int => (int) $id)->all();
            $watchableEpisodeIds = $this->playback
                ->watchableEpisodesForVisibleTitles($user)
                ->whereIn($season->qualifyColumn('catalog_title_id'), $catalogTitleIds)
                ->reorder()
                ->select($episode->qualifyColumn('id'));
            $watchableSequence = $this->playback
                ->orderedEpisodesForVisibleTitles($user)
                ->whereIn($season->qualifyColumn('catalog_title_id'), $catalogTitleIds)
                ->leftJoinSub(clone $watchableEpisodeIds, 'watchable_episode_ids', function ($join) use ($episode): void {
                    $join->on('watchable_episode_ids.id', '=', $episode->qualifyColumn('id'));
                })
                ->where(function ($query) use ($episode, $episodeIds): void {
                    $query
                        ->whereNotNull('watchable_episode_ids.id')
                        ->orWhereIn($episode->qualifyColumn('id'), $episodeIds);
                })
                ->reorder()
                ->addSelect('watchable_episode_ids.id as watchable_episode_id')
                ->addSelect(DB::raw(
                    "LEAD({$episodeTable}.id) OVER (PARTITION BY {$seasonTable}.catalog_title_id, {$seasonTable}.kind, {$episodeTable}.kind ORDER BY {$episodeOrder}) AS next_episode_id",
                ));
            $sequenceRows = DB::query()
                ->fromSub($watchableSequence->toBase(), 'watchable_episode_sequence')
                ->whereIn('id', $episodeIds)
                ->get()
                ->keyBy('id');

            foreach ($activityRows as $row) {
                $sequence = $sequenceRows->get((int) $row->episode_id);

                if ($sequence === null
                    || (int) $sequence->playback_catalog_title_id !== (int) $row->catalog_title_id) {
                    continue;
                }

                $continueCurrent = $row->completed_at === null || $this->completionRule->isInProgress(
                    (int) $row->position_seconds,
                    (int) $row->duration_seconds,
                );

                if ($continueCurrent && $sequence->watchable_episode_id === null) {
                    continue;
                }

                $targetEpisodeId = $continueCurrent
                    ? (int) $row->episode_id
                    : (int) ($sequence->next_episode_id ?? 0);

                if ($targetEpisodeId < 1) {
                    continue;
                }

                $selected->push([
                    'catalog_title_id' => (int) $row->catalog_title_id,
                    'episode_id' => $targetEpisodeId,
                    'action_type' => $continueCurrent ? 'continue' : 'next',
                    'position_seconds' => $continueCurrent ? (int) $row->position_seconds : 0,
                    'progress_percent' => $continueCurrent && $row->progress_percent !== null
                        ? (int) $row->progress_percent
                        : null,
                ]);

                if ($selected->count() === $limit) {
                    break;
                }
            }

            $activityOffset += $activityRows->count();

            if ($activityRows->count() < $candidateBatchSize) {
                break;
            }
        }

        if ($selected->isEmpty()) {
            return collect();
        }

        $titles = CatalogTitle::query()
            ->availableTo($user)
            ->whereKey($selected->pluck('catalog_title_id'))
            ->select([
                'id',
                'slug',
                'title',
                'original_title',
                'type',
                'year',
                'description',
                'poster_url',
                'indexed_at',
            ])
            ->get()
            ->keyBy('id');
        $episodes = Episode::query()
            ->availableTo($user)
            ->whereKey($selected->pluck('episode_id'))
            ->select([
                'id',
                'season_id',
                'number',
                'kind',
                'sort_order',
                'title',
                'released_at',
                'summary',
            ])
            ->with([
                'season' => function ($relation) use ($user): void {
                    $relation->getQuery()
                        ->availableTo($user)
                        ->select(['id', 'catalog_title_id', 'number', 'kind', 'sort_order', 'title']);
                },
            ])
            ->get()
            ->keyBy('id');

        return $selected->map(function (array $item) use ($titles, $episodes): ?CatalogContinueWatchingItem {
            $title = $titles->get($item['catalog_title_id']);
            $episode = $episodes->get($item['episode_id']);

            if (! $title instanceof CatalogTitle || ! $episode instanceof Episode || $episode->season === null) {
                return null;
            }

            $position = (int) $item['position_seconds'];

            return new CatalogContinueWatchingItem(
                title: $title,
                episode: $episode,
                actionType: $item['action_type'],
                actionLabel: $item['action_type'] === 'continue'
                    ? __('catalog.player.continue_from', ['position' => $this->times->compact($position)])
                    : __('catalog.player.next'),
                positionSeconds: $position,
                progressPercent: $item['progress_percent'],
            );
        })->filter()->values();
    }

    /** @return LengthAwarePaginator<int, EpisodeViewProgress> */
    public function history(User $user, int $perPage = 12, string $pageName = 'historyPage'): LengthAwarePaginator
    {
        $perPage = max(1, min(48, $perPage));
        $accountSettings = $this->accountSettings->resolve($user);
        $history = EpisodeViewProgress::query()
            ->whereBelongsTo($user)
            ->whereNotNull('first_started_at')
            ->select([
                'id',
                'user_id',
                'catalog_title_id',
                'episode_id',
                'position_seconds',
                'duration_seconds',
                'progress_percent',
                'completed_at',
                'first_started_at',
                'last_watched_at',
            ])
            ->with([
                'catalogTitle' => function ($relation): void {
                    $relation->getQuery()
                        ->withTrashed()
                        ->select(['id', 'slug', 'title', 'poster_url', 'deleted_at']);
                },
                'episode' => function ($relation): void {
                    $relation->getQuery()
                        ->withTrashed()
                        ->select(['id', 'season_id', 'number', 'title', 'deleted_at']);
                },
                'episode.season' => function ($relation): void {
                    $relation->getQuery()
                        ->withTrashed()
                        ->select(['id', 'catalog_title_id', 'number', 'title', 'deleted_at']);
                },
            ])
            ->orderByDesc('last_watched_at')
            ->orderByDesc('id')
            ->paginate($perPage, pageName: $pageName);

        $episodeIds = $history->getCollection()->pluck('episode_id')->all();
        $accessibleEpisodeIds = $episodeIds === []
            ? collect()
            : $this->playback
                ->watchableEpisodesForVisibleTitles($user)
                ->whereIn((new Episode)->qualifyColumn('id'), $episodeIds)
                ->pluck((new Episode)->qualifyColumn('id'))
                ->mapWithKeys(fn (int $episodeId): array => [$episodeId => true]);

        $history->getCollection()->each(function (EpisodeViewProgress $progress) use ($accessibleEpisodeIds, $accountSettings): void {
            $progress->setAttribute('is_accessible', $accessibleEpisodeIds->has($progress->episode_id));
            $progress->setAttribute('last_watched_at_label', $this->dateTimes->value(
                $progress->last_watched_at,
                $accountSettings->locale,
                $accountSettings->timezone,
            ));
        });

        return $history;
    }
}
