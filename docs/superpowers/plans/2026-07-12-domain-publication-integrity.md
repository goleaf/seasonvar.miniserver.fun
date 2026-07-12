# Domain Publication and Ordering Implementation Plan

## Цель

Укрепить существующую модель каталога без параллельных сущностей: отделить production-статусы из `catalog_statuses` от состояния публикации, добавить окна и аудиторию доступности, мягкое удаление и безопасную поддержку спецвыпусков.

## Границы текущего прохода

- Сохранить `Actor` и `Director` как существующие role-specific справочники; не вводить дублирующую таблицу `people`.
- Сохранить `Translation`, `licensed_media.translation_name` и `licensed_media.has_subtitles`; отдельные нормализованные audio/subtitle tracks остаются следующим этапом, потому что источник пока не отдаёт устойчивые идентификаторы дорожек.
- Сохранить `CatalogStatus` как production status и добавить отдельный `PublicationStatus` для `CatalogTitle`, `Season` и `Episode`.
- Сохранить `is_published` на `catalog_titles` как legacy-совместимый защитный флаг до отдельной миграции его удаления.
- Не менять внешние URL и provider identifiers: `(source_id, external_id)`, `(source_id, source_url_hash)`, `(catalog_title_id, provider)` и `(catalog_title_id, source_media_key)` уже защищены unique-ограничениями.

## Выполняемые шаги

1. Расширить существующие relationship/API/importer tests: публикационные состояния, окна доступности, аудитория, soft delete, deterministic ordering и спецвыпуски.
2. Добавить enum-объекты публикации, аудитории и вида выпуска, а общие publication scopes вынести в доменный trait.
3. Добавить nullable schema-поля отдельной миграцией.
4. Backfill существующих строк отдельной DML-миграцией.
5. Проверить отсутствие конфликтующих ключей, затем добавить `NOT NULL`, индексы и unique `(parent_id, kind, number)` отдельной constraint-миграцией.
6. Перевести importer upsert keys на `kind=regular`, не меняя публичную команду `seasonvar:import` и не создавая отдельные catalog titles для сезонов.
7. Ограничить публичные relationship loads и counts состоянием, окном, аудиторией и soft-delete scope; обеспечить сортировку `kind, sort_order, number, id`.
8. Обновить описание модели данных, deployment order и журнал обслуживания.
9. Запустить focused tests, Pint, полный PHP test suite, syntax checks, supported migration checks и frontend build при затронутых Blade/assets.

## Порядок развёртывания

1. Остановить или дождаться завершения текущего import batch, не очищая очередь.
2. Сделать резервную копию SQLite-файла.
3. Развернуть код и выполнить `php artisan migrate --force`; миграции запускаются по порядку add columns → backfill → constraints.
4. Запустить короткий `php artisan seasonvar:import --help` smoke check, затем обычный плановый импорт.
5. Проверить публичные `/titles`, `/api/titles`, sitemap/feed и запись import events.

Rollback constraint-миграции возможен только если special-записи не конфликтуют со старым ключом `(parent_id, number)`; миграция явно остановит rollback вместо удаления данных.
