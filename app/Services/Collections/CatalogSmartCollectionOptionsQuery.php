<?php

declare(strict_types=1);

namespace App\Services\Collections;

use App\Models\Actor;
use App\Models\Country;
use App\Models\Genre;
use App\Support\PlainText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class CatalogSmartCollectionOptionsQuery
{
    /** @return Collection<int, Country> */
    public function countries(): Collection
    {
        return Country::query()
            ->select(['id', 'name', 'slug'])
            ->orderBy('name')
            ->orderBy('id')
            ->limit(300)
            ->get();
    }

    /** @return Collection<int, Genre> */
    public function genres(): Collection
    {
        return Genre::query()
            ->select(['id', 'name', 'slug'])
            ->orderBy('name')
            ->orderBy('id')
            ->limit(300)
            ->get();
    }

    /** @return Collection<int, Actor> */
    public function actors(string $search): Collection
    {
        $search = PlainText::clean($search, 80);

        if (mb_strlen($search) < 2) {
            return new Collection;
        }

        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

        return Actor::query()
            ->select(['id', 'name', 'slug'])
            ->where(fn (Builder $actors): Builder => $actors
                ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                ->orWhereRaw("slug LIKE ? ESCAPE '!'", [$pattern]))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(10)
            ->get();
    }
}
