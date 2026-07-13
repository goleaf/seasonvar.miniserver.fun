<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SeasonvarImportFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly bool $targeted,
        public readonly bool $force,
        public readonly bool $discover,
        public readonly ?string $exceptionClass,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return [
            'mail' => (string) config('notifications.mail_queue', 'default'),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Ошибка импорта Seasonvar')
            ->greeting('Импорт Seasonvar завершился ошибкой')
            ->line('Очередной запуск импорта не был завершен успешно.')
            ->line('Режим: '.($this->targeted ? 'одна страница' : 'обычный запуск'))
            ->line('Force: '.($this->force ? 'да' : 'нет'))
            ->line('Discovery: '.($this->discover ? 'да' : 'нет'));

        if ($this->exceptionClass !== null && $this->exceptionClass !== '') {
            $mail->line('Исключение: '.$this->exceptionClass);
        }

        return $mail
            ->action('Открыть статистику', route('stats'))
            ->line('Проверьте логи приложения и состояние последнего запуска импорта.');
    }
}
