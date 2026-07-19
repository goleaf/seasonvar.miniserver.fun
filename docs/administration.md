# Каноническая администрация портала

Обновлено: 19.07.2026

## System-wide administration integration

Administration показывает истинное permission-scoped состояние content, users, moderation, requests, tickets, help articles, calendar, recommendations, premium, payments, advertisers, rights-holder cases, translations, imports, caches, search, SEO, redirects, audit и system configuration только там, где соответствующий domain реально существует. Она переиспользует канонические services/queries/policies и не дублирует domain logic ради dashboard.

Immutable financial, legal, audit и historical records не редактируются напрямую. Их correction/reconciliation выполняет отдельная authorized domain action с impact preview, idempotency/audit и сохранением history; отсутствующая integration отображается честно как unavailable/not installed без fake health, controls или data.

`admin_audit_events.actor_id` использует `RESTRICT`, поэтому self-service account deletion административного actor блокируется до отдельного authorized retention решения. Это сохраняет immutable actor evidence и выдаёт безопасную локализованную validation error вместо SQL details; обычному администратору не добавлена кнопка изменения или удаления audit history.

## Реализация Task 26

Одна route group `/admin` содержит 17 стабильных `GET|HEAD` entry routes: dashboard, users, access, audit, operations и прежние catalog/imports/comments/reviews/profiles/tags/requests/issues/calendar/premium/help/help-preview. Группа использует canonical `web` identity и middleware `auth`, `throttle:administration`, `auth.session`, `verified`, `account.private`, `account.active`, `admin.access`; каждый destination дополнительно требует action-level `can:*`. State changes выполняются только CSRF-protected Livewire requests, destructive `GET` отсутствует.

`AdminAccessRegistry` определяет stable codes 14 ролей и 60 permissions. `AdminAccessResolver` загружает memberships и permissions одним bounded eager-loaded graph на request; `AdminGateRegistrar` публикует новые permission gates и прежние gate names как compatibility facade. `AdminLegacyAccessMap` временно сохраняет только старые возможности точных email allowlists, а отдельные sensitive Premium allowlists не расширяются. `superadministrator` не получает автоматически `billing.refund`, `billing.reconcile`, `legal.identity_documents` или `legal.authority_documents`.

Новые таблицы `admin_roles`, `admin_permissions`, `admin_role_permissions`, `admin_user_roles`, `account_restrictions` и `admin_operational_events` additive и SQLite-compatible. Роли/permissions заполняются stable enum-кодами; translated labels остаются только в `lang/ru/administration.php` и `lang/en/administration.php`. Final active superadministrator нельзя revoke/suspend; role mutation требует recent authentication, stable reason, explicit confirmation для destructive changes, assign-only-what-actor-possesses и secret-free audit.

Shared navigation registry фильтруется server-side одним resolved permission set и не выполняет query на item/badge. Dashboard использует реальные grouped aggregates, скрывает недоступные sections и изолирует отказ виджета. Shared table/filter/state/confirmation contracts задают allowlisted sort/filter codes, page sizes `15|25|50`, максимум 50 explicit bulk identities, deterministic pagination, accessible mobile scroll/card behavior и generic failure messages без SQL/class/path details.

User directory выводит public identity, masked email и разрешённый account summary без hashes, tokens, MFA secrets, payment credentials, private legal/support data. Canonical account restrictions имеют stable reason/expiry/public notice/private note, audit и notification; blocking restrictions отзывают sessions/tokens, запрещают browser/mobile/social-equivalent повторный вход и optional Sanctum playback, сохраняя Premium history. Account merge не симулируется: без proof/conflict/OAuth/billing/legal reconciliation coordinator capability честно unavailable.

`/admin/operations` показывает только проверяемые capabilities и safe readiness summary. Разрешены allowlisted targeted cache-version invalidation и rebuild одного существующего SQL search document с confirmation, idempotency key и audit. Full cache flush, arbitrary key/log/file/shell/Artisan/SQL/environment editor, fake external index, fake provider, fake advertiser/rights-holder UI и browser deployment/restore отсутствуют. Частичные ошибки capabilities/search/history изолируются и показывают локализованное безопасное состояние.

## Постоянные administration requirements

