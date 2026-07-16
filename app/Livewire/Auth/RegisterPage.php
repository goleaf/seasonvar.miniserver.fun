<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Livewire\Forms\Auth\RegistrationForm;
use App\Services\Auth\AccountRegistrationService;
use App\Services\Auth\AuthenticationRedirectService;
use App\Services\Auth\RegistrationAvailability;
use App\Services\Auth\WebAuthenticationRateLimiter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class RegisterPage extends Component
{
    private const MAX_ATTEMPTS = 3;

    public RegistrationForm $form;

    public function mount(RegistrationAvailability $registration): void
    {
        $registration->ensureEnabled();
    }

    public function register(
        AccountRegistrationService $accounts,
        WebAuthenticationRateLimiter $rateLimiter,
        RegistrationAvailability $registration,
    ): void {
        $registration->ensureEnabled();
        try {
            $attributes = $this->form->validatedData();
        } catch (ValidationException $exception) {
            $this->applyValidationErrors($exception);

            return;
        }
        $rateKey = $rateLimiter->registrationKey(request()->ip());

        if ($rateLimiter->tooManyAttempts($rateKey, self::MAX_ATTEMPTS)) {
            $this->form->addError(
                'email',
                __('auth.errors.too_many_registration_attempts', ['seconds' => $rateLimiter->availableIn($rateKey)]),
            );
            $this->form->reset('password', 'passwordConfirmation');

            return;
        }

        $rateLimiter->hit($rateKey);
        try {
            $user = $accounts->register($attributes, app()->getLocale());
        } catch (ValidationException $exception) {
            $this->applyValidationErrors($exception);

            return;
        }

        Auth::guard('web')->login($user);
        Session::regenerate();

        $this->redirectRoute('verification.notice');
    }

    public function render(AuthenticationRedirectService $redirects): View
    {
        return view('livewire.auth.register-page', [
            'loginUrl' => $redirects->guestUrl('login'),
        ])
            ->extends('layouts.app', [
                'title' => __('auth.pages.register.title'),
                'seo' => [
                    'title' => __('auth.pages.register.title'),
                    'description' => __('auth.pages.register.description'),
                    'robots' => 'noindex, nofollow',
                    'canonical' => $redirects->guestUrl('register'),
                    'social' => false,
                    'alternates' => [],
                    'jsonLd' => [],
                ],
            ])
            ->section('content');
    }

    private function applyValidationErrors(ValidationException $exception): void
    {
        $this->resetValidation();
        $this->form->reset('password', 'passwordConfirmation');

        foreach ($exception->errors() as $field => $messages) {
            $field = str_starts_with($field, 'form.') ? substr($field, 5) : $field;

            foreach ($messages as $message) {
                $this->form->addError($field, $message);
            }
        }
    }
}
