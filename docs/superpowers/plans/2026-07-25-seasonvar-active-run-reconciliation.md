# Seasonvar Active Run Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Восстановить `php artisan seasonvar:import`, когда Redis потерял queued transport, а SQLite сохранил активный run, page claims, title groups и nonterminal prepared-page ledger.

**Architecture:** Существующий `seasonvar_import_prepared_pages` остаётся durable ledger. Новый bounded reconciler под per-run lock закрывает только доказанно остановленный `dispatch_completed=false`, повторно ставит due `queued`/stale `preparing` rows в прежнюю Redis queue ID-only jobs и сигналит существующие group/global finalizers. CLI и scheduled watchdog вызывают одну boundary; polling больше не обновляет progress heartbeat при незавершённом dispatch.

**Tech Stack:** PHP 8.5.8, Laravel 13.22.0, SQLite, Redis queues/locks, PHPUnit 12.5.32.

## Global Constraints

- Единственная публичная команда остаётся `php artisan seasonvar:import`.
- Queue connection/name и constructors существующих serialized jobs не меняются.
- Не использовать `queue:clear`, `queue:flush`, массовый retry/forget, `cache:clear`, ручной SQL status/claim reset или destructive migration.
- Full video body не загружается и не сохраняется; подготовка использует существующие guarded HTTP boundaries.
- Recovery не освобождает claims: existing page jobs повторно доказывают ownership или получают claim штатным CAS.
- Один reconciliation attempt ставит bounded batch; follow-up использует тот же ID-only job и existing queue.
- `retry_after=1200` остаётся больше importer timeout `900`.
- Работа ведётся только в существующей `main`; чужой dirty worktree не переписывается.

---

### Task 1: Зафиксировать regression contract

**Files:**
- Create: `tests/Feature/SeasonvarActiveRunReconciliationTest.php`
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`

**Interfaces:**
- Consumes: `SeasonvarImportRun`, `SeasonvarImportPreparedPage`, `SeasonvarImportTitleGroup`, `SeasonvarPageClaimManager`.
- Produces: ожидаемый contract `SeasonvarActiveRunReconciler::reconcile(int $runId): SeasonvarActiveRunReconciliationResult`.

- [x] **Step 1: Написать тест transport loss**

Создать active `sitemap/queue/running` run с `dispatch_completed=false`, старым durable staging progress, одной `queued` prepared row и живым claim. Ожидать barrier `true`, сохранение claim и dispatch `PrepareSeasonvarImportTitlePage`, group/global finalizers.

- [x] **Step 2: Написать тест fresh dispatcher**

Создать такой же run со свежей prepared row. Ожидать no-op: barrier остаётся `false`, jobs не ставятся.

- [x] **Step 3: Написать тест bounded outbox**

Создать больше `seasonvar.queue.finalizer_watchdog_batch_size` due rows. Ожидать dispatch только bounded batch, обновление transport-attempt timestamp и один follow-up `ReconcileSeasonvarQueuedImportRun`.

- [x] **Step 4: Написать тест heartbeat**

Выполнить `FinalizeSeasonvarQueuedImport` при `dispatch_completed=false`. Ожидать, что `last_heartbeat_at` не меняется без durable state transition.

- [x] **Step 5: Проверить RED**

Run:

```bash
php artisan test --filter=SeasonvarActiveRunReconciliationTest
php artisan test --filter=test_global_finalizer_waits_until_page_dispatch_is_completed
```

Expected: FAIL только из-за отсутствующего reconciler/result/job contract и прежнего heartbeat touch.

### Task 2: Реализовать bounded reconciliation

**Files:**
- Create: `app/DTOs/Seasonvar/SeasonvarActiveRunReconciliationResult.php`
- Create: `app/Services/Seasonvar/SeasonvarActiveRunReconciler.php`
- Create: `app/Jobs/ReconcileSeasonvarQueuedImportRun.php`
- Modify: `app/Jobs/FinalizeSeasonvarQueuedImport.php`
- Modify: `tests/Unit/SeasonvarQueueJobContractTest.php`

**Interfaces:**
- `SeasonvarActiveRunReconciler::reconcile(int $runId): SeasonvarActiveRunReconciliationResult`.
- Result fields: `eligible`, `dispatchRecovered`, `jobsDispatched`, `hasRemainingDueWork`.
- `ReconcileSeasonvarQueuedImportRun::__construct(public readonly int $importRunId)`.

- [x] **Step 1: Реализовать immutable result DTO**

DTO возвращает только counters/booleans и не содержит URL, payload, error message или claim token.

- [x] **Step 2: Реализовать eligibility**

Под per-run lock повторно загрузить exact `sitemap/queue/running` run. Для `dispatch_completed=false` вычислить durable progress как maximum из `started_at` и последнего `prepared_pages.updated_at`; recovery разрешить только после existing `stale_after_minutes`.

- [x] **Step 3: Закрыть interrupted barrier**

Через `SeasonvarImportRunRecorder::mergeSummary()` записать `dispatch_completed=true` и bounded `active_run_reconciliation` evidence. Не менять status/claims/counters вручную.

- [x] **Step 4: Переотправить due ledger**

Projected query выбирает bounded `queued` rows и stale `preparing` rows. CAS обновляет `updated_at`; только выигравшая CAS row dispatch-ит `PrepareSeasonvarImportTitlePage(id)` в persisted group queue.

- [x] **Step 5: Сигналить fan-in**

После barrier recovery сигналить nonterminal groups и global finalizer. При полном batch ставить один unique follow-up reconciliation job.

- [x] **Step 6: Реализовать recovery job contract**

Job использует current Redis connection/queue, `ShouldBeUniqueUntilProcessing`, bounded timeout/backoff, `$tries=0`, `retryUntil()`, safe `failed()` context и только scalar run ID.

- [x] **Step 7: Проверить GREEN**

Run:

```bash
php artisan test --filter=SeasonvarActiveRunReconciliationTest
php artisan test --filter=SeasonvarQueueJobContractTest
```

Expected: PASS.

### Task 3: Подключить CLI и scheduled recovery

**Files:**
- Modify: `app/Console/Commands/ImportSeasonvar.php`
- Modify: `app/Jobs/WakeSeasonvarImportFinalizers.php`
- Modify: `routes/console.php`
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`
- Modify: `tests/Feature/SeasonvarImportFinalizationWatchdogTest.php`

