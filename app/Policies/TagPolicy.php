<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

final class TagPolicy
{
    public function view(?User $user, Tag $tag): Response
    {
        return $tag->isPubliclyEligible()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return Gate::forUser($user)->allows('manage-catalog');
    }

    public function update(User $user, Tag $tag): bool
    {
        return Gate::forUser($user)->allows('manage-catalog');
    }

    public function archive(User $user, Tag $tag): bool
    {
        return $this->update($user, $tag);
    }

    public function restore(User $user, Tag $tag): bool
    {
        return $this->update($user, $tag);
    }

    public function merge(User $user, Tag $tag): bool
    {
        return $this->update($user, $tag);
    }

    public function assign(User $user, Tag $tag): bool
    {
        return $this->update($user, $tag);
    }

    public function moderate(User $user, Tag $tag): bool
    {
        return $this->update($user, $tag);
    }
}
