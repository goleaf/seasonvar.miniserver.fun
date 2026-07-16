<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Enums\AuthenticationEvent;
use App\Models\User;
use App\Services\Auth\AuthenticationAuditService;
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

    public function resend(
        WebAuthenticationRateLimiter $rateLimiter,
        AuthenticationAuditService $audit,
    ): void {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        if ($user->hasVerifiedEmail()) {
            $this->redirectRoute('library.index');

            return;
        }

        $rateKey = $rateLimiter->verificationKey($user->getKey());

        if ($rateLimiter->tooManyAttempts($rateKey, self::MAX_ATTEMPTS)) {
            $this->addError('email', __('auth.errors.too_many_requests'));

            return;
        }

        $rateLimiter->hit($rateKey);
        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('email', __('auth.errors.mail_delivery_failed'));

            return;
        }

        $audit->record(AuthenticationEvent::VerificationRequested, $user, $user->email);
        $this->status = __('auth.status.verification_sent');
    }

    public function render(): View
    {
        return view('livewire.auth.verify-email-page')
            ->extends('layouts.app', [
                'title' => __('auth.pages.verify_email.title'),
                'seo' => [
                    'title' => __('auth.pages.verify_email.title'),
                    'description' => __('auth.pages.verify_email.description'),
                    'robots' => 'noindex, nofollow',
                    'canonical' => route('verification.notice'),
                    'social' => false,
                    'alternates' => [],
                    'jsonLd' => [],
                ],
            ])
            ->section('content');
    }
}
