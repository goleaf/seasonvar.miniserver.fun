<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MobileAccountService
{
    /** @param array{name?: string, email?: string} $data */
    public function updateProfile(User $user, array $data): User
    {
        $emailChanged = array_key_exists('email', $data)
            && Str::lower(Str::squish((string) $user->email)) !== $data['email'];

        DB::transaction(function () use ($user, $data, $emailChanged): void {
            $user->fill($data);

            if ($emailChanged) {
                $user->email_verified_at = null;
            }

            $user->save();
        }, attempts: 3);

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return $user->refresh();
    }

    public function updatePassword(User $user, string $current, string $new, ?int $currentTokenId): void
    {
        if (! Hash::check($current, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Текущий пароль указан неверно.'],
            ]);
        }

        DB::transaction(function () use ($user, $new, $currentTokenId): void {
            $user->forceFill([
                'password' => Hash::make($new),
                'remember_token' => Str::random(60),
            ])->save();

            $tokens = $user->tokens();

            if ($currentTokenId !== null) {
                $tokens->where('id', '!=', $currentTokenId);
            }

            $tokens->delete();
        }, attempts: 3);
    }

    public function delete(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['Не удалось подтвердить пароль.'],
            ]);
        }

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->deleteOrFail();
        }, attempts: 3);
    }
}
