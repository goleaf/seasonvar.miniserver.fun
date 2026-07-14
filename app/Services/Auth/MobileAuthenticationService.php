<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MobileAuthenticationService
{
    private const ABILITIES = ['mobile:read', 'mobile:write'];

    private const TOKEN_DAYS = 90;

    /**
     * @param  array{name: string, email: string, password: string, device_name: string}  $attributes
     * @return array{user: User, token: string, expires_at: CarbonInterface}
     */
    public function register(array $attributes): array
    {
        $user = DB::transaction(function () use ($attributes): User {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => Hash::make($attributes['password']),
            ]);

            DB::afterCommit(function () use ($user): void {
                if (method_exists($user, 'sendEmailVerificationNotification')) {
                    $user->sendEmailVerificationNotification();
                }
            });

            return $user;
        }, attempts: 3);

        return $this->issueToken($user, $attributes['device_name']);
    }

    /** @return array{user: User, token: string, expires_at: CarbonInterface} */
    public function login(string $email, string $password, string $deviceName): array
    {
        $normalizedEmail = Str::lower(Str::squish($email));
        $user = User::query()->whereRaw('lower(email) = ?', [$normalizedEmail])->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Указаны неверные данные для входа.'],
            ]);
        }

        return $this->issueToken($user, $deviceName);
    }

    /** @return array{user: User, token: string, expires_at: CarbonInterface} */
    private function issueToken(User $user, string $deviceName): array
    {
        $expiresAt = now()->addDays(self::TOKEN_DAYS);
        $token = $user->createToken($deviceName, self::ABILITIES, $expiresAt);

        return [
            'user' => $user,
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt,
        ];
    }
}
