# Управление каталогом

Обновлено: 18.07.2026

## System-wide administration integration

Administration показывает истинное permission-scoped состояние content, users, moderation, requests, tickets, help articles, calendar, recommendations, premium, payments, advertisers, rights-holder cases, translations, imports, caches, search, SEO, redirects, audit и system configuration только там, где соответствующий domain реально существует. Она переиспользует канонические services/queries/policies и не дублирует domain logic ради dashboard.

Immutable financial, legal, audit и historical records не редактируются напрямую. Их correction/reconciliation выполняет отдельная authorized domain action с impact preview, idempotency/audit и сохранением history; отсутствующая integration отображается честно как unavailable/not installed без fake health, controls или data.

`admin_audit_events.actor_id` использует `RESTRICT`, поэтому self-service account deletion административного actor блокируется до отдельного authorized retention решения. Это сохраняет immutable actor evidence и выдаёт безопасную локализованную validation error вместо SQL details; обычному администратору не добавлена кнопка изменения или удаления audit history.

## Maintenance visibility boundary

- Administration может показывать read-only maintenance summary только из real repository/runtime registry data и только authorized operational roles.
- Summary states: `current`, `update available`, `deprecated`, `unsupported`, `blocked by compatibility`, `under review`, `unknown`; fake update notice запрещён.
- Browser UI не исполняет Composer/npm, не редактирует lock files, не запускает uncontrolled updates и не предоставляет shell access.
- Package credentials, private repository URLs и exploit details не показываются unrelated roles.
- Approved plans, compatibility warnings, registry counts и documentation links допустимы; package changes остаются repository/deployment workflow в `main`.
- Permissions для summary, compatibility matrix, debt, deprecations, approval, advisories и production compatibility разделяются по least privilege; content admin не управляет dependencies.

## Production operations boundary

- Administration может показывать только real, permission-scoped status: `configured`, `reachable`, `degraded`, `unavailable`, `not_installed` или `unknown`; fake health/backup/queue/scheduler/deployment state запрещён.
- Secret values, `.env`, connection strings, private hostnames/paths, provider responses и raw logs не показываются. Log access bounded, redacted и path-safe.
- Unrestricted `.env` editor, shell, arbitrary Artisan/SQL/filesystem browser и browser-triggered Composer/npm updates запрещены.
- Targeted cache/health/idempotent retry actions допустимы только при существующем application-owned boundary, separate permission, recent authentication, impact preview, confirmation, audit и rollback guidance.
- Backup/restore/deployment controls не добавляются без реально существующей safe orchestration. Restore permission отделено от general administration и выдаётся только operational role.
- Task 28 не добавляет browser deployment/backup/restore/cache-shell panel: реальной application-owned execution/audit/backup platform нет. Detailed `app:health`, migration/import/queue checks и runbooks остаются operator CLI/panel workflow; existing `/admin/imports` показывает только domain-safe importer/media aggregates. Поэтому новые operational UI labels, permissions и fake events не создаются.

## Доступ и границы

- Full-page Livewire 4 компонент `App\Livewire\CatalogAdministrationManager` доступен по `/admin/catalog` только authenticated user из `SEASONVAR_IMPORT_ADMIN_EMAILS`.
- Route middleware проверяет gate `manage-catalog`, компонент повторяет gate на `mount()` и `render()`, а каждую запись сервис авторизует через `CatalogTitlePolicy`. Browser не передаёт user ID, source ID или родительские IDs для записи.
- `/admin/imports` остаётся отдельным существующим экраном запусков импортёра. Из каталога на него ведёт служебная ссылка; новый importer workflow не создавался. Экран показывает per-run counters, здоровье источников и кешируемый глобальный backlog размеров direct-file media; snapshot не содержит source/playback URL и не запускает сетевую проверку при Livewire render.
- Write actions проходят gate, policy, server-side validation и optimistic version checks без локального request budget.

## Возможности

