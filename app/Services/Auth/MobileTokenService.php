<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

final class MobileTokenService
{
    private const TOKEN_DAYS = 90;

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
    }

    public function revokeAll(User $user): int
    {
        return $user->tokens()->delete();
    }
}
