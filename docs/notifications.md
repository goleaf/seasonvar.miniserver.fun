# Notifications и emails

Обновлено: 09.07.2026

## Текущее состояние

- В проекте нет публичных пользовательских email-функций и нет Blade email templates.
- Mailer по умолчанию остается `MAIL_MAILER=log`, чтобы локальная разработка не отправляла реальные письма.
- Operational notification используется только для queued импортера Seasonvar.

## Ошибка queued import

`App\Jobs\RunSeasonvarImport::failed()` всегда логирует ошибку. Если задан `SEASONVAR_IMPORT_FAILURE_MAIL_TO`, дополнительно отправляется on-demand notification:

- class: `App\Notifications\SeasonvarImportFailed`;
- канал: `mail`;
- получатель: `SEASONVAR_IMPORT_FAILURE_MAIL_TO`;
- имя получателя: optional `SEASONVAR_IMPORT_FAILURE_MAIL_TO_NAME`;
- очередь: `NOTIFICATIONS_MAIL_QUEUE`, по умолчанию `default`.

Пустой `SEASONVAR_IMPORT_FAILURE_MAIL_TO` отключает отправку. Некорректный email не отправляется и пишет warning в лог. Письмо содержит exception class и параметры запуска, но не raw exception message или stack trace.

## Правила

- Notifications должны реализовать `ShouldQueue`, если канал может обращаться к внешнему сервису.
- Для external recipients используйте on-demand notifications через `Notification::route()`, не создавайте фиктивных пользователей.
- Не включайте в письма stack traces, raw source HTML snapshots, private media URLs, секреты, cookies или credential paths.
- Email Blade templates можно добавлять только как display-only Markdown templates без `@php`/`@endphp`; вычисления должны оставаться в notification/mailable class или сервисе.
- Dispatch тестируется через `Notification::fake()`, content — прямым вызовом `toMail()`.

## Проверки

```bash
php artisan test --filter=RunSeasonvarImportJobTest
php artisan test --filter=SeasonvarImportFailedNotificationTest
php artisan test --filter=ConfigurationEnvironmentTest
```
