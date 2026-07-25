# Оптимизация запросов восстановления активного Seasonvar run — дизайн

Дата: 25.07.2026.  
Статус: дизайн и письменная спецификация одобрены; детальный implementation
plan подготовлен для TDD-исполнения.

## Цель

Ускорить bounded reconciliation активного queued-импорта без изменения
публичной команды, queue payload, lease/CAS semantics, размера dispatch batch
или terminal lifecycle. Изменение относится только к hot path
`SeasonvarActiveRunReconciler`; полная пакетная регистрация sitemap и durable
dispatch cursor остаются отдельной throughput-частью Task 6.

## Измеренный baseline

Read-only аудит рабочей SQLite 25.07.2026 подтвердил:

- `seasonvar_import_prepared_pages` содержит `187 293` строки в `262` runs;
- активный run `#1255` имел `24 280` due `queued|preparing` rows и `16 967`
  distinct due groups на момент снимка;
- выбор 250 due rows использовал
  `SCAN seasonvar_import_prepared_pages`;
- 30 последовательных read-only выполнений дали p50 `124,902 ms`,
  p95 `128,302 ms`;
- один фактический batch содержал 80–95 distinct groups; reconciler сначала
  eager-loads их одним запросом, но затем повторно выполняет отдельный
  `find()` для каждой группы перед finalizer signal;
- legacy non-serial claim query использовал существующий
  `source_pages_parallel_import_run_index`; due legacy rows в снимке
  отсутствовали.

На reflink-копии той же 27-ГБ SQLite индекс
`(seasonvar_import_run_id, status, updated_at, id)` создавался `20,634 s`.
Тот же due-select выбрал индекс и дал p50 `2,518 ms`, p95 `2,897 ms`.
SQLite сохранил малую temporary B-tree для `ORDER BY id`; это не мешает
селективному indexed lookup. Значения являются локальными diagnostic
observations, а не SLA или обещанием production latency.

## Рассмотренные подходы

### 1. Только убрать повторное чтение групп

Reconciler может повторно использовать уже eager-loaded
`SeasonvarImportTitleGroup` и устранить 80–95 запросов на наблюдавшийся batch.
Подход безопасен, но оставляет два повторяющихся full scan prepared ledger на
каждый recovery batch и не устраняет измеренный главный SQL bottleneck.

### 2. Оптимизировать bounded recovery hot path и добавить additive index

Выбранный подход:

1. повторно использовать eager-loaded группы;
2. читать `batchSize + 1` due rows, где последняя строка служит только
   sentinel наличия следующего batch;
3. отправлять не больше прежнего `batchSize`;
4. не выполнять второй prepared-ledger existence scan, когда sentinel уже
   дал точный ответ;
5. добавить reversible composite index
   `(seasonvar_import_run_id, status, updated_at, id)`.

Подход сохраняет текущую архитектуру и адресует оба доказанных источника
query amplification.

### 3. Немедленно реализовать полную throughput-часть Task 6

Bulk claims, grouped group resolution, prepared-row bulk insert, aggregate
counters и durable cursor устранят часы первоначальной регистрации sitemap.
Подход не выбран для этого change set: он меняет несколько write boundaries,
требует отдельной schema/rollback/load программы и не должен смешиваться с
малой оптимизацией уже работающего recovery path.

## Архитектурное решение

### Due-row selection

`SeasonvarActiveRunReconciler::duePreparedPages()` сохраняет существующие
filters:

- exact `seasonvar_import_run_id`;
- statuses `queued|preparing`;
- `updated_at <= transport replay cutoff`;
- deterministic `ORDER BY id`.

Query получает limit `batchSize + 1`. Первые `batchSize` rows участвуют в CAS
и dispatch. Дополнительная row не меняется и не отправляется: она только
доказывает `hasRemainingPreparedDueWork=true`, после чего существующий
ID-only reconciliation job продолжает backpressured drain.

Если rows не больше `batchSize` и dispatch не завершился исключением, второй
prepared existence query не нужен: все выбранные due rows либо успешно
получили новый attempt timestamp, либо перестали соответствовать CAS из-за
конкурентного authoritative transition. При dispatch exception прежний
timestamp восстанавливается compare-and-swap; follow-up остаётся обязательным
и может быть назначен без общего ledger scan.

Legacy non-serial claims сохраняют текущий indexed query и отдельную
existence-проверку только когда prepared sentinel не доказал продолжение.

### Finalizer group signals

`duePreparedPages()` уже eager-loads `group:id,queue_name`. После успешного CAS
reconciler сохраняет этот model instance в map по group ID. Finalizer signals
получают deduplicated values map напрямую; повторный
`$run->titleGroups()->find($groupId)` удаляется.

`SeasonvarImportFinalizationDispatcher::signalTitleGroup()` использует только
`id`, `seasonvar_import_run_id` для безопасного лога и `queue_name`. Поэтому
projection должна включать эти три поля. Status или полный group graph не
загружаются.

