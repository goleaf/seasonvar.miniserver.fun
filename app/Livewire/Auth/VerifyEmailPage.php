<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\Auth\WebAuthenticationRateLimiter;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class VerifyEmailPage extends Component
{
    private const MAX_ATTEMPTS = 3;

    public string $email = '';

    public ?string $status = null;

    public function mount(): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        if ($user->hasVerifiedEmail()) {
            $this->redirectRoute('library.index');

            return;
        }

        $this->email = $user->email;
    }

    public function resend(WebAuthenticationRateLimiter $rateLimiter): void
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        if ($user->hasVerifiedEmail()) {
            $this->redirectRoute('library.index');

            return;
        }

        $rateKey = $rateLimiter->verificationKey($user->getKey());

        if ($rateLimiter->tooManyAttempts($rateKey, self::MAX_ATTEMPTS)) {
            $this->addError('email', 'Слишком много запросов. Повторите попытку позже.');

            return;
        }

        $rateLimiter->hit($rateKey);
        $user->sendEmailVerificationNotification();
        $this->status = 'Новое письмо для подтверждения отправлено.';
    }

    public function render(): View
    {
        return view('livewire.auth.verify-email-page')
            ->extends('layouts.app', [
                'title' => 'Подтверждение почты',
                'seo' => [
                    'title' => 'Подтверждение почты',
                    'description' => 'Подтверждение адреса электронной почты аккаунта.',
                    'robots' => 'noindex, nofollow',
                    'canonical' => route('verification.notice'),
                ],
            ])
            ->section('content');
    }
}