- В портале существует одна canonical administration architecture. Feature modules интегрируются через shared navigation, authorization, audit, filters, tables, pagination, dialogs, notifications, translations, cache invalidation и design components, а не создают отдельные admin mini-applications.
- Identity использует stable role/permission codes и централизованный role-based/permission-based server access. Least privilege обязателен; permissions разделяются по sensitivity, и каждый administrator не становится superadministrator автоматически.
- Billing, legal, identity, technical-ticket, advertiser и private user data не доступны unrelated administrators. Legal identity/authority documents, billing operations, internal notes и audit export имеют отдельные permissions.
- Каждая administrative mutation авторизуется, валидируется и создаёт meaningful secret-free audit event. Destructive action не использует `GET`, требует explicit confirmation и recent authentication там, где risk это оправдывает.
- Internal notes private, не скрываются только CSS, не входят в public API/cache/search/export без отдельного policy requirement. Legal и billing notes ограничены отдельно.
- Bulk actions bounded, дают impact preview, выполняют per-item authorization, объявляют transaction/partial-failure/idempotency strategy и показывают итог по каждому failure class. Legal removal, refund и role escalation не выполняются без отдельного безопасного workflow.
- Admin lists используют deterministic sorting, validated stable filter/sort codes, bounded pagination и projection/eager loading без N+1. Private attachments/document binaries и raw diagnostics не загружаются в list views.
- Administration сохраняет locale; все labels переведены через существующую систему. Role, permission, status, route, cache и configuration identity не используют translated labels.
- Все administration routes имеют `noindex`, исключены из sitemap/public search/structured data/service-worker public cache и всегда возвращают private `no-store` semantics.
- Feature configuration использует typed stable setting definitions. Generic unrestricted key-value, `.env`, secret, shell, arbitrary SQL/filesystem/cache/log interface запрещены.
- Immutable financial, legal и audit history не редактируется напрямую. Correction/reconciliation создаёт отдельное событие и сохраняет history.
- Administrator impersonation не вводится автоматически. Если она уже существует, она должна быть highly restricted, audited, time-limited и visibly indicated.
- System-level action имеет rollback/correction workflow, когда practically возможно. UI показывает truthful system state и никогда не симулирует health, progress, analytics, provider, index, backup или deployment capability.
- Responsive/a11y contract обязателен для phone emergency access, tablet и desktop: keyboard, focus, screen-reader labels, touch targets, accessible tables/dialogs/announcements, long translations и zoom.

## Реализованная shared architecture

Canonical administration объединяет stable `/admin` routes и names, common middleware/authentication/active-account eligibility, shared layout/navigation registry, centralized roles/permissions, permission-aware dashboard, table/filter/search/pagination primitives, bounded bulk contracts, confirmation/recent-auth boundary, audit recorder/viewer, domain-owned private notes/activity timelines, status badges, empty/loading/error/unavailable states, targeted cache invalidation и multilingual responsive behavior.

Resource policy остаётся authoritative поверх section/action permission. Resource ownership применяется там, где domain его имеет. Navigation hiding не заменяет route/policy checks; browser никогда не передаёт trusted role, permission, user, ownership, premium, region, price, status или legal state.

Отсутствующий advertiser, rights-holder, external search-index, payment provider, feature-flag, backup/deployment orchestration или log-viewer domain не создаётся ради заполнения меню. Registry помечает capability как `not_installed`/`unavailable` только на authorized overview либо полностью убирает control; fake destination и fake metric запрещены.

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

- Full-page Livewire 4 shell `App\Livewire\CatalogAdministrationPage` доступен по `/admin/catalog` пользователю с `administration.access` и `content.view`; конкретные формы требуют `content.create`, `content.manage`, `content.publish`, `content.delete`, `sources.view`, `sources.manage`, `sources.disable`, `collections.moderate` или `recommendations.manage`.
- Route middleware и component hydration повторяют section permission, а каждую запись сервис авторизует через policy/action permission. Moderator получает read-only catalog context, нужный для collection queue, без metadata/source/publication прав; `content_editor` не может archive/publish, `media_manager` не получает metadata edit.
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

