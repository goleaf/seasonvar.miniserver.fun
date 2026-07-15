<?php

declare(strict_types=1);

namespace App\Services\Catalog\Search;

use App\Models\Actor;
use App\Models\Director;
use App\Models\User;
use App\Services\Catalog\CatalogTitleQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class CatalogPeopleLookup
{
    public function __construct(private CatalogTitleQuery $titles) {}

    /** @return Collection<int, Actor>|Collection<int, Director> */
    public function search(string $type, string $query, ?User $user): Collection
    {
        return $type === 'director'
            ? $this->searchDirectors($type, $query, $user)
            : $this->searchActors($type, $query, $user);
    }

    /** @return Collection<int, Actor> */
    private function searchActors(string $type, string $query, ?User $user): Collection
    {
        $builder = Actor::query();
        $this->configureSearch($builder, new Actor, $query, $user);

        return $builder->get()->each->setAttribute('filter_type', $type);
    }

    /** @return Collection<int, Director> */
    private function searchDirectors(string $type, string $query, ?User $user): Collection
    {
        $builder = Director::query();
        $this->configureSearch($builder, new Director, $query, $user);

        return $builder->get()->each->setAttribute('filter_type', $type);
    }

    /** @param Builder<Actor>|Builder<Director> $queryBuilder */
    private function configureSearch(
        Builder $queryBuilder,
        Actor|Director $model,
        string $query,
        ?User $user,
    ): void {
        $search = str_replace(['%', '_'], '', $query);
        $slugSearch = str_replace(['%', '_'], '', str($query)->slug()->toString());
        $visibleTitleIds = $this->titles->visibleTo($user)->select('catalog_titles.id');

        $queryBuilder
            ->select([$model->getTable().'.id', $model->getTable().'.name', $model->getTable().'.slug'])
            ->where(function (Builder $builder) use ($model, $search, $slugSearch): void {
                $builder->where($model->qualifyColumn('name'), 'like', "%{$search}%")
                    ->orWhere($model->qualifyColumn('slug'), 'like', "%{$slugSearch}%");
            })
            ->whereHas(
                'catalogTitles',
                fn (Builder $builder): Builder => $builder->whereIn('catalog_titles.id', $visibleTitleIds),
            )
            ->withCount([
                'catalogTitles as public_titles_count' => fn (Builder $builder): Builder => $builder->whereIn(
                    'catalog_titles.id',
                    $this->titles->visibleTo($user)->select('catalog_titles.id'),
                ),
            ])
            ->orderByDesc('public_titles_count')
            ->orderBy($model->qualifyColumn('name'))
            ->orderBy($model->qualifyColumn('id'))
            ->limit(20);
    }
}
