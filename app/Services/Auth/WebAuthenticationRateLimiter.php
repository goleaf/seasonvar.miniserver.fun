<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

final class WebAuthenticationRateLimiter
{
    private const DECAY_SECONDS = 60;

    public function loginKey(string $email, ?string $ipAddress): string
    {
        return 'web-auth:login:'.Str::lower(Str::squish($email)).'|'.($ipAddress ?? 'unknown');
    }

    public function registrationKey(?string $ipAddress): string
    {
        return 'web-auth:register:'.($ipAddress ?? 'unknown');
    }

    public function tooManyAttempts(string $key, int $maximumAttempts): bool
    {
        return RateLimiter::tooManyAttempts($key, $maximumAttempts);
    }

    public function verificationKey(int $userId): string
    {
        return 'web-auth:verification:'.$userId;
    }

    public function forgotPasswordKey(string $email, ?string $ipAddress): string
    {
        return 'web-auth:forgot-password:'.Str::lower(Str::squish($email)).'|'.($ipAddress ?? 'unknown');
    }

    public function resetPasswordKey(string $email, ?string $ipAddress): string
    {
        return 'web-auth:reset-password:'.Str::lower(Str::squish($email)).'|'.($ipAddress ?? 'unknown');
    }

    public function hit(string $key, int $decaySeconds = self::DECAY_SECONDS): void
    {
        RateLimiter::hit($key, $decaySeconds);
    }

    public function clear(string $key): void
    {
        RateLimiter::clear($key);
    }

    public function availableIn(string $key): int
    {
        return RateLimiter::availableIn($key);
    }
}
