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
| DB-01 | Confirmed problem | Две additive migrations pending | Backup + остановка writers + `migrate --force` + integrity/index/API verification | Pending P0 | Нельзя применять при legacy active runs до verified safe point |
| DB-02 | Confirmed problem | SQLite — single-writer при 12 queue workers и overlapping cycles | Устранить лишнюю работу/runs до изменения concurrency | Pending P0 | Увеличение workers запрещено без lock/write-latency measurement |
| DB-03 | Confirmed problem | Raw source snapshot hash меняется из-за volatile provider counters/timestamps | Semantic fingerprint отдельно от retained raw evidence | Pending P2 | Retention/recovery contract должен предшествовать prune |
| DB-04 | Confirmed problem, code fixed | Prepared/finalizer state и failed jobs росли из-за polling terminal lifecycle | Completion signals, unique-until-processing finalizers и bounded watchdog; cleanup только для доказанно terminal rows | Implemented; data reconciliation pending | Нельзя удалять live claims/jobs; existing 4155/793/9 failed-job classes требуют state-aware disposition |
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

## Task 10 collection schema audit

Pre-implementation scan подтвердил отсутствие collection/list/playlist/folder tables, duplicate item pivots и rows в production-style SQLite snapshot; watchlist является отдельным boolean state. Поэтому additive migration безопасно создаёт unique current/public IDs and unique collection/title immediately, без destructive reconciliation. User UUID backfill использует ordered `chunkById(500)` и model callback для будущих inserts.

Добавлены пять tables (`catalog_collections`, slugs, items, reports, translations) и одна nullable unique `users.public_id`. Индексы соответствуют owner/public/featured directory, manual order, title membership/merge, locale lookup и moderation queue; guessed likes/follows/popularity indexes не добавлены. Schema остаётся explicit serial-only, без arbitrary morph type. Title merge выполняет service reconciliation до duplicate force-delete, а soft-delete restoration сохраняет structural children.

Полная migration chain была применена к отдельной SQLite базе, после чего обе Task 10 migrations откатились в обратном порядке. Проверка после rollback показала ноль `catalog_collection*` tables, отсутствие additive `users.public_id`, сохранённые baseline `users`/`catalog_titles` и удалённые только две migration rows. Повторный final full-batch rollback подтвердил те же collection postconditions, а затем уже ниже по истории остановился на unrelated released importer migration `2026_07_09_204238`; Task 10 не переписывает старую migration и не скрывает это отдельное migration-program ограничение. Представительные планы запросов использовали documented owner/public/manual/title/report/translation indexes; duplicate `(collection, title)` rows отсутствовали после повторного batch Apply.

Risk до rollout: migrations ещё должны пройти обычный disposable SQLite up/down/schema/index inspection и production backup/write-pause gate; текущий live snapshot не мигрировался этой задачей. Collection benchmark не публикуется без representative rows. Task instruction запрещает создание/запуск automated tests, поэтому финальная verification использует static migration inspection и disposable schema diagnostics, не заменяя production backup.

## Task 12 discussion schema audit

Pre-implementation inspection нашёл zero discussion tables/rows, поэтому migrations `210000`–`210300` add comments, engagement/reports/restrictions, blocks/mutes/preferences и standard notifications без legacy copy/delete/backfill. Stable numeric comment ID отделён от body/locale/slug; target хранит enum code + ID, а root title FK служит merge/cache identity. Replies используют один self-FK root плюс nullable reply context, не отдельную table/nested set/morph.

Unique submission, user/comment reaction, report deduplication и directional relationship pairs безопасны на empty audited domain. Exact composite indexes обслуживают target pagination, root replies, author activity, same-root duplicate window, moderation/report queue, reaction totals, grouped `(user,comment)` current state, restriction expiry, reverse block queries и independent `(owner,id)` pagination приватных block/mute lists. Reply/reaction totals derived; stored score/count columns не добавлены.

`CommentSchema` различает stored-schema capability и product writability: обязательные columns/tables fail-closed проверяются независимо от `COMMENTS_ENABLED`, а feature flag применяется только к complete UI/write boundary. Поэтому отключение комментариев не выключает account privacy cleanup, export, target merge или collection retirement.

Disposable SQLite migration output подтвердил успешный full-repository `up()`, clean direct `down()/up()` всех четырёх discussion migrations и focused `235200` index migration, наличие восьми discussion tables, required indexes и пустой `PRAGMA foreign_key_check`. Representative query plans выбирают `comments_target_list_idx`, `user_blocks_owner_page_idx` и `user_mutes_owner_page_idx`. Production file не мигрировался; production rollout всё ещё требует verified backup/writer pause и обычную post-deploy schema inspection до приёма writes.

## Task 13 review schema audit

The deployed table has 73 101 provider rows and one unique `(catalog_title_id,body_hash)` key; audit found zero duplicate groups, invalid body hashes, orphan title/source or legacy user/vote/report data. Therefore migration `2026_07_15_220000` adds nullable community columns/default `origin=provider,status=published` and aliases/votes/reports/restrictions/preferences without copying, deleting or re-rating provider content. Rating remains unique `catalog_title_user_states(user_id,catalog_title_id)`.

Nullable unique ownership/submission hashes avoid cross-engine nullable composite semantics and preserve provider many-per-title behavior. Vote pair and open-report dedup keys make engagement retry-safe. Public title/author/moderation, vote totals/current state, report queue and active restriction indexes correspond to exact documented query predicates; no stored aggregate or speculative sentiment/reaction/season index was added.

Title merge archives hash/author collisions with stable alias/original hash instead of hard delete, moves votes/reports and uses `eachById()` while changing scope. The configured SQLite later received the in-flight review shape inside the separately disclosed batch-14 cached-config incident: all 73 101 provider rows remain published and unchanged, but four final columns were absent and report dedup remained non-nullable. Idempotent migration `2026_07_15_235100` converges those five schema differences; focused old-shape and fresh-schema rehearsals preserved IDs/text/hash/indexes with zero FK violations. It is pending on the configured database and Task 13 does not apply it autonomously or create/run automated tests.

Повторный read-only census 18.07.2026 после демонстрационного наполнения насчитал 1 720 085 reviews, 3 294 158 votes и 79 aliases. Duplicate ownership/submission/vote pairs, self votes, orphan targets/users/votes, invalid origin/status/vote/rating и duplicate current user/title rows отсутствуют. Одна user `removed` row не имела deletion reason/timestamp при сохранённом moderation actor/time; migration `2026_07_18_220000_repair_removed_review_deletion_state.php` остаётся pending и idempotently заполняет только эти nullable evidence fields. In-memory SQLite rehearsal дважды применила migration: malformed row сохранила ID/body и получила прежние moderator/time, merged и published fixtures не изменились. Existing public/author/rating/vote indexes выбраны реальными query plans, поэтому новый performance index/constraint не оправдан.

Operational follow-up 19.07.2026: другой rollout применил review repair вместе с comment repair/index в batch 31. Read-only canonical invariant теперь даёт `0` invalid removed reviews: reason/timestamp обязательны для всех tombstones, actor — для author/moderator deletion, а `merged_into_id` и `ownership_released_at` — для merged deletion. Поэтому 425 merged rows с nullable actor являются корректными system tombstones, а не незавершённой migration. `PRAGMA foreign_key_check` вернул ноль строк. Task 29 не нашла доступный pre-migration backup artifact этого batch и не заявляет его наличие.