- Успешные изменения RBAC/membership/account restriction/cache/search, title metadata/publication, связей, lookup, сезонов, серий, media, collections, comments, reviews и tags атомарно добавляют строку в существующий `admin_audit_events` внутри той же database transaction. Auth, Premium, requests, tickets, help, calendar и imports продолжают использовать свои canonical histories/events; данные не дублируются механически.
- Событие хранит public UUID, actor, allowlisted action/resource type, safe public resource identity, optional UUID correlation identity, SHA-256 fingerprints до/после, отсортированные allowlisted имена изменённых полей и время. Значения полей, private notes, playback/source URL, provider payload, search text, tokens, sessions и stack traces не сохраняются.
- `AdminAuditEvent` является append-only: application model запрещает update/delete, а admin route/service для изменения или удаления событий отсутствует. Неуспешная validation, optimistic lock или unique constraint не создаёт audit row.
- `/admin/audit` использует permission `audit.view`, bounded 90-day date filter, deterministic pagination и safe projection. CSV export требует `audit.export`, ограничен 1000 rows, защищает от spreadsheet formula injection и не включает internal numeric IDs, secrets или raw field values.

## Публикация и актуализация

- Application timezone — UTC; значения `available_from`/`available_until` вводятся и сохраняются в UTC. Плановая публикация становится видимой только после начала окна.
- Изменение сериалов, сезонов, серий, связей и источников обновляет `catalog_titles.indexed_at`, сбрасывает только snapshot статистики и удаляет materialized recommendations, затронутые тайтлом. Каталог, Continue Watching и playback используют свежие SQL-boundaries без shared user cache.
- SQL-поиск не требует отдельного search-index job. Recommendation fallback остаётся доступен, а полный materialized rebuild выполняется существующим importer lifecycle.

## Деплой

Перед развёртыванием применяются пять additive migrations `2026_07_19_235900`—`2026_07_19_235904`, затем код/assets и штатный restart существующих long-lived workers. Preflight: backup по production runbook, `migrate:status`, доступность `users`/старого `admin_audit_events`, проверка `ADMIN_BOOTSTRAP_SUPERADMIN_EMAILS` и legacy allowlists без вывода значений. Post-deploy: `/admin`, route middleware, final-super invariant, private/no-store/noindex headers, targeted cache/search actions, queue/import status и public portal smoke.

Rollback сначала возвращает предыдущий код, сохраняя additive tables/columns и legacy compatibility adapter. Down migrations выполняются только после отдельной проверки dependants и сохранения audit/role evidence; автоматическое удаление production RBAC/audit rows не является штатным rollback. Schema не требует ручного map всех legacy administrators в sensitive roles: старые точные allowlists продолжают работать только в прежнем объёме, а новые назначения выполняются явно.

Текущие честные ограничения: advertiser/rights-holder case schemas, configured payment gateway, external search engine, generic redirect/settings/feature-flag store, impersonation, safe raw-log browser и deployment/restore orchestration отсутствуют; поэтому соответствующих routes и mutation controls нет. Core serial/season/episode localized metadata schema также отсутствует; interface и существующие editorial translation domains сохраняют RU/EN integration без fake translation studio.
## Модерация коллекций

`/admin/catalog` — единственный full-page Livewire shell для управления сериалами и коллекциями и защищён stable permission `content.view`; mutations разделены между `content.*`, `sources.*`, `collections.moderate` и `recommendations.manage`, а `manage-catalog` остаётся compatibility alias для `content.manage`. Query-параметр `section=collections` требует `collections.moderate` и монтирует `CatalogCollectionAdministrationManager` как вложенный manager без собственного маршрута; `catalog_q`, `collection_admin_q`, `catalogAdminPage` и `collectionAdminPage` не конфликтуют. Manager показывает bounded pending/open-report queue, а `CatalogCollectionModerationService` является единственной write boundary для approved/rejected/hidden/archived и feature. Каждое material action повторно разрешает stable UUID и locked record including soft-deleted where appropriate, меняет content version, сбрасывает incompatible feature/publication state и атомарно пишет `AdminAuditRecorder` fingerprint в той же transaction; exact retry является no-op, а invalidation discovery/cache/sitemap выполняется after commit. Raw internal note пользователю не показывается.

Feature разрешён только approved public editorial collection. Обычный user не может назначить editorial/system type, moderation state или feature. Collection reports используют stable reason/status values, sanitized details, per-user rate limit и deduplication key; reporter identity/moderation notes не публикуются. Admin action закрывает максимум 100 open reports за запрос и явно предлагает следующий пакет, сохраняя decision+audit атомарно. Permanent target deletion сохраняет report UUID/version evidence с nullable relation и privacy-retires generic comments.

Editorial editor в `/my/collections/{uuid}/edit` доступен только `manage-catalog`, хранит `ru/en` DB title/description/SEO rows и не копирует user-created text в translation catalog. Admin workflow не заменяет importer admin, title moderation или generic comment moderation.

