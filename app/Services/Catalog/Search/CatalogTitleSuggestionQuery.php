<?php

declare(strict_types=1);

namespace App\Services\Catalog\Search;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\Season;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class CatalogTitleSuggestionQuery
{
    public function __construct(
        private CatalogTitleSearch $search,
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
        $candidates = $this->search->candidateQuery($query);

        if ($candidates !== null) {
            $candidateIds = $candidates
                ->limit($limit)
                ->pluck('catalog_title_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->values();

            if ($candidateIds->isEmpty()) {
                return collect();
            }

            $titlesById = $this->summaryQuery($user)
                ->whereKey($candidateIds)
                ->get()
                ->keyBy('id');

            return $this->loadPublicReleaseCounts($candidateIds
                ->map(fn (int $id): ?CatalogTitle => $titlesById->get($id))
                ->filter()
                ->values(), $user);
        }

        $titles = $this->matchingNamesQuery($query, $user)
            ->with('aliases:id,catalog_title_id,name')
            ->orderByDesc('catalog_titles.indexed_at')
            ->orderByDesc('catalog_titles.id')
            ->limit(max(40, $limit * 8))
            ->get()
            ->sortBy(fn (CatalogTitle $title): array => [
                $this->rank($title, $query->normalized),
                $this->normalizer->key($title->display_title),
                $title->id,
            ])
            ->take($limit)
            ->values();

        return $this->loadPublicReleaseCounts($titles, $user);
    }

    public function count(CatalogSearchQuery $query, ?User $user): int
    {
        if (! $query->isReady()) {
            return 0;
        }

        $matchingIds = $this->search->matchingTitleIdsQuery($query);

        if ($matchingIds !== null) {
            return $this->titles->visibleTo($user)
                ->whereIn('catalog_titles.id', $matchingIds)
                ->count();
        }

        return $this->matchingNamesQuery($query, $user)->count();
    }

    /** @return Builder<CatalogTitle> */
    private function summaryQuery(?User $user): Builder
    {
        return $this->titles->visibleTo($user)->select([
            'catalog_titles.id',
            'catalog_titles.slug',
            'catalog_titles.title',
            'catalog_titles.original_title',
            'catalog_titles.type',
            'catalog_titles.year',
            'catalog_titles.poster_url',
            'catalog_titles.indexed_at',
        ]);
    }

    /** @return Builder<CatalogTitle> */
    private function matchingNamesQuery(CatalogSearchQuery $query, ?User $user): Builder
    {
        $variants = collect($this->normalizer->legacyVariants(str_replace(['%', '_'], '', $query->raw)))
            ->filter(fn (string $variant): bool => $variant !== '')
            ->take(12)
            ->values();

        if ($variants->isEmpty()) {
            return $this->summaryQuery($user)->whereRaw('1 = 0');
        }

        return $this->summaryQuery($user)->where(function (Builder $builder) use ($variants): void {
            $variants->each(function (string $variant) use ($builder): void {
                $builder->orWhere('catalog_titles.title', 'like', "%{$variant}%")
                    ->orWhere('catalog_titles.original_title', 'like', "%{$variant}%");
            });

            $builder->orWhereHas('aliases', function (Builder $aliases) use ($variants): void {
                $variants->each(
                    fn (string $variant): Builder => $aliases->orWhere('name', 'like', "%{$variant}%"),
                );
            });
        });
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
