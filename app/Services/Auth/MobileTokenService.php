<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DTOs\MobileTokenRotationResult;
use App\Enums\AuthenticationEvent;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final class MobileTokenService
{
    private const TOKEN_DAYS = 90;

    public function __construct(private readonly AuthenticationAuditService $audit) {}

    /** @return Collection<int, PersonalAccessToken> */
    public function devices(User $user): Collection
    {
        return $user->tokens()
            ->latest('id')
            ->get();
    }

    public function rotate(User $user, PersonalAccessToken $current): MobileTokenRotationResult
    {
        $tokenId = (int) $current->getKey();

        return DB::transaction(function () use ($user, $tokenId): MobileTokenRotationResult {
            $lockedToken = $user->tokens()
                ->whereKey($tokenId)
                ->lockForUpdate()
                ->first();

            if (! $lockedToken instanceof PersonalAccessToken) {
                throw new AuthenticationException;
            }

            $name = (string) $lockedToken->name;
            $abilities = $lockedToken->abilities ?? [];
            $lockedToken->delete();

            $expiresAt = now()->addDays(self::TOKEN_DAYS);
            $token = $user->createToken(
                $name,
                $abilities,
                $expiresAt,
            );

            return new MobileTokenRotationResult(
                token: $token->plainTextToken,
                expiresAt: $expiresAt,
            );
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
