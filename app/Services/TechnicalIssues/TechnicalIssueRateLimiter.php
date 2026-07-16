<?php

declare(strict_types=1);

namespace App\Services\TechnicalIssues;

use App\Exceptions\TechnicalIssues\TechnicalIssueActionException;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

final class TechnicalIssueRateLimiter
{
    public function ensure(User $user, string $action): void
    {
        [$attempts, $seconds] = match ($action) {
            'create' => [(int) config('technical-issues.rate_limits.create_per_hour', 6), 3600],
            'engagement' => [(int) config('technical-issues.rate_limits.engagement_per_minute', 20), 60],
            'message' => [(int) config('technical-issues.rate_limits.message_per_minute', 8), 60],
            'reopen' => [(int) config('technical-issues.rate_limits.reopen_per_day', 3), 86400],
            default => [(int) config('technical-issues.rate_limits.update_per_minute', 12), 60],
        };
        $key = 'technical-issue:'.$action.':'.$user->id;

        if (! RateLimiter::attempt($key, max(1, $attempts), static fn (): bool => true, $seconds)) {
            throw new TechnicalIssueActionException('issues.errors.rate_limited');
        }
    }
}
