<?php

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\Season;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CatalogApiTitleQuery
{
    /**
     * @var list<string>
     */
    private const TITLE_COLUMNS = [
        'id',
        'slug',
        'title',
        'original_title',
        'type',
        'year',
        'description',
        'poster_url',
        'indexed_at',
    ];

    public function __construct(
        private readonly CatalogTaxonomyRegistry $taxonomies,
        private readonly CatalogTitleQuery $titles,
    ) {}

    /**
     * @return LengthAwarePaginator<int, CatalogTitle>
     */
    public function paginateVisible(int $perPage, ?User $user = null): LengthAwarePaginator
    {
        return $this->titles->visibleTo($user)
            ->select(self::TITLE_COLUMNS)
            ->with($this->publicTaxonomyRelations())
            ->withCount($this->publicCounts($user))
            ->orderByDesc('indexed_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findVisibleForApi(CatalogTitle $catalogTitle, ?User $user = null): CatalogTitle
    {
        return $this->titles->visibleTo($user)
            ->select(self::TITLE_COLUMNS)
            ->whereKey($catalogTitle->getKey())
            ->with(array_merge(
                $this->publicTaxonomyRelations(),
                [
                    'seasons' => function ($relation) use ($user): void {
                        $relation
                            ->whereIn('seasons.id', Season::query()->availableTo($user)->select('id'))
                            ->select([
                                'id',
                                'catalog_title_id',
                                'number',
                                'kind',
                                'sort_order',
                                'title',
                                'latest_episode_released_at',
                                'episodes_released',
                                'episodes_total',
                                'translation_name',
                            ])
                            ->with([
                                'episodes' => function ($relation) use ($user): void {
                                    $relation
                                        ->whereIn('episodes.id', Episode::query()->availableTo($user)->select('id'))
                                        ->select(['id', 'season_id', 'number', 'kind', 'sort_order', 'title', 'released_at', 'summary']);
                                },
                            ]);
                    },
                ],
            ))
            ->withCount($this->publicCounts($user))
            ->firstOrFail();
    }

    /** @return array<string, \Closure> */
    private function publicTaxonomyRelations(): array
    {
        return $this->taxonomies->relationSummaryLoads();
    }

    /**
     * @return array<int|string, string|\Closure>
     */
    private function publicCounts(?User $user): array
    {
        $counts = $this->titles->publicCardCounts($user);
        $mediaCount = $counts['licensedMedia as published_media_count'];
        unset($counts['licensedMedia as published_media_count']);
        $counts['licensedMedia as published_licensed_media_count'] = $mediaCount;

        return $counts;
    }
}
