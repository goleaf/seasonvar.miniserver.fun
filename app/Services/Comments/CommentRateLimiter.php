<?php

declare(strict_types=1);

namespace App\Services\Comments;

use App\Exceptions\Comments\CommentActionException;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

final class CommentRateLimiter
{
    public function hit(string $action, User $user, string $scope): void
    {
        $policy = config('comments.rate_limits.'.$action);

        if (! is_array($policy)) {
            throw new CommentActionException('comments.errors.action_unavailable');
        }

        $attempts = max(1, (int) ($policy['attempts'] ?? 1));
        $globalAttempts = max($attempts, (int) ($policy['global_attempts'] ?? $attempts));
        $decay = max(1, (int) ($policy['decay_seconds'] ?? 60));
        $keys = [
            ['key' => 'comments:'.$action.':'.$user->id.':'.hash('sha256', $scope), 'attempts' => $attempts],
            ['key' => 'comments:'.$action.':'.$user->id.':global', 'attempts' => $globalAttempts],
        ];
        $exhausted = collect($keys)
            ->filter(fn (array $limit): bool => RateLimiter::tooManyAttempts($limit['key'], $limit['attempts']));

        if ($exhausted->isNotEmpty()) {
            $retryAfter = max(1, (int) $exhausted
                ->max(fn (array $limit): int => RateLimiter::availableIn($limit['key'])));

            throw new CommentActionException(
                'comments.errors.rate_limited',
                ['seconds' => $retryAfter],
                $retryAfter,
            );
        }

        foreach ($keys as $limit) {
            RateLimiter::hit($limit['key'], $decay);
        }
    }
}
