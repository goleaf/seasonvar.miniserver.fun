<?php

namespace Tests\Unit;

use App\Notifications\SeasonvarImportFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use stdClass;
use Tests\TestCase;

class SeasonvarImportFailedNotificationTest extends TestCase
{
    public function test_notification_is_queued_on_configured_mail_queue(): void
    {
        config(['notifications.mail_queue' => 'mail']);

        $notification = new SeasonvarImportFailed(
            argument: null,
            force: false,
            discover: true,
            exceptionClass: null,
        );

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertSame(['mail'], $notification->via(new stdClass));
        $this->assertSame(['mail' => 'mail'], $notification->viaQueues());
        $this->assertSame(3, $notification->tries);
        $this->assertSame([60, 300, 900], $notification->backoff);
    }

    public function test_mail_message_contains_safe_import_failure_context(): void
    {
        $notification = new SeasonvarImportFailed(
            argument: 'https://seasonvar.ru/serial-1-Test-1-season.html',
            force: true,
            discover: false,
            exceptionClass: 'RuntimeException',
        );

        $mail = $notification->toMail(new stdClass);

        $this->assertSame('Ошибка импорта Seasonvar', $mail->subject);
        $this->assertSame('Открыть статистику', $mail->actionText);
        $this->assertStringEndsWith('/stats', $mail->actionUrl);

        $lines = implode("\n", $mail->introLines);

        $this->assertStringContainsString('Очередной запуск импорта не был завершен успешно.', $lines);
        $this->assertStringContainsString('Режим: одна страница', $lines);
        $this->assertStringContainsString('Force: да', $lines);
        $this->assertStringContainsString('Discovery: нет', $lines);
        $this->assertStringContainsString('URL: https://seasonvar.ru/serial-1-Test-1-season.html', $lines);
        $this->assertStringContainsString('Исключение: RuntimeException', $lines);
        $this->assertStringNotContainsString('network failed', $lines);
    }
}
