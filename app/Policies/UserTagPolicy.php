<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\UserTag;
use Illuminate\Auth\Access\Response;

final class UserTagPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, UserTag $tag): Response
    {
        return $tag->isOwnedBy($user)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function update(User $user, UserTag $tag): bool
    {
        return $user->hasVerifiedEmail() && $tag->isOwnedBy($user);
    }

    public function delete(User $user, UserTag $tag): bool
    {
        return $this->update($user, $tag);
    }

    public function restore(User $user, UserTag $tag): bool
    {
        return $this->update($user, $tag);
    }

    public function assign(User $user, UserTag $tag): bool
    {
        return $this->update($user, $tag) && $tag->deleted_at === null;
    }
}
