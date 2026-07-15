# Управление каталогом

Обновлено: 15.07.2026

## Доступ и границы

- Full-page Livewire 4 компонент `App\Livewire\CatalogAdministrationManager` доступен по `/admin/catalog` только authenticated user из `SEASONVAR_IMPORT_ADMIN_EMAILS`.
- Route middleware проверяет gate `manage-catalog`, компонент повторяет gate на `mount()` и `render()`, а каждую запись сервис авторизует через `CatalogTitlePolicy`. Browser не передаёт user ID, source ID или родительские IDs для записи.
- `/admin/imports` остаётся отдельным существующим экраном запусков импортёра. Из каталога на него ведёт служебная ссылка; новый importer workflow не создавался.
- Write actions проходят gate, policy, server-side validation и optimistic version checks без локального request budget.

## Возможности

- Сериал: редакционное и оригинальное название, slug, внешний ID в пределах существующего source, год, описание, постер, `publication_status`, audience и UTC-окно доступности.
- Связи: актёры, режиссёры, жанры, страны и существующая модель `Translation` для языка/перевода. Варианты ищутся на сервере, максимум по 20; полные справочники не сериализуются в Livewire snapshot.
- Иерархия: обычные и специальные сезоны/серии, детерминированный `sort_order`, publication status, audience и UTC-окна. Все IDs повторно ограничиваются выбранным тайтлом и сезоном.
- Видео: создание только из HTTPS URL на host из `PLAYBACK_ALLOWED_HOSTS`, безопасные allowlist значения формата/качества и публикация. URL существующего источника не возвращается в форму и не редактируется из browser.
- «Скрыть» переводит запись в reversible `hidden`/`draft`; строки progress, history, watchlist и rating не удаляются каскадно. Каждый такой action имеет `wire:confirm` и повторную server-side авторизацию.

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

Администратор создаёт только allowlisted `system|editorial` global rows, задаёт optional immutable language-independent code, safe canonical label/slug, visibility/moderation/source, редактирует `ru/en` label/plain descriptions/SEO, approved/pending aliases, bounded directional/bidirectional synonyms, global title assignments и provider mapping decision. `hidden_internal` всегда internal; `imported` создаётся только synchronizer/mapping path. Current code нельзя изменить обычным edit, translated labels никогда не становятся enum/code.

Alias проверяется против canonical names/current/history/other alias identity и не создаёт отдельную page; deletion сохраняет approved slug как history. Synonym не допускает self-pair/duplicate и не превращается в merge. Provider approval/rejection сохраняет source identity; rejection marks current observations stale и снимает только assignments без другого source. Editorial corrections, explicit assignments и archive/rejected decisions import не перезаписывает.

Merge требует source/target preview с counts/translations/aliases/provider impact и явное confirmation. Transaction reconciles pivots/provenance/translations/aliases/slugs/provider mappings/synonyms, оставляет merged source/legacy redirects, invalidates recommendations/search/cache/sitemap и пишет `TagMergeEvent` плюс `AdminAuditRecorder`. Repeat source→same target idempotent; personal tags и разные owners никогда не затрагиваются.

Permanent global delete отсутствует: active tag archive-ится или merge-ится. Archive сохраняет pre-visibility/pre-moderation и прекращает public discovery/new assignment; restore возвращает recorded state и не публикует internal/rejected row. Все validation/result/loading/error labels локализованы; raw SQL, source credentials, moderation internals и private personal labels не выводятся.
