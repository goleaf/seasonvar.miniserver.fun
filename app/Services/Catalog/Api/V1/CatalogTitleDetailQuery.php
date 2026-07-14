<?php

declare(strict_types=1);

namespace App\Services\Catalog\Api\V1;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\CatalogPrimaryActionResolver;
use App\Services\Catalog\CatalogTitlePageBuilder;
use App\Services\Catalog\CatalogTitlePlaybackQuery;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Catalog\CatalogUserStateService;
use Illuminate\Support\Collection;

final class CatalogTitleDetailQuery
{
    public function __construct(
        private readonly CatalogTitleQuery $titles,
        private readonly CatalogTitlePageBuilder $pageBuilder,
        private readonly CatalogTitlePlaybackQuery $playback,
        private readonly CatalogPrimaryActionResolver $primaryActions,
        private readonly CatalogUserStateService $userStates,
    ) {}

    public function visibleTitle(string $titleSlug, ?User $user): CatalogTitle
    {
        return $this->titles->visibleTo($user)
            ->where('slug', $titleSlug)
            ->firstOrFail();
    }

    public function title(string $titleSlug, ?User $user): CatalogTitle
    {
        $title = $this->visibleTitle($titleSlug, $user);
        $data = $this->pageBuilder->data($title, $user);
        $seasons = $data['seasons'];

        $title->setRelation('aliases', $data['aliases']);
        $title->setRelation('ratings', $data['ratings']);
        $title->setAttribute('api_counts', [
            'seasons' => $seasons->count(),
            'episodes' => (int) $data['episodeCount'],
            'media_profiles' => (int) $data['mediaCount'],
            'taxonomies' => (int) $data['taxonomyCount'],
        ]);
        $title->setAttribute('api_primary_action', $this->primaryActions->resolve($title, $user));
        $title->setAttribute('api_rating_summary', $this->userStates->summary($title));
        $title->setAttribute('api_user_state', $user === null ? null : $this->userStates->state($user, $title));

        return $title;
    }

    /** @return Collection<int, Season> */
    public function seasons(CatalogTitle $title, ?User $user): Collection
    {
        return $this->playback->seasonSummaries($title, $user)
            ->each(fn (Season $season): Season => $season->setAttribute('api_title_slug', $title->slug));
    }

    /** @return Collection<int, Episode> */
    public function episodes(CatalogTitle $title, int $seasonId, ?User $user): Collection
    {
        $season = $title->seasons()
            ->availableTo($user)
            ->whereKey($seasonId)
            ->firstOrFail();

        return $this->playback->episodesForSeason($title, $season, $user)
            ->each(function (Episode $episode) use ($title): void {
                $episode->setAttribute('api_title_slug', $title->slug);
            });
    }
}
