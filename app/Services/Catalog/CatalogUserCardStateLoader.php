<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\Episode;
use App\Models\EpisodeViewProgress;
use App\Models\LicensedMedia;
use App\Models\User;
use App\Services\Auth\AccountSettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CatalogUserCardStateLoader
{
    public function __construct(private readonly AccountSettingsService $settings) {}

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
        $translationStates = $this->translationStates($titleIds, $user);

        return $titles->each(function (CatalogTitle $title) use ($states, $progress, $episodeSeasonIds, $translationStates): void {
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
            $title->setAttribute(
                'user_translation_preference_state',
                $translationStates->get($title->id),
            );
        });
    }

    /**
     * @param  Collection<int, int>  $titleIds
     * @return Collection<int, string>
     */
    private function translationStates(Collection $titleIds, User $user): Collection
    {
        $preferences = $this->settings->resolve($user);

        if ($preferences->preferredVariant === null) {
            return collect();
        }

        $media = LicensedMedia::query()
            ->availableTo($user)
            ->forAvailableReleases($user)
            ->withoutKnownFailures()
            ->withPlaybackLocation()
            ->whereIn('catalog_title_id', $titleIds)
            ->where(function ($query) use ($preferences): void {
                $query
                    ->where('variant_key', $preferences->preferredVariant)
                    ->orWhere('variant_type', 'voiceover');
            })
            ->when(
                $preferences->hiddenVariantKeys !== [],
                fn ($query) => $query->where(function ($query) use ($preferences): void {
                    $query
                        ->whereNull('variant_key')
                        ->orWhereNotIn('variant_key', $preferences->hiddenVariantKeys);
                }),
            )
            ->get(['catalog_title_id', 'variant_key', 'variant_type'])
            ->groupBy('catalog_title_id');

        return $titleIds->mapWithKeys(function (int $titleId) use ($media, $preferences): array {
            $titleMedia = $media->get($titleId, collect());
            $preferredAvailable = $titleMedia->contains(
                fn (LicensedMedia $item): bool => $item->variant_key === $preferences->preferredVariant,
            );
            $alternativeAvailable = $titleMedia->contains(
                fn (LicensedMedia $item): bool => $item->variant_type === 'voiceover',
            );

            return $preferredAvailable
                ? [$titleId => 'preferred']
                : ($alternativeAvailable ? [$titleId => 'alternative'] : []);
        });
    }

    /**
     * @param  Collection<int, int>  $episodeSeasonIds
     * @return array{type: string, label: string, url: string}|null
     */
    private function primaryAction(
        CatalogTitle $title,
        ?object $progress,
        Collection $episodeSeasonIds,
    ): ?array {
        if ($progress === null) {
            return null;
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
            'label' => $completed ? __('catalog.player.watch_again') : __('catalog.player.continue'),
            'url' => $url,
        ];
    }
}
