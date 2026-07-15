<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Livewire\Forms\Auth\ForgotPasswordForm;
use App\Services\Auth\AccountPasswordResetService;
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
            $this->form->addError('email', 'Слишком много запросов. Повторите попытку позже.');

            return;
        }

        $rateLimiter->hit($rateKey, self::DECAY_SECONDS);
        $passwords->sendResetLink($email);
        $this->status = AccountPasswordResetService::REQUEST_STATUS;
    }

    public function render(): View
    {
        return view('livewire.auth.forgot-password-page')
            ->extends('layouts.app', [
                'title' => 'Восстановление пароля',
                'seo' => [
                    'title' => 'Восстановление пароля',
                    'description' => 'Запрос ссылки для изменения пароля аккаунта.',
                    'robots' => 'noindex, nofollow',
                    'canonical' => route('password.request'),
                ],
            ])
            ->section('content');
    }
}