## Модерация обсуждений

`/admin/comments` — единственная comment moderation queue и защищена stable permission `moderation.comments`; прежний `manage-comments` остаётся compatibility alias той же server-side boundary. `CommentAdministrationManager` хранит только allowlisted filters/form values и selected stable ID; `CommentModerationQuery` пагинирует deterministic queue, eager-loads author, не более пяти oldest open-report previews, grouped exact report/reply counts и active restrictions. Filter status/target — enums, user search очищает wildcard/control input и использует bound `LIKE`. Ошибка чтения queue/context журналируется, скрывает зависящие от состояния формы и показывает локализованную fail-closed панель вместо framework error.

Модератор может перевести comment в `published|pending|hidden|rejected|spam|removed`, выбрать stable reason, добавить private plain-text note, одновременно resolve open reports, отдельно resolve/dismiss report и применить/revoke temporary/permanent comment-only restriction. Выбранная запись показывает bounded thread context: root, первые 20 chronological replies и сам выбранный reply, даже если он находится вне окна; вся большая ветка в память не загружается. Removed создаёт soft-delete tombstone; возврат из moderator removal восстанавливает row, но не отменяет author deletion. Privacy-retired tombstone является terminal `removed` evidence и не переоткрывается обычной модерацией. Каждый action повторно gate/policy-check-ит actor, lock-ит row, идемпотентно обрабатывает retry и атомарно пишет `AdminAuditRecorder` fingerprint в той же транзакции без body/private note value; массовое закрытие ограничено 100 oldest report rows за request и пишет отдельный fingerprint каждой, а остаток остаётся actionable. Affected target и author notification меняются только после commit и только при реальном status/delete visibility transition, не при правке одной приватной заметки.

Queue показывает spoiler и сохранённый deleted-review title/body только внутри moderator-only page, reporter identity публично не выводится. Hidden/deleted/inaccessible target не открывается обычному посетителю; direct moderator link ведёт в private selected queue context. Bulk moderation и edit history не добавлены: текущий product не требует их, а одиночные explicit confirmation actions сохраняют понятный audit/partial-failure contract.

## Модерация отзывов

`/admin/reviews` — единственная review moderation queue и защищена stable permission `moderation.reviews` на route, component и action levels; прежний `manage-reviews` остаётся compatibility alias. `ReviewModerationManager` хранит allowlisted filters, form scalars and stable selected IDs; `CatalogTitleReviewQuery::forModeration()` пагинирует pending/unresolved-report priority, eager-loads author/target/all report statuses, grouped unresolved count and one active restriction per page author. `open` и `reviewed` остаются unresolved до явного `resolved|dismissed`. Filters support status, exact review ID, sanitized author, title ID/slug and canonical 1–10 rating.

Moderator may publish/pending/hide/reject/spam/remove or re-publish a moderation-hidden row, set stable reason, correct whole-review spoiler flag, store private plain-text note, resolve/dismiss one unresolved report with its own independent private note, and apply/revoke temporary/permanent review-only restrictions. `remove` atomically сохраняет stable moderator deletion reason, первоначального actor и timestamp; повторный submit не переписывает evidence, а переход из `removed` восстанавливает только moderator tombstone. Author deletion и merge evidence не восстанавливаются и не заменяются модератором; target/author/identity/rating are not editable there. Every mutation locks/reloads the row, is idempotent, writes the decision and exact changed-field audit atomically, updates public caches/notifications only for a real presentation/status/deletion transition and keeps body/note/reporter identity out of the audit payload.

Restrictions evaluate `expires_at` during permission checks, so expiration needs no cron. Private notes, reporter and exact watch evidence never appear in public/profile payload or author notification. Bulk destructive actions and hard-delete controls are intentionally absent; imported and user rows retain stable IDs/evidence. Title merge remains a domain service, not an admin button that bypasses canonical catalog merge.

## Управление глобальными тегами

`/admin/tags` защищён stable permission `content.manage` на route и повторно в каждом Livewire hydration/service policy; прежний `manage-catalog` остаётся compatibility alias. `TagAdministrationManager` хранит только allowlisted form scalars, locked UUID/IDs и version fingerprints; `TagAdministrationQuery` строит deterministic bounded list, translations/alias/provider counts и merge preview. Private `user_tags` не входят в global admin directory.

