<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Collection;

final readonly class CatalogTitleCardCountLoader
{
    /**
     * @param  Collection<int, CatalogTitle>  $titles
     * @return Collection<int, CatalogTitle>
     */
    public function load(Collection $titles, ?User $user): Collection
    {
        $titleIds = $titles
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($titleIds === []) {
            return $titles;
        }

        $seasons = Season::query()
            ->availableTo($user)
            ->whereIn('catalog_title_id', $titleIds)
            ->get(['id', 'catalog_title_id']);
        $seasonCounts = $seasons->countBy('catalog_title_id');
        $episodeCountsBySeason = $seasons->isEmpty()
            ? collect()
            : Episode::query()
                ->availableTo($user)
                ->whereIn('season_id', $seasons->modelKeys())
                ->selectRaw('season_id, COUNT(*) AS aggregate_count')
                ->groupBy('season_id')
                ->pluck('aggregate_count', 'season_id');
        $episodeCounts = $seasons
            ->groupBy('catalog_title_id')
            ->map(fn (Collection $titleSeasons): int => $titleSeasons->sum(
                fn (Season $season): int => (int) $episodeCountsBySeason->get($season->id, 0),
            ));
        $mediaCounts = LicensedMedia::query()
            ->availableTo($user)
            ->forAvailableReleases($user)
            ->whereIn('licensed_media.catalog_title_id', $titleIds)
            ->selectRaw('licensed_media.catalog_title_id, COUNT(*) AS aggregate_count')
            ->groupBy('licensed_media.catalog_title_id')
            ->pluck('aggregate_count', 'licensed_media.catalog_title_id');

        $titles->each(function (CatalogTitle $title) use ($episodeCounts, $mediaCounts, $seasonCounts): void {
            $title->setAttribute('seasons_count', (int) $seasonCounts->get($title->id, 0));
            $title->setAttribute('episodes_count', (int) $episodeCounts->get($title->id, 0));
            $title->setAttribute('published_media_count', (int) $mediaCounts->get($title->id, 0));
        });

        return $titles;
    }
}
