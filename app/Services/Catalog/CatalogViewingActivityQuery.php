<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\DTOs\CatalogContinueWatchingItem;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\Season;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CatalogViewingActivityQuery
{
    public function __construct(
        private readonly CatalogTitlePlaybackQuery $playback,
        private readonly CatalogPlaybackCompletionRule $completionRule,
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
        $watchableEpisodeIds = $this->playback
            ->watchableEpisodesForVisibleTitles($user)
            ->reorder()
            ->select($episode->qualifyColumn('id'));
        $latestActivityEpisodeIds = DB::query()
            ->fromSub(clone $latestActivity, 'latest_activity_episode_ids')
            ->select('episode_id');
        $watchableSequence = $this->playback
            ->orderedEpisodesForVisibleTitles($user)
            ->leftJoinSub(clone $watchableEpisodeIds, 'watchable_episode_ids', function ($join) use ($episode): void {
                $join->on('watchable_episode_ids.id', '=', $episode->qualifyColumn('id'));
            })
            ->where(function ($query) use ($episode, $latestActivityEpisodeIds): void {
                $query
                    ->whereNotNull('watchable_episode_ids.id')
                    ->orWhereIn($episode->qualifyColumn('id'), clone $latestActivityEpisodeIds);
            })
            ->reorder()
            ->addSelect('watchable_episode_ids.id as watchable_episode_id')
            ->addSelect(DB::raw(
                "LEAD({$episodeTable}.id) OVER (PARTITION BY {$seasonTable}.catalog_title_id, {$seasonTable}.kind, {$episodeTable}.kind ORDER BY {$episodeOrder}) AS next_episode_id",
            ));

        $activity = DB::query()
            ->fromSub($latestActivity, 'latest_viewing_activity')
            ->joinSub($watchableSequence->toBase(), 'watchable_episode_sequence', function ($join): void {
                $join->on('watchable_episode_sequence.id', '=', 'latest_viewing_activity.episode_id')
                    ->on('watchable_episode_sequence.playback_catalog_title_id', '=', 'latest_viewing_activity.catalog_title_id');
            })
            ->select([
                'latest_viewing_activity.id',
                'latest_viewing_activity.catalog_title_id',
                'latest_viewing_activity.episode_id',
                'latest_viewing_activity.position_seconds',
                'latest_viewing_activity.duration_seconds',
                'latest_viewing_activity.progress_percent',
                'latest_viewing_activity.completed_at',
                'latest_viewing_activity.last_watched_at',
                'watchable_episode_sequence.watchable_episode_id',
                'watchable_episode_sequence.next_episode_id',
            ])
            ->orderByDesc('latest_viewing_activity.last_watched_at')
            ->orderByDesc('latest_viewing_activity.id');

        $selected = collect();

        foreach ($activity->cursor() as $row) {
            $continueCurrent = $row->completed_at === null || $this->completionRule->isInProgress(
                (int) $row->position_seconds,
                (int) $row->duration_seconds,
            );

            if ($continueCurrent && $row->watchable_episode_id === null) {
                continue;
            }

            $targetEpisodeId = $continueCurrent ? (int) $row->episode_id : (int) ($row->next_episode_id ?? 0);

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

        if ($selected->isEmpty()) {
            return collect();
        }

        $titles = CatalogTitle::query()
            ->availableTo($user)
            ->whereKey($selected->pluck('catalog_title_id'))
            ->select(['id', 'slug', 'title', 'type', 'year', 'poster_url'])
            ->get()
            ->keyBy('id');
        $episodes = Episode::query()
            ->availableTo($user)
            ->whereKey($selected->pluck('episode_id'))
            ->select(['id', 'season_id', 'number', 'kind', 'sort_order', 'title'])
            ->with([
                'season' => fn (BelongsTo $query): BelongsTo => $query
                    ->availableTo($user)
                    ->select(['id', 'catalog_title_id', 'number', 'kind', 'sort_order', 'title']),
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
                    ? 'Продолжить с '.$this->formatPosition($position)
                    : 'Следующая серия',
                progressPercent: $item['progress_percent'],
            );
        })->filter()->values();
    }

    /** @return LengthAwarePaginator<int, EpisodeViewProgress> */
    public function history(User $user, int $perPage = 12): LengthAwarePaginator
    {
        $perPage = max(1, min(48, $perPage));
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
                'catalogTitle' => fn (BelongsTo $query): BelongsTo => $query
                    ->withTrashed()
                    ->select(['id', 'slug', 'title', 'poster_url', 'deleted_at']),
                'episode' => fn (BelongsTo $query): BelongsTo => $query
                    ->withTrashed()
                    ->select(['id', 'season_id', 'number', 'title', 'deleted_at']),
                'episode.season' => fn (BelongsTo $query): BelongsTo => $query
                    ->withTrashed()
                    ->select(['id', 'catalog_title_id', 'number', 'title', 'deleted_at']),
            ])
            ->orderByDesc('last_watched_at')
            ->orderByDesc('id')
            ->paginate($perPage, pageName: 'historyPage');

        $episodeIds = $history->getCollection()->pluck('episode_id')->all();
        $accessibleEpisodeIds = $episodeIds === []
            ? collect()
            : $this->playback
                ->watchableEpisodesForVisibleTitles($user)
                ->whereIn((new Episode)->qualifyColumn('id'), $episodeIds)
                ->pluck((new Episode)->qualifyColumn('id'))
                ->mapWithKeys(fn (int $episodeId): array => [$episodeId => true]);

        $history->getCollection()->each(function (EpisodeViewProgress $progress) use ($accessibleEpisodeIds): void {
            $progress->setAttribute('is_accessible', $accessibleEpisodeIds->has($progress->episode_id));
        });

        return $history;
    }

    private function formatPosition(int $seconds): string
    {
        $seconds = max(0, $seconds);

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
