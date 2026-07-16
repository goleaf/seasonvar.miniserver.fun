<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AuthenticationEvent;
use App\Models\User;
use App\Support\UserPlainText;
use App\ValueObjects\NormalizedEmail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountRegistrationService
{
    public function __construct(
        private readonly AccountSettingsService $settings,
        private readonly AuthenticationAuditService $audit,
    ) {}

    /** @param array{name: string, email: string, password: string} $attributes */
    public function register(array $attributes, ?string $locale = null): User
    {
        $name = UserPlainText::name($attributes['name']);
        $email = NormalizedEmail::value($attributes['email']);
        $locale ??= app()->getLocale();

        try {
            $user = DB::transaction(function () use ($attributes, $name, $email, $locale): User {
                if (User::query()->whereEmailIdentity($email)->exists()) {
                    throw ValidationException::withMessages([
                        'email' => [__('auth.validation.email_unique')],
                    ]);
                }

                DB::table('password_reset_tokens')
                    ->whereRaw('lower(email) = ?', [$email])
                    ->delete();

                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => $attributes['password'],
                ]);

                $this->settings->adoptLocaleIfUnset($user, $locale);

                return $user;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => [__('auth.validation.email_unique')],
            ]);
        }

        try {
            event(new Registered($user));
        } catch (\Throwable $exception) {
            report($exception);
        }

        $this->audit->record(AuthenticationEvent::Registered, $user, $email);

        return $user;
    }
}
