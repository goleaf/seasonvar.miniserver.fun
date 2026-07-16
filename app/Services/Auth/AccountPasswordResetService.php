<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AuthenticationEvent;
use App\Models\User;
use App\ValueObjects\NormalizedEmail;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AccountPasswordResetService
{
    public function __construct(private readonly AuthenticationAuditService $audit) {}

    public function requestStatus(): string
    {
        return __('auth.status.recovery_requested');
    }

    public function sendResetLink(string $email): void
    {
        $email = NormalizedEmail::value($email);
        $user = User::query()->whereEmailIdentity($email)->first();
        $recipient = $user instanceof User ? $user->email : $email;

        try {
            Password::sendResetLink(['email' => $recipient]);
        } catch (\Throwable $exception) {
            report($exception);
        }

        $this->audit->record(AuthenticationEvent::PasswordResetRequested, $user, $email);
    }

    public function reset(string $email, string $token, string $password): void
    {
        $email = NormalizedEmail::value($email);
        $user = User::query()->whereEmailIdentity($email)->first();
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

            $this->audit->record(AuthenticationEvent::PasswordResetCompleted, $user, $user->email);
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [__('auth.errors.password_reset_failed')],
            ]);
        }
    }
}
