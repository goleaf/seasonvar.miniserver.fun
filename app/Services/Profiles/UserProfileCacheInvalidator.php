<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\Models\UserProfile;
use Illuminate\Support\Facades\Cache;

final class UserProfileCacheInvalidator
{
    public function changed(UserProfile $profile, int $previousVersion): void
    {
        Cache::forget($this->summaryKey($profile, $previousVersion));
        Cache::forget($this->summaryKey($profile, (int) $profile->content_version));
    }

    public function summaryKey(UserProfile $profile, int $version): string
    {
        return 'user-profile:summary:v1:user:'.$profile->user_id.':version:'.$version;
    }
}
