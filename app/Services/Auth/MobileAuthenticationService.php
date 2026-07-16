<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AuthenticationEvent;
use App\Models\User;
use App\ValueObjects\NormalizedEmail;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class MobileAuthenticationService
{
    private const ABILITIES = ['mobile:read', 'mobile:write'];

    private const TOKEN_DAYS = 90;

    public function __construct(
        private readonly AccountRegistrationService $accounts,
        private readonly AuthenticationAuditService $audit,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, device_name: string}  $attributes
     * @return array{user: User, token: string, expires_at: CarbonInterface}
     */
    public function register(array $attributes): array
    {
        $user = $this->accounts->register([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'password' => $attributes['password'],
        ], app()->getLocale());

        return $this->issueToken($user, $attributes['device_name']);
    }

    /** @return array{user: User, token: string, expires_at: CarbonInterface} */
    public function login(string $email, string $password, string $deviceName): array
    {
        $normalizedEmail = NormalizedEmail::value($email);
        $user = User::query()->whereEmailIdentity($normalizedEmail)->first();

        if ($user === null) {
            Hash::make($password);
            $this->audit->record(AuthenticationEvent::LoginFailed, email: $normalizedEmail);

            throw ValidationException::withMessages([
                'email' => [__('auth.errors.invalid_credentials')],
            ]);
        }

        if (! Hash::check($password, $user->password)) {
            $this->audit->record(AuthenticationEvent::LoginFailed, email: $normalizedEmail);

            throw ValidationException::withMessages([
                'email' => [__('auth.errors.invalid_credentials')],
            ]);
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => Hash::make($password)])->save();
        }

        $this->audit->record(AuthenticationEvent::LoginSucceeded, $user, $normalizedEmail);

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
