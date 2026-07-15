<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Livewire\Forms\Auth\ResetPasswordForm;
use App\Services\Auth\AccountPasswordResetService;
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
    ): void {
        $attributes = $this->form->validatedData();
        $rateKey = $rateLimiter->resetPasswordKey($attributes['email'], request()->ip());

        if ($rateLimiter->tooManyAttempts($rateKey, self::MAX_ATTEMPTS)) {
            $this->form->addError('email', 'Слишком много запросов. Повторите попытку позже.');

            return;
        }

        $rateLimiter->hit($rateKey, self::DECAY_SECONDS);

        try {
            $passwords->reset($attributes['email'], $attributes['token'], $attributes['password']);
        } catch (ValidationException) {
            $this->form->addError('email', 'Не удалось сбросить пароль с указанными данными.');

            return;
        }

        $rateLimiter->clear($rateKey);
        $this->form->reset('password', 'passwordConfirmation');
        Session::flash('status', 'Пароль успешно изменён. Войдите с новым паролем.');
        $this->redirectRoute('login');
    }

    public function render(): View
    {
        return view('livewire.auth.reset-password-page')
            ->extends('layouts.app', [
                'title' => 'Новый пароль',
                'seo' => [
                    'title' => 'Новый пароль',
                    'description' => 'Изменение пароля пользовательского аккаунта.',
                    'robots' => 'noindex, nofollow',
                    'canonical' => route('password.request'),
                ],
            ])
            ->section('content');
    }
}
