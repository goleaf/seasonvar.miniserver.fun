# Быстрый snapshot десятилетий справочника годов: дизайн

Дата: 26.07.2026.

Статус: approved — пользователь прямо поручил самостоятельно выбирать
рекомендуемые измеренные улучшения, обновлять безлимитный план и продолжать
реализацию.

## Цель

Ускорить metadata-часть web-страницы `/years` и
`GET /api/v1/catalog/directories/years`, не меняя набор, порядок или
visibility-семантику десятилетий.

## Измеренный root cause

`CatalogDirectoryQuery::decades()` на production-scale SQLite выполняет
`GROUP BY` по вычисляемому выражению
`cast(year / 10 as integer) * 10`. При 33 002 опубликованных строках,
103 различных годах и существующем индексе
`catalog_titles_published_year_idx` SQLite всё равно строит временное
B-tree для вычисляемой группы.

Девять read-only запусков дали:

- текущий query: медиана `123,72 ms`, p90 `281,48 ms`;
- `SELECT DISTINCT year` с прежним public visibility scope и преобразованием
  годов через `intdiv()` в PHP: медиана `2,93 ms`, p90 `19,08 ms`;
- оба варианта вернули одинаковые 13 десятилетий от `2020` до `1900`.

EXPLAIN для текущей формы содержит `USE TEMP B-TREE FOR GROUP BY`, а
`SELECT DISTINCT year ... ORDER BY year DESC` использует существующий
`catalog_titles_published_year_idx` без нового индекса.

## Рассмотренные варианты

### 1. Только заменить вычисляемый `GROUP BY`

Холодный query становится примерно в 42 раза быстрее и сохраняет portability.
Недостаток: каждый web/API запрос справочника годов продолжает выполнять SQL,
хотя набор меняется только вместе с видимостью или годом тайтла.

### 2. Кэшировать прежний query

Повторные чтения становятся дешёвыми, но первый запрос после invalidation или
cache outage сохраняет измеренный `123+ ms` rebuild. Этот вариант маскирует,
но не устраняет неэффективную форму SQL.

### 3. Быстрый rebuild плюс compact snapshot — выбран

Холодный rebuild выбирает только уникальные годы по существующему индексу,
преобразует максимум bounded календарного диапазона в десятилетия и сохраняет
маленький scalar payload через существующий `CatalogFacetSnapshotCache`.
Повторный read использует тот же `CatalogFacets` version/TTL/stale/lock/
telemetry/failure contract, который уже обслуживает summary и alphabet
справочников.

### 4. Новый индекс, таблица или материализованный aggregate

Отклонено. Существующий индекс уже делает `DISTINCT year` быстрым, поэтому
DDL, backfill, write amplification и отдельная invalidation boundary не
оправданы измерениями.

## Выбранная архитектура

```text
CatalogDirectoryPageBuilder / API V1 controller
    → CatalogDirectoryQuery::decades()
        → CatalogFacetSnapshotCache::remember(
              resource: directory-decades-v1,
              dimensions: minimum_year + maximum_year
          )
            MISS / cache failure
              → CatalogTitleQuery::visibleTo(null)
              → SELECT DISTINCT year ORDER BY year DESC
              → PHP intdiv(year, 10) * 10
              → unique ordered scalar rows
            HIT
              → scalar rows без catalog SQL
```

`minimum_year` и фактический `maximum_year` входят в dimensions. Это не
позволяет прежнему snapshot пережить configuration change или календарный
rollover, когда default maximum равен следующему году.

## Семантика и совместимость

Не меняются:

- public visibility через `CatalogTitleQuery::visibleTo(null)`;
- диапазон `catalog.directories.minimum_year` — configured maximum либо
  следующий календарный год;
- descending integer list десятилетий без дублей;
- web routes, full-page Livewire, query parameters, filters, pagination,
  SEO/canonical и redirect validation;
- API V1 route, Resources, pagination и `meta.decades` shape;
- Homepage `year_buckets` и его отдельный resource;
- importer/admin/tag invalidation owners;
- authentication, authorization, Premium, payment, advertising, region/legal,
  privacy, search, recommendations, sitemap и personal state.

Новые route, translation, UI, JavaScript, CSS, dependency, environment value,
queue, migration, table, index или production data write не добавляются.

## Cache и failure contract

Resource принадлежит существующему `CacheDomain::CatalogFacets`.
`CatalogCacheInvalidator` уже повышает этот version после изменения каталога,
а `TieredCache` предоставляет текущие TTL, stale copy, lock, telemetry и
authoritative rebuild при cache failure. Store-wide flush не используется.

Payload содержит только строки вида `['decade' => 2020]`; модели, source URL,
private state и произвольные serialized objects в cache не попадают.

## TDD и verification

RED должен доказать:

1. cold rebuild выполняет `SELECT DISTINCT year`, не содержит вычисляемого
   `GROUP BY decade` и возвращает прежний точный ordered set;
2. повторное чтение остаётся на прежнем snapshot после добавления нового
   видимого десятилетия;
3. bump `CatalogFacets` открывает новую generation и перестраивает результат.

GREEN и регрессии должны включать:

- draft/future/expired/deleted/authenticated-only и дубли одного десятилетия;
- web `/years` и API V1 exact meta shape;
- cache state/failure tests;
- production payload parity, query count, wall/SQL profile и EXPLAIN;
- Pint, focused/related tests, PHPStan, scoped Rector и docs checks.

## Rollout и rollback

Изменение code-only и не требует migration, backfill, backup restore,
maintenance window или нового production service. Deployment использует
обычный verified in-place contract проекта и существующий asset/runtime
checklist; frontend build не затронут.

Rollback — revert task-specific code/docs commit. Versioned cache entries
истекут естественно; глобальный flush не нужен. При cache outage authoritative
`DISTINCT year` query продолжает возвращать точные данные.
