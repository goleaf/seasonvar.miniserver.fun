# Дизайн коррелированных счётчиков справочников каталога

Дата: 26.07.2026.

Статус: одобрен повторным прямым указанием пользователя выполнить
рекомендованный вариант и начать реализацию.

## Контекст

`CatalogDirectoryQuery::paginate()` обслуживает одиннадцать web/API
справочников. Для taxonomy-справочников текущий `taxonomyQuery()` всегда
сначала материализует grouped count всех видимых связей, даже когда результат
сортируется по имени и paginator читает только 36–48 строк.

На текущем read-only SQLite snapshot `/actors` содержит 111 669 значений,
28 181 видимый связанный тайтл и показывает 48 строк. Двенадцать
чередующихся same-transaction samples дали прежнему result query медиану
`349,28 ms`. Прототип, который идёт по существующему
`actors_directory_name_idx`, проверяет видимую связь через коррелированный
`EXISTS` и считает связи только у выбранных строк, дал `0,78 ms`. Оба варианта
вернули один и тот же ordered payload и SHA-256
`e4eb4f9a6a9e250c76cdba0a873901440c4c0601dd522f924787c023f20cfc72`.

Это локальное диагностическое сравнение под параллельной SQLite/import
нагрузкой, а не p95/SLA. Остальные запросы summary/alphabet остаются
отдельными затратами и не объявляются исправленными этой итерацией.

## Варианты

### 1. Коррелированные `EXISTS` и count для bounded name-order page

Для `sort=name_asc` основной query начинает с taxonomy table и её существующего
name/ID order. Два коррелированных подзапроса переиспользуют один canonical
visible-title builder:

- `EXISTS` останавливается на первой видимой связи и исключает пустые
  taxonomy values;
- scalar `count(distinct title_id)` вычисляет прежний
  `published_titles_count` только для строк, дошедших до bounded страницы.

`count_desc` сохраняет прежний grouped aggregate, потому что глобальная
сортировка по count обязана знать счётчики всех кандидатов.

Плюсы: минимальный code-only change, точная семантика, существующие индексы,
portable Laravel Query Builder, отсутствие stale state.

Минусы: summary/alphabet cold queries остаются отдельным follow-up.

### 2. Материализованная сводка или persisted counter

Отдельная таблица/колонка могла бы ускорить все режимы, но потребовала бы
миграцию, initial backfill, importer/admin synchronization, reconciliation,
targeted invalidation и recovery от stale state.

Вариант отклонён: измеренный bottleneck устраняется существующей схемой, а
стоимость новой write architecture несоразмерна.

### 3. Кеширование полной выдачи справочника

Guest full-response cache уже ускоряет повторный HTML. Новый data cache скрыл
бы часть cold-path, но потребовал бы новых dimensions/invalidation и не решил
бы authenticated/API/filter variants или первый rebuild.

Вариант отклонён: он маскирует неэффективную SQL-форму и расширяет cache
boundary без необходимости.

## Выбранный дизайн

`CatalogDirectoryQuery::taxonomyQuery()` получает validated sort code.
При `name_asc` он строит correlation-safe relation query через pivot и
`joinSub(CatalogTitleQuery::visibleTo(null))`. Клон этого query используется
для `whereExists(...selectRaw('1'))` и `selectSub(...count(distinct ...))`.
Название таблицы, related/title keys и model table приходят только из
`CatalogTaxonomyRegistry`; пользовательские значения в SQL identifiers не
попадают.

При `count_desc` сохраняется текущий grouped `joinSub`. Общие constraints
имени/slug, canonical tag eligibility, active/fallback tag labels, search,
letter filtering, deterministic secondary ID и pagination не меняются.

## Публичные и data contracts

Не меняются:

- web routes `/genres`, `/countries`, `/actors`, `/directors`,
  `/age-ratings`, `/translations`, `/statuses`, `/networks`, `/studios`,
  `/tags` и localized variants;
- `GET /api/v1/catalog/directories/{directory}`, Resources, pagination,
  alphabet/summary metadata и OpenAPI;
- `name_asc|count_desc`, search/letter/page/per-page validation и
  deterministic order;
- `published_titles_count`, exact visible eligibility, publication window,
  audience, soft-delete, canonical tag readiness и locale fallback;
- cache keys, TTL, invalidation, SEO, sitemap, permissions, translations,
  importer/admin writes and warm-target estimates.

## Files

Ожидаемые изменения:

- `app/Services/Catalog/CatalogDirectoryQuery.php`;
- новый focused
  `tests/Feature/CatalogDirectoryQueryOptimizationTest.php`;
- этот design и связанный execution plan;
- exact Task 82 sections в `docs/performance.md`,
  `docs/catalog-search.md`, `docs/plans/current-task-plan.md`, `README.md`
  и `CHANGELOG.md`.

`CatalogDirectoryPageBuilder`, API controller, routes, models, migrations,
translations, config and assets должны остаться совместимыми и по плану не
редактируются.

## Риски и rollback

- Ошибка correlation может дать отсутствующие values или неверный count:
  закрывается mixed visible/draft/future/deleted fixtures и exact order/count
  assertions.
- `count_desc` может случайно перейти на bounded counts: закрывается отдельной
  regression, сохраняющей глобальный grouped aggregate и order.
- SQLite planner может выбрать широкий path: production-scale
  `EXPLAIN QUERY PLAN` должен показать taxonomy name index, pivot reverse
  index и title primary-key probe без materialized global count aggregate.
- Другие database grammars: используются поддержанные Laravel 13
  `joinSub`, `whereExists`, `whereColumn` и `selectSub`, без SQLite-only SQL
  в application path.
- Rollback — revert PHP/tests/docs. Schema/data restore, reindex, cache flush,
  queue restart и dependency rollback не нужны.
