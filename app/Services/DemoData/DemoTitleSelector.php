<?php

declare(strict_types=1);

namespace App\Services\DemoData;

use App\DTOs\DemoData\DemoDataOptions;
use App\DTOs\DemoData\DemoTitleContext;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\Season;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;

final class DemoTitleSelector
{
    /** @var list<int>|null */
    private ?array $publishedIds = null;

    public function __construct(private readonly DemoDataOptions $options) {}

    public function publishedCount(): int
    {
        return count($this->publishedIds());
    }

    /** @return LazyCollection<int, int> */
    public function selectedIds(int $userIndex): LazyCollection
    {
        if ($userIndex < 1 || $userIndex > $this->options->userCount) {
            throw new InvalidArgumentException('Demo user index is outside configured range.');
        }

        $ids = $this->publishedIds();

        return LazyCollection::make(function () use ($ids, $userIndex): iterable {
            $publishedCount = count($ids);

            if ($publishedCount === 0) {
                return;
            }

            $selectedCount = $this->options->selectedTitleCount($publishedCount);
            $step = max(1, intdiv($publishedCount, $this->options->userCount));
            $offset = (($userIndex - 1) * $step) % $publishedCount;

            for ($position = 0; $position < $selectedCount; $position++) {
                yield $ids[($offset + $position) % $publishedCount];
            }
        });
    }

    /**
     * @param  list<int>  $titleIds
     * @return Collection<int, DemoTitleContext>
     */
    public function contexts(array $titleIds): Collection
    {
        $titleIds = array_values(array_unique(array_map('intval', $titleIds)));

        if ($titleIds === []) {
            return collect();
        }

        $titles = CatalogTitle::query()
            ->select(['id', 'title', 'original_title', 'year'])
            ->with(['genres' => fn ($query) => $query->select(['genres.id', 'genres.name'])])
            ->whereIn('id', $titleIds)
            ->get()
            ->keyBy('id');

        $seasons = Season::query()
            ->published()
            ->select(['id', 'catalog_title_id', 'kind', 'sort_order', 'number'])
            ->whereIn('catalog_title_id', $titleIds)
            ->orderBy('kind')
            ->orderBy('sort_order')
            ->orderBy('number')
            ->orderBy('id')
            ->get()
            ->groupBy('catalog_title_id');

        $episodes = Episode::query()
            ->published()
            ->select(['id', 'season_id', 'kind', 'sort_order', 'number'])
            ->with('season:id,catalog_title_id')
            ->whereHas('season', fn ($query) => $query->whereIn('catalog_title_id', $titleIds))
            ->orderBy('kind')
            ->orderBy('sort_order')
            ->orderBy('number')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Episode $episode): int => (int) $episode->season->catalog_title_id);

        $media = LicensedMedia::query()
            ->published()
            ->withPlaybackLocation()
            ->select(['id', 'catalog_title_id', 'episode_id', 'duration_seconds'])
            ->whereIn('catalog_title_id', $titleIds)
            ->whereNotNull('episode_id')
            ->orderBy('id')
            ->get()
            ->groupBy('catalog_title_id');

        return $titles->mapWithKeys(function (CatalogTitle $title) use ($seasons, $episodes, $media): array {
            /** @var Season|null $firstSeason */
            $firstSeason = $seasons->get($title->id)?->first();
            /** @var Episode|null $firstEpisode */
            $firstEpisode = $episodes->get($title->id)?->first();
            /** @var Episode|null $lastEpisode */
            $lastEpisode = $episodes->get($title->id)?->last();
            /** @var LicensedMedia|null $firstMedia */
            $firstMedia = $media->get($title->id)?->first();

            return [$title->id => new DemoTitleContext(
                titleId: (int) $title->id,
                displayTitle: $title->display_title,
                year: $title->year,
                firstSeasonId: $firstSeason?->id,
                firstEpisodeId: $firstMedia?->episode_id ?? $firstEpisode?->id,
                lastEpisodeId: $lastEpisode?->id,
                licensedMediaId: $firstMedia?->id,
                durationSeconds: $firstMedia?->duration_seconds,
                genreNames: $title->genres->pluck('name')->map(strval(...))->values()->all(),
            )];
        });
    }

    /** @return list<int> */
    private function publishedIds(): array
    {
        if ($this->publishedIds === null) {
            $this->publishedIds = CatalogTitle::query()
                ->published()
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
        }

        return $this->publishedIds;
    }
}
