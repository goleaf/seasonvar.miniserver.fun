# Homepage Latest Media Correlated Title Visibility Design

Дата: 26.07.2026
Статус: approved under explicit autonomous user authorization

## Контекст и подтверждённая причина

После Task 68/70/73/74/77 обычный `CatalogHomePageBuilder::webData()` на
fresh cache hit выполняет 33 SQL statements и сохраняет секции
`12/0/8/0`, 12 release groups и восемь recommendations. Отдельный
`CatalogHomeSnapshotCache::build()` выполняет 16 statements и нужен на
cache miss, refresh и warm.

Семь read-only cold-build samples дали медиану `842,075 ms` wall и
`544,56 ms` учтённого SQL. Самый дорогой стабильный statement выбирает
`latest_media_ids`:

```sql
SELECT id
FROM licensed_media
WHERE ...
  AND catalog_title_id IN (
      SELECT id
      FROM catalog_titles
      WHERE <public visibility>
  )
ORDER BY published_at DESC, id DESC
LIMIT 12
```

`EXPLAIN QUERY PLAN` подтверждает правильный
`licensed_media_home_feed_idx(status,published_at,id)`, но перед bounded
media result строится `LIST SUBQUERY` всех публично видимых тайтлов через
`catalog_titles_publication_lookup_idx`. На текущей базе существуют
880 661 published media rows и около 35 тысяч тайтлов; запросу нужны только
первые 12 подходящих media rows.

Парный read-only probe из 15 чередующихся samples сравнил текущую форму с
коррелированной проверкой тайтла:

- current `IN (visible titles)`: median `534,067 ms`, range
  `120,442–1 259,443 ms`;
- correlated `EXISTS`: median `0,365 ms`, range `0,265–8,174 ms`;
- обе формы каждый раз вернули 12 ID в одинаковом порядке с SHA-256
  `412fd422115fe129a5e25dea93d452af315d618a18088f0168b6388809ba8c64`.

Значения получены локально под конкурентной SQLite-нагрузкой и являются
диагностическим evidence, а не p95/SLA.

## Цель

Устранить глобальную материализацию всех видимых тайтлов при пересборке
`latest_media_ids`, сохранив точный порядок, набор media IDs, canonical
title/media/release visibility, snapshot shape, cache contract и API/HTML
поведение.

## Рассмотренные варианты

### 1. Новый media или title index

Отклонено. Planner уже выбирает существующий
`licensed_media_home_feed_idx`; title subquery использует существующий
publication index. Причина задержки — обязательная полная материализация
`LIST SUBQUERY`, а не ошибочный index selection. Новый индекс добавил бы
write amplification importer, migration/backup/rollback scope и не устранил
бы саму query shape.

### 2. Materialized latest-media read model или отдельный cache key

Отклонено. Homepage snapshot уже является bounded scalar cache owner с
single-flight/stale contract. Второй persistent aggregate потребовал бы
новые writes, invalidation, reconciliation и rolling-deployment rules ради
одного запроса, который исправляется без новой state boundary.

### 3. Correlated `EXISTS` по текущему media candidate

Выбрано. `CatalogHomeSnapshotCache` строит существующий
`CatalogTitleQuery::visibleTo(null)`, добавляет
`whereColumn('catalog_titles.id', 'licensed_media.catalog_title_id')`,
проецирует constant `1` и передаёт query в `whereExists()`.

SQLite продолжает читать media в готовом feed order, но для каждого
фактически рассмотренного кандидата делает integer-primary-key probe
конкретного тайтла. `LIST SUBQUERY` исчезает. Другие database drivers
получают portable Laravel Query Builder `EXISTS`, без database-specific
syntax или hint.

## Архитектура и поток данных

Поток остаётся прежним:

`CatalogHomeSnapshotCache::build() → ordered latest_media_ids → TieredCache
homepage content-index-v2 → CatalogHomePageBuilder::data() → API Resource`.

