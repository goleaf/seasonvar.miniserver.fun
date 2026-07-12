<?php

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
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
        'is_published',
        'indexed_at',
    ];

    public function __construct(
        private readonly CatalogTaxonomyRegistry $taxonomies,
    ) {}

    /**
     * @return LengthAwarePaginator<int, CatalogTitle>
     */
    public function paginatePublished(int $perPage): LengthAwarePaginator
    {
        return CatalogTitle::query()
            ->select(self::TITLE_COLUMNS)
            ->published()
            ->with($this->publicTaxonomyRelations())
            ->withCount($this->publicCounts())
            ->orderByDesc('indexed_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findPublishedForApi(CatalogTitle $catalogTitle): CatalogTitle
    {
        return CatalogTitle::query()
            ->select(self::TITLE_COLUMNS)
            ->whereKey($catalogTitle->getKey())
            ->published()
            ->with(array_merge(
                $this->publicTaxonomyRelations(),
                [
                    'seasons' => function (HasMany $query): void {
                        $query
                            ->published()
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
                                'episodes' => function (HasMany $query): void {
                                    $query
                                        ->published()
                                        ->select(['id', 'season_id', 'number', 'kind', 'sort_order', 'title', 'released_at', 'summary']);
                                },
                            ]);
                    },
                ],
            ))
            ->withCount($this->publicCounts())
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
    private function publicCounts(): array
    {
        return [
            'seasons' => fn (Builder $query): Builder => $query->published(),
            'episodes' => fn (Builder $query): Builder => $query
                ->published()
                ->whereHas('season', fn (Builder $query): Builder => $query->published()),
            'publishedLicensedMedia',
        ];
    }
}
