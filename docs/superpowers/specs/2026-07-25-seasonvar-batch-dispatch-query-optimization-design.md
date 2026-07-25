# Seasonvar bounded batch dispatch и durable resume

Дата: 25.07.2026.

Статус: `approved_for_planning`.

## Контекст и подтверждённая проблема

Полный queued import регистрирует каждую выбранную serial-страницу через
последовательность отдельных операций: claim, проверка active run, поиск уже
подготовленной строки, до двух одинаковых поисков `CatalogTitle`,
`firstOrCreate` группы, повторный `SourcePage` lookup, вставка prepared row,
два increment и до трёх prepared-row selects.

Изолированный профиль текущего кода на SQLite с 100 serial-страницами,
сгруппированными в 10 title families, выполнил 1 239 SQL-запросов:

- 101 updates `source_pages`, включая восстановление просроченных claims;
- 100 prepared-ledger `exists`;
- 202 selects `catalog_titles`;
- 100 selects `seasonvar_import_title_groups`;
- 300 selects `seasonvar_import_prepared_pages`;
- 100 increments `expected_pages`;
- 100 increments `seasonvar_import_runs.selected`.

Это воспроизводит production-наблюдение: run `#1254` регистрировал 41 043
страницы около 2 ч 43 мин 36 с, а run `#1255` успел создать более 32 тысяч
durable staging rows до потери Redis transport. Оптимизация recovery-query из
Task 48 не уменьшает первоначальную стоимость регистрации.

## Цель и границы

Цель — заменить per-page SQL-регистрацию initial sitemap fan-out на
ограниченные, идемпотентные batch transactions максимум по 100 страниц и
сделать незавершённую регистрацию возобновляемой из database state.

Изменение обязано:

- обрабатывать `min(100, seasonvar.import.chunk_size)` страниц за batch, чтобы
  bulk statements сохраняли запас до SQLite bind-variable limit;
- выполнять grouped title/group/prepared lookups и bulk writes;
- фиксировать claims и serial staging атомарно;
- продолжать active `dispatch_completed=false` run после coordinator crash;
- оставлять `seasonvar_import_prepared_pages` durable outbox/ledger;
- отделять фактический progress от observer heartbeat;
- сохранять ID-only queue jobs, queue names и public command;
- не выполнять provider HTTP, catalog apply или Redis fan-out внутри длинной
  SQLite transaction.

В scope не входят compact payload storage, writer admission, изменение
worker count/timeouts, новая публичная команда, новый route, UI, cache
infrastructure, full video download или production activation migrations.

## Рассмотренные подходы

### 1. Локальные удаления повторных selects

Можно убрать второй `CatalogTitle` lookup и часть prepared reads, не меняя
архитектуру dispatch.

Это даст ограниченное ускорение, но оставит claim, group creation, counters и
registration loop линейными по страницам. Crash посередине по-прежнему не
сможет доказать, какие ещё страницы нужно зарегистрировать.

### 2. Bounded batch registration с database-ledger resume

Выбранный подход. Один batch:

1. повторно проверяет active run и discovery state;
2. выбирает очередную bounded страницу через текущий planner;
3. внутри короткой transaction исключает уже зарегистрированные страницы,
   batch-claim-ит оставшиеся serial rows, разрешает title identity grouped
   queries, bulk-создаёт группы и prepared rows;
4. агрегированно обновляет group/run counters и durable progress;
5. после commit ставит только новые prepared IDs в существующую очередь;
6. продолжает следующим ID-only reconciliation job.

Prepared ledger, а не один небезопасный high-water ID, является authority
возобновления. Текущий planner состоит из нескольких overlapping reason
queries и metadata phases; один глобальный `dispatch_cursor_id` мог бы
пропустить страницу, которая находится в более ранней фазе, но стала eligible
после crash/retry. Поэтому каждый planner query исключает exact
`(run_id, source_page_id)` из durable ledger, а повторный проход безопасно
находит только незарегистрированный остаток.

### 3. Отдельная очередь batch envelopes

