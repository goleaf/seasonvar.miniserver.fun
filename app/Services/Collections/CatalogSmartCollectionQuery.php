<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\DTOs\CatalogSmartCollectionRules;
use App\Enums\CatalogSmartCollectionCompletion;
use App\Enums\ReleaseKind;
use App\Models\CatalogCollection;
use App\Models\CatalogTitle;
use App\Models\CatalogTitleUserState;
use App\Models\EpisodeViewProgress;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\CatalogPersonalUpdateQuery;
use Illuminate\Database\Eloquent\Builder;

final readonly class CatalogSmartCollectionQuery
{
    public function __construct(
        private CatalogPersonalUpdateQuery $personalUpdates,
    ) {}

    /**
     * @param  Builder<CatalogTitle>  $query
     * @return Builder<CatalogTitle>
     */
    public function constrain(
        Builder $query,
        CatalogCollection $collection,
        ?User $owner,
    ): Builder {
        $rules = $collection->smartRules();

        if (! $owner instanceof User || ! $collection->isOwnedBy($owner) || ! $rules instanceof CatalogSmartCollectionRules) {
            return $query->whereRaw('1 = 0');
        }

        foreach ([
            'countries' => $rules->countrySlug,
            'genres' => $rules->genreSlug,
            'actors' => $rules->actorSlug,
        ] as $relation => $slug) {
            if ($slug !== null) {
                $query->whereHas(
                    $relation,
                    fn (Builder $relationQuery): Builder => $relationQuery->where('slug', $slug),
                );
            }
        }

        if ($rules->imdbMin !== null) {
            $query->whereHas('ratings', fn (Builder $rating): Builder => $rating
                ->where('provider', 'imdb')
                ->where('rating', '>=', $rules->imdbMin));
        }

        $query
            ->when(
                $rules->yearFrom !== null,
                fn (Builder $titles): Builder => $titles->where('catalog_titles.year', '>=', $rules->yearFrom),
            )
            ->when(
                $rules->yearTo !== null,
                fn (Builder $titles): Builder => $titles->where('catalog_titles.year', '<=', $rules->yearTo),
            );

        $this->constrainCompletion($query, $owner, $rules);
        $this->constrainEpisodeCount($query, $owner, $rules);
        $this->constrainPersonalState($query, $owner, $rules);
        $this->constrainMedia($query, $owner, $rules);

        return $query;
    }

    /**
     * @return Builder<CatalogTitle>
     */
    public function titleIds(CatalogCollection $collection, User $owner): Builder
    {
        return $this->constrain(CatalogTitle::query(), $collection, $owner)
            ->select('catalog_titles.id');
    }

    /** @param Builder<CatalogTitle> $query */
    private function constrainCompletion(
        Builder $query,
        User $owner,
        CatalogSmartCollectionRules $rules,
    ): void {
        if ($rules->completion === null) {
            return;
        }

        $regularSeasons = Season::query()
            ->availableTo($owner)
            ->whereColumn('seasons.catalog_title_id', 'catalog_titles.id')
            ->where('seasons.kind', ReleaseKind::Regular->value);

        if ($rules->completion === CatalogSmartCollectionCompletion::Ongoing) {
            $query->whereExists(
                (clone $regularSeasons)
                    ->whereNotNull('seasons.episodes_released')
                    ->whereNotNull('seasons.episodes_total')
                    ->whereColumn('seasons.episodes_released', '<', 'seasons.episodes_total')
                    ->selectRaw('1')
                    ->toBase(),
            );

            return;
        }

        $query
            ->whereExists(
                (clone $regularSeasons)
                    ->whereNotNull('seasons.episodes_released')
                    ->whereNotNull('seasons.episodes_total')
                    ->whereColumn('seasons.episodes_released', '>=', 'seasons.episodes_total')
                    ->selectRaw('1')
                    ->toBase(),
            )
            ->whereNotExists(
                (clone $regularSeasons)
                    ->where(function (Builder $season): void {
                        $season
                            ->whereNull('seasons.episodes_released')
                            ->orWhereNull('seasons.episodes_total')
                            ->orWhereColumn('seasons.episodes_released', '<', 'seasons.episodes_total');
                    })
                    ->selectRaw('1')
                    ->toBase(),
            );
    }

    /** @param Builder<CatalogTitle> $query */
    private function constrainEpisodeCount(
        Builder $query,
        User $owner,
        CatalogSmartCollectionRules $rules,
    ): void {
        if ($rules->episodesMax === null) {
            return;
        }

        $query->whereExists(
            Season::query()
                ->availableTo($owner)
                ->whereColumn('seasons.catalog_title_id', 'catalog_titles.id')
                ->where('seasons.kind', ReleaseKind::Regular->value)
                ->whereNotNull('seasons.episodes_released')
                ->groupBy('seasons.catalog_title_id')
                ->havingRaw('SUM(seasons.episodes_released) > 0')
                ->havingRaw('SUM(seasons.episodes_released) <= ?', [$rules->episodesMax])
                ->selectRaw('1')
                ->toBase(),
        );
    }

    /** @param Builder<CatalogTitle> $query */
    private function constrainPersonalState(
        Builder $query,
        User $owner,
        CatalogSmartCollectionRules $rules,
    ): void {
        $state = CatalogTitleUserState::query()
            ->where('catalog_title_user_states.user_id', $owner->id)
            ->whereColumn('catalog_title_user_states.catalog_title_id', 'catalog_titles.id');

        if ($rules->inLibrary) {
            $query->whereExists(
                (clone $state)
                    ->where(fn (Builder $library): Builder => $library
                        ->where('catalog_title_user_states.in_watchlist', true)
                        ->orWhereNotNull('catalog_title_user_states.watch_status'))
                    ->selectRaw('1')
                    ->toBase(),
            );
        }

        if ($rules->unwatched) {
            $query->whereNotExists(
                EpisodeViewProgress::query()
                    ->where('episode_view_progress.user_id', $owner->id)
                    ->whereColumn('episode_view_progress.catalog_title_id', 'catalog_titles.id')
                    ->selectRaw('1')
                    ->toBase(),
            );
        }

        if ($rules->watchStatus !== null) {
            $query->whereExists(
                (clone $state)
                    ->where('catalog_title_user_states.watch_status', $rules->watchStatus->value)
                    ->when(
                        $rules->watchStatusOlderDays !== null,
                        fn (Builder $userState): Builder => $userState->where(
                            'catalog_title_user_states.watch_status_updated_at',
                            '<=',
                            now()->subDays($rules->watchStatusOlderDays),
                        ),
                    )
                    ->selectRaw('1')
                    ->toBase(),
            );
        }

        if ($rules->hasNewEpisodes) {
            $newEpisodeState = $this->personalUpdates->constrain(clone $state, $owner, true);
            $query->whereExists($newEpisodeState->selectRaw('1')->toBase());
        }
    }

    /** @param Builder<CatalogTitle> $query */
    private function constrainMedia(
        Builder $query,
        User $owner,
        CatalogSmartCollectionRules $rules,
    ): void {
        if (! $rules->videoAvailable && ! $rules->hasSubtitles && $rules->maxEpisodeMinutes === null) {
            return;
        }

        $availableMedia = $this->availableMedia($owner);

        if ($rules->videoAvailable && ! $rules->hasSubtitles && $rules->maxEpisodeMinutes === null) {
            $query->whereExists((clone $availableMedia)->selectRaw('1')->toBase());
        }

        if ($rules->hasSubtitles) {
            $query->whereExists(
                (clone $availableMedia)
                    ->where('licensed_media.has_subtitles', true)
                    ->selectRaw('1')
                    ->toBase(),
            );
        }

        if ($rules->maxEpisodeMinutes !== null) {
            $maximumSeconds = $rules->maxEpisodeMinutes * 60;
            $query
                ->whereExists(
                    (clone $availableMedia)
                        ->whereNotNull('licensed_media.duration_seconds')
                        ->where('licensed_media.duration_seconds', '<=', $maximumSeconds)
                        ->selectRaw('1')
                        ->toBase(),
                )
                ->whereNotExists(
                    (clone $availableMedia)
                        ->where('licensed_media.duration_seconds', '>', $maximumSeconds)
                        ->selectRaw('1')
                        ->toBase(),
                );
        }
    }

    /** @return Builder<LicensedMedia> */
    private function availableMedia(User $owner): Builder
    {
        return LicensedMedia::query()
            ->availableTo($owner)
            ->forAvailableReleases($owner)
            ->withPlaybackLocation()
            ->withoutKnownFailures()
            ->whereColumn('licensed_media.catalog_title_id', 'catalog_titles.id');
    }
}
