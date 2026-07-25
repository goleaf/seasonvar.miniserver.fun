<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ReleaseCalendarFeed;
use App\Models\User;

final class ReleaseCalendarFeedPolicy
{
    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, ReleaseCalendarFeed $feed): bool
    {
        return $feed->user_id === $user->id;
    }

    public function update(User $user, ReleaseCalendarFeed $feed): bool
    {
        return $this->view($user, $feed);
    }

    public function delete(User $user, ReleaseCalendarFeed $feed): bool
    {
        return $this->view($user, $feed);
    }
}
