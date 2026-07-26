# Card Rating Eager-Load SQLite Index Design

Дата: 26.07.2026
Статус: approved

## Контекст и подтверждённая причина

После Task 73 и Task 74 текущий `CatalogHomePageBuilder::webData()` выполняет
34 SQL-запроса. Девять отдельных fresh-process samples сохранили одинаковые
section counts `12/0/8/0`, 12 release groups и 8 recommendations; медиана
builder составила `146,85 ms`, медиана SQL — `39,24 ms`.

Два card rating eager-load запроса используют канонический
`CatalogTaxonomyRegistry::cardSummaryLoads()`:

- один для объединённых homepage sections;
- один для независимого recommendation title loader.

Запрос ограничен небольшим `catalog_title_id IN (...)`, двумя provider и
диапазоном `rating BETWEEN 0 AND 10`. SQLite выбирает покрывающий индекс
`catalog_ratings_provider_score_votes_title_idx
(provider,rating,votes,catalog_title_id)`, поэтому сначала проходит широкий
provider/rating диапазон и только затем фильтрует bounded title IDs. Для
20 текущих homepage IDs `EXPLAIN QUERY PLAN` подтверждает этот выбор.

Read-only парный probe из 21 чередующегося sample дал:

- текущий planner path: median `4,552 ms`, range `4,226–13,691 ms`;
- существующий unique index
  `catalog_title_ratings_catalog_title_id_provider_unique`: median
  `0,124 ms`, range `0,079–0,276 ms`;
- обе формы вернули 32 строки и один одинаковый SHA-256
  `ddcdadf0cc2b560985f604fcb39acbc07037fb2904b7e15607b946e5f7a46eb2`.

Это isolated local diagnostic evidence текущей SQLite, а не p95/SLA.

## Цель

Сохранить единый card relation contract и exact rating semantics, но на
SQLite обязать bounded eager-load использовать уже существующий
title/provider unique index. Не добавлять schema, cache, route, API,
translation, dependency или production-data изменение.

## Рассмотренные варианты

### 1. Новый covering index `(catalog_title_id, provider, rating)`

Отклонено. Существующий unique `(catalog_title_id, provider)` уже даёт
измеренный быстрый lookup и exact результат. Новый почти дублирующий индекс
увеличил бы SQLite database size и write amplification активного importer,
потребовал migration/backup/writer-pause/rollback и всё равно не гарантировал
выбор planner без нового evidence.

### 2. Удалить `rating BETWEEN 0 AND 10`

Отклонено. Это может побудить SQLite выбрать title/provider index, но меняет
канонический relation payload и допускает invalid historical rating rows.
Фильтрация после hydration также расширила бы read и создала второй
presentation-level correctness boundary.

### 3. SQLite-only `INDEXED BY` для существующего unique index

Выбрано. `CatalogTaxonomyRegistry` остаётся единственным владельцем card
relations. Только ratings eager-load получает небольшой private helper:

1. проверяет driver текущего Eloquent query;
2. для SQLite формирует table/index identifiers через database grammar;
3. меняет `FROM` на
   `catalog_title_ratings INDEXED BY
   catalog_title_ratings_catalog_title_id_provider_unique`;
4. для остальных drivers возвращает query без изменения;
5. затем применяет прежние projection, provider и rating predicates.

Проект уже использует тот же точечный SQLite `INDEXED BY` pattern для
измеренных homepage/discovery paths. Новый общий abstraction или raw
application input не добавляется.

## Архитектура и поток данных

`CatalogHomePageBuilder` и `CatalogRecommendationTitleLoader` продолжают
запрашивать `CatalogTaxonomyRegistry::cardSummaryLoads()`. Registry возвращает
тот же relation key `ratings` и closure. Closure:

- получает Eloquent builder relation;
- применяет SQLite-specific index preference;
- выбирает только `catalog_title_id`, `provider`, `rating`;
- оставляет только `kinopoisk|imdb`;
- оставляет только rating `0..10`.

Eloquent по-прежнему сопоставляет rows с parent title через
`catalog_title_id`. Blade, Resources и recommendation presenter получают
тот же relation shape и не знают об index hint.

## Compatibility contracts

- named/localized homepage, catalog, top-list, discovery и title routes;
- `CatalogHomePageBuilder::webData()`/`data()` keys, limits и ordering;
- `/api/v1/home` и card Resource shape;
- `x-catalog.title-card` provider preference: КиноПоиск с fallback IMDb;
- exact rating provider/range predicates and selected columns;
- `CatalogTaxonomyRegistry` remains the single card-load owner;
- all non-SQLite SQL remains unchanged;
- no query from Blade and no Eloquent graph cache;
- homepage snapshot/full-response cache key, version, TTL, stale and
  invalidation remain unchanged;
- publication, availability, audience, authentication, Premium, region,
  legal and authorization boundaries remain unchanged;
- importer, queue, scheduler, search, SEO, sitemap and administration remain
  unchanged.

## Риски

- `INDEXED BY` является SQLite-specific обязательным planner contract. Rename
  или removal exact index должен intentionally break the regression instead
  of silently returning to the slow plan.
- Registry is shared by homepage, catalogue, recommendations and other card
  consumers. This is intentional because all use the same bounded
  title/provider relation shape; focused and broad tests must cover shared
  consumers.
- The existing unique index is not covering for `rating`, so SQLite performs
  row lookup after the title/provider probe. Measured cost remains much lower
  than the current broad covering scan; no speculative covering index is
  justified.
- Timing varies under concurrent SQLite workload. PHPUnit asserts compiled
  plan and semantics, never milliseconds.

## Failure и rollback

The change is read-only. Cache or database failure follows the existing
authoritative path; no new fallback or catch is added.

Rollback is a normal revert of the PHP/test/docs commit followed by the
documented compiled-cache/PHP-FPM refresh. No database restore, migration
rollback, cache flush, generation bump, queue cleanup or asset rollback is
required.

## TDD и verification

1. RED feature contract calls `cardSummaryLoads()['ratings']` through the
   real homepage builder and asserts that SQLite SQL contains the exact
   `INDEXED BY` clause.
2. The same regression asserts one ratings eager-load for the consolidated
   homepage section group and unchanged relation rows/providers/range.
3. `EXPLAIN QUERY PLAN` must select the existing unique index and no longer
   select `catalog_ratings_provider_score_votes_title_idx` for the bounded
   eager-load shape.
4. Focused homepage/card/recommendation/catalog/API tests run first, followed
   by broad related filters.
5. A repeated fresh-process profile records query count, SQL/wall medians and
   unchanged section counts. A matched pair probe records exact row hash.
6. Pint, PHPStan, Rector, Vite, docs/diff checks and available full suite run
   in proportion to the shared relation boundary.
7. Managed Chromium checks `/`, `/ru` and mobile/desktop status, section
   counts, response cache, overflow and browser errors without cache flush or
   production mutation.
