<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AccountEmailVerificationService;
use App\Services\Auth\AuthenticationRedirectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

final class VerifyEmailController extends Controller
{
    public function __invoke(
        AccountEmailVerificationService $verification,
        AuthenticationRedirectService $redirects,
        int $id,
        string $hash,
    ): RedirectResponse {
        $user = $verification->verify($id, $hash);
        $status = $user->wasChanged('email_verified_at')
            ? __('auth.status.email_verified')
            : __('auth.status.email_already_verified');
        $route = Auth::guard('web')->id() === $user->getKey()
            ? 'library.index'
            : 'login';

        $destination = $route === 'login'
            ? $redirects->guestUrl('login')
            : route($route);

        return redirect($destination)->with('status', $status);
    }
}
