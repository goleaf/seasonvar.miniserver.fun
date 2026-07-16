<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\RateLimiter;

final class WebAuthenticationRateLimiter
{
    private const DECAY_SECONDS = 60;

    private const LOGIN_ATTEMPT_LIMIT = 5;

    private const LOGIN_IDENTIFIER_DECAY_SECONDS = 600;

    private const LOGIN_IDENTIFIER_LIMIT = 20;

    private const LOGIN_NETWORK_LIMIT = 60;

    public function __construct(private readonly AuthenticationFingerprint $fingerprints) {}

    public function loginKey(string $email, ?string $ipAddress): string
    {
        return 'web-auth:login:'.$this->fingerprints->email($email).'|'.$this->fingerprints->network($ipAddress);
    }

    public function loginRetryAfter(string $email, ?string $ipAddress): int
    {
        $retryAfter = 0;

        foreach ($this->loginBuckets($email, $ipAddress) as $bucket) {
            if (RateLimiter::tooManyAttempts($bucket['key'], $bucket['limit'])) {
                $retryAfter = max($retryAfter, RateLimiter::availableIn($bucket['key']));
            }
        }

        return $retryAfter;
    }

    public function hitLogin(string $email, ?string $ipAddress): void
    {
        foreach ($this->loginBuckets($email, $ipAddress) as $bucket) {
            RateLimiter::hit($bucket['key'], $bucket['decay']);
        }
    }

    public function clearSuccessfulLogin(string $email, ?string $ipAddress): void
    {
        foreach ($this->loginBuckets($email, $ipAddress) as $bucket) {
            if ($bucket['clear_on_success']) {
                RateLimiter::clear($bucket['key']);
            }
        }
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

    /** @return list<array{key: string, limit: int, decay: int, clear_on_success: bool}> */
    private function loginBuckets(string $email, ?string $ipAddress): array
    {
        $emailFingerprint = $this->fingerprints->email($email);
        $networkFingerprint = $this->fingerprints->network($ipAddress);

        return [
            [
                'key' => $this->loginKey($email, $ipAddress),
                'limit' => self::LOGIN_ATTEMPT_LIMIT,
                'decay' => self::DECAY_SECONDS,
                'clear_on_success' => true,
            ],
            [
                'key' => 'web-auth:login-identifier:'.$emailFingerprint,
                'limit' => self::LOGIN_IDENTIFIER_LIMIT,
                'decay' => self::LOGIN_IDENTIFIER_DECAY_SECONDS,
                'clear_on_success' => true,
            ],
            [
                'key' => 'web-auth:login-network:'.$networkFingerprint,
                'limit' => self::LOGIN_NETWORK_LIMIT,
                'decay' => self::DECAY_SECONDS,
                'clear_on_success' => false,
            ],
        ];
    }
}
