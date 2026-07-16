<?php

declare(strict_types=1);

namespace App\Services\ContentRequests;

use App\Exceptions\ContentRequests\ContentRequestActionException;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

final class ContentRequestRateLimiter
{
    public function hit(string $action, User $user, string $scope): void
    {
        $policy = config('content-requests.rate_limits.'.$action);

        if (! is_array($policy)) {
            throw new ContentRequestActionException('requests.errors.action_unavailable');
        }

        $attempts = max(1, (int) ($policy['attempts'] ?? 1));
        $global = max($attempts, (int) ($policy['global_attempts'] ?? $attempts));
        $decay = max(1, (int) ($policy['decay_seconds'] ?? 60));
        $keys = [
            ['key' => 'content-requests:'.$action.':'.$user->id.':'.hash('sha256', $scope), 'attempts' => $attempts],
            ['key' => 'content-requests:'.$action.':'.$user->id.':global', 'attempts' => $global],
        ];

        if (in_array($action, ['create', 'clarify'], true)) {
            $network = hash('sha256', (string) request()->ip());
            $keys[] = ['key' => 'content-requests:'.$action.':network:'.$network, 'attempts' => $global * 4];
        }

        foreach ($keys as $limit) {
            if (RateLimiter::tooManyAttempts($limit['key'], $limit['attempts'])) {
                $retry = max(1, RateLimiter::availableIn($limit['key']));
                throw new ContentRequestActionException('requests.errors.rate_limited', ['seconds' => $retry], retryAfter: $retry);
            }
        }

        foreach ($keys as $limit) {
            RateLimiter::hit($limit['key'], $decay);
        }
    }
}
