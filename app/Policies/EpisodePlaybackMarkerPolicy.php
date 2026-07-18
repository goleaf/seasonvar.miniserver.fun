<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EpisodePlaybackMarker;
use App\Models\User;

final class EpisodePlaybackMarkerPolicy
{
    public function view(User $user, EpisodePlaybackMarker $marker): bool
    {
        return $marker->user_id === $user->id;
    }

    public function update(User $user, EpisodePlaybackMarker $marker): bool
    {
        return $user->hasVerifiedEmail() && $marker->user_id === $user->id;
    }

    public function delete(User $user, EpisodePlaybackMarker $marker): bool
    {
        return $this->update($user, $marker);
    }
}
