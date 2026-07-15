<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Arr;

final class ResetAccountPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;

    /** @param mixed $notifiable */
    protected function resetUrl($notifiable): string
    {
        return url('/api/v1/auth/reset-password').'?'.Arr::query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject('Восстановление пароля')
            ->line('Получен запрос на изменение пароля аккаунта Seasonvar.')
            ->action('Изменить пароль', $url)
            ->line('Если вы не запрашивали восстановление, письмо можно проигнорировать.');
    }
}
