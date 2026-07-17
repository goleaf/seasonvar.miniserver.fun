<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Country;
use App\Models\Genre;
use Illuminate\Support\Collection;

final class CatalogTopListFilterOptions
{
    /** @return Collection<int, array{name: string, slug: string}> */
    public function countries(): Collection
    {
        return Country::cachedCatalogFilterOptions()
            ->get()
            ->map(fn (Country $country): array => [
                'name' => $country->name,
                'slug' => $country->slug,
            ])
            ->values();
    }

    /** @return Collection<int, array{name: string, slug: string}> */
    public function genres(): Collection
    {
        return Genre::cachedCatalogFilterOptions()
            ->get()
            ->map(fn (Genre $genre): array => [
                'name' => $genre->name,
                'slug' => $genre->slug,
            ])
            ->values();
    }
}
