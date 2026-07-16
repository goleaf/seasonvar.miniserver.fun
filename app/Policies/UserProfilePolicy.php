<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\UserBlock;
use App\Models\UserProfile;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

final class UserProfilePolicy
{
    public function view(?User $viewer, UserProfile $profile): Response
    {
        if ($viewer !== null && ((int) $viewer->id === (int) $profile->user_id || Gate::forUser($viewer)->allows('manage-catalog'))) {
            return Response::allow();
        }

        if (! $profile->isPublic()) {
            return Response::denyAsNotFound();
        }

        if ($viewer !== null && UserBlock::query()
            ->where(function ($query) use ($viewer, $profile): void {
                $query->where('blocker_id', $viewer->id)->where('blocked_id', $profile->user_id);
            })
            ->orWhere(function ($query) use ($viewer, $profile): void {
                $query->where('blocker_id', $profile->user_id)->where('blocked_id', $viewer->id);
            })
            ->exists()) {
            return Response::denyAsNotFound();
        }

        return Response::allow();
    }

    public function update(User $user, UserProfile $profile): bool
    {
        return (int) $user->id === (int) $profile->user_id;
    }

    public function updateMedia(User $user, UserProfile $profile): bool
    {
        return $this->update($user, $profile);
    }

    public function report(User $user, UserProfile $profile): bool
    {
        return $user->hasVerifiedEmail()
            && (int) $user->id !== (int) $profile->user_id
            && $profile->isPublic();
    }

    public function moderate(User $user): bool
    {
        return Gate::forUser($user)->allows('manage-catalog');
    }
}
