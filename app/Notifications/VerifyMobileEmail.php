<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

final class VerifyMobileEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    /** @param mixed $notifiable */
    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute('api.v1.auth.verify', now()->addMinutes(60), [
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
        ]);
    }

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Подтверждение адреса электронной почты')
            ->line('Подтвердите адрес электронной почты для доступа ко всем функциям Seasonvar.')
            ->action('Подтвердить адрес', $url)
            ->line('Если вы не создавали аккаунт, письмо можно проигнорировать.');
    }
}