- Сериал: редакционное и оригинальное название, slug, внешний ID в пределах существующего source, год, описание, постер, `publication_status`, audience и UTC-окно доступности.
- Связи: актёры, режиссёры, жанры, страны и существующая модель `Translation` для языка/перевода. Варианты ищутся на сервере, максимум по 20; полные справочники не сериализуются в Livewire snapshot.
- Иерархия: обычные и специальные сезоны/серии, детерминированный `sort_order`, publication status, audience и UTC-окна. Все IDs повторно ограничиваются выбранным тайтлом и сезоном.
- Видео: создание только из HTTPS URL на host из `PLAYBACK_ALLOWED_HOSTS`, безопасные allowlist значения формата/качества и публикация. URL существующего источника не возвращается в форму и не редактируется из browser.
- «Скрыть» переводит запись в reversible `hidden`/`draft`; строки progress, history, watchlist и rating не удаляются каскадно. Каждый такой action имеет `wire:confirm` и повторную server-side авторизацию.

## Наблюдаемость импортёра

- Size-backlog panel показывает eligible, known, pending и due totals, terminal breakdown, metadata coverage, сумму известных exact bytes, плановые hard count/time ограничения scheduled batch и время capture.
- Данные подготавливает `SeasonvarImportAdminService`; Blade получает только локализованные strings/icons. Полный aggregate по большой SQLite-таблице хранится в operational tiered cache пятнадцать минут и допускает bounded stale fallback, поэтому 5-секундный poll активного запуска не повторяет scan; время capture показывается рядом с totals.
- Это read-only observability: панель не увеличивает throughput, не принимает URL от browser, не выполняет HEAD/Range и не меняет media metadata. Backfill продолжает только существующая `seasonvar:import --refresh-media-sizes` и её консервативный scheduled запуск.

## Управление переводами

- Interface copy главной и общей оболочки не является editable editorial content: она хранится в парных PHP catalogs `lang/ru/home.php`, `lang/en/home.php` и `catalog.php`, проходит code review и static parity/placeholder validation.
- Редакционные коллекции и global tags остаются в существующих `catalog_collection_translations`/`tag_translations` и редактируются текущими admin flows для `ru`/`en`; missing locale применяет documented model/query fallback. Не создаётся DB copy для source-code labels.
- Core serial/season/episode translations не добавлены: authoritative translated fields и admin workflow отсутствуют. Provider/original titles, studio brands, audio/subtitle language/type и user comments/reviews сохраняются как отдельные доменные значения и не машинно переводятся.
- При добавлении locale сначала расширяется единый config allowlist, затем все PHP catalogs, route/SEO/sitemap/cache dimensions, account validation и admin translation forms. Частичное включение locale запрещено.

## Целостность и конкурентные изменения

- Формы нормализуют и валидируют explicit allowlist полей. Уникальность slug, `(source_id, external_id)`, `(catalog_title_id, kind, number)`, `(season_id, kind, number)`, metadata pivots и `(catalog_title_id, source_media_key)` дополнительно обеспечивается существующими database constraints.
- `CatalogAdministrationService` выполняет multi-table writes в коротких транзакциях и блокирует выбранную hierarchy через `lockForUpdate()`.
- Locked Livewire version fingerprints включают редактируемые поля, timestamps и связи. Если importer или другой администратор изменил запись после открытия формы, устаревшее сохранение отклоняется с русской ошибкой вместо silent overwrite.
- Новые справочники создаются как локальные строки без provider identity. `SeasonvarCatalogRelationSyncer` использует `syncWithoutDetaching`, поэтому повторный импорт не удаляет локальную связь. Provider baseline в `provider_field_values` продолжает защищать локальные title/description/artwork.
- Исправление внешнего ID допустимо только как осознанная коррекция provider identity: следующий импорт будет искать тайтл по новому `(source_id, external_id)`.

## Аудит административных изменений

