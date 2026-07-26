# План коррелированных счётчиков справочников каталога

Дата: 26.07.2026.

Design:
[`2026-07-26-directory-name-order-correlated-counts-design.md`](../specs/2026-07-26-directory-name-order-correlated-counts-design.md).

## Цель

Устранить глобальную materialization taxonomy counts из bounded
`name_asc` directory page, не меняя result set, count, порядок, routes,
API, cache или schema.

## Шаги

1. Зафиксировать shared-tree ownership и runtime:
   - подтвердить existing `main`;
   - сохранить foreign staged/unstaged scope нетронутым;
   - записать PHP/Laravel/Boost/Livewire/PHPUnit/SQLite versions;
   - подтвердить активную SQLite/import нагрузку как ограничение benchmark.

2. Проверить canonical contracts:
   - `CatalogDirectoryRegistry`, `CatalogDirectoryQuery`,
     `CatalogDirectoryPageBuilder`, API controller/Resources;
   - `CatalogTitleQuery::visibleTo(null)` и entitlement constraints;
   - pivot reverse indexes и taxonomy name/ID indexes;
   - web/API tests, cache/SEO/warming consumers;
   - `catalog-search.md`, `api.md`, performance/cache/security/ops owners.

3. Сохранить baseline:
   - census actors/directors/tags и pivot rows;
   - query count и slow-query split прямого page builder;
   - same-transaction old/prototype ordered ID/name/count hashes;
   - production-scale `EXPLAIN QUERY PLAN`.

4. TDD RED:
   - создать отдельный `CatalogDirectoryQueryOptimizationTest`;
   - подготовить visible, draft, future, expired/deleted и empty-link
     fixtures;
   - проверить exact `name_asc` order and counts;
   - проверить, что result SQL не содержит
     `directory_value_counts`/global `GROUP BY`;
   - проверить correlation `EXISTS` и scalar count;
   - проверить, что `count_desc` по-прежнему сортирует по полному grouped
     aggregate;
   - запустить только новый test и получить expected structural failure.

5. Minimal implementation:
   - передать validated `$sort` в `taxonomyQuery`;
   - выделить private visible pivot correlation builder;
   - для `name_asc` применить cloned `whereExists` и `selectSub`;
   - для `count_desc` оставить существующий grouped aggregate;
   - не менять summary/letters/years, request/controller/presenter contracts.

6. GREEN и форматирование:
   - новый focused test;
   - `CatalogPageTest`;
   - `Api/V1/CatalogDiscoveryTest`;
   - directory/cache/SEO/warming related tests;
   - `./vendor/bin/pint --dirty --format agent`;
   - PHP syntax и task-scoped Larastan/Rector.

7. Production-scale parity:
   - frozen-time read transaction;
   - alternating old-equivalent/new samples для actors/directors/tags;
   - exact ordered hashes/counts;
   - `EXPLAIN QUERY PLAN` по фактическому compiled SQL;
   - direct page-builder query count/wall/SQL split;
   - результаты обозначить local diagnostics, не SLA.

8. Cross-feature regression:
   - web and API directory routes;
   - catalog search/filter route composition;
   - sitemap/cache warm estimate;
   - no route/config/translation/cache/schema diff;
   - full suite только если shared processes позволяют, foreign failures
     отделять по exact rerun.

9. Documentation:
   - обновить `docs/catalog-search.md` и `docs/performance.md`;
   - проверить `README.md`, добавить visitor history при verified product
     result;
   - добавить отдельный русский пункт `CHANGELOG.md`;
   - актуализировать Task 82 matrix/evidence.

10. Финализация:
    - перечитать canonical requirements и этот план;
    - найти legacy duplicate/unfinished/debug/secret paths текущего scope;
    - проверить exact task diff и branch/status;
    - commit только exact Task 82 scope в `main` через hooks;
    - push configured remote только после разрешённого commit и clean-tree
      gate; shared/external blocker отметить `unresolved`.
