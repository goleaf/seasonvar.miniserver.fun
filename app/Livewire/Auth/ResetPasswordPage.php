<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Livewire\Forms\Auth\ResetPasswordForm;
use App\Services\Auth\AccountPasswordResetService;
use App\Services\Auth\AuthenticationRedirectService;
use App\Services\Auth\WebAuthenticationRateLimiter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class ResetPasswordPage extends Component
{
    private const MAX_ATTEMPTS = 3;

    private const DECAY_SECONDS = 600;

    public ResetPasswordForm $form;

    public function mount(string $token): void
    {
        $email = request()->query('email', '');

        $this->form->token = $token;
        $this->form->email = is_string($email) ? $email : '';
    }

    public function resetPassword(
        AccountPasswordResetService $passwords,
        WebAuthenticationRateLimiter $rateLimiter,
        AuthenticationRedirectService $redirects,
    ): void {
        try {
            $attributes = $this->form->validatedData();
        } catch (ValidationException $exception) {
            $this->form->reset('password', 'passwordConfirmation');

            throw $exception;
        }
        $rateKey = $rateLimiter->resetPasswordKey($attributes['email'], request()->ip());

        if ($rateLimiter->tooManyAttempts($rateKey, self::MAX_ATTEMPTS)) {
            $this->form->addError('email', __('auth.errors.too_many_requests'));
            $this->form->reset('password', 'passwordConfirmation');

            return;
        }

        $rateLimiter->hit($rateKey, self::DECAY_SECONDS);

        try {
            $passwords->reset($attributes['email'], $attributes['token'], $attributes['password']);
        } catch (ValidationException) {
            $this->form->addError('email', __('auth.errors.password_reset_failed'));
            $this->form->reset('password', 'passwordConfirmation');

            return;
        }

        $rateLimiter->clear($rateKey);
        $this->form->reset('password', 'passwordConfirmation');
        Session::flash('status', __('auth.status.password_reset_login'));
        $this->redirect($redirects->guestUrl('login'));
    }

    public function render(AuthenticationRedirectService $redirects): View
    {
        return view('livewire.auth.reset-password-page')
            ->extends('layouts.app', [
                'title' => __('auth.pages.reset_password.title'),
                'seo' => [
                    'title' => __('auth.pages.reset_password.title'),
                    'description' => __('auth.pages.reset_password.description'),
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
