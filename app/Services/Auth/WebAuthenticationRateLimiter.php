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

    private const PASSWORD_FLOW_DECAY_SECONDS = 600;

    private const PASSWORD_FLOW_PAIR_LIMIT = 3;

    private const PASSWORD_FLOW_NETWORK_LIMIT = 30;

    private const RECOVERY_IDENTIFIER_LIMIT = 3;

    private const RESET_IDENTIFIER_LIMIT = 10;

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

    public function forgotPasswordRetryAfter(string $email, ?string $ipAddress): int
    {
        return $this->retryAfter($this->passwordFlowBuckets(
            'forgot-password',
            $email,
            $ipAddress,
            self::RECOVERY_IDENTIFIER_LIMIT,
        ));
    }

    public function hitForgotPassword(string $email, ?string $ipAddress): void
    {
        $this->hitBuckets($this->passwordFlowBuckets(
            'forgot-password',
            $email,
            $ipAddress,
            self::RECOVERY_IDENTIFIER_LIMIT,
        ));
    }

    public function resetPasswordKey(string $email, ?string $ipAddress): string
    {
        return 'web-auth:reset-password:'.$this->fingerprints->email($email).'|'.$this->fingerprints->network($ipAddress);
    }

    public function resetPasswordRetryAfter(string $email, ?string $ipAddress): int
    {
        return $this->retryAfter($this->passwordFlowBuckets(
            'reset-password',
            $email,
            $ipAddress,
            self::RESET_IDENTIFIER_LIMIT,
        ));
    }

    public function hitResetPassword(string $email, ?string $ipAddress): void
    {
        $this->hitBuckets($this->passwordFlowBuckets(
            'reset-password',
            $email,
            $ipAddress,
            self::RESET_IDENTIFIER_LIMIT,
        ));
    }

    public function clearSuccessfulPasswordReset(string $email, ?string $ipAddress): void
    {
        foreach ($this->passwordFlowBuckets(
            'reset-password',
            $email,
            $ipAddress,
            self::RESET_IDENTIFIER_LIMIT,
        ) as $bucket) {
            if ($bucket['clear_on_success']) {
                RateLimiter::clear($bucket['key']);
            }
        }
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

    /**
     * @return list<array{key: string, limit: int, decay: int, clear_on_success: bool}>
     */
    private function passwordFlowBuckets(
        string $scope,
        string $email,
        ?string $ipAddress,
        int $identifierLimit,
    ): array {
        $emailFingerprint = $this->fingerprints->email($email);
        $networkFingerprint = $this->fingerprints->network($ipAddress);

        return [
            [
                'key' => 'web-auth:'.$scope.':'.$emailFingerprint.'|'.$networkFingerprint,
                'limit' => self::PASSWORD_FLOW_PAIR_LIMIT,
                'decay' => self::PASSWORD_FLOW_DECAY_SECONDS,
                'clear_on_success' => true,
            ],
            [
                'key' => 'web-auth:'.$scope.'-identifier:'.$emailFingerprint,
                'limit' => $identifierLimit,
                'decay' => self::PASSWORD_FLOW_DECAY_SECONDS,
                'clear_on_success' => true,
            ],
            [
                'key' => 'web-auth:'.$scope.'-network:'.$networkFingerprint,
                'limit' => self::PASSWORD_FLOW_NETWORK_LIMIT,
                'decay' => self::PASSWORD_FLOW_DECAY_SECONDS,
                'clear_on_success' => false,
            ],
        ];
    }

    /** @param list<array{key: string, limit: int, decay: int, clear_on_success: bool}> $buckets */
    private function retryAfter(array $buckets): int
    {
        $retryAfter = 0;

        foreach ($buckets as $bucket) {
            if (RateLimiter::tooManyAttempts($bucket['key'], $bucket['limit'])) {
                $retryAfter = max($retryAfter, RateLimiter::availableIn($bucket['key']));
            }
        }

        return $retryAfter;
    }

    /** @param list<array{key: string, limit: int, decay: int, clear_on_success: bool}> $buckets */
    private function hitBuckets(array $buckets): void
    {
        foreach ($buckets as $bucket) {
            RateLimiter::hit($bucket['key'], $bucket['decay']);
        }
    }
}
