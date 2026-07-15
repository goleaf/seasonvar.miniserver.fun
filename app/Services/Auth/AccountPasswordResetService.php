<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountPasswordResetService
{
    public const REQUEST_STATUS = 'Если аккаунт существует, письмо для восстановления отправлено.';

    public function sendResetLink(string $email): void
    {
        $email = Str::lower(Str::squish($email));
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        $recipient = $user instanceof User ? $user->email : $email;

        Password::sendResetLink(['email' => $recipient]);
    }

    public function reset(string $email, string $token, string $password): void
    {
        $email = Str::lower(Str::squish($email));
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        $recipient = $user instanceof User ? $user->email : $email;
        $status = Password::reset([
            'email' => $recipient,
            'token' => $token,
            'password' => $password,
            'password_confirmation' => $password,
        ], function (User $user, string $password): void {
            DB::transaction(function () use ($user, $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
                $user->tokens()->delete();
                event(new PasswordReset($user));
            }, attempts: 3);
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['Не удалось сбросить пароль с указанными данными.'],
            ]);
        }
    }
}
