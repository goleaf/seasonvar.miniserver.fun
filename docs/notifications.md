# Notifications и emails

Обновлено: 15.07.2026

## Текущее состояние

- В проекте нет настраиваемых пользовательских email/push notification categories и нет Blade email templates. Canonical password reset, email verification и другие critical account mail не выдаются за отключаемые настройки.
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
- Events: immediately published reply, pending reply в момент реального approval, active reaction set/change, видимый moderation status/delete transition и report resolution. Изменение только private note/internal reason не выдаёт автору сигнал о внутренней работе модератора. Pending reply не уведомляет адресата до публикации, а deterministic ID не позволяет повторному hide/publish создать дубль. Mention notifications отсутствуют, потому что mention domain не реализован. Remove reaction/read-state/block/mute/edit/delete не создают лишнего уведомления.
- `CommentNotificationService` исключает self-events, обе стороны block relation и actor, muted recipient-ом. Reply идёт только автору текущего published/non-deleted `reply_to`; root используется лишь для совместимой строки без logical `reply_to_id`, поэтому вложенный разговор не создаёт fan-out spam, а delayed approval не уведомляет уже скрытый/удалённый контекст. `comment_notification_preferences` независимо управляет reply/reaction/moderation/report; отсутствующая строка читается через неперсистентные opt-in defaults и создаётся только при явном сохранении настроек. Deterministic UUID recipient+event делает concurrent retry/idempotent delivery duplicate-free; reaction одного actor агрегируется одной logical notification identity.
- Inbox `/profile/discussions`/`/notifications` повторно проверяет current target visibility и comment policy перед ссылкой. Missing/deleted/inaccessible target показывает body-free unavailable entry без dead public URL. Notification read state/private preferences никогда не входят в public comment cache и не инвалидируют public target page.
- Database indexes соответствуют двум реальным доменным запросам: `(notifiable_type, notifiable_id, type, created_at, id)` для deterministic pagination и `(notifiable_type, notifiable_id, type, read_at)` для unread operations. Отдельный morph-prefix index не дублирует их левый префикс.
- Notification text переводится при presentation через активный locale; user comment не переводится. Safe excerpt policy строже требования: excerpt вообще не сохраняется и не передаётся, поэтому spoiler/deleted/HTML/private moderation content исключены по конструкции.
- Database delivery выполняется синхронно без mandatory queue, но является best-effort post-commit boundary: inbox/schema/query failure report-ится и не превращает уже committed comment/reaction/moderation/report mutation в ложную ошибку для пользователя. Перед insert recipient user row блокируется и повторно разрешается: concurrent account deletion либо удалит уже вставленное событие своим cleanup, либо завершится раньше и новая orphan morph notification не появится. Deterministic ID защищает любую последующую явную повторную доставку от дубля; скрытой queue/retry guarantee система не заявляет.

## Уведомления отзывов

- `ReviewActivityNotification` переиспользует standard database `notifications` и stable type `review.activity`; payload содержит только `kind`, stable review ID, optional vote/report ID and optional moderation status. Target title/ID повторно разрешается при presentation и не хранится в notification. Review title/body/excerpt, spoiler, actor name, reporter, moderator note and watch evidence никогда не сохраняются.
- Stable types cover helpful vote received, moderation result and report resolution. Deterministic UUID makes retry deduplicated; self event and disabled preference suppress delivery. Generic block/mute suppresses the social helpful-vote notice, while official moderation/report outcomes intentionally remain deliverable according to their own preferences. Helpful notification exists only while the actor's current helpful vote exists; vote change/remove cleans deterministic stale notification.
- `catalog_title_review_notification_preferences` stores helpful/moderation/report booleans for current user. Каноническая matrix находится в `/settings/notifications`; `/profile/reviews` сохраняет только own history, а `/profile/discussions`/`/notifications` — inbox с отдельно пагинированным review stream. Mark-read revalidates recipient. Public destination is emitted only for currently published/non-deleted review on visible title, otherwise neutral profile history/admin-safe destination is used without exposing content.
- Account deletion removes own review notifications/preferences before user deletion. Export does not include another actor or internal payload. Delivery is a synchronous best-effort database boundary: a notification-store failure is reported but does not turn an already committed review/vote/moderation/report mutation into a false user-facing failure. Like comment delivery, it locks/re-resolves the morph recipient so concurrent account deletion cannot leave an orphan database notification. It does not introduce mandatory queue/worker infrastructure.

## Каноническая matrix настроек

`/settings/notifications` показывает только реально поддержанные in-portal database categories: comment reply/reaction/moderation/report и review helpful/moderation/report. Stable DB booleans остаются в существующих dedicated tables; `UpdateCommentNotificationPreferences` и `UpdateReviewNotificationPreferences` являются единственными write actions, а delivery services продолжают применять их до создания уведомления.

Отсутствующая preference row означает неперсистентный opt-in default и создаётся только после explicit Apply/Reset. Email, push, episode/season/translation/subtitle/quality, collection follow/like, mention, follower и premium reminder controls не показываются, потому что соответствующие event/channel domains отсутствуют. Critical account security mail остаётся mandatory в своих auth workflows и не представляется configurable category.

## Проверки

```bash
php artisan test --filter=RunSeasonvarImportJobTest
php artisan test --filter=SeasonvarImportFailedNotificationTest
php artisan test --filter=ConfigurationEnvironmentTest
```
