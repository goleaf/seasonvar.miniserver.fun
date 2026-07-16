<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Country;
use Illuminate\Support\Collection;

final class CatalogTopListFilterOptions
{
    /** @return Collection<int, array{name: string, slug: string}> */
    public function countries(): Collection
    {
        return Country::query()
            ->select(['id', 'name', 'slug'])
            ->orderBy('name')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (Country $country): array => [
                'name' => $country->name,
                'slug' => $country->slug,
            ])
            ->values();
    }
}
