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
        $ipAddress = request()->ip();
        $retryAfter = $rateLimiter->loginRetryAfter($credentials['email'], $ipAddress);

        if ($retryAfter > 0) {
            $this->form->addError(
                'email',
                __('auth.errors.too_many_login_attempts', ['seconds' => $retryAfter]),
            );
            $this->form->reset('password');

            return;
        }

        $rateLimiter->hitLogin($credentials['email'], $ipAddress);

        if (! $authentication->attempt(
            $credentials['email'],
            $credentials['password'],
            $credentials['remember'],
        )) {
            $this->form->addError('email', __('auth.errors.invalid_credentials'));
            $this->form->reset('password');

            return;
        }

        $rateLimiter->clearSuccessfulLogin($credentials['email'], $ipAddress);
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
