# Дизайн facet snapshot для годов главной страницы

Дата: 26.07.2026.

Статус: одобрено прямым разрешением пользователя выполнить рекомендуемый
вариант.

## Проблема

`CatalogHomeSnapshotCache::build()` сохраняет компактные scalar-данные
главной страницы, но при каждом rebuild повторно группирует все видимые
`catalog_titles` по году ради 12 строк. Свежий read-only профиль текущего
`main` показал 16 SQL statements, `370,898 ms` wall и `194,54 ms` SQL.
Годовая агрегация была самым дорогим statement — `100,56 ms`; следующий
statement проверки доступности эпизодов занял `53,98 ms`.

Этот расчёт зависит от состава и видимости тайтлов, но не от новых эпизодов,
медиа или календарных событий. Между тем
`ReleaseCalendarCacheInvalidator::scheduleChanged()` повышает Homepage,
не повышая CatalogFacets. Поэтому homepage snapshot теряет готовые годы при
событии, которое их не меняет.

## Цель

Повторно использовать компактный публичный year-bucket snapshot через уже
существующий `CatalogFacetSnapshotCache`, чтобы Homepage-only rebuild не
выполнял повторную годовую агрегацию.

Успех означает:

- прежние 12 строк `year_buckets` с теми же `year` и `titles_count`;
- прежние guest visibility, временные окна, порядок и лимит;
- второй принудительный Homepage rebuild в той же версии CatalogFacets не
  выполняет SQL `GROUP BY year`;
- bump CatalogFacets делает прежний snapshot недоступным и пересчитывает
  новые значения;
- route, API Resource, HTML, locale, cache TTL Homepage и invalidation
  boundaries остаются совместимыми.

## Не цели

- не добавлять migration, index, таблицу или материализованное read model;
- не менять canonical visibility или правила публикации;
- не объединять year buckets с Task 85 directory summary/alphabet;
- не оптимизировать в том же изменении episode availability;
- не менять UI, переводы, маршруты, Vite assets, зависимости или environment.

## Рассмотренные подходы

### 1. Существующий CatalogFacets snapshot — выбран

`CatalogHomeSnapshotCache` оборачивает прежний годовой query через
`CatalogFacetSnapshotCache::remember()`. Resource получает отдельное имя и
dimensions `audience=public`, текущий календарный год и фиксированный limit.
Snapshot хранит только список scalar arrays.

Преимущества:

- использует существующие TieredCache, locks, stale fallback, telemetry и
  CatalogFacets generation;
- `CatalogCacheInvalidator::catalogChanged()` уже повышает Homepage и
  CatalogFacets вместе;
- Homepage-only календарная/media invalidation сохраняет правильный готовый
  year snapshot;
- cache outage безопасно вызывает прежний authoritative query.

Ограничение: первый build новой CatalogFacets generation остаётся таким же
дорогим. Это честный компромисс без нового write-path.

### 2. Принудительный существующий индекс — отклонён

Двенадцать чередующихся read-only samples сравнили обычный planner,
`catalog_titles_published_year_idx`,
`catalog_titles_public_year_updated_idx` и
`catalog_titles_year_indexed_idx`. Все вернули одинаковый SHA-256
`bdbafb0688941c845c9107e54d00194ae1e5ec0d61852f184d3696a049649d4f`.
Медианы составили соответственно `98,657`, `86,537`, `76,851` и
`76,632 ms`. Обычный plan уже использует
`catalog_titles_published_year_idx`; разница недостаточна для SQLite-only
hint как самостоятельного решения.

### 3. Новый covering index — отложен

Кандидат мог бы включить все publication/audience/window/delete поля и год,
но увеличил бы индекс и стоимость активного importer. Безопасная попытка
benchmark на временной копии показала размер production-like SQLite около
15 ГБ и была остановлена; временная копия удалена, исходная база не
изменялась. Непроверенная migration не добавляется.

### 4. Новая materialized table или очередь — отклонено

Она дала бы самый дешёвый read, но создала бы второй источник истины,
backfill, отдельный failure mode и новый invalidation contract ради 12
scalar rows. Текущий TieredCache уже решает эту задачу.

