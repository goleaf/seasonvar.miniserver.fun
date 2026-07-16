<?php

declare(strict_types=1);

namespace App\Actions\ContentRequests;

use App\Models\ContentRequestNotificationPreference;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class UpdateContentRequestNotificationPreferences
{
    /** @param array{requester_updates: bool, voted_updates: bool, followed_updates: bool} $preferences */
    public function handle(User $user, array $preferences): ContentRequestNotificationPreference
    {
        Gate::forUser($user)->authorize('update-account-settings');

        return ContentRequestNotificationPreference::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'requester_updates' => (bool) $preferences['requester_updates'],
                'voted_updates' => (bool) $preferences['voted_updates'],
                'followed_updates' => (bool) $preferences['followed_updates'],
            ],
        );
    }
}
