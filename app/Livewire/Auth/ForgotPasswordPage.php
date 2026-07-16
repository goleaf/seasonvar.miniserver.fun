<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Livewire\Forms\Auth\ForgotPasswordForm;
use App\Services\Auth\AccountPasswordResetService;
use App\Services\Auth\AuthenticationRedirectService;
use App\Services\Auth\WebAuthenticationRateLimiter;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class ForgotPasswordPage extends Component
{
    private const MAX_ATTEMPTS = 3;

    private const DECAY_SECONDS = 600;

    public ForgotPasswordForm $form;

    public ?string $status = null;

    public function sendResetLink(
        AccountPasswordResetService $passwords,
        WebAuthenticationRateLimiter $rateLimiter,
    ): void {
        $email = $this->form->validatedEmail();
        $rateKey = $rateLimiter->forgotPasswordKey($email, request()->ip());

        if ($rateLimiter->tooManyAttempts($rateKey, self::MAX_ATTEMPTS)) {
            $this->form->addError('email', __('auth.errors.too_many_requests'));

            return;
        }

        $rateLimiter->hit($rateKey, self::DECAY_SECONDS);
        $passwords->sendResetLink($email);
        $this->status = $passwords->requestStatus();
    }

    public function render(AuthenticationRedirectService $redirects): View
    {
        return view('livewire.auth.forgot-password-page', [
            'loginUrl' => $redirects->guestUrl('login'),
        ])
            ->extends('layouts.app', [
                'title' => __('auth.pages.forgot_password.title'),
                'seo' => [
                    'title' => __('auth.pages.forgot_password.title'),
                    'description' => __('auth.pages.forgot_password.description'),
                    'robots' => 'noindex, nofollow',
                    'canonical' => $redirects->guestUrl('password.request'),
                    'social' => false,
                    'alternates' => [],
                    'jsonLd' => [],
                ],
            ])
            ->section('content');
    }
}
