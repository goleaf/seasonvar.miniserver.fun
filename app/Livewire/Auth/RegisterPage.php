<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Livewire\Forms\Auth\RegistrationForm;
use App\Services\Auth\AccountRegistrationService;
use App\Services\Auth\WebAuthenticationRateLimiter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

final class RegisterPage extends Component
{
    private const MAX_ATTEMPTS = 3;

    public RegistrationForm $form;

    public function register(
        AccountRegistrationService $accounts,
        WebAuthenticationRateLimiter $rateLimiter,
    ): void {
        $attributes = $this->form->validatedData();
        $rateKey = $rateLimiter->registrationKey(request()->ip());

        if ($rateLimiter->tooManyAttempts($rateKey, self::MAX_ATTEMPTS)) {
            $this->form->addError(
                'email',
                'Слишком много попыток регистрации. Повторите через '.$rateLimiter->availableIn($rateKey).' сек.',
            );

            return;
        }

        $rateLimiter->hit($rateKey);
        $user = $accounts->register($attributes);

        Auth::guard('web')->login($user);
        Session::regenerate();

        $this->redirectRoute('verification.notice');
    }

    public function render(): View
    {
        return view('livewire.auth.register-page')
            ->extends('layouts.app', [
                'title' => 'Регистрация',
                'seo' => [
                    'title' => 'Регистрация',
                    'description' => 'Создание пользовательского аккаунта.',
                    'robots' => 'noindex, nofollow',
                    'canonical' => route('register'),
                ],
            ])
            ->section('content');
    }
}
