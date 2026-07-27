<?php

declare(strict_types=1);

namespace App\Services\Catalog\Queries;

use App\Models\Country;
use App\Services\Catalog\CatalogFacetQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final readonly class CatalogHomeFacetGroupsQuery
{
    public function __construct(private CatalogFacetQuery $facets) {}

    /** @return Collection<string, Collection<int, Model>> */
    public function handle(): Collection
    {
        $groups = $this->facets->taxonomyGroups(
            ['genre', 'country'],
            ['genre' => null, 'country' => null],
        );

        $groups->get('country', collect())->each(function (Model $country): void {
            if (! $country instanceof Country) {
                return;
            }

            $country->setAttribute('detail_url', route('titles.taxonomy', [
                'type' => 'country',
                'taxonomy' => $country->getAttribute('slug'),
            ]));
        });

        return $groups;
    }
}
