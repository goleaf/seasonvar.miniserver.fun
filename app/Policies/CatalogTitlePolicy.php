<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Catalog\CatalogEntitlementService;

class CatalogTitlePolicy
{
    public function __construct(
        private readonly CatalogEntitlementService $entitlements,
    ) {}

    public function interact(User $user, CatalogTitle $catalogTitle): bool
    {
        return $this->entitlements
            ->constrain(CatalogTitle::query(), $user)
            ->whereKey($catalogTitle->id)
            ->exists();
    }
}
