<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CatalogHomeContentAdditionQuery
{
    public function __construct(
        private readonly CatalogTitleQuery $titles,
    ) {}

    /**
     * @return list<array{id: int, added_at: string}>
     */
    public function latestTitleUpdates(int $limit = 48): array
    {
        if ($limit <= 0) {
            return [];
        }

        $additions = $this->episodeAdditions()
            ->toBase()
            ->unionAll($this->mediaAdditions()->toBase());

        return DB::query()
            ->fromSub($additions, 'catalog_home_content_additions')
            ->select('catalog_title_id')
            ->selectRaw('MAX(added_at) AS added_at')
            ->groupBy('catalog_title_id')
            ->orderByDesc('added_at')
            ->orderByDesc('catalog_title_id')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->catalog_title_id,
                'added_at' => CarbonImmutable::parse($row->added_at)->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, CatalogTitle>  $titles
     * @param  list<array{id: int, added_at: string}>  $updates
     * @return Collection<int, array{
     *     title: CatalogTitle,
     *     episodes: Collection<int, Episode>,
     *     media: Collection<int, LicensedMedia>
     * }>
     */
    public function latestReleaseGroups(Collection $titles, array $updates, int $limit = 12): Collection
    {
        if ($limit <= 0 || $titles->isEmpty() || $updates === []) {
            return collect();
        }

        $titlesById = $titles->keyBy(fn (CatalogTitle $title): int => (int) $title->id);
        $coordinates = collect($updates)
            ->take($limit)
            ->filter(fn (array $update): bool => $titlesById->has((int) $update['id']))
            ->map(function (array $update): array {
                $addedAt = CarbonImmutable::parse($update['added_at']);

                return [
                    'id' => (int) $update['id'],
                    'start' => $addedAt->startOfDay(),
                    'end' => $addedAt->endOfDay(),
                ];
            })
            ->values();

        if ($coordinates->isEmpty()) {
            return collect();
        }

        $episodesByTitle = $this->episodesFor($coordinates)
            ->filter(fn (Episode $episode): bool => $episode->season !== null)
            ->groupBy(fn (Episode $episode): int => (int) $episode->season->catalog_title_id);
        $mediaByTitle = $this->mediaFor($coordinates)
            ->groupBy(fn (LicensedMedia $media): int => (int) $media->catalog_title_id);

        return $coordinates
            ->map(function (array $coordinate) use ($titlesById, $episodesByTitle, $mediaByTitle): array {
                $titleId = $coordinate['id'];

                return [
                    'title' => $titlesById->get($titleId),
                    'episodes' => $episodesByTitle->get($titleId, collect())->values(),
                    'media' => $mediaByTitle->get($titleId, collect())->values(),
                ];
            })
            ->filter(fn (array $group): bool => $group['title'] instanceof CatalogTitle
                && ($group['episodes']->isNotEmpty() || $group['media']->isNotEmpty()))
            ->values();
    }

    /** @return Builder<Episode> */
    private function episodeAdditions(): Builder
    {
        $episodeTable = (new Episode)->getTable();
        $seasonTable = (new Season)->getTable();

        return Episode::query()
            ->join($seasonTable, $seasonTable.'.id', '=', $episodeTable.'.season_id')
            ->availableTo(null)
            ->whereIn($seasonTable.'.id', $this->visibleSeasonIds())
            ->whereNotNull($episodeTable.'.created_at')
            ->selectRaw($seasonTable.'.catalog_title_id AS catalog_title_id')
            ->selectRaw($episodeTable.'.created_at AS added_at');
    }

    /** @return Builder<LicensedMedia> */
    private function mediaAdditions(): Builder
    {
        $media = new LicensedMedia;
        $mediaTable = $media->getTable();
        $titleTable = (new CatalogTitle)->getTable();

        return LicensedMedia::query()
            ->published()
            ->forAvailableReleases(null)
            ->whereNotNull($media->qualifyColumn('catalog_title_id'))
            ->whereNotNull($media->qualifyColumn('created_at'))
            ->whereIn(
                $media->qualifyColumn('catalog_title_id'),
                $this->titles->visibleTo(null)->select($titleTable.'.id'),
            )
            ->selectRaw($mediaTable.'.catalog_title_id AS catalog_title_id')
            ->selectRaw($mediaTable.'.created_at AS added_at');
    }

    /**
     * @param  Collection<int, array{id: int, start: CarbonImmutable, end: CarbonImmutable}>  $coordinates
     * @return Collection<int, Episode>
     */
    private function episodesFor(Collection $coordinates): Collection
    {
        $episodeTable = (new Episode)->getTable();
        $seasonTable = (new Season)->getTable();
        $query = Episode::query()
            ->join($seasonTable, $seasonTable.'.id', '=', $episodeTable.'.season_id')
            ->select($episodeTable.'.*')
            ->availableTo(null)
            ->whereIn($seasonTable.'.id', $this->visibleSeasonIds())
            ->with([
                'season' => fn ($query) => $query
                    ->availableTo(null)
                    ->select(['id', 'catalog_title_id', 'number', 'kind', 'sort_order', 'title']),
            ]);

        $this->constrainCoordinates(
            $query,
            $coordinates,
            $seasonTable.'.catalog_title_id',
            $episodeTable.'.created_at',
        );

        return $query
            ->orderByDesc($episodeTable.'.created_at')
            ->orderByDesc($episodeTable.'.id')
            ->get();
    }

    /**
     * @param  Collection<int, array{id: int, start: CarbonImmutable, end: CarbonImmutable}>  $coordinates
     * @return Collection<int, LicensedMedia>
     */
    private function mediaFor(Collection $coordinates): Collection
    {
        $media = new LicensedMedia;
        $mediaTable = $media->getTable();
        $query = LicensedMedia::query()
            ->published()
            ->forAvailableReleases(null)
            ->select([
                'id',
                'catalog_title_id',
                'season_id',
                'episode_id',
                'title',
                'quality',
                'translation_name',
                'format',
                'published_at',
                'created_at',
            ])
            ->with([
                'season' => fn ($query) => $query
                    ->availableTo(null)
                    ->select(['id', 'catalog_title_id', 'number', 'kind', 'sort_order', 'title']),
                'episode' => fn ($query) => $query
                    ->availableTo(null)
                    ->select(['id', 'season_id', 'number', 'kind', 'sort_order', 'title', 'released_at']),
            ]);

        $this->constrainCoordinates(
            $query,
            $coordinates,
            $media->qualifyColumn('catalog_title_id'),
            $media->qualifyColumn('created_at'),
        );

        return $query
            ->orderByDesc($mediaTable.'.created_at')
            ->orderByDesc($mediaTable.'.id')
            ->get();
    }

    /**
     * @param  Builder<Episode>|Builder<LicensedMedia>  $query
     * @param  Collection<int, array{id: int, start: CarbonImmutable, end: CarbonImmutable}>  $coordinates
     */
    private function constrainCoordinates(
        Builder $query,
        Collection $coordinates,
        string $titleColumn,
        string $createdAtColumn,
    ): void {
        $query->where(function (Builder $query) use ($coordinates, $titleColumn, $createdAtColumn): void {
            foreach ($coordinates as $coordinate) {
                $query->orWhere(function (Builder $query) use ($coordinate, $titleColumn, $createdAtColumn): void {
                    $query
                        ->where($titleColumn, $coordinate['id'])
                        ->whereBetween($createdAtColumn, [$coordinate['start'], $coordinate['end']]);
                });
            }
        });
    }

    /** @return Builder<Season> */
    private function visibleSeasonIds(): Builder
    {
        $seasonTable = (new Season)->getTable();
        $titleTable = (new CatalogTitle)->getTable();

        return Season::query()
            ->availableTo(null)
            ->whereIn(
                $seasonTable.'.catalog_title_id',
                $this->titles->visibleTo(null)->select($titleTable.'.id'),
            )
            ->select($seasonTable.'.id');
    }
}
