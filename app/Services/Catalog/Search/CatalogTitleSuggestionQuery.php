<?php

declare(strict_types=1);

namespace App\Services\Catalog\Search;

use App\Enums\CatalogSort;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use Illuminate\Support\Collection;

final readonly class CatalogTitleSuggestionQuery
{
    public function __construct(
        private CatalogTitleQuery $titles,
        private CatalogSearchNormalizer $normalizer,
    ) {}

    /** @return Collection<int, CatalogTitle> */
    public function search(CatalogSearchQuery $query, ?User $user, int $limit = 5): Collection
    {
        if (! $query->isReady()) {
            return collect();
        }

        $limit = max(1, min(25, $limit));
        $matches = $this->titles->matchingTitles($query, $user)
            ->select([
                'catalog_titles.id',
                'catalog_titles.slug',
                'catalog_titles.title',
                'catalog_titles.original_title',
                'catalog_titles.type',
                'catalog_titles.year',
                'catalog_titles.poster_url',
                'catalog_titles.indexed_at',
            ])
            ->with('aliases:id,catalog_title_id,name')
            ->limit(max(40, $limit * 8));

        $titles = $this->titles
            ->sorted($matches, CatalogSort::Relevance)
            ->get()
            ->values()
            ->map(fn (CatalogTitle $title, int $position): array => [
                'title' => $title,
                'rank' => $this->rank($title, $query->normalized),
                'position' => $position,
            ])
            ->sortBy(fn (array $row): array => [$row['rank'], $row['position']])
            ->take($limit)
            ->pluck('title')
            ->values();

        return $this->loadPublicReleaseCounts($titles, $user);
    }

    public function count(CatalogSearchQuery $query, ?User $user): int
    {
        if (! $query->isReady()) {
            return 0;
        }

        return $this->titles->matchingTitles($query, $user)->count();
    }

    /**
     * @param  Collection<int, CatalogTitle>  $titles
     * @return Collection<int, CatalogTitle>
     */
    private function loadPublicReleaseCounts(Collection $titles, ?User $user): Collection
    {
        $titleIds = $titles->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        if ($titleIds === []) {
            return $titles;
        }

        $availableSeasons = Season::query()
            ->availableTo($user)
            ->whereIn('catalog_title_id', $titleIds)
            ->get(['id', 'catalog_title_id']);
        $seasonsByTitle = $availableSeasons->groupBy('catalog_title_id');
        $seasonIds = $availableSeasons->pluck('id')->all();
        $episodeCountsBySeason = $seasonIds === []
            ? collect()
            : Episode::query()
                ->availableTo($user)
                ->whereIn('season_id', $seasonIds)
                ->selectRaw('season_id, COUNT(*) AS aggregate_count')
                ->groupBy('season_id')
                ->pluck('aggregate_count', 'season_id');

        return $titles->each(function (CatalogTitle $title) use ($episodeCountsBySeason, $seasonsByTitle): void {
            $seasons = $seasonsByTitle->get($title->id, collect());

            $title->setAttribute('seasons_count', $seasons->count());
            $title->setAttribute('episodes_count', $seasons->sum(
                fn (Season $season): int => (int) $episodeCountsBySeason->get($season->id, 0),
            ));
        });
    }

    private function rank(CatalogTitle $title, string $needle): int
    {
        return collect([
            $title->title,
            $title->original_title,
            ...$title->aliases->pluck('name')->all(),
        ])
            ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
            ->map(fn (string $name): int => $this->nameRank($this->normalizer->key($name), $needle))
            ->min() ?? 4;
    }

    private function nameRank(string $name, string $needle): int
    {
        return match (true) {
            $name === $needle => 0,
            str_starts_with($name, $needle) => 1,
            str_contains(' '.$name, ' '.$needle) => 2,
            str_contains($name, $needle) => 3,
            default => 4,
        };
    }
}