**Interfaces:**
- CLI вызывает reconciliation только для обычного full sync start, не для `--status`, URL, inventory или size-only.
- Watchdog job сначала reconciles bounded active ledger, затем вызывает прежний `wakeReady()`.

- [x] **Step 1: Подключить exact CLI path**

Перед global sync acquisition получить active run и вызвать reconciler. Если active run остаётся running, сохранить existing single-flight warning.

- [x] **Step 2: Подключить watchdog**

Scheduled `ReconcileSeasonvarQueuedImportRun` остаётся independent internal job; `WakeSeasonvarImportFinalizers` дополнительно восстанавливает текущий active run до fan-in signals.

- [x] **Step 3: Проверить command/watchdog tests**

Run:

```bash
php artisan test --filter=SeasonvarParallelImportTest
php artisan test --filter=SeasonvarImportFinalizationWatchdogTest
```

Expected: PASS и отсутствие mutation в `--status`.

### Task 4: Production-safe recovery и документация

**Files:**
- Modify: `docs/importer.md`
- Modify: `docs/queues.md`
- Modify: `docs/performance.md`
- Modify: `docs/superpowers/plans/2026-07-24-seasonvar-importer-improvement-master-plan.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `docs/README.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Public command, routes, translations, permissions, schema, cache identities and media boundaries stay compatible.

- [x] **Step 1: Выполнить preflight**

Повторно проверить active run, queue counts, process owners, disk/backup evidence и отсутствие live coordinator process. Не выводить secrets/private paths.

- [x] **Step 2: Выполнить internal reconciliation**

Запустить exact CLI path либо ID-only recovery job; не менять rows SQL вручную. Проверить barrier, queued work, worker consumption, parsed/prepared counters и claims trend.

- [x] **Step 3: Обновить owners**

Описать durable ledger reconciliation, ложный heartbeat fix, bounded dispatch, failure recovery и rollback. README visitor history сообщает только фактическое восстановление обновлений каталога.

- [x] **Step 4: Полная verification**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=Seasonvar
php artisan test
./vendor/bin/phpstan analyse --memory-limit=1G
php artisan project:docs-refresh --check
```

Expected: все scoped проверки green; full-suite unrelated failures фиксируются честно.

- [x] **Step 5: Commit/push gate**

Проверить `git status --short --branch`. Commit/push разрешены только в `main` и только если чужой dirty worktree не поглощается; иначе delivery status остаётся `unresolved_shared_worktree`.

Result: `unresolved_shared_worktree`. Existing `main` содержит многочисленные
чужие tracked/untracked changes, а `pre-commit` требует отсутствие обоих
классов. Полный suite также сохраняет один прежний unrelated account-session
failure. Изменения задачи не staged, commit/push/PR не выполнялись и hook не
обходился.
