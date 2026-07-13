# Управление каталогом

Обновлено: 13.07.2026

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
