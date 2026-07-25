<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CatalogHomeContentAdditionQuery
{
    public const RELEASE_ITEMS_PER_TITLE = 8;

    private const INITIAL_EVENT_WINDOW = 2_048;

    private const MAXIMUM_EVENT_WINDOW = 20_000;

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

        $eventWindow = min(
            self::MAXIMUM_EVENT_WINDOW,
            max(self::INITIAL_EVENT_WINDOW, $limit * 32),
        );

        do {
            $episodeEvents = $this->recentEpisodeEvents($eventWindow);
            $mediaEvents = $this->recentMediaEvents($eventWindow);
            $updates = $this->latestVisibleUpdates($episodeEvents, $mediaEvents);

            if ($this->eventWindowCovers($updates, $episodeEvents, $mediaEvents, $eventWindow, $limit)
                || $eventWindow >= self::MAXIMUM_EVENT_WINDOW) {
                break;
            }

            $eventWindow = min(self::MAXIMUM_EVENT_WINDOW, $eventWindow * 2);
        } while (true);

        return $updates
            ->take($limit)
            ->map(fn (array $update): array => [
                'id' => $update['id'],
                'added_at' => CarbonImmutable::parse($update['added_at'])->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @param  Collection<int, CatalogTitle>  $titles
     * @param  list<array{id: int, added_at: string}>  $updates
     * @return Collection<int, array{
     *     title: CatalogTitle,
     *     episodes: Collection<int, Episode>,
     *     media: Collection<int, LicensedMedia>,
     *     has_more: bool
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
        $truncatedTitleIds = $episodesByTitle
            ->filter(fn (Collection $episodes): bool => $episodes->count() > self::RELEASE_ITEMS_PER_TITLE)
            ->keys()
            ->concat($mediaByTitle
                ->filter(fn (Collection $media): bool => $media->count() > self::RELEASE_ITEMS_PER_TITLE)
                ->keys())
            ->map(fn (mixed $titleId): int => (int) $titleId)
            ->unique()
            ->all();
        $episodesByTitle = $episodesByTitle->map(
            fn (Collection $episodes): Collection => $episodes->take(self::RELEASE_ITEMS_PER_TITLE)->values(),
        );
        $mediaByTitle = $mediaByTitle->map(
            fn (Collection $media): Collection => $media->take(self::RELEASE_ITEMS_PER_TITLE)->values(),
        );

        return $coordinates
            ->map(function (array $coordinate) use (
                $titlesById,
                $episodesByTitle,
                $mediaByTitle,
                $truncatedTitleIds,
            ): array {
                $titleId = $coordinate['id'];

                return [
                    'title' => $titlesById->get($titleId),
                    'episodes' => $episodesByTitle->get($titleId, collect())->values(),
                    'media' => $mediaByTitle->get($titleId, collect())->values(),
                    'has_more' => in_array($titleId, $truncatedTitleIds, true),
                ];
            })
            ->filter(fn (array $group): bool => $group['title'] instanceof CatalogTitle
                && ($group['episodes']->isNotEmpty() || $group['media']->isNotEmpty()))
            ->values();
    }

    /**
     * @return Collection<int, array{id: int, catalog_title_id: int, added_at: string}>
     */
    private function recentEpisodeEvents(int $limit): Collection
    {
        return $this->recentTable('episodes', 'episodes_created_at_idx')
            ->join('seasons', 'seasons.id', '=', 'episodes.season_id')
            ->where('episodes.publication_status', 'published')
            ->whereNull('episodes.deleted_at')
            ->whereNotNull('episodes.created_at')
            ->select([
                'episodes.id',
                'seasons.catalog_title_id',
                'episodes.created_at as added_at',
            ])
            ->orderByDesc('episodes.created_at')
            ->orderByDesc('episodes.id')
            ->limit($limit)
            ->get()
            ->map(fn (object $event): array => [
                'id' => (int) $event->id,
                'catalog_title_id' => (int) $event->catalog_title_id,
                'added_at' => (string) $event->added_at,
            ]);
    }

    /**
     * @return Collection<int, array{id: int, catalog_title_id: int, added_at: string}>
     */
    private function recentMediaEvents(int $limit): Collection
    {
        return $this->recentTable('licensed_media', 'licensed_media_created_at_idx')
            ->where('licensed_media.status', 'published')
            ->whereNull('licensed_media.deleted_at')
            ->whereNotNull('licensed_media.catalog_title_id')
            ->whereNotNull('licensed_media.created_at')
            ->select([
                'licensed_media.id',
                'licensed_media.catalog_title_id',
                'licensed_media.created_at as added_at',
            ])
            ->orderByDesc('licensed_media.created_at')
            ->orderByDesc('licensed_media.id')
            ->limit($limit)
            ->get()
            ->map(fn (object $event): array => [
                'id' => (int) $event->id,
                'catalog_title_id' => (int) $event->catalog_title_id,
                'added_at' => (string) $event->added_at,
            ]);
    }

    private function recentTable(string $table, string $index): QueryBuilder
    {
        $query = DB::query();
        $connection = DB::connection();

        if ($connection->getDriverName() === 'sqlite') {
            $grammar = $connection->getQueryGrammar();

            return $query->fromRaw(
                $grammar->wrapTable($table).' INDEXED BY '.$grammar->wrap($index),
            );
        }

        return $query->from($table);
    }

    /**
     * @param  Collection<int, array{id: int, catalog_title_id: int, added_at: string}>  $episodeEvents
     * @param  Collection<int, array{id: int, catalog_title_id: int, added_at: string}>  $mediaEvents
     * @return Collection<int, array{id: int, added_at: string}>
     */
    private function latestVisibleUpdates(Collection $episodeEvents, Collection $mediaEvents): Collection
    {
        $availableEpisodeIds = $this->availableEpisodeIds($episodeEvents->pluck('id'));
        $availableMediaIds = $this->availableMediaIds($mediaEvents->pluck('id'));
        $eligibleEvents = $episodeEvents
            ->filter(fn (array $event): bool => $availableEpisodeIds->has($event['id']))
            ->concat($mediaEvents->filter(
                fn (array $event): bool => $availableMediaIds->has($event['id']),
            ))
            ->filter(fn (array $event): bool => $event['catalog_title_id'] > 0 && $event['added_at'] !== '')
            ->values();
        $visibleTitleIds = $this->visibleTitleIds($eligibleEvents->pluck('catalog_title_id'));
        $latestByTitle = [];

        foreach ($eligibleEvents as $event) {
            $titleId = $event['catalog_title_id'];

            if (! $visibleTitleIds->has($titleId)
                || (isset($latestByTitle[$titleId])
                    && strcmp($latestByTitle[$titleId]['added_at'], $event['added_at']) >= 0)) {
                continue;
            }

            $latestByTitle[$titleId] = [
                'id' => $titleId,
                'added_at' => $event['added_at'],
            ];
        }

        return collect(array_values($latestByTitle))
            ->sort(function (array $left, array $right): int {
                $dateOrder = strcmp($right['added_at'], $left['added_at']);

                return $dateOrder !== 0 ? $dateOrder : $right['id'] <=> $left['id'];
            })
            ->values();
    }

    /**
     * @param  Collection<int, int>  $eventIds
     * @return Collection<int, true>
     */
    private function availableEpisodeIds(Collection $eventIds): Collection
    {
        $ids = $eventIds
            ->filter(fn (mixed $id): bool => is_int($id) && $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $query = $this->withoutSecondaryIndexes(Episode::query(), 'episodes');

        return $query
            ->availableTo(null)
            ->whereKey($ids->all())
            ->whereIn('season_id', Season::query()->availableTo(null)->select('id'))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->flip()
            ->map(fn (): true => true);
    }

    /**
     * @param  Collection<int, int>  $eventIds
     * @return Collection<int, true>
     */
    private function availableMediaIds(Collection $eventIds): Collection
    {
        $ids = $eventIds
            ->filter(fn (mixed $id): bool => is_int($id) && $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $query = $this->withoutSecondaryIndexes(LicensedMedia::query(), 'licensed_media');

        return $query
            ->published()
            ->forAvailableReleases(null)
            ->whereKey($ids->all())
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->flip()
            ->map(fn (): true => true);
    }

    /**
     * @template TModel of Episode|LicensedMedia
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function withoutSecondaryIndexes(Builder $query, string $table): Builder
    {
        $connection = DB::connection();

        if ($connection->getDriverName() === 'sqlite') {
            $query->fromRaw($connection->getQueryGrammar()->wrapTable($table).' NOT INDEXED');
        }

        return $query;
    }

    /**
     * @param  Collection<int, int>  $titleIds
     * @return Collection<int, true>
     */
    private function visibleTitleIds(Collection $titleIds): Collection
    {
        return $titleIds
            ->filter(fn (mixed $id): bool => is_int($id) && $id > 0)
            ->unique()
            ->chunk(500)
            ->flatMap(fn (Collection $ids): Collection => $this->titles
                ->visibleTo(null)
                ->whereKey($ids->all())
                ->pluck('id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->flip()
            ->map(fn (): true => true);
    }

    /**
     * @param  Collection<int, array{id: int, added_at: string}>  $updates
     * @param  Collection<int, array{id: int, catalog_title_id: int, added_at: string}>  $episodeEvents
     * @param  Collection<int, array{id: int, catalog_title_id: int, added_at: string}>  $mediaEvents
     */
    private function eventWindowCovers(
        Collection $updates,
        Collection $episodeEvents,
        Collection $mediaEvents,
        int $eventWindow,
        int $limit,
    ): bool {
        if ($updates->count() < $limit) {
            return $episodeEvents->count() < $eventWindow && $mediaEvents->count() < $eventWindow;
        }

        $threshold = (string) $updates->get($limit - 1)['added_at'];

        return $this->sourceWindowCovers($episodeEvents, $eventWindow, $threshold)
            && $this->sourceWindowCovers($mediaEvents, $eventWindow, $threshold);
    }

    /**
     * @param  Collection<int, array{id: int, catalog_title_id: int, added_at: string}>  $events
     */
    private function sourceWindowCovers(Collection $events, int $eventWindow, string $threshold): bool
    {
        return $events->count() < $eventWindow
            || strcmp((string) $events->last()['added_at'], $threshold) < 0;
    }

    /**
     * @param  Collection<int, array{id: int, start: CarbonImmutable, end: CarbonImmutable}>  $coordinates
     * @return Collection<int, Episode>
     */
    private function episodesFor(Collection $coordinates): Collection
    {
        $episodeTable = (new Episode)->getTable();
        $seasonTable = (new Season)->getTable();
        $ranked = Episode::query()
            ->join($seasonTable, $seasonTable.'.id', '=', $episodeTable.'.season_id')
            ->availableTo(null)
            ->whereIn(
                $seasonTable.'.id',
                $this->visibleSeasonIds($coordinates->pluck('id')->all()),
            );

        $this->constrainCoordinates(
            $ranked,
            $coordinates,
            $seasonTable.'.catalog_title_id',
            $episodeTable.'.created_at',
        );

        $rankedIds = $this->rankedIds(
            $ranked,
            $episodeTable.'.id',
            $seasonTable.'.catalog_title_id',
            $episodeTable.'.created_at',
            'catalog_home_episode_ids',
        );

        return Episode::query()
            ->select($episodeTable.'.*')
            ->availableTo(null)
            ->whereIn($episodeTable.'.id', $rankedIds)
            ->with([
                'season' => fn ($query) => $query
                    ->availableTo(null)
                    ->select(['id', 'catalog_title_id', 'number', 'kind', 'sort_order', 'title']),
            ])
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
        $ranked = LicensedMedia::query()
            ->published()
            ->forAvailableReleases(null);

        $this->constrainCoordinates(
            $ranked,
            $coordinates,
            $media->qualifyColumn('catalog_title_id'),
            $media->qualifyColumn('created_at'),
        );

        $rankedIds = $this->rankedIds(
            $ranked,
            $media->qualifyColumn('id'),
            $media->qualifyColumn('catalog_title_id'),
            $media->qualifyColumn('created_at'),
            'catalog_home_media_ids',
        );

        return LicensedMedia::query()
            ->published()
            ->forAvailableReleases(null)
            ->whereIn($media->qualifyColumn('id'), $rankedIds)
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
                    ->select(['id', 'season_id', 'number', 'kind', 'sort_order', 'title', 'released_at', 'created_at']),
            ])
            ->orderByDesc($mediaTable.'.created_at')
            ->orderByDesc($mediaTable.'.id')
            ->get();
    }

    /**
     * @param  Builder<Episode>|Builder<LicensedMedia>  $query
     */
    private function rankedIds(
        Builder $query,
        string $idColumn,
        string $titleColumn,
        string $createdAtColumn,
        string $alias,
    ): QueryBuilder {
        $ranked = $query
            ->selectRaw("{$idColumn} AS home_release_id")
            ->selectRaw(
                "ROW_NUMBER() OVER (PARTITION BY {$titleColumn} ORDER BY {$createdAtColumn} DESC, {$idColumn} DESC) AS home_release_rank",
            )
            ->reorder()
            ->toBase();

        return DB::query()
            ->fromSub($ranked, $alias)
            ->select('home_release_id')
            ->where('home_release_rank', '<=', self::RELEASE_ITEMS_PER_TITLE + 1);
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
    private function visibleSeasonIds(array $titleIds = []): Builder
    {
        $seasonTable = (new Season)->getTable();
        $titleTable = (new CatalogTitle)->getTable();
        $visibleTitles = $this->titles->visibleTo(null);

        if ($titleIds !== []) {
            $visibleTitles->whereKey($titleIds);
        }

        return Season::query()
            ->availableTo(null)
            ->whereIn(
                $seasonTable.'.catalog_title_id',
                $visibleTitles->select($titleTable.'.id'),
            )
            ->select($seasonTable.'.id');
    }
}
