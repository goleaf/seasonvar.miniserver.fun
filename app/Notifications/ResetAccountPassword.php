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
        $locale = method_exists($notifiable, 'preferredLocale')
            ? $notifiable->preferredLocale()
            : (string) config('account-settings.default_locale', 'ru');
        $route = $locale !== (string) config('account-settings.default_locale', 'ru')
            && in_array($locale, (array) config('catalog-collections.supported_locales', []), true)
            ? 'localized.password.reset'
            : 'password.reset';
        $parameters = $route === 'localized.password.reset'
            ? ['locale' => $locale, 'token' => $this->token]
            : ['token' => $this->token];

        return route($route, $parameters).'?'.Arr::query([
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }

    protected function buildMailMessage($url): MailMessage
    {
        return (new MailMessage)
            ->subject(__('auth.mail.reset.subject'))
            ->line(__('auth.mail.reset.line'))
            ->action(__('auth.mail.reset.action'), $url)
            ->line(__('auth.mail.reset.expires', [
                'minutes' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
            ]))
            ->line(__('auth.mail.reset.ignore'));
    }
}
