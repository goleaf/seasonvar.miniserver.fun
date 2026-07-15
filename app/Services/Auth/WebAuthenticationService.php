<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

final class WebAuthenticationService
{
    public function attempt(
        string $email,
        string $password,
        bool $remember,
    ): bool {
        $normalizedEmail = Str::lower(Str::squish($email));
        $storedEmail = User::query()
            ->whereRaw('lower(email) = ?', [$normalizedEmail])
            ->value('email');

        if (! is_string($storedEmail) || ! Auth::guard('web')->attempt([
            'email' => $storedEmail,
            'password' => $password,
        ], $remember)) {
            return false;
        }

        Session::regenerate();

        return true;
    }

    public function logout(): void
    {
        Auth::guard('web')->logout();

        Session::invalidate();
        Session::regenerateToken();
    }
}