Меняется только title-visibility predicate внутри latest-media selector:

1. `LicensedMedia::published()` применяет media status, publication time,
   availability and audience.
2. `LicensedMedia::forAvailableReleases(null)` сохраняет canonical season
   and episode visibility checks.
3. Correlated `CatalogTitleQuery::visibleTo(null)` проверяет exact
   `licensed_media.catalog_title_id`.
4. Existing order `published_at DESC, id DESC` and `LIMIT 12` remain.
5. Snapshot stores the same ordered integer list under the same key and
   generation.

Новый service, scope, raw SQL, index hint, migration, cache family или
fallback не добавляется.

## Compatibility contracts

- `/`, `/ru`, named/localized homepage, Livewire, Blade and SEO;
- `/api/v1/home` latest media IDs/order and Resource shape;
- `CatalogHomeSnapshotCache` keys:
  `latest_title_ids`, `latest_title_updates`, `featured_title_ids`,
  `video_title_ids`, `latest_media_ids`, `year_buckets`, `subtitle_tag`;
- cache resource `content-index-v2`, dimensions, version, TTL, stale,
  single-flight, warming and targeted invalidation;
- media order `published_at DESC, id DESC`, limit 12 and existing feed index;
- `CatalogTitleQuery::visibleTo(null)` as the canonical public title
  visibility owner;
- `LicensedMedia::published()` and `forAvailableReleases(null)` as canonical
  media/release boundaries;
- title/media publication, availability, audience, Premium, region, legal,
  privacy and authorization behavior;
- importer, administration, search, recommendations, sitemap, queues and
  account lifecycle;
- SQLite plus all other supported Laravel database grammars.

## Риски и защита

- Ошибка correlation column могла бы превратить `EXISTS` в global boolean.
  Реальный snapshot regression должен проверять generated SQL, exact ordered
  IDs и `EXPLAIN QUERY PLAN`.
- Query shape shared only by snapshot rebuild, но snapshot feeds both HTML
  and API. Focused homepage/API tests therefore remain required.
- Timing varies with importer and other SQLite readers. Automated tests
  assert semantics and plan shape, never milliseconds.
- `visibleTo(null)` must remain the only title visibility predicate. No
  copied publication/audience literals are allowed in the snapshot class.

## Failure, production impact и rollback

Изменение read-only и code-only. Database/cache failure uses the existing
TieredCache stale/fallback path; no catch or fail-open behavior is added.

No migration, index, DML, cache flush, key bump, route, translation,
dependency, environment, queue, asset or service restart is required beyond
the normal documented PHP deployment/compiled-cache refresh.

Rollback is a normal revert of the code/tests/docs commit. Database restore,
reindex, cache deletion, generation bump and queue cleanup are not required.
An old compatible snapshot remains readable because its scalar schema and
meaning do not change.

## TDD и verification

1. RED feature test builds a real snapshot with visible and hidden title/media
   fixtures, captures the exact latest-media query and asserts correlated
   `EXISTS` plus the absence of `catalog_title_id IN (SELECT id FROM
   catalog_titles ...)`.
2. The test asserts exact ordered `latest_media_ids`, publication/audience/
   release exclusions and `published_at,id` tie-break behavior.
3. SQLite `EXPLAIN QUERY PLAN` must contain the existing home feed index and
   a primary-key title probe, with no title `LIST SUBQUERY`.
4. Minimal implementation changes only the latest-media title predicate.
5. Focused homepage/snapshot/content-addition/API/cache tests run first,
   followed by shared catalogue/recommendation regressions.
6. Repeated cold-build and paired query profiles record counts, hashes,
   query plan and diagnostic timing without cache flush or production DML.
7. Pint, syntax, PHPStan, Rector, broad tests, Vite/docs/diff gates and
   managed Chromium desktop/mobile `/`, `/ru`, `/api/v1/home` run in
   proportion to the visitor-facing path.
