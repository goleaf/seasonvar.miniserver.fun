<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\RateLimiter;

final class WebAuthenticationRateLimiter
{
    private const DECAY_SECONDS = 60;

    public function __construct(private readonly AuthenticationFingerprint $fingerprints) {}

    public function loginKey(string $email, ?string $ipAddress): string
    {
        return 'web-auth:login:'.$this->fingerprints->email($email).'|'.$this->fingerprints->network($ipAddress);
    }

    public function registrationKey(?string $ipAddress): string
    {
        return 'web-auth:register:'.$this->fingerprints->network($ipAddress);
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
        return 'web-auth:forgot-password:'.$this->fingerprints->email($email).'|'.$this->fingerprints->network($ipAddress);
    }

    public function resetPasswordKey(string $email, ?string $ipAddress): string
    {
        return 'web-auth:reset-password:'.$this->fingerprints->email($email).'|'.$this->fingerprints->network($ipAddress);
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