Можно сначала сохранить весь inventory dispatch plan, а затем обрабатывать
batch envelopes отдельным job type.

Это добавит вторую staging topology, увеличит database footprint и изменит
rolling queue contract без необходимости. Существующая prepared table уже
является подходящим durable ledger, поэтому подход отклонён.

## Архитектура

### Orchestration

`SeasonvarQueuedImportDispatcher` сохраняет lifecycle start и discovery, но
после discovery выполняет не весь многотысячный loop, а один bounded batch.
Если работа осталась, он ставит существующий
`ReconcileSeasonvarQueuedImportRun` с одним scalar run ID.

`StartSeasonvarQueuedImport` остаётся коротким coordinator job. Он может
обслужить новый `queued` run и совместимо продолжить exact
`running + dispatch_completed=false` run. Другой execution mode, terminal
status или уже completed dispatch остаются no-op.

`SeasonvarActiveRunReconciler` больше не закрывает incomplete dispatch только
по числу уже существующих prepared rows. При explicit
`dispatch_completed=false` он сначала просит batcher зарегистрировать
следующий незавершённый batch. Только planner exhaustion под свежей row-lock
проверкой разрешает записать `dispatch_completed=true`.

Scheduled `WakeSeasonvarImportFinalizers` сохраняет прежний вызов одной
reconciliation boundary. Provider HTTP и полный registration loop внутри
watchdog отсутствуют.

### Discovery state

Новые queued runs получают bounded summary markers:

- `discovery_completed=false`, если discovery запрошен;
- `discovery_completed=true`, если запуск создан с `--no-discovery`;
- `dispatch_completed=false` до исчерпания planner;
- `dispatch_batches` и безопасные числовые counts как operational evidence.

После полного mirror/store dispatcher под row lock фиксирует
`discovery_completed=true`. Incomplete discovery продолжает существующий
`StartSeasonvarQueuedImport` retry path и не разрешает batcher объявить
dispatch завершённым. Для legacy rows без marker применяется совместимый
fallback, основанный на существующем `discover` и lifecycle state; marker не
подменяет actual source rows.

Sitemap-tail остаётся bounded `1..1000`. После полного разрешённого mirror и
bulk store coordinator сопоставляет выбранный tail с уже сохранёнными
`source_pages.id` и под row lock записывает ordered bounded
`sitemap_tail_page_ids`. Raw URL/hash list в summary не сохраняется. Initial и
resumed coordinator регистрируют exact persisted IDs теми же batch
operations; изменение внешнего sitemap после crash не меняет выбор уже
начатого run.

### Batch selection

`SeasonvarRefreshPlanner` сохраняет текущие reason order, visibility,
freshness, retry, claim и page-type predicates. Когда передан active run ID,
каждая selection query дополнительно исключает страницу, уже существующую в
`seasonvar_import_prepared_pages` этого run.

Эта anti-join boundary поддерживается unique/index contract
`(seasonvar_import_run_id, source_page_id)`. Read-only production census перед
design зафиксировал ноль duplicate run/page pairs среди 187 293 prepared rows.

Planner возвращает первый непустой bounded chunk. Повторный reconciliation
начинает deterministic traversal заново, но indexed anti-join пропускает уже
зарегистрированное. Это сохраняет correctness overlapping reason queries без
неограниченного PHP-множества selected IDs и без ложного единственного cursor.

### Atomic serial registration

Новый `SeasonvarImportDispatchBatcher` принимает exact active run и bounded
collection уже загруженных `SourcePage`.

В одной короткой retry-aware transaction он:

1. row-lock-ит run и повторно требует `sitemap/queue/running`,
   `discovery_completed=true`, `dispatch_completed=false`;
2. одним запросом получает существующие run/page ledger rows и исключает их;
3. одним conditional update устанавливает общий cryptographically random
   claim token только свободным/истёкшим source rows;