Администратор создаёт только allowlisted `system|editorial` global rows, задаёт optional immutable language-independent code, safe canonical label/slug, visibility/moderation/source, редактирует выбранную `ru|en` translation label/plain descriptions/SEO, locale-aware approved/pending aliases, bounded directional/bidirectional synonyms, global title assignments и provider mapping decision. Locale selectors построены из configured supported locales и не принимают произвольный client value; перевод сохраняется с тем же optimistic `content_version`, что и base edit. `hidden_internal` всегда internal; `imported` создаётся только synchronizer/mapping path. Current code нельзя изменить обычным edit, translated labels никогда не становятся enum/code.

Alias проверяется против canonical names/current/history/other alias identity всех locale и не создаёт отдельную page; один normalized alias не может указывать на разные canonical targets, а legacy ambiguity resolver отклоняет без случайного выбора. Deletion сохраняет approved slug как history. Synonym не допускает self-pair/duplicate и не превращается в merge. Provider approval/rejection сохраняет source identity; rejection marks current observations stale и снимает только assignments без другого source. Editorial corrections, explicit assignments и archive/rejected decisions import не перезаписывает.

Merge требует source/target preview с counts/translations/aliases/provider impact и явное confirmation. Transaction reconciles pivots/provenance/translations/aliases/slugs/provider mappings/synonyms, оставляет merged source/legacy redirects, invalidates recommendations/search/cache/sitemap и пишет `TagMergeEvent` плюс `AdminAuditRecorder`. Repeat source→same target idempotent; personal tags и разные owners никогда не затрагиваются.

Permanent global delete отсутствует: active tag archive-ится или merge-ится. Archive сохраняет pre-visibility/pre-moderation и прекращает public discovery/new assignment; restore возвращает recorded state и не публикует internal/rejected row. Все validation/result/loading/error labels локализованы; raw SQL, source credentials, moderation internals и private personal labels не выводятся.

## Модерация профилей пользователей

`/admin/profiles` требует отдельный stable permission `moderation.profiles` на route, Livewire hydration и service action; общий catalog-management permission больше не является profile moderation bypass. Deterministic bounded queue показывает safe target identity/category/status/date без reporter email, account credentials, raw media path или unrelated private activity. Moderator может задать stable profile moderation status, скрыть biography, удалить avatar/cover и resolve/dismiss report с private note; он не может редактировать password/email/role/premium или восстанавливать deleted account data. Report details и private notes проходят Unicode plain-text/control/bidi boundary, остаются bounded и никогда не рендерятся как raw HTML. Каждая public-presentation transition увеличивает profile version, поэтому policy/SEO/sitemap reads меняются сразу.

## Модерация заявок на материалы

`/admin/requests` требует canonical admin middleware group и stable permission `moderation.requests` на route, Livewire hydration и каждой action; legacy `manage-content-requests` остаётся compatibility alias, response — `private, no-store`/`noindex`. Queue имеет deterministic pagination, search/type/status/sort, public card counts, private note, rejection reason, stable priority, clarification question, canonical merge UUID, verified completion title/season/episode/media IDs, importer handoff и связанный real import-run ID. PHP готовит per-request capability flags из canonical status rules, поэтому несовместимые clarification/completion/merge/handoff controls не рендерятся. Normal user не получает controls или underlying private fields.

Transition matrix запрещает произвольный jump, а generic action дополнительно не принимает clarification/duplicate/merge/withdraw status без dedicated invariant. Rejection требует stable public-safe reason; optional public explanation и private note разделены. Partial/full completion требует published result нужного canonical target. Merge требует exact semantic compatibility, переносит votes/follows/evidence/external IDs идемпотентно, сохраняет source history/redirect и никогда не объединяет private records разных requester; restricted clarification переносится только без смены requester visibility. Bulk moderation намеренно не добавлена: без подтверждённого большого объёма per-item preview/authorization безопаснее текущих bounded single-item actions.

## Очередь технической поддержки

`/admin/issues` требует stable permission `support.tickets` на route, hydration и каждой action; legacy `manage-technical-issues` остаётся compatibility alias. Queue имеет deterministic pagination, search/status/type/severity/priority/team/target/assignment/source-health filters, grouped counts и safe affected-user/attachment/message indicators без list diagnostic payload. Staff detail поддерживает classification, validated administrator assignment, clarification/public reply/internal note, transition, resolution, requester verification review, reopen, reject/reroute, redact, exact merge и linked source under-review/disable/restore.

