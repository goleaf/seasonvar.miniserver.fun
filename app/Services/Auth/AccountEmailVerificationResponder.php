<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Services\Catalog\CatalogTasteOnboardingSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

final readonly class AccountEmailVerificationResponder
{
    public function __construct(
        private AccountEmailVerificationService $verification,
        private AuthenticationRedirectService $redirects,
        private CatalogTasteOnboardingSchema $onboardingSchema,
    ) {}

    public function response(int $id, string $hash): RedirectResponse
    {
        $user = $this->verification->verify($id, $hash);
        $newlyVerified = $user->wasChanged('email_verified_at');
        $status = $newlyVerified
            ? __('auth.status.email_verified')
            : __('auth.status.email_already_verified');
        $matchingOwner = Auth::guard('web')->id() === $user->getKey();
        $route = match (true) {
            ! $matchingOwner => 'login',
            $newlyVerified && $this->onboardingSchema->ready() => 'onboarding.tastes',
            default => 'library.index',
        };
        $destination = $route === 'login'
            ? $this->redirects->guestUrl('login')
            : $this->redirects->guestUrl($route, locale: $user->preferredLocale());

        return redirect($destination)->with('status', $status);
    }
}