- Успешные изменения title metadata/publication, связей, lookup, сезонов, серий и media source metadata атомарно добавляют строку в `admin_audit_events` внутри той же database transaction.
- Событие хранит actor ID, allowlisted action/resource type и resource ID, SHA-256 fingerprints до/после, отсортированные allowlisted имена изменённых полей и время. Значения полей, playback/source URL, provider payload, search text, tokens и stack traces не сохраняются.
- `AdminAuditEvent` является append-only: application model запрещает update/delete, а admin route/service для изменения или удаления событий отсутствует. Неуспешная validation, optimistic lock или unique constraint не создаёт audit row.
- Импортёр и публичные пользовательские действия не пишут в эту таблицу: recorder подключён только к `CatalogAdministrationService` и всегда получает authenticated actor из текущего admin action.

## Публикация и актуализация

- Application timezone — UTC; значения `available_from`/`available_until` вводятся и сохраняются в UTC. Плановая публикация становится видимой только после начала окна.
- Изменение сериалов, сезонов, серий, связей и источников обновляет `catalog_titles.indexed_at`, сбрасывает только snapshot статистики и удаляет materialized recommendations, затронутые тайтлом. Каталог, Continue Watching и playback используют свежие SQL-boundaries без shared user cache.
- SQL-поиск не требует отдельного search-index job. Recommendation fallback остаётся доступен, а полный materialized rebuild выполняется существующим importer lifecycle.

## Деплой

Перед развёртыванием admin audit нужно применить additive migration `2026_07_13_210000_create_admin_audit_events_table`. Затем разворачивается код и перезапускаются долгоживущие queue workers через `php artisan queue:restart`. После деплоя проверяются `SEASONVAR_IMPORT_ADMIN_EMAILS` и `PLAYBACK_ALLOWED_HOSTS`; secrets в репозиторий не записываются.

Текущие ограничения: нет отдельной RBAC/role модели, workflow approval, UI просмотра/экспорта audit trail, restore-кнопки и нормализованной сущности языка. До появления этих доменных моделей email allowlist и `Translation` остаются осознанными границами продукта.
## Модерация коллекций

`/admin/collections` защищён существующим gate `manage-catalog`. `CatalogCollectionAdministrationManager` показывает bounded pending/open-report queue, а `CatalogCollectionModerationService` является единственной write boundary для approved/rejected/hidden/archived и feature. Каждое material action повторно разрешает stable UUID и locked record including soft-deleted where appropriate, меняет content version, сбрасывает incompatible feature/publication state и атомарно пишет `AdminAuditRecorder` fingerprint в той же transaction; exact retry является no-op, а invalidation discovery/cache/sitemap выполняется after commit. Raw internal note пользователю не показывается.

Feature разрешён только approved public editorial collection. Обычный user не может назначить editorial/system type, moderation state или feature. Collection reports используют stable reason/status values, sanitized details, per-user rate limit и deduplication key; reporter identity/moderation notes не публикуются. Admin action закрывает максимум 100 open reports за запрос и явно предлагает следующий пакет, сохраняя decision+audit атомарно. Permanent target deletion сохраняет report UUID/version evidence с nullable relation и privacy-retires generic comments.

Editorial editor в `/my/collections/{uuid}/edit` доступен только `manage-catalog`, хранит `ru/en` DB title/description/SEO rows и не копирует user-created text в translation catalog. Admin workflow не заменяет importer admin, title moderation или generic comment moderation.

## Модерация обсуждений

`/admin/comments` — единственная comment moderation queue и защищена `manage-comments`, который использует существующий administrator allowlist. `CommentAdministrationManager` хранит только allowlisted filters/form values и selected stable ID; `CommentModerationQuery` пагинирует deterministic queue, eager-loads author, не более пяти oldest open-report previews, grouped exact report/reply counts и active restrictions. Filter status/target — enums, user search очищает wildcard/control input и использует bound `LIKE`. Ошибка чтения queue/context журналируется, скрывает зависящие от состояния формы и показывает локализованную fail-closed панель вместо framework error.

