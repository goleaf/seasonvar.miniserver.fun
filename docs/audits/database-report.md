# Отчёт по базе данных

Проверено: 15.07.2026. Production использует SQLite; тесты — SQLite in-memory или изолированные временные файлы. Никакие destructive команды в ходе аудита не выполнялись.

## Подтверждённое состояние

- 60 migration-файлов; две миграции pending: API offline-sync/state versions и индексы пользовательской библиотеки.
- Live database превышает 14 GB. Крупнейшие классы данных по предыдущему завершённому `dbstat`: source snapshots, prepared import pages и licensed media; отдельная резервная копия занимает ещё несколько GB.
- `CatalogTitle` владеет seasons/episodes; external video хранится в `licensed_media`, а не в отдельных сезонных catalog titles.
- Schema содержит явные pivots, foreign keys, unique/check constraints и 18 индексов на `catalog_titles`, 20 на `licensed_media`; идентичных index-column sequences текущий read-only scan не нашёл.
- FTS5 documents покрывают source titles, но state version отстаёт от code version 3.
- Instrumented read-only deployment preflight завершился за 24.45 s; SQLite quick/FK path занял 23655 ms и прошёл. Это подтверждает конечную, но дорогую integrity-проверку; результат нельзя подменять более слабой выборкой ради скорости.

## Реестр выводов

| ID | Класс | Наблюдение | Изменение | Статус | Проверка / риск |
| --- | --- | --- | --- | --- | --- |
| DB-01 | Confirmed problem | Две additive migrations pending | Backup + остановка writers + `migrate --force` + integrity/index/API verification | Pending P0 | Нельзя применять при 11 overlapping runs |
| DB-02 | Confirmed problem | SQLite — single-writer при 12 queue workers и overlapping cycles | Устранить лишнюю работу/runs до изменения concurrency | Pending P0 | Увеличение workers запрещено без lock/write-latency measurement |
| DB-03 | Confirmed problem | Raw source snapshot hash меняется из-за volatile provider counters/timestamps | Semantic fingerprint отдельно от retained raw evidence | Pending P2 | Retention/recovery contract должен предшествовать prune |
| DB-04 | Confirmed problem | Prepared/finalizer state и failed jobs растут из-за terminal lifecycle | Idempotent terminal transitions и bounded cleanup only for proven terminal rows | Pending P1 | Нельзя удалять live claims/jobs |
| DB-05 | Confirmed problem | FTS state stale | Rebuild после migrations/import safe point, затем compare document/source counts | Pending P0 | Rebuild конкурирует за SQLite writer/CPU |
| DB-06 | Confirmed performance cost | Некоторые hot aggregations медленные; deployment quick/FK integrity path занимает 23655 ms на 14+ GB | Inspect SQL + `EXPLAIN QUERY PLAN` для application queries; integrity duration наблюдать отдельно и не «ускорять» guessed indexes | Pending P3 | Не добавлять guessed/redundant indexes и не ослаблять corruption detection |
| DB-07 | Intentional | No polymorphic metadata, no production seeders, external URLs only | Preserve | Accepted | Соответствует project constraints |
| DB-08 | Probable | 20 licensed-media indexes могут иметь prefix overlap, но identical-column duplicates не найдены | Compare actual hot plans and write cost before removal | Verify later | Removing an index can regress importer/read path |
| DB-09 | Proposed | Enable lazy-loading prevention outside production | Verify existing provider behavior and tests, then fail fast in local/testing | Planned | Must not break legitimate CLI serialization paths |

## Query and migration acceptance gates

- Every changed query gets a characterization/feature test and, for critical paths, query-count budget.
- Every proposed index records the target SQL, pre/post `EXPLAIN QUERY PLAN`, selectivity and write-cost rationale.
- Migrations run first on a complete temporary SQLite copy/schema and exercise `up()` plus practical `down()`.
- Production sequence is backup → stop writers → migrate → quick/FK check → required index check → FTS state/rebuild → smoke → resume workers.
- Bulk importer work uses `upsert`, grouped queries, `chunkById`/`lazyById`; no `Model::all()` exists in application code.
- No destructive schema/data cleanup is accepted without a verified backup and explicit row ownership/retention rule.

## Measurements still required

After lifecycle stabilization, capture stable medians for home, catalog, title, stats, sitemap, import page apply and recommendation rebuild. Active import contention currently makes isolated response-time comparisons noisy; this limitation is explicit rather than hidden.
