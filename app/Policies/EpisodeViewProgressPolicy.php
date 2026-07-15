<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EpisodeViewProgress;
use App\Models\User;

final class EpisodeViewProgressPolicy
{
    public function deleteAny(User $user): bool
    {
        return $user->hasVerifiedEmail();
    }

    public function delete(User $user, EpisodeViewProgress $progress): bool
    {
        return $user->hasVerifiedEmail() && $progress->user_id === $user->id;
    }
}