Модератор может перевести comment в `published|pending|hidden|rejected|spam|removed`, выбрать stable reason, добавить private plain-text note, одновременно resolve open reports, отдельно resolve/dismiss report и применить/revoke temporary/permanent comment-only restriction. Выбранная запись показывает bounded thread context: root, первые 20 chronological replies и сам выбранный reply, даже если он находится вне окна; вся большая ветка в память не загружается. Removed создаёт soft-delete tombstone; возврат из moderator removal восстанавливает row, но не отменяет author deletion. Privacy-retired tombstone является terminal `removed` evidence и не переоткрывается обычной модерацией. Каждый action повторно gate/policy-check-ит actor, lock-ит row, идемпотентно обрабатывает retry и атомарно пишет `AdminAuditRecorder` fingerprint в той же транзакции без body/private note value; массовое закрытие ограничено 100 oldest report rows за request и пишет отдельный fingerprint каждой, а остаток остаётся actionable. Affected target и author notification меняются только после commit и только при реальном status/delete visibility transition, не при правке одной приватной заметки.

Queue показывает spoiler и сохранённый deleted-review title/body только внутри moderator-only page, reporter identity публично не выводится. Hidden/deleted/inaccessible target не открывается обычному посетителю; direct moderator link ведёт в private selected queue context. Bulk moderation и edit history не добавлены: текущий product не требует их, а одиночные explicit confirmation actions сохраняют понятный audit/partial-failure contract.

## Модерация отзывов

`/admin/reviews` — единственная review moderation queue и защищена `manage-reviews` на route, component и action levels. `ReviewModerationManager` хранит allowlisted filters, form scalars and stable selected IDs; `CatalogTitleReviewQuery::forModeration()` пагинирует pending/unresolved-report priority, eager-loads author/target/all report statuses, grouped unresolved count and one active restriction per page author. `open` и `reviewed` остаются unresolved до явного `resolved|dismissed`. Filters support status, exact review ID, sanitized author, title ID/slug and canonical 1–10 rating.

Moderator may publish/pending/hide/reject/spam/remove or re-publish a moderation-hidden row, set stable reason, correct whole-review spoiler flag, store private plain-text note, resolve/dismiss one unresolved report with its own independent private note, and apply/revoke temporary/permanent review-only restrictions. Author/privacy soft deletion is never restored or silently undone by moderation; target/author/identity/rating are not editable there. Every mutation locks/reloads the row, is idempotent, writes the decision and exact changed-field audit atomically, updates public caches/notifications only for a real presentation/status transition and keeps body/note/reporter identity out of the audit payload.

Restrictions evaluate `expires_at` during permission checks, so expiration needs no cron. Private notes, reporter and exact watch evidence never appear in public/profile payload or author notification. Bulk destructive actions and hard-delete controls are intentionally absent; imported and user rows retain stable IDs/evidence. Title merge remains a domain service, not an admin button that bypasses canonical catalog merge.

## Управление глобальными тегами

`/admin/tags` защищён `manage-catalog` на route и повторно в каждом Livewire hydration/service policy. `TagAdministrationManager` хранит только allowlisted form scalars, locked UUID/IDs и version fingerprints; `TagAdministrationQuery` строит deterministic bounded list, translations/alias/provider counts и merge preview. Private `user_tags` не входят в global admin directory.

Администратор создаёт только allowlisted `system|editorial` global rows, задаёт optional immutable language-independent code, safe canonical label/slug, visibility/moderation/source, редактирует выбранную `ru|en` translation label/plain descriptions/SEO, locale-aware approved/pending aliases, bounded directional/bidirectional synonyms, global title assignments и provider mapping decision. Locale selectors построены из configured supported locales и не принимают произвольный client value; перевод сохраняется с тем же optimistic `content_version`, что и base edit. `hidden_internal` всегда internal; `imported` создаётся только synchronizer/mapping path. Current code нельзя изменить обычным edit, translated labels никогда не становятся enum/code.

