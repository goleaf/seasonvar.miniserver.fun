<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\Models\UserProfile;
use App\Support\Cache\CacheDomain;
use App\Support\Cache\CacheVersionRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class UserProfileCacheInvalidator
{
    public function __construct(private readonly CacheVersionRegistry $versions) {}

    public function changed(UserProfile $profile, int $previousVersion): void
    {
        $invalidate = function () use ($profile, $previousVersion): void {
            Cache::forget($this->summaryKey($profile, $previousVersion));
            Cache::forget($this->summaryKey($profile, (int) $profile->content_version));
            $this->versions->bump(CacheDomain::SearchSuggestions);
        };

        DB::transactionLevel() > 0 ? DB::afterCommit($invalidate) : $invalidate();
    }

    public function deleted(UserProfile $profile): void
    {
        $invalidate = function () use ($profile): void {
            Cache::forget($this->summaryKey($profile, (int) $profile->content_version));
            $this->versions->bump(CacheDomain::SearchSuggestions);
        };

        DB::transactionLevel() > 0 ? DB::afterCommit($invalidate) : $invalidate();
    }

    public function summaryKey(UserProfile $profile, int $version): string
    {
        return 'user-profile:summary:v1:user:'.$profile->user_id.':version:'.$version;
    }
}