Bulk ограничен 10 explicit selected tickets и только priority/assignment; каждый ticket повторно authorizes, partial failures показываются. Merge/resolve/reject/source action не bulk. Private note, requester evidence, provider/source URL и unrelated account data не выходят normal users. Полный support contract: [`technical-issues.md`](technical-issues.md).

## Редактор календаря релизов

`/admin/calendar` требует stable permission `calendar.manage` на route, Livewire hydration и save; legacy `manage-release-calendar` остаётся compatibility alias. Редактор ищет каноническую цель, задаёт stable type/precision/status/source, IANA timezone, partial/exact date, public/notification flags и manual lock; target ancestry и status transition валидируются server-side. История показывает public reason отдельно от private note. Обычный importer не перезаписывает locked event. Bulk editor и iCalendar token controls не добавлены без подтверждённой product boundary. Полный contract: [`release-calendar.md`](release-calendar.md).

## Администрирование Premium

`/admin/premium` повторно использует private admin shell и stable permission `premium.view`; прежний `view-premium-administration` и отдельные Premium allowlists сохранены как узкие compatibility adapters. Action permissions `premium.grant`, `premium.promotions`, `billing.view` и `billing.reconcile` не следуют из общего admin access; legacy exact allowlists требуют соответственно `PREMIUM_GRANT_ADMIN_EMAILS`, `PREMIUM_PROMOTION_ADMIN_EMAILS`, `PREMIUM_BILLING_AUDIT_EMAILS` и `PREMIUM_RECONCILIATION_ADMIN_EMAILS`. Пустой список запрещает capability и скрывает control. Уполномоченный staff может найти account по public ID/email, выдать duration/lifetime `premium_access` с stable reason/private note, отозвать ровно одну administrative/promotion запись, создать campaign и одноразово получить generated coupon. Payment entitlements не отзываются manual action.

Safe audit показывает stable action/resource/time; provider summary раскрывает только registered code. Environment, secrets и raw payload отсутствуют. Cancel/refund/replay/reconciliation controls не имитируются до появления реального adapter/policy. Полный least-privilege и rollout contract — [`premium.md`](premium.md).

## Редактура центра помощи

`/admin/help` требует canonical private admin middleware и stable permission `help.manage` на route, hydration и каждом action; legacy `manage-help-center` остаётся compatibility alias. Редактор управляет stable article/category, обеими translations, aliases, SEO/callout, related/context/escalation/order/featured, preview, review/publication/archive, freshness, revision restore, merge/replacement и queue outdated reports. Published content сначала выводится из publication; restore возвращает draft. Articles, feedback и reports пагинированы, revision timeline ограничен.

Editor не задаёт произвольный route/class/HTML/CSS/media URL/status/type/locale/escalation. Встроенная link validation и broken filter не выполняют внешний crawl. Private notes/reporter/actor/revisions/internal articles отсутствуют в public payload. Полный workflow, permission matrix, ownership/review и rollback: [`help-center.md`](help-center.md).

## Управление playback sources Task 07

Existing catalog administration остаётся единственной write surface для source status, health, priority, format, quality, translation/variant/type, subtitle flag, audience и availability window. Каждая mutation авторизуется и сохраняет importer/editorial ownership; provider credentials/raw response не выводятся в списках. Technical issue source action проходит отдельную Task 20 staff boundary и не отключает source после одного client failure.

Отдельные audio tracks, subtitle bodies/languages, Premium source feature, region-country и age-profile rules не добавлены, поскольку текущие schema/importer не могут управлять ими правдиво. При появлении таких данных сначала расширяются canonical source/import/admin contracts, затем player DTO. Подробности: [`audits/video-playback-report.md`](audits/video-playback-report.md).

## Administration boundary личной библиотеки Task 09

Bookmark, status, feedback/blacklist, exact progress, markers и acknowledgments являются owner-private состоянием и не получают общего staff editor/list. Existing catalog admin управляет только title/episode/media publication и canonical release events; collection moderation сохраняет существующие отдельные policy/gates. Изменение контента отражается в personal update query через visibility и release identity, а не через ручное редактирование пользовательских строк.

Merge tooling обязано вызывать existing `CatalogTitleUserDataMerger` до удаления duplicate identity. Администратор не может через UI подменить owner ID, открыть private marker или сбросить progress; account export/delete выполняются владельцем через existing privacy workflow.
