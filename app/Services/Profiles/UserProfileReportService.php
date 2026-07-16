<?php

declare(strict_types=1);

namespace App\Services\Profiles;

use App\Enums\UserProfileReportCategory;
use App\Enums\UserProfileReportStatus;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserProfileReport;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UserProfileReportService
{
    public function report(
        User $reporter,
        UserProfile $profile,
        UserProfileReportCategory $category,
        ?string $details,
    ): UserProfileReport {
        Gate::forUser($reporter)->authorize('report', $profile);
        $key = 'profile-report:'.$reporter->id;
        $attempts = max(1, (int) config('user-profiles.reports.attempts', 4));
        $decay = max(60, (int) config('user-profiles.reports.decay_seconds', 3600));

        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            throw ValidationException::withMessages(['reportCategory' => [__('profiles.errors.rate_limited')]]);
        }

        RateLimiter::hit($key, $decay);
        $details = is_string($details) ? trim(strip_tags($details)) : null;
        $maximum = max(1, (int) config('user-profiles.reports.maximum_details_length', 1500));
        $details = $details !== '' ? Str::limit($details, $maximum, '') : null;
        $deduplicationKey = hash('sha256', implode(':', [
            $profile->user_id,
            $reporter->id,
            $category->value,
            $profile->content_version,
        ]));
        $profile->loadMissing('user:id,public_id');

        return UserProfileReport::query()->firstOrCreate(
            ['deduplication_key' => $deduplicationKey],
            [
                'public_id' => (string) Str::uuid(),
                'target_user_id' => $profile->user_id,
                'target_public_id' => $profile->user->public_id,
                'reporter_id' => $reporter->id,
                'category' => $category->value,
                'details' => $details,
                'status' => UserProfileReportStatus::Open->value,
            ],
        );
    }
}
