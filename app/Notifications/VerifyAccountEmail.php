<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

final class VerifyAccountEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    /** @param mixed $notifiable */
    protected function verificationUrl($notifiable): string
    {
        $locale = method_exists($notifiable, 'preferredLocale')
            ? $notifiable->preferredLocale()
            : (string) config('account-settings.default_locale', 'ru');

        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
            'locale' => $locale,
        ]);
    }

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject(__('auth.mail.verify.subject'))
            ->line(__('auth.mail.verify.line'))
            ->action(__('auth.mail.verify.action'), $url)
            ->line(__('auth.mail.verify.expires'))
            ->line(__('auth.mail.verify.ignore'));
    }
}
