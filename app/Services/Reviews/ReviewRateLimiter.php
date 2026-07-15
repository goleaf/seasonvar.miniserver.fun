<?php

declare(strict_types=1);

namespace App\Services\Reviews;

use App\Exceptions\Reviews\ReviewActionException;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

final class ReviewRateLimiter
{
    public function hit(string $action, User $user, string $scope): void
    {
        $this->hitForKey($action, 'user:'.$user->id, $scope);
    }

    public function hitGuest(string $action, string $networkAddress, string $scope): void
    {
        $this->hitForKey(
            $action,
            'guest:'.hash('sha256', $networkAddress),
            $scope,
        );
    }

    private function hitForKey(string $action, string $actorKey, string $scope): void
    {
        $policy = config('reviews.rate_limits.'.$action);

        if (! is_array($policy)) {
            throw new ReviewActionException('reviews.errors.action_unavailable');
        }

        $attempts = max(1, (int) ($policy['attempts'] ?? 1));
        $decay = max(1, (int) ($policy['decay_seconds'] ?? 60));
        $key = 'reviews:'.$action.':'.$actorKey.':'.hash('sha256', $scope);

        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            $retryAfter = max(1, RateLimiter::availableIn($key));

            throw new ReviewActionException(
                'reviews.errors.rate_limited',
                ['seconds' => $retryAfter],
                $retryAfter,
            );
        }

        RateLimiter::hit($key, $decay);
    }
}
