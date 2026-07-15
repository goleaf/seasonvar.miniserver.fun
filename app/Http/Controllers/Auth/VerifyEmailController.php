<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AccountEmailVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

final class VerifyEmailController extends Controller
{
    public function __invoke(
        AccountEmailVerificationService $verification,
        int $id,
        string $hash,
    ): RedirectResponse {
        $user = $verification->verify($id, $hash);
        $status = $user->wasChanged('email_verified_at')
            ? 'Адрес электронной почты подтверждён.'
            : 'Адрес электронной почты уже был подтверждён.';
        $route = Auth::guard('web')->id() === $user->getKey()
            ? 'library.index'
            : 'login';

        return redirect()->route($route)->with('status', $status);
    }
}
