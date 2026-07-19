<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\AuthenticationEvent;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

final class WebAuthenticationService
{
    public function __construct(
        private readonly AccountSettingsService $settings,
        private readonly AuthenticationAuditService $audit,
        private readonly AccountAccessResolver $accountAccess,
    ) {}

    public function attempt(
        string $email,
        string $password,
        bool $remember,
    ): bool {
        $storedEmail = User::query()
            ->whereEmailIdentity($email)
            ->value('email');

        if (! is_string($storedEmail) || ! Auth::guard('web')->attempt([
            'email' => $storedEmail,
            'password' => $password,
        ], $remember)) {
            $this->audit->record(AuthenticationEvent::LoginFailed, email: $email);

            return false;
        }

        Session::regenerate();
        $user = Auth::guard('web')->user();

        if ($user instanceof User) {
            if (! $this->accountAccess->canAuthenticate($user)) {
                Auth::guard('web')->logout();
                Session::invalidate();
                Session::regenerateToken();
                $this->audit->record(AuthenticationEvent::LoginFailed, email: $email);

                return false;
            }

            try {
                $this->settings->adoptLocaleIfUnset($user, app()->getLocale());
            } catch (\Throwable $exception) {
                report($exception);
            }

            $this->audit->record(AuthenticationEvent::LoginSucceeded, $user, $email);
        }

        return true;
    }

    public function logout(): void
    {
        $user = Auth::guard('web')->user();

        if ($user instanceof User) {
            $this->audit->record(AuthenticationEvent::LoggedOut, $user, $user->email);
        }

        Auth::guard('web')->logout();

        Session::invalidate();
        Session::regenerateToken();
    }
}