## Архитектура

`CatalogHomeSnapshotCache` получает `CatalogFacetSnapshotCache` через
constructor injection. Приватный `yearBuckets()`:

1. вызывает `CatalogFacetSnapshotCache::remember()` с отдельным resource;
2. внутри rebuild выполняет прежний
   `CatalogTitleQuery::visibleTo(null) → GROUP BY year → ORDER BY year DESC
   → LIMIT 12`;
3. нормализует Eloquent aggregate rows в
   `list<array{year:int,titles_count:int}>`;
4. возвращает scalar rows в прежний Homepage snapshot.

Новый service/query layer не создаётся. `CatalogFacetQuery::years()` не
вызывается напрямую, потому что ему нужен полный `CatalogTitlesCriteria`, а
Task 86 не должен расширять связность Homepage с catalog-list state.

## Cache и invalidation

Resource принадлежит `CacheDomain::CatalogFacets`, использует его текущие
fresh/stale/hot/lock значения и общую version registry.

- `CatalogCacheInvalidator::catalogChanged()` повышает обе версии, поэтому
  title year/publication/audience/window/delete change пересчитывается.
- Homepage-only release/calendar/collection invalidation не меняет годовые
  данные и получает cache hit.
- Переход календарного года меняет dimension и создаёт новый snapshot без
  scan/flush.
- Cache failure проходит существующий `TieredCache` fallback и выполняет
  authoritative query.
- Store-wide flush, wildcard scan и Eloquent graph cache не добавляются.

Homepage snapshot продолжает хранить копию `year_buckets`; его resource,
dimensions, version, TTL, stale, lock и payload schema не меняются.

## Совместимость и безопасность

Сохраняются:

- `/`, `/ru`, `/en`, full-page Livewire и SSR SEO;
- `/api/v1/home` и `CatalogHomeResource`;
- `CatalogTitleQuery::visibleTo(null)` и все server-side access predicates;
- exact `year_buckets` key, integer types, порядок и limit 12;
- SQLite и остальные Laravel-supported grammars;
- importer/admin/catalog invalidation, warming и rollback behavior;
- отсутствие private/user state в shared snapshot.

Изменение read-only. Оно не выполняет DML, не читает secrets, не вводит
provider calls и не изменяет authorization.

## Проверки

TDD RED:

- создать видимые и скрытые тайтлы разных годов;
- дважды вызвать `CatalogHomeSnapshotCache::refresh()`;
- доказать exact bucket values;
- ожидать только один `GROUP BY year` statement до bump CatalogFacets;
- подтвердить, что текущая реализация падает на повторном statement.

TDD GREEN:

- применить минимальный facet-snapshot wrapper;
- повторить тест;
- добавить новый видимый год, повысить CatalogFacets и подтвердить второй
  query и обновлённый payload.

Далее:

- homepage/cache/API regression matrix;
- Pint, PHP syntax, scoped PHPStan и Rector;
- fresh-process profile первого и повторного Homepage rebuild;
- полный доступный PHPUnit suite с честной фиксацией foreign failures;
- Vite build и managed Chromium desktop/mobile `/`, `/ru`, `/api/v1/home`;
- `project:docs-refresh --check`, task-scoped/global diff audit, поиск
  duplicate/legacy/debug/secret paths.

## Rollout и rollback

Deployment требует только обычный application-code rollout и штатный cache
warm. Migration, backup restore, reindex, queue restart и cache flush не
нужны. При cache outage прежний SQL остаётся fallback.

Rollback — revert PHP/tests/docs commit. Уже созданный versioned scalar cache
resource становится недостижимым после истечения либо смены code path и не
требует удаления.

## Ожидаемые изменяемые файлы

- `app/Services/Catalog/CatalogHomeSnapshotCache.php`;
- `tests/Feature/CatalogHomePerformanceTest.php`;
- `docs/superpowers/plans/2026-07-26-homepage-year-buckets-facet-snapshot.md`;
- `docs/plans/current-task-plan.md`;
- `docs/caching.md`;
- `docs/performance.md`;
- `README.md`;
- `CHANGELOG.md`.