4. одним select подтверждает exact `(run_id, token, page IDs)` ownership;
5. grouped queries разрешают `CatalogTitle` по прежнему приоритету:
   same source + direct `source_page_id`/`source_url_hash`, затем season
   `source_url_hash`, с минимальным title ID как прежний `orderBy(id)->first()`;
6. вычисляет canonical `group_key_hash` в PHP существующим
   `SeasonvarImportGroupKey`;
7. одним `insertOrIgnore` создаёт отсутствующие группы и одним select получает
   полную group projection;
8. заполняет только `null catalog_title_id`, не перезаписывая уже выбранную
   canonical identity;
9. одним `insertOrIgnore` создаёт prepared rows;
10. повторно выбирает batch prepared IDs, вычисляет действительно новые rows и
    агрегированно обновляет `expected_pages`, run `selected`,
    `last_progress_at` и batch evidence.

Title resolution сначала выбирает прежний минимальный matching title для
каждой отдельной страницы. Если несколько страниц одной family указывают на
разные existing titles, группа сохраняет прежнюю последовательную семантику:
первый non-null title в стабильном порядке page ID заполняет только
`null catalog_title_id`; существующее non-null значение не перезаписывается.

Общий claim token допустим только внутри batch: ownership/release всегда
дополнительно содержит exact source page ID и run ID. Страница, не получившая
conditional claim, не попадает в staging или dispatch. Claim duration не
увеличивается.

Операции с разными group increments выполняются одним bounded
`CASE id WHEN ...` update с fixed table/column names и bound values. SQL
остаётся совместимым с SQLite, MariaDB/MySQL и PostgreSQL; никакие identifier
или expression не принимаются из request/provider data.

### Non-serial compatibility

Non-serial page types продолжают использовать текущие per-page claim и
`ImportSeasonvarSourcePage`; их batching не входит в Task 49. Existing claim
остаётся durable replay evidence, а
`SeasonvarActiveRunReconciler::requeueLegacyClaimedPages()` продолжает
bounded redispatch.

Disabled/unconfirmed page handlers не включаются автоматически.

### Queue dispatch после commit

Транзакция никогда не push-ит Redis jobs. После commit batcher:

- dispatches только новые `PrepareSeasonvarImportTitlePage($preparedPageId)`;
- сохраняет прежние connection, queue, retry, timeout и unique contracts;
- собирает успешно отправленные IDs и одним bulk update фиксирует
  `last_enqueue_attempt_at`/`enqueue_attempts`;
- оставляет неотправленные rows с due outbox state для reconciler;
- сигналит только новые title groups и не делает per-page group lookup;
- ставит один continuation reconciliation job, если planner ещё не исчерпан.

Crash после database commit и до Redis dispatch оставляет `queued` prepared
rows. Crash после части Redis dispatch может дать duplicate delivery, но не
duplicate database rows, counters или apply: Redis unique key и database CAS
обеспечивают идемпотентность.

### Preparation CAS

`SeasonvarImportPreparedPage` получает atomic transition:

- только `queued -> preparing` начинает HTTP/parser работу;
- duplicate delivery, увидевшая fresh `preparing`, завершает no-op;
- transient failure перед повторным throw возвращает exact row в `queued`;
- stale `preparing` может быть возвращена reconciler в `queued` только после
  существующего bounded retry/claim window;
- `prepared|applied|failed` никогда не начинает подготовку повторно.

Source-page claim по-прежнему повторно разрешается/продлевается worker и
освобождается exact `(page_id, run_id, token)`. Queue payload остаётся одним
integer prepared-page ID.

### Progress и heartbeat

Additive `seasonvar_import_runs.last_progress_at` отражает только durable
state transition:

- lifecycle start;
- completed discovery marker;
- committed registration batch;
- реальные counters preparation/apply/failure/finalization.

`last_heartbeat_at` остаётся observer/liveness timestamp. Status poll,
finalizer wake-up, enqueue attempt без state change и простое чтение не меняют
`last_progress_at`. Legacy rows с `null` используют bounded fallback к
существующим lifecycle timestamps.

## Schema

Additive reversible migration:

`seasonvar_import_runs`:

