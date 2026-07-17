<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

final readonly class AccountEmailVerificationResponder
{
    public function __construct(
        private AccountEmailVerificationService $verification,
        private AuthenticationRedirectService $redirects,
    ) {}

    public function response(int $id, string $hash): RedirectResponse
    {
        $user = $this->verification->verify($id, $hash);
        $status = $user->wasChanged('email_verified_at')
            ? __('auth.status.email_verified')
            : __('auth.status.email_already_verified');
        $route = Auth::guard('web')->id() === $user->getKey()
            ? 'library.index'
            : 'login';
        $destination = $route === 'login'
            ? $this->redirects->guestUrl('login')
            : route($route);

        return redirect($destination)->with('status', $status);
    }
}
