<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Livewire\Forms\Auth\LoginForm;
use App\Services\Auth\AuthenticationRedirectService;
use App\Services\Auth\RegistrationAvailability;
use App\Services\Auth\WebAuthenticationRateLimiter;
use App\Services\Auth\WebAuthenticationService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class LoginPage extends Component
{
    private const MAX_ATTEMPTS = 5;

    public LoginForm $form;

    public ?string $status = null;

    public function mount(): void
    {
        $status = session('status');

        $this->status = is_string($status) ? $status : null;
    }

    public function login(
        WebAuthenticationService $authentication,
        WebAuthenticationRateLimiter $rateLimiter,
        AuthenticationRedirectService $redirects,
    ): void {
        try {
            $credentials = $this->form->validatedData();
        } catch (ValidationException $exception) {
            $this->form->reset('password');

            throw $exception;
        }
        $rateKey = $rateLimiter->loginKey($credentials['email'], request()->ip());

        if ($rateLimiter->tooManyAttempts($rateKey, self::MAX_ATTEMPTS)) {
            $this->form->addError(
                'email',
                __('auth.errors.too_many_login_attempts', ['seconds' => $rateLimiter->availableIn($rateKey)]),
            );
            $this->form->reset('password');

            return;
        }

        $rateLimiter->hit($rateKey);

        if (! $authentication->attempt(
            $credentials['email'],
            $credentials['password'],
            $credentials['remember'],
        )) {
            $this->form->addError('email', __('auth.errors.invalid_credentials'));
            $this->form->reset('password');

            return;
        }

        $rateLimiter->clear($rateKey);
        $this->form->reset('password');

        $this->redirect($redirects->intended());
    }

    public function render(
        AuthenticationRedirectService $redirects,
        RegistrationAvailability $registration,
    ): View {
        $registrationEnabled = $registration->enabled();

        return view('livewire.auth.login-page', [
            'forgotPasswordUrl' => $redirects->guestUrl('password.request'),
            'registerUrl' => $registrationEnabled ? $redirects->guestUrl('register') : null,
            'registrationEnabled' => $registrationEnabled,
        ])
            ->extends('layouts.app', [
                'title' => __('auth.pages.login.title'),
                'seo' => [
                    'title' => __('auth.pages.login.title'),
                    'description' => __('auth.pages.login.description'),
                    'robots' => 'noindex, nofollow',
                    'canonical' => $redirects->guestUrl('login'),
                    'social' => false,
                    'alternates' => [],
                    'jsonLd' => [],
                ],
            ])
            ->section('content');
    }
}
