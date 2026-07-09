<?php

namespace App\Services\Catalog;

use App\Models\CatalogTitle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
            ->where('is_published', true)
            ->with($this->publicTaxonomyRelations())
            ->withCount(['seasons', 'episodes', 'publishedLicensedMedia'])
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
            ->where('is_published', true)
            ->with(array_merge(
                $this->publicTaxonomyRelations(),
                [
                    'seasons' => function (HasMany $query): void {
                        $query
                            ->select([
                                'id',
                                'catalog_title_id',
                                'number',
                                'title',
                                'latest_episode_released_at',
                                'episodes_released',
                                'episodes_total',
                                'translation_name',
                            ])
                            ->orderBy('number')
                            ->with([
                                'episodes' => function (HasMany $query): void {
                                    $query
                                        ->select(['id', 'season_id', 'number', 'title', 'released_at', 'summary'])
                                        ->orderBy('number');
                                },
                            ]);
                    },
                ],
            ))
            ->withCount(['seasons', 'episodes', 'publishedLicensedMedia'])
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
}
