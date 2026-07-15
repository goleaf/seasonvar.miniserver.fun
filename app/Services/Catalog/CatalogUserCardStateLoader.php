<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CatalogUserCardStateLoader
{
    /**
     * @param  Collection<int, CatalogTitle>  $titles
     * @return Collection<int, CatalogTitle>
     */
    public function load(Collection $titles, ?User $user): Collection
    {
        if ($user === null || $titles->isEmpty()) {
            return $titles;
        }

        $titleIds = $titles
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $states = CatalogTitleUserState::query()
            ->whereBelongsTo($user)
            ->whereIn('catalog_title_id', $titleIds)
            ->select(['catalog_title_id', 'in_watchlist', 'rating'])
            ->get()
            ->keyBy('catalog_title_id');
        $progressTable = (new EpisodeViewProgress)->getTable();
        $rankedProgress = EpisodeViewProgress::query()
            ->whereBelongsTo($user)
            ->whereIn('catalog_title_id', $titleIds)
            ->whereNotNull('first_started_at')
            ->select([
                'id',
                'catalog_title_id',
                'episode_id',
                'position_seconds',
                'progress_percent',
                'completed_at',
                'last_watched_at',
            ])
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY {$progressTable}.catalog_title_id ORDER BY {$progressTable}.last_watched_at DESC, {$progressTable}.id DESC) AS activity_rank");
        $progress = DB::query()
            ->fromSub($rankedProgress->toBase(), 'ranked_card_progress')
            ->where('activity_rank', 1)
            ->get()
            ->keyBy('catalog_title_id');
        $episodeSeasonIds = Episode::query()
            ->whereKey($progress->pluck('episode_id')->filter()->unique())
            ->pluck('season_id', 'id');

        return $titles->each(function (CatalogTitle $title) use ($states, $progress, $episodeSeasonIds): void {
            $state = $states->get($title->id);
            $latestProgress = $progress->get($title->id);
            $progressPercent = $latestProgress?->progress_percent;

            $title->setAttribute(
                'user_in_watchlist',
                $state instanceof CatalogTitleUserState && $state->in_watchlist,
            );
            $title->setAttribute(
                'user_rating',
                $state instanceof CatalogTitleUserState && $state->rating !== null
                    ? (int) $state->rating
                    : null,
            );
            $title->setAttribute('user_progress_percent', $progressPercent === null ? null : (int) $progressPercent);
            $title->setAttribute(
                'user_primary_action',
                $this->primaryAction($title, $latestProgress, $episodeSeasonIds),
            );
        });
    }

    /**
     * @param  Collection<int, int>  $episodeSeasonIds
     * @return array{type: string, label: string, url: string}
     */
    private function primaryAction(
        CatalogTitle $title,
        ?object $progress,
        Collection $episodeSeasonIds,
    ): array {
        if ($progress === null) {
            return [
                'type' => 'open',
                'label' => 'Открыть тайтл',
                'url' => route('titles.show', $title),
            ];
        }

        $episodeId = (int) $progress->episode_id;
        $seasonId = $episodeSeasonIds->get($episodeId);
        $url = route('titles.show', array_filter([
            'catalogTitle' => $title,
            'season' => $seasonId,
            'episode' => $episodeId,
        ])).'#player';
        $completed = $progress->completed_at !== null;

        return [
            'type' => $completed ? 'replay' : 'continue',
            'label' => $completed ? 'Смотреть снова' : 'Продолжить просмотр',
            'url' => $url,
        ];
    }
}
