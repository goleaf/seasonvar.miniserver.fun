<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AuthenticationEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final class MobileTokenService
{
    private const TOKEN_DAYS = 90;

    public function __construct(private readonly AuthenticationAuditService $audit) {}

    /** @return array{token: string, expires_at: CarbonInterface} */
    public function rotate(User $user, PersonalAccessToken $current): array
    {
        return DB::transaction(function () use ($user, $current): array {
            $expiresAt = now()->addDays(self::TOKEN_DAYS);
            $token = $user->createToken(
                (string) $current->name,
                $current->abilities ?? [],
                $expiresAt,
            );

            $current->delete();

            return [
                'token' => $token->plainTextToken,
                'expires_at' => $expiresAt,
            ];
        }, attempts: 3);
    }

    public function revoke(User $user, int $tokenId): void
    {
        $token = $user->tokens()->whereKey($tokenId)->firstOrFail();

        $token->delete();
        $this->audit->record(AuthenticationEvent::DeviceRevoked, $user, $user->email);
    }

    public function revokeAll(User $user): int
    {
        $deleted = $user->tokens()->delete();
        $this->audit->record(AuthenticationEvent::DevicesRevoked, $user, $user->email);

        return $deleted;
    }

    public function revokeConfirmed(User $user, int $tokenId, string $password): void
    {
        $this->confirmPassword($user, $password);
        $this->revoke($user, $tokenId);
    }

    public function revokeAllConfirmed(User $user, string $password): int
    {
        $this->confirmPassword($user, $password);

        return $this->revokeAll($user);
    }

    private function confirmPassword(User $user, string $password): void
    {
        $rateKey = 'mobile-token-revoke:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($rateKey, 8)) {
            throw ValidationException::withMessages([
                'current_password' => [__('settings.security_page.security_rate_limited')],
            ]);
        }

        RateLimiter::hit($rateKey, 120);

        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('settings.security_page.current_password_invalid')],
            ]);
        }

        RateLimiter::clear($rateKey);
    }
}
