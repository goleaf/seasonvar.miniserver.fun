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

    /** @return Collection<int, Actor|Director> */
    public function search(string $type, string $query, ?User $user): Collection
    {
        $modelClass = $type === 'director' ? Director::class : Actor::class;
        $model = new $modelClass;
        $search = str_replace(['%', '_'], '', $query);
        $slugSearch = str_replace(['%', '_'], '', str($query)->slug()->toString());

        return $modelClass::query()
            ->select([$model->getTable().'.id', $model->getTable().'.name', $model->getTable().'.slug'])
            ->where(function (Builder $builder) use ($model, $search, $slugSearch): void {
                $builder->where($model->qualifyColumn('name'), 'like', "%{$search}%")
                    ->orWhere($model->qualifyColumn('slug'), 'like', "%{$slugSearch}%");
            })
            ->whereHas('catalogTitles', fn (Builder $builder): Builder => $this->titles->constrainVisible($builder, $user))
            ->withCount([
                'catalogTitles as public_titles_count' => fn (Builder $builder): Builder => $this->titles->constrainVisible($builder, $user),
            ])
            ->orderByDesc('public_titles_count')
            ->orderBy($model->qualifyColumn('name'))
            ->orderBy($model->qualifyColumn('id'))
            ->limit(20)
            ->get()
            ->each->setAttribute('filter_type', $type);
    }
}