Alias проверяется против canonical names/current/history/other alias identity всех locale и не создаёт отдельную page; один normalized alias не может указывать на разные canonical targets, а legacy ambiguity resolver отклоняет без случайного выбора. Deletion сохраняет approved slug как history. Synonym не допускает self-pair/duplicate и не превращается в merge. Provider approval/rejection сохраняет source identity; rejection marks current observations stale и снимает только assignments без другого source. Editorial corrections, explicit assignments и archive/rejected decisions import не перезаписывает.

Merge требует source/target preview с counts/translations/aliases/provider impact и явное confirmation. Transaction reconciles pivots/provenance/translations/aliases/slugs/provider mappings/synonyms, оставляет merged source/legacy redirects, invalidates recommendations/search/cache/sitemap и пишет `TagMergeEvent` плюс `AdminAuditRecorder`. Repeat source→same target idempotent; personal tags и разные owners никогда не затрагиваются.

Permanent global delete отсутствует: active tag archive-ится или merge-ится. Archive сохраняет pre-visibility/pre-moderation и прекращает public discovery/new assignment; restore возвращает recorded state и не публикует internal/rejected row. Все validation/result/loading/error labels локализованы; raw SQL, source credentials, moderation internals и private personal labels не выводятся.

## Модерация профилей пользователей

`/admin/profiles` requires the existing `manage-catalog` gate at route, Livewire hydration and service action. The deterministic bounded queue shows safe target identity/category/status/date without reporter email, account credentials, raw media path or unrelated private activity. Moderator may set stable profile moderation status, hide biography, remove avatar/cover and resolve/dismiss a report with a private note; it cannot edit password/email/role/premium or restore deleted account data. Every public-presentation transition increments the profile version so policy/SEO/sitemap reads change immediately.

## Модерация заявок на материалы

`/admin/requests` требует `auth`, `auth.session`, `account.private` и `manage-content-requests` на route, Livewire hydration и каждой action; response остаётся `private, no-store`/`noindex`. Queue имеет deterministic pagination, search/type/status/sort, public card counts, private note, rejection reason, stable priority, clarification question, canonical merge UUID, verified completion title/season/episode/media IDs, importer handoff и связанный real import-run ID. PHP готовит per-request capability flags из canonical status rules, поэтому несовместимые clarification/completion/merge/handoff controls не рендерятся. Normal user не получает controls или underlying private fields.

Transition matrix запрещает произвольный jump, а generic action дополнительно не принимает clarification/duplicate/merge/withdraw status без dedicated invariant. Rejection требует stable public-safe reason; optional public explanation и private note разделены. Partial/full completion требует published result нужного canonical target. Merge требует exact semantic compatibility, переносит votes/follows/evidence/external IDs идемпотентно, сохраняет source history/redirect и никогда не объединяет private records разных requester; restricted clarification переносится только без смены requester visibility. Bulk moderation намеренно не добавлена: без подтверждённого большого объёма per-item preview/authorization безопаснее текущих bounded single-item actions.

## Очередь технической поддержки

`/admin/issues` требует `manage-technical-issues` на route, hydration и каждой action. Queue имеет deterministic pagination, search/status/type/severity/priority/team/target/assignment/source-health filters, grouped counts и safe affected-user/attachment/message indicators без list diagnostic payload. Staff detail поддерживает classification, validated administrator assignment, clarification/public reply/internal note, transition, resolution, requester verification review, reopen, reject/reroute, redact, exact merge и linked source under-review/disable/restore.

Bulk ограничен 10 explicit selected tickets и только priority/assignment; каждый ticket повторно authorizes, partial failures показываются. Merge/resolve/reject/source action не bulk. Private note, requester evidence, provider/source URL и unrelated account data не выходят normal users. Полный support contract: [`technical-issues.md`](technical-issues.md).

