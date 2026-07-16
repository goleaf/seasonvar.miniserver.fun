<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Livewire\Forms\Auth\ConfirmPasswordForm;
use App\Models\User;
use App\Services\Auth\AuthenticationRedirectService;
use App\Services\Auth\WebAuthenticationRateLimiter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class ConfirmPasswordPage extends Component
{
    private const MAX_ATTEMPTS = 5;

    public ConfirmPasswordForm $form;

    public function confirm(
        WebAuthenticationRateLimiter $rateLimiter,
        AuthenticationRedirectService $redirects,
    ): void {
        try {
            $password = $this->form->validatedPassword();
        } catch (ValidationException $exception) {
            $this->form->reset('password');

            throw $exception;
        }
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        $rateKey = $rateLimiter->loginKey($user->email, request()->ip());

        if ($rateLimiter->tooManyAttempts($rateKey, self::MAX_ATTEMPTS)) {
            $this->form->addError('password', __('auth.errors.too_many_attempts'));
            $this->form->reset('password');

            return;
        }

        $rateLimiter->hit($rateKey);

        if (! Hash::check($password, $user->password)) {
            $this->form->addError('password', __('auth.errors.password_confirmation_failed'));
            $this->form->reset('password');

            return;
        }

        $rateLimiter->clear($rateKey);
        $this->form->reset();
        Session::passwordConfirmed();
        $this->redirect($redirects->intended());
    }

    public function render(): View
    {
        return view('livewire.auth.confirm-password-page')
            ->extends('layouts.app', [
                'title' => __('auth.pages.confirm_password.title'),
                'seo' => [
                    'title' => __('auth.pages.confirm_password.title'),
                    'description' => __('auth.pages.confirm_password.description'),
                    'robots' => 'noindex, nofollow',
                    'canonical' => route('password.confirm'),
                    'social' => false,
                    'alternates' => [],
                    'jsonLd' => [],
                ],
            ])
            ->section('content');
    }
}
