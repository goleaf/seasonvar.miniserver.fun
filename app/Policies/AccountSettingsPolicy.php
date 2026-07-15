<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class AccountSettingsPolicy
{
    public function view(User $user): bool
    {
        return $user->exists;
    }

    public function update(User $user): bool
    {
        return $user->exists;
    }
}