## Редактор календаря релизов

`/admin/calendar` требует `manage-release-calendar` на route, Livewire hydration и save. Редактор ищет каноническую цель, задаёт stable type/precision/status/source, IANA timezone, partial/exact date, public/notification flags и manual lock; target ancestry и status transition валидируются server-side. История показывает public reason отдельно от private note. Обычный importer не перезаписывает locked event. Bulk editor и iCalendar token controls не добавлены без подтверждённой product boundary. Полный contract: [`release-calendar.md`](release-calendar.md).

## Администрирование Premium

`/admin/premium` повторно использует private admin shell и входной `view-premium-administration` из `SEASONVAR_IMPORT_ADMIN_EMAILS`. Отдельные action gates `manage-premium-grants`, `manage-premium-promotions`, `view-premium-billing-audit`, `reconcile-premium` требуют соответственно `PREMIUM_GRANT_ADMIN_EMAILS`, `PREMIUM_PROMOTION_ADMIN_EMAILS`, `PREMIUM_BILLING_AUDIT_EMAILS` и `PREMIUM_RECONCILIATION_ADMIN_EMAILS`; пустой список запрещает capability и скрывает control. Уполномоченный staff может найти account по public ID/email, выдать duration/lifetime `premium_access` с stable reason/private note, отозвать ровно одну administrative/promotion запись, создать campaign и одноразово получить generated coupon. Payment entitlements не отзываются manual action.

Safe audit показывает stable action/resource/time; provider summary раскрывает только registered code. Environment, secrets и raw payload отсутствуют. Cancel/refund/replay/reconciliation controls не имитируются до появления реального adapter/policy. Полный least-privilege и rollout contract — [`premium.md`](premium.md).

## Редактура центра помощи

`/admin/help` требует private admin middleware и `manage-help-center` на route, hydration и каждом action. Редактор управляет stable article/category, обеими translations, aliases, SEO/callout, related/context/escalation/order/featured, preview, review/publication/archive, freshness, revision restore, merge/replacement и queue outdated reports. Published content сначала выводится из publication; restore возвращает draft. Articles, feedback и reports пагинированы, revision timeline ограничен.

Editor не задаёт произвольный route/class/HTML/CSS/media URL/status/type/locale/escalation. Встроенная link validation и broken filter не выполняют внешний crawl. Private notes/reporter/actor/revisions/internal articles отсутствуют в public payload. Полный workflow, permission matrix, ownership/review и rollback: [`help-center.md`](help-center.md).

## Управление playback sources Task 07

Existing catalog administration остаётся единственной write surface для source status, health, priority, format, quality, translation/variant/type, subtitle flag, audience и availability window. Каждая mutation авторизуется и сохраняет importer/editorial ownership; provider credentials/raw response не выводятся в списках. Technical issue source action проходит отдельную Task 20 staff boundary и не отключает source после одного client failure.

Отдельные audio tracks, subtitle bodies/languages, Premium source feature, region-country и age-profile rules не добавлены, поскольку текущие schema/importer не могут управлять ими правдиво. При появлении таких данных сначала расширяются canonical source/import/admin contracts, затем player DTO. Подробности: [`audits/video-playback-report.md`](audits/video-playback-report.md).

## Administration boundary личной библиотеки Task 09

Bookmark, status, feedback/blacklist, exact progress, markers и acknowledgments являются owner-private состоянием и не получают общего staff editor/list. Existing catalog admin управляет только title/episode/media publication и canonical release events; collection moderation сохраняет существующие отдельные policy/gates. Изменение контента отражается в personal update query через visibility и release identity, а не через ручное редактирование пользовательских строк.

Merge tooling обязано вызывать existing `CatalogTitleUserDataMerger` до удаления duplicate identity. Администратор не может через UI подменить owner ID, открыть private marker или сбросить progress; account export/delete выполняются владельцем через existing privacy workflow.
