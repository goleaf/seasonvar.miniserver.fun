<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CatalogTitle;
use App\Models\User;

class CatalogTitlePolicy
{
    public function interact(User $user, CatalogTitle $catalogTitle): bool
    {
        return CatalogTitle::query()
            ->availableTo($user)
            ->whereKey($catalogTitle->id)
            ->exists();
    }
}
