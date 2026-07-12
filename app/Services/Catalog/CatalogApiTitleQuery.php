<?php

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
                    'seasons' => function (HasMany $query) use ($user): void {
                        $query
                            ->availableTo($user)
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
                                'episodes' => function (HasMany $query) use ($user): void {
                                    $query
                                        ->availableTo($user)
                                        ->select(['id', 'season_id', 'number', 'kind', 'sort_order', 'title', 'released_at', 'summary']);
                                },
                            ]);
                    },
                ],
            ))
            ->withCount($this->publicCounts($user))
            ->firstOrFail();
    }

    /**
     * @return array<string, \Closure(BelongsToMany): void>
     */
    private function publicTaxonomyRelations(): array
    {
        $relations = [];

        foreach ($this->taxonomies->relationNames() as $relation) {
            $relations[$relation] = function (BelongsToMany $query): void {
                $model = $query->getRelated();

                $query
                    ->select([
                        $model->qualifyColumn('id'),
                        $model->qualifyColumn('name'),
                        $model->qualifyColumn('slug'),
                    ])
                    ->orderBy($model->qualifyColumn('name'));
            };
        }

        return $relations;
    }

    /**
     * @return array<int|string, string|\Closure(Builder): Builder>
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
