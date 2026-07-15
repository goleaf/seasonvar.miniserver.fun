# Notifications и emails

Обновлено: 15.07.2026

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

## Коллекции

Task 10 не добавляет collection follow/like notifications и не требует новую queue: likes/follows как reusable product domains отсутствуют. Generic discussion на collection target использует существующий `CommentNotificationService` и его user preferences; visibility/target resolver повторно ограничивает доставку. Membership, reorder, visibility, moderation и feature меняют UI/cache/audit, но не отправляют скрытые автоматические письма.

## Уведомления обсуждений

- `CommentActivityNotification` использует только Laravel database channel и не требует worker. Stable type в `notifications.type` — `comment.activity`; payload содержит `kind`, stable comment/reaction/report ID и optional moderation status, но никогда body, excerpt, spoiler, actor name, target URL или moderator note.
- Events: published reply, active reaction set/change, видимый moderation status/delete transition и report resolution. Изменение только private note/internal reason не выдаёт автору сигнал о внутренней работе модератора. Mention notifications отсутствуют, потому что mention domain не реализован. Remove reaction/read-state/block/mute/edit/delete не создают лишнего уведомления.
- `CommentNotificationService` исключает self-events, обе стороны block relation и actor, muted recipient-ом. Reply идёт только непосредственному `reply_to` author; root author используется лишь для совместимой строки без logical `reply_to_id`, поэтому вложенный разговор не создаёт fan-out spam. `comment_notification_preferences` независимо управляет reply/reaction/moderation/report. Deterministic UUID recipient+event делает concurrent retry/idempotent delivery duplicate-free; reaction одного actor агрегируется одной logical notification identity.
- Inbox `/profile/discussions`/`/notifications` повторно проверяет current target visibility и comment policy перед ссылкой. Missing/deleted/inaccessible target показывает body-free unavailable entry без dead public URL. Notification read state/private preferences никогда не входят в public comment cache и не инвалидируют public target page.
- Notification text переводится при presentation через активный locale; user comment не переводится. Safe excerpt policy строже требования: excerpt вообще не сохраняется и не передаётся, поэтому spoiler/deleted/HTML/private moderation content исключены по конструкции.
- Database delivery выполняется синхронно без mandatory queue, но является best-effort post-commit boundary: inbox/schema/query failure report-ится и не превращает уже committed comment/reaction/moderation/report mutation в ложную ошибку для пользователя. Deterministic ID защищает любую последующую явную повторную доставку от дубля; скрытой queue/retry guarantee система не заявляет.

## Уведомления отзывов

- `ReviewActivityNotification` переиспользует standard database `notifications` и stable type `review.activity`; payload содержит только notification type, review/title ID and public-safe status. Review title/body/excerpt, spoiler, actor name, reporter, moderator note and watch evidence никогда не сохраняются.
- Stable types cover helpful vote received, moderation result and report resolution. Deterministic UUID makes retry deduplicated; self event and disabled preference suppress delivery. Generic block/mute suppresses the social helpful-vote notice, while official moderation/report outcomes intentionally remain deliverable according to their own preferences. Helpful notification exists only while the actor's current helpful vote exists; vote change/remove cleans deterministic stale notification.
- `catalog_title_review_notification_preferences` stores helpful/moderation/report booleans for current user. `/profile/reviews` manages preferences/history, while the existing `/profile/discussions`/`/notifications` inbox shows a separately paginated review stream; mark-read revalidates recipient. Public destination is emitted only for currently published/non-deleted review on visible title, otherwise neutral profile history/admin-safe destination is used without exposing content.
- Account deletion removes own review notifications/preferences before user deletion. Export does not include another actor or internal payload. Delivery is a synchronous best-effort database boundary: a notification-store failure is reported but does not turn an already committed review/vote/moderation/report mutation into a false user-facing failure. It does not introduce mandatory queue/worker infrastructure.

## Проверки

```bash
php artisan test --filter=RunSeasonvarImportJobTest
php artisan test --filter=SeasonvarImportFailedNotificationTest
php artisan test --filter=ConfigurationEnvironmentTest
```
