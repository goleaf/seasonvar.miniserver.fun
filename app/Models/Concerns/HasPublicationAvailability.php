<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Services\Catalog\CatalogEntitlementService;
use Illuminate\Database\Eloquent\Builder;

trait HasPublicationAvailability
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublished(Builder $query): Builder
    {
        return CatalogEntitlementService::constrainQuery($query, null);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAvailableTo(Builder $query, ?User $user): Builder
    {
        return CatalogEntitlementService::constrainQuery($query, $user);
    }
}