### Database index

Новая additive migration создаёт индекс
`seasonvar_prepared_run_status_updated_id_idx` на:

```text
seasonvar_import_run_id, status, updated_at, id
```

Он соответствует exact run, двум nonterminal status, retry cutoff и
deterministic tie-break. Existing unique/group indexes не удаляются и не
переписываются. `down()` удаляет только новый индекс.

Application code совместим со схемой до migration: запрос остаётся
функциональным, но медленным. Поэтому rolling order — migration до активации
оптимизированного long-lived worker code; rollback code может читать схему с
оставленным additive index.

## Data flow

1. Reconciler получает canonical per-run Redis lock.
2. Active run projection и dispatch barrier проверяются как раньше.
3. Indexed select читает максимум `batchSize + 1` lightweight rows и
   projected groups.
4. Только первые `batchSize` rows проходят прежний per-row database CAS.
5. Успешные rows получают прежний ID-only preparation job; failed dispatch
   восстанавливает timestamp прежним conditional update.
6. Deduplicated eager-loaded groups получают finalizer signals без SQL.
7. Sentinel или исключение определяет необходимость следующего reconciliation
   envelope; legacy lane проверяется только при оставшейся capacity.
8. Heartbeat меняется только после реального dispatch/barrier transition, как
   в существующем reliability contract.

## Ошибки и конкурентность

- Per-row CAS сохраняется; bulk update не вводится.
- Run-specific reconciliation lock сохраняется; Redis не становится
  authoritative ledger.
- Queue dispatch exception не теряет due row и не раскрывает URL/token.
- Extra sentinel row никогда не получает timestamp или job в текущем batch.
- Duplicate group signal остаётся безопасным благодаря existing unique
  finalizer job contract.
- Отсутствующая relation считается несовместимым persistent state и не
  маскируется новым query; foreign key/cascade contracts сохраняются.
- Legacy claims, claims release, terminal statuses, failed jobs и cache keys
  не меняются.

## TDD и query-plan verification

RED сначала должен доказать:

1. representative batch выполняет ровно один group projection query и не
   делает повторный per-group `find()`;
2. `batchSize + 1` due rows dispatch-ят ровно `batchSize`, возвращают
   `hasRemainingDueWork=true` и создают один follow-up;
3. ровно `batchSize` due rows dispatch-ят все rows без ложного follow-up;
4. dispatch exception восстанавливает attempt timestamp и сохраняет
   reconciliation continuation;
5. SQLite `EXPLAIN QUERY PLAN` выбирает
   `seasonvar_prepared_run_status_updated_id_idx` для exact recovery query;
6. migration `down()` оставляет исходные indexes и данные.

После GREEN запускаются focused reconciliation/queue tests, весь Seasonvar
filter, Pint, Larastan/PHPStan, migration/integrity checks, managed docs и
полный PHPUnit suite. Production timing повторяется только после разрешённого
rollout; результаты не переносятся с clone как production SLA.

## Production rollout

Рабочая SQLite около 27 ГБ и активно обслуживается workers. В этой задаче
production migration автоматически не запускается.

Перед применением требуются:

1. verified non-public backup и доступный restore path;
2. подтверждённый запас диска для index/WAL;
3. один production operator и paused SQLite writers;
4. `php artisan migrate --force` на intended commit;
5. `PRAGMA quick_check`, `foreign_key_check`, schema/index inventory и exact
   `EXPLAIN QUERY PLAN`;
6. graceful restart long-lived workers;
7. bounded canary по query latency, batch progress, queue slope, SQLite busy
   errors и run heartbeat;
8. resume writers только после successful checks.

Store-wide cache flush, queue clear, failed-job retry-all, manual status/claim
updates и provider HTTP не входят в rollout.

## Совместимость и cross-feature impact

- Публичная команда `php artisan seasonvar:import` не меняется.
- Queue connection/name, job class, scalar ID-only payload, timeout,
  `retry_after`, batch size и retry window не меняются.
- Catalog identity, title/season/episode/media writes и external URL-only
  boundary не меняются.
- Search, recommendations, calendar, API sync, cache invalidation, player,
  authentication, authorization, premium, region/legal и administration
  получают прежние finalizer handoffs.
- Routes, translations, permissions, environment variables, dependencies,
  cache keys, session и frontend assets не меняются.
- SQLite остаётся поддерживаемой authoritative database; index использует
  portable Laravel schema API.

## Откат

До production activation код откатывается обычным revert без data action.
После migration безопасный rollback приложения оставляет additive index на
месте. Если measured write/storage regression требует удалить индекс, writers
снова приостанавливаются после backup verification и выполняется только
migration `down()`/forward-fix для этого exact index. Queue, cache, claims,
staging rows и catalog data не очищаются.