- nullable indexed `last_progress_at`.

`seasonvar_import_prepared_pages`:

- nullable `last_enqueue_attempt_at`;
- unsigned `enqueue_attempts` default `0`;
- unique `(seasonvar_import_run_id, source_page_id)`;
- due-outbox index
  `(seasonvar_import_run_id, status, last_enqueue_attempt_at, id)`.

Перед unique index migration выполняет fail-closed duplicate preflight. Она не
сливает и не удаляет строки автоматически. Production migration
классифицируется как additive/potentially-locking для SQLite и остаётся
operator-gated.

Pending Task 48 index
`(seasonvar_import_run_id,status,updated_at,id)` не редактируется и остаётся
совместимым с уже подготовленным recovery rollout. Новый outbox index имеет
другую семантику; возможное удаление старого индекса требует отдельного
последующего EXPLAIN/rollout решения.

## Ошибки и восстановление

- Run cancelled/terminal между selection и transaction: batch ничего не
  регистрирует и не ставит continuation.
- Claim race: staging получает только подтверждённые exact-owned rows.
- Duplicate batch: unique run/page + group keys и повторный read дают нулевой
  counter delta.
- SQLite lock: existing retry-aware transaction/classifier повторяет
  ограниченно; транзакция не охватывает Redis или HTTP.
- Redis outage после commit: database rows остаются due; scheduled watchdog
  повторяет ID-only dispatch.
- Coordinator crash до commit: batch откатывается целиком и выбирается снова.
- Coordinator crash после commit: ledger исключает завершённый batch, а
  continuation начинает следующий.
- Discovery crash: explicit marker остаётся false; start retry повторяет
  bounded guarded discovery, не закрывая dispatch.
- Corrupt/legacy summary: unknown marker не выдаёт completion нового run;
  legacy behavior ограничивается rows, созданными до schema rollout.
- Production rollback: code может вернуться к per-page path, пока additive
  columns/indexes остаются. Down migration запрещена при active writers/jobs;
  database restore требуется только при отдельном подтверждённом повреждении,
  которого эта migration не создаёт.

## Query budget и тестирование

Обязательный RED → GREEN набор:

1. 100 serial pages регистрируются без query shape, повторённого на каждую
   страницу для title/group/prepared/counter paths.
2. Query count фиксируется на deterministic fixture и уменьшается минимум на
   порядок относительно измеренных 1 239 запросов; окончательный ceiling
   записывается только после GREEN profile.
3. Рост fixture с 10 до 100 страниц не добавляет per-page group/title/prepared
   selects или counter updates.
4. Crash до batch commit не оставляет claim/staging/counter.
5. Crash после commit до Redis dispatch возобновляет due rows без дублей.
6. Повтор того же batch не увеличивает `selected`/`expected_pages`.
7. Claim race исключает неприобретённую страницу.
8. Cancelled run останавливается до следующего batch.
9. Incomplete run возобновляет незарегистрированный остаток и только после
   planner exhaustion устанавливает `dispatch_completed=true`.
10. Overlapping planner reasons не пропускают и не дублируют страницу после
    resume.
11. Duplicate preparation delivery допускает только один `queued->preparing`.
12. Transient preparation failure возвращает row в retryable `queued`.
13. Targeted title refresh и dynamically discovered season сохраняют прежнюю
    group identity и unlimited URL count.
14. Non-serial handler сохраняет legacy ID/token job contract.
15. Queue contract подтверждает прежние queue names, scalar constructors,
    retry/backoff/timeout и `retry_after > timeout`.
16. Migration preflight отклоняет duplicate run/page fixture без удаления
    данных; clean fixture создаёт unique/due indexes.

