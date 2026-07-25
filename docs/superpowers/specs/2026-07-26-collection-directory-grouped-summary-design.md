# Grouped Summary для каталога подборок — дизайн

Дата: 26.07.2026.

Статус: `approved_for_implementation`.

Пользователь поручил выполнить все рекомендованные улучшения `/discover`
без искусственного лимита. Этот change set продолжает уже утверждённые и
реализованные Task 51/56 и не меняет их информационную архитектуру.

## Проблема и evidence

`CatalogCollectionQuery::publicDirectory()` уже выполняет первую фазу
правильно: eligible IDs сортируются и пагинируются до summary hydration.
На production-size SQLite snapshot с 501 публичной подборкой и 3,29 млн
membership rows первая страница из 12 записей дала:

- полный directory read: `211,38 ms`;
- 12 SQL statements с cold schema probes;
- ID page query: `1,60 ms`;
- bounded summary query: `180,78 ms`.

Оставшаяся стоимость находится в двух `withCount()` scalar subqueries,
которые коррелированно считают total и guest-visible membership для каждой
из 12 строк. Эквивалентный read-only grouped aggregate по тем же ID и той же
guest visibility дал после прогрева `108,01–121,48 ms` и те же суммы
`35 375 / 35 375`.

Это локальные последовательные наблюдения одного snapshot, а не внешний SLA.

## Цель

Уменьшить стоимость второй фазы public directory без изменения route, URL
state, paginator metadata, сортировки, карточек, категорий, visibility,
локали, API, cache или persistent data.

## Не-цели

- не менять text-only presentation и не возвращать cover/poster fallback;
- не менять category/subcategory UX или classification workflow;
- не добавлять migration, index, table, cache key, queue, scheduler,
  dependency, environment variable или production DML;
- не менять `summaryQuery()` для detail/profile/search/title/admin consumers;
- не материализовать новый read model и не переносить access truth в cache;
- не менять recommendation ranking или title entitlement.

## Рассмотренные варианты

### 1. Оставить bounded correlated `withCount()`

Корректно и уже укладывается в текущий локальный ceiling, но сохраняет
измеренный dominant query. Отклонено как незакрытая оптимизация.

### 2. Материализовать collection counters

Дало бы дешёвое чтение, но потребовало бы schema, invalidation всех mutation
owners, reconciliation, stale/readiness и production rollout. Для bounded
12-row страницы это непропорциональная сложность и риск рассинхронизации.

### 3. Bounded grouped aggregate

Выбрано. Один aggregate ограничивается exact current-page IDs, один раз
соединяет membership с каноническим guest-visible title subquery, вычисляет
оба счётчика и присоединяется к summary rows. Query count не растёт, access
truth остаётся в `CatalogTitleQuery`, а empty membership получает нули через
`COALESCE`.

## Архитектура

Новый `CatalogCollectionSummaryLoader` получает существующий prepared
`Builder<CatalogCollection>` и список ID текущей страницы.

1. Нормализует IDs как unique positive integers и отклоняет внутренний
   overflow сверх текущего public-directory maximum `36`.
2. Создаёт canonical guest-visible subquery через
   `CatalogTitleQuery::visibleTo(null)->select('catalog_titles.id')`.
3. Строит grouped membership subquery только для exact page IDs:
   `COUNT(*)` для total и conditional `SUM` для visible.
4. Присоединяет aggregate к уже подготовленному collection summary query
   через Laravel 13 `leftJoinSub()`.
5. Выставляет `total_items_count` и `visible_items_count` через `COALESCE`,
   загружает прежние eager relations и возвращает Eloquent collection.
6. `CatalogCollectionQuery` восстанавливает exact phase-one order в памяти и
   оставляет исходный paginator object/metadata.

`summaryQuery(bool $withCounts = true)` сохраняет прежнее поведение для всех
остальных consumers. Только `publicDirectory()` вызывает его без прежних
correlated counts и передаёт builder новому loader.

## Correctness и failure behavior

- Empty ID page не вызывает summary loader.
- Collection без items получает оба счётчика `0`.
- Hidden/unpublished/unavailable title входит в total, но не в visible.
- Soft-deleted collection между фазами не гидратируется; paginator metadata
  сохраняет phase-one snapshot, как и текущая реализация.
- Schema-unavailable path остаётся fail-closed empty paginator.
- Source-managed missing rows, owner/category/translations/source eager loads
  и locale fallback не меняются.
- Никакой browser state или user ID не участвует в grouped query.

## Производительность и portability

- Обе стороны `WHERE IN` ограничены максимум 36 ID.
- Existing unique/indexed membership paths обслуживают
  `(catalog_collection_id, catalog_title_id)` lookup.
- `leftJoinSub`, `COUNT`, `SUM(CASE ...)`, `GROUP BY` и `COALESCE`
  поддерживаются Laravel query builder и SQLite; raw database-specific
  syntax, window/lateral functions и optimizer hints не используются.
- PHPUnit фиксирует SQL shape/query count и correctness, но не wall-clock.
- Read-only benchmark и `EXPLAIN QUERY PLAN` фиксируются отдельно после
  GREEN на неизменном snapshot.

## Совместимость и rollback

Сохраняются `/discover/popular`, localized routes, `collections_q`,
`collections_sort`, category keys, `collectionsPage`, exact order,
paginator totals, public/approved/deleted/source rules, RU/EN, text-only
cards, policies, API/SEO/sitemap, importer и cache invalidation.

Rollback — revert loader integration и вернуть `withCount()` в public
directory. Database, files, cache, workers и persistent rows откатывать не
нужно.

## Acceptance

- RED доказывает наличие correlated count в public directory.
- GREEN доказывает один grouped aggregate, exact page-ID bound и отсутствие
  correlated collection count.
- Total/visible counts совпадают с canonical fixtures, включая invisible
  title и empty collection.
- Search, sort, category, public/moderation/deleted rules, paginator metadata
  и page order остаются зелёными.
- Production-size read-only benchmark показывает улучшение median без
  ухудшения statement ceiling.
- Focused tests, Pint, static analysis, docs check и `/discover/popular`
  browser smoke проходят.
