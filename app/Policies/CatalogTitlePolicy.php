<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\CatalogTitle;
use App\Models\User;
use App\Services\Catalog\CatalogEntitlementService;
use Illuminate\Support\Facades\Gate;

class CatalogTitlePolicy
{
    public function __construct(
        private readonly CatalogEntitlementService $entitlements,
    ) {}

    public function interact(User $user, CatalogTitle $catalogTitle): bool
    {
        return $user->hasVerifiedEmail()
            && $this->entitlements
                ->constrain(CatalogTitle::query(), $user)
                ->whereKey($catalogTitle->id)
                ->exists();
    }

    public function viewAdmin(User $user, CatalogTitle $catalogTitle): bool
    {
        return Gate::forUser($user)->allows(AdminPermission::ContentView->value);
    }

    public function update(User $user, CatalogTitle $catalogTitle): bool
    {
        return Gate::forUser($user)->allows(AdminPermission::ContentManage->value);
    }

    public function archive(User $user, CatalogTitle $catalogTitle): bool
    {
        return Gate::forUser($user)->allows(AdminPermission::ContentDelete->value);
    }
}