Focused verification:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=SeasonvarImportDispatchBatcherTest
php artisan test --filter=SeasonvarImportTitleGroupDispatcherTest
php artisan test --filter=SeasonvarParallelImportTest
php artisan test --filter=SeasonvarActiveRunReconciliationTest
php artisan test --filter=SeasonvarQueueJobContractTest
php artisan test --filter=SeasonvarImportDispatchQueryPlanTest
```

После focused GREEN выполняются широкий `--filter=Seasonvar`, direct PHPStan
для изменённых boundaries и полный PHPUnit. Production/provider activation,
worker restart и migration не являются test step этой задачи.

## Rollout и rollback

Rollout только после отдельного operator gate:

1. подтвердить backup и доступный restore path;
2. подтвердить свободное место с WAL/index headroom;
3. дождаться terminal importer и остановить все importer writers;
4. снять duplicate preflight и baseline `PRAGMA quick_check`,
   `foreign_key_check`, schema/index inventory;
5. применить additive migrations;
6. проверить unique/due indexes через `EXPLAIN QUERY PLAN`;
7. deploy code и graceful restart workers;
8. выполнить bounded canary без production full fan-out;
9. сравнить batch queries, rows, counters, claims, queue delivery и progress;
10. только затем разрешить обычный queued schedule.

Rollback code-only возвращает прежний per-page dispatcher; additive columns и
indexes остаются неиспользуемыми. Удаление indexes/columns не выполняется под
traffic и требует нового backup/paused-writer решения. Queue/cache clear,
retry-all, manual status/claim DML и broad failed-job mutation запрещены.

## Совместимость

Сохраняются:

- единственная публичная команда `php artisan seasonvar:import`;
- `seasonvar-import` и `seasonvar-title-refresh`;
- ID-only preparation/reconciliation/finalizer payloads;
- run/group/prepared status codes и terminal reasons;
- current claim duration, worker timeout, retry/backoff и finalizer locks;
- один `CatalogTitle` для всех сезонов;
- provider URL-only media и запрет full video storage;
- catalog identity, publication/editorial ownership и partial snapshot rules;
- cache/search/recommendation/calendar/API-sync handoffs;
- routes, permissions, locale, SEO, frontend, dependencies и environment keys.

## Ожидаемые файлы

Create:

- `app/DTOs/Seasonvar/SeasonvarImportDispatchBatch.php`;
- `app/Services/Seasonvar/SeasonvarImportDispatchBatcher.php`;
- `database/migrations/2026_07_25_130000_add_batch_dispatch_progress_to_seasonvar_import.php`;
- `tests/Feature/SeasonvarImportDispatchBatcherTest.php`;
- `tests/Feature/SeasonvarImportDispatchQueryPlanTest.php`.

Modify:

- `app/Jobs/PrepareSeasonvarImportTitlePage.php`;
- `app/Jobs/ReconcileSeasonvarQueuedImportRun.php`;
- `app/Jobs/StartSeasonvarQueuedImport.php`;
- `app/Jobs/FinalizeSeasonvarQueuedImport.php`;
- `app/Jobs/FinalizeSeasonvarImportTitleGroup.php`;
- `app/Models/SeasonvarImportPreparedPage.php`;
- `app/Models/SeasonvarImportRun.php`;
- `app/Services/Seasonvar/SeasonvarActiveRunReconciler.php`;
- `app/Services/Seasonvar/SeasonvarGlobalImportRunCoordinator.php`;
- `app/Services/Seasonvar/SeasonvarImportRunRecorder.php`;
- `app/Services/Seasonvar/SeasonvarImportPipeline.php`;
- `app/Services/Seasonvar/SeasonvarImportTitleGroupDispatcher.php`;
- `app/Services/Seasonvar/SeasonvarPageClaimManager.php`;
- `app/Services/Seasonvar/SeasonvarQueuedImportDispatcher.php`;
- `app/Services/Seasonvar/SeasonvarRefreshPlanner.php`;
- related Seasonvar feature/unit tests;
- `docs/importer.md`, `docs/queues.md`, `docs/performance.md`;
- importer improvement master plan, current task plan, `README.md`,
  `CHANGELOG.md`.

Protected unchanged contracts/files include public web/API routes, Blade/UI,
translations, cache key factories, authorization policies, catalog migrations,
queue names, external media delivery and package lock files.
