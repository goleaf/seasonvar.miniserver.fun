<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Livewire\Forms\Auth\LoginForm;
use App\Services\Auth\WebAuthenticationRateLimiter;
use App\Services\Auth\WebAuthenticationService;
use Illuminate\Contracts\View\View;
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
    ): void {
        $credentials = $this->form->validatedData();
        $rateKey = $rateLimiter->loginKey($credentials['email'], request()->ip());

        if ($rateLimiter->tooManyAttempts($rateKey, self::MAX_ATTEMPTS)) {
            $this->form->addError(
                'email',
                'Слишком много попыток входа. Повторите через '.$rateLimiter->availableIn($rateKey).' сек.',
            );

            return;
        }

        $rateLimiter->hit($rateKey);

        if (! $authentication->attempt(
            $credentials['email'],
            $credentials['password'],
            $credentials['remember'],
        )) {
            $this->form->addError('email', 'Указаны неверные данные для входа.');

            return;
        }

        $rateLimiter->clear($rateKey);

        $this->redirectIntended(route('library.index'));
    }

    public function render(): View
    {
        return view('livewire.auth.login-page')
            ->extends('layouts.app', [
                'title' => 'Вход',
                'seo' => [
                    'title' => 'Вход',
                    'description' => 'Вход в пользовательский аккаунт.',
                    'robots' => 'noindex, nofollow',
                    'canonical' => route('login'),
                ],
            ])
            ->section('content');
    }
}
