# Seasonvar Active Run Query Optimization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ускорить bounded recovery активного queued Seasonvar run, устранив
повторные group reads и prepared-ledger existence scan и добавив измеренный
reversible index без изменения публичной команды, queue payload или CAS.

**Architecture:** `SeasonvarActiveRunReconciler` читает максимум
`batchSize + 1` due prepared rows. Последняя row является только sentinel;
первые `batchSize` проходят прежний per-row CAS и ID-only dispatch.
Eager-loaded `SeasonvarImportTitleGroup` переиспользуется для deduplicated
finalizer signal. Отдельная additive migration индексирует exact recovery
predicate. Legacy non-serial lane и её существующий индекс сохраняются.

**Tech Stack:** PHP 8.5, Laravel 13.22.0, Eloquent/Schema Builder, SQLite,
Redis queue transport, PHPUnit 12.5.32, Laravel Pint 1.29.3.

**Execution status 25.07.2026:** Tasks 1–4 реализованы в общем рабочем дереве.
Текущий continuation-pass независимо воспроизвёл focused GREEN:
query-plan `2/2` теста и `6` утверждений, reconciliation `11/11` и `62`,
queue-contract `2/2` и `83`. Production migration не применялась. Task 5
остаётся активной до broad verification и delivery-gate.

## Global Constraints

- Единственная публичная команда остаётся `php artisan seasonvar:import`.
- Queue connection/name, job classes, ID-only serialized payloads,
  `retry_after`, timeout, backoff, batch size и heartbeat semantics не
  меняются.
- Per-row database CAS, run-specific Redis lock и conditional timestamp
  restore при dispatch exception сохраняются.
- Full Task 6 bulk registration, durable dispatch cursor и grouped bulk writes
  не входят в этот change set.
- Нельзя выполнять production migration, останавливать/перезапускать workers,
  очищать queue/cache или менять run/claim rows без отдельного operator gate.
- Миграция additive и reversible; прежние unique/group indexes и данные не
  изменяются.
- Работа выполняется только в существующей `main`; foreign dirty paths нельзя
  stage/reset/stash/delete или поглощать в commit.

---

### Task 1: Зафиксировать query-plan и rollback contract индекса

**Files:**

- Create:
  `tests/Feature/SeasonvarActiveRunQueryPlanTest.php`
- Create:
  `database/migrations/2026_07_25_120000_add_active_run_recovery_index_to_seasonvar_import_prepared_pages.php`

**Protected contracts:**

- Existing unique
  `seasonvar_import_prepared_pages_group_page_unique`.
- Existing index
  `seasonvar_import_prepared_pages_group_status_idx`.
- Prepared-page rows, foreign keys and cascades.

- [x] **Step 1: Написать RED query-plan test**

Создать `SeasonvarActiveRunQueryPlanTest` с `RefreshDatabase`. Представительный
query должен повторять production predicate и projection:

```php
$query = SeasonvarImportPreparedPage::query()
    ->select([
        'id',
        'seasonvar_import_title_group_id',
        'status',
        'updated_at',
    ])
    ->where('seasonvar_import_run_id', $run->id)
    ->whereIn('status', [
        SeasonvarPreparedPageStatus::Queued->value,
        SeasonvarPreparedPageStatus::Preparing->value,
    ])
    ->where('updated_at', '<=', now()->subMinutes(20))
    ->orderBy('id')
    ->limit(251);

$plan = DB::select(
    'EXPLAIN QUERY PLAN '.$query->toSql(),
    $query->getBindings(),
);
$details = collect($plan)
    ->map(fn (object $row): string => (string) $row->detail)
    ->implode(' ');

$this->assertStringContainsString(
    'seasonvar_prepared_run_status_updated_id_idx',
    $details,
);
```

Тем же тестом получить `PRAGMA index_info` и потребовать точный порядок:

```php
$columns = collect(DB::select(
    "PRAGMA index_info('seasonvar_prepared_run_status_updated_id_idx')",
))->pluck('name')->all();

$this->assertSame([
    'seasonvar_import_run_id',
    'status',
    'updated_at',
    'id',
], $columns);
```

- [x] **Step 2: Написать RED migration-down test**

Создать run, group, source page и одну prepared row. Сохранить её ID, затем
вызвать `down()` exact migration instance:

```php
$migration = require database_path(
    'migrations/2026_07_25_120000_add_active_run_recovery_index_to_seasonvar_import_prepared_pages.php',
);

$migration->down();

$indexNames = collect(Schema::getIndexes(
    'seasonvar_import_prepared_pages',
))->pluck('name');

$this->assertDatabaseHas('seasonvar_import_prepared_pages', [
    'id' => $preparedPage->id,
]);
$this->assertTrue($indexNames->contains(
    'seasonvar_import_prepared_pages_group_page_unique',
));
$this->assertTrue($indexNames->contains(
    'seasonvar_import_prepared_pages_group_status_idx',
));
$this->assertFalse($indexNames->contains(
    'seasonvar_prepared_run_status_updated_id_idx',
));

$migration->up();
```

Тест обязан восстановить новый индекс в конце, чтобы не влиять на следующие
tests этого process.

- [x] **Step 3: Получить RED**

Run:

```bash
php artisan test tests/Feature/SeasonvarActiveRunQueryPlanTest.php
```

Expected: FAIL, потому что migration file/index отсутствует и representative
query не может выбрать named index.

- [x] **Step 4: Добавить минимальную migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seasonvar_import_prepared_pages', function (Blueprint $table): void {
            $table->index(
                [
                    'seasonvar_import_run_id',
                    'status',
                    'updated_at',
                    'id',
                ],
                'seasonvar_prepared_run_status_updated_id_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('seasonvar_import_prepared_pages', function (Blueprint $table): void {
            $table->dropIndex(
                'seasonvar_prepared_run_status_updated_id_idx',
            );
        });
    }
};
```

- [x] **Step 5: Получить GREEN и отформатировать**

Run:

```bash
php artisan test tests/Feature/SeasonvarActiveRunQueryPlanTest.php
./vendor/bin/pint --dirty --format agent
```

Expected: query plan содержит exact index name; `down()` сохраняет row и оба
исходных index; `up()` восстанавливает новый index.

---

### Task 2: Зафиксировать query-count и sentinel regression contracts

**Files:**

- Modify:
  `tests/Feature/SeasonvarActiveRunReconciliationTest.php`

**Protected contracts:**

- Result DTO fields `eligible`, `dispatchRecovered`, `jobsDispatched`,
  `hasRemainingDueWork`.
- Existing Queue fake assertions для preparation, group/global finalizers и
  follow-up reconciliation.
- `finalizer_watchdog_batch_size=2` test configuration.

- [x] **Step 1: Написать RED для group-query budget**

Создать один active run с `dispatch_completed=true` и двумя due prepared rows
в разных groups. После fixture setup включить `DB::listen()` и выполнить
reconciliation:

```php
$queries = [];
DB::listen(static function ($query) use (&$queries): void {
    $queries[] = strtolower($query->sql);
});

$result = app(SeasonvarActiveRunReconciler::class)->reconcile($run->id);

$groupSelects = collect($queries)
    ->filter(static fn (string $sql): bool => str_starts_with(
        ltrim($sql),
        'select',
    ))
    ->filter(static fn (string $sql): bool => str_contains(
        $sql,
        'from "seasonvar_import_title_groups"',
    ));

$this->assertSame(2, $result->jobsDispatched);
$this->assertCount(1, $groupSelects);
```

До изменения reconciler тест должен видеть один eager-load select и два
повторных per-group `find()`.

- [x] **Step 2: Ужесточить bounded sentinel contract**

Существующий test с тремя due rows и batch size `2` сохранить и дополнить
query assertion: первый attempt dispatch-ит ровно две rows, возвращает
`hasRemainingDueWork=true` и ставит ровно один follow-up.

Отдельно создать ровно две due rows и включить SQL listener после fixtures.
Ожидать:

```php
$this->assertSame(2, $result->jobsDispatched);
$this->assertFalse($result->hasRemainingDueWork);
Queue::assertNotPushed(ReconcileSeasonvarQueuedImportRun::class);
```

Prepared-ledger selects, не являющиеся частью legacy source-page subquery,
должны иметь count `1`:

```php
$preparedLedgerSelects = collect($queries)
    ->filter(static fn (string $sql): bool => str_starts_with(
        ltrim($sql),
        'select',
    ))
    ->filter(static fn (string $sql): bool => str_contains(
        $sql,
        'from "seasonvar_import_prepared_pages"',
    ))
    ->reject(static fn (string $sql): bool => str_contains(
        $sql,
        'from "source_pages"',
    ));

$this->assertCount(1, $preparedLedgerSelects);
```

До изменения current implementation выполняет второй prepared-ledger
`exists()` и должен нарушить этот budget.

- [x] **Step 3: Написать RED для dispatch exception**

Подменить `Illuminate\Contracts\Bus\Dispatcher` scoped fake, который бросает
`RuntimeException` на первом preparation dispatch и принимает последующий
follow-up dispatch. Для одной due row ожидать:

```php
$result = app(SeasonvarActiveRunReconciler::class)->reconcile($run->id);

$this->assertSame(0, $result->jobsDispatched);
$this->assertTrue($result->hasRemainingDueWork);
$this->assertTrue(
    $preparedPage->updated_at->equalTo(
        $preparedPage->fresh()->updated_at,
    ),
);
```

Fake должен также доказать, что вторым dispatch был
`ReconcileSeasonvarQueuedImportRun` с тем же scalar run ID. Test не проверяет
exception text и не раскрывает URL/token.

- [x] **Step 4: Получить целевой RED**

Run:

```bash
php artisan test tests/Feature/SeasonvarActiveRunReconciliationTest.php
```

Expected:

- group-query test FAIL с тремя group selects вместо одного;
- exact-batch budget FAIL из-за дополнительного prepared `exists()`;
- существующие correctness tests остаются зелёными;
- dispatch exception test может быть уже functional, но обязан закрепить
  timestamp restore и continuation до refactor.

---

### Task 3: Реализовать sentinel selection и reuse eager-loaded groups

**Files:**

- Modify:
  `app/Services/Seasonvar/SeasonvarActiveRunReconciler.php`

**Interfaces:**

- `reconcile(int $runId): SeasonvarActiveRunReconciliationResult` не меняется.
- `duePreparedPages(int, Carbon, int): Collection` остаётся private.
- `hasRemainingDueWork()` с prepared scan заменяется private
  `hasRemainingLegacyDueWork()`.

- [x] **Step 1: Выбрать один sentinel сверх batch**

```php
$batchSize = $this->batchSize();
$preparedCandidates = $this->duePreparedPages(
    $run->id,
    $dueCutoff,
    $batchSize + 1,
);
$hasMorePreparedDueWork = $preparedCandidates->count() > $batchSize;
$preparedPages = $preparedCandidates->take($batchSize);
```

Extra row не участвует в CAS, timestamp update, dispatch или group signal.

- [x] **Step 2: Сохранить projected group models**

Импортировать `App\Models\SeasonvarImportTitleGroup` и заменить boolean
`$groupIds` на typed model map:

```php
/** @var array<int, SeasonvarImportTitleGroup> $groups */
$groups = [];
$restoredPreparedDispatchFailure = false;
```

После successful preparation dispatch:

```php
$groups[(int) $page->group->id] = $page->group;
```

При dispatch exception сохранить результат conditional restore:

```php
$restored = SeasonvarImportPreparedPage::query()
    ->whereKey($page->id)
    ->where('updated_at', $attemptedAt)
    ->update(['updated_at' => $page->updated_at]);

$restoredPreparedDispatchFailure =
    $restoredPreparedDispatchFailure || $restored === 1;
```

- [x] **Step 3: Удалить повторный per-group `find()`**

```php
foreach ($groups as $group) {
    $this->finalizers->signalTitleGroup($group);
}
```

Projection eager loading должна включать все поля dispatcher:

```php
->with('group:id,seasonvar_import_run_id,queue_name')
```

- [x] **Step 4: Удалить prepared existence scan**

Вычислить continuation из уже известного authoritative state:

```php
$hasRemainingDueWork = $hasMorePreparedDueWork
    || $restoredPreparedDispatchFailure
    || $this->hasRemainingLegacyDueWork($run->id, $dueCutoff);
```

`hasRemainingLegacyDueWork()` сохраняет только существующий SourcePage
predicate с `source_pages_parallel_import_run_index` и prepared-page
anti-subquery. Prepared rows отдельно больше не сканируются.

- [x] **Step 5: Проверить focused GREEN**

Run:

```bash
php artisan test tests/Feature/SeasonvarActiveRunReconciliationTest.php
php artisan test tests/Feature/SeasonvarActiveRunQueryPlanTest.php
php artisan test --filter=SeasonvarQueueJobContractTest
```

Expected:

- один projected group query для двух distinct groups;
- максимум batch size preparation jobs;
- sentinel row остаётся due до follow-up;
- exact batch не создаёт ложный follow-up;
- dispatch failure восстанавливает timestamp и создаёт continuation;
- queue job serialization/timeout/backoff contracts не меняются.

- [x] **Step 6: Отформатировать и проверить static analysis**

Run:

```bash
./vendor/bin/pint --dirty --format agent
composer analyse
```

Expected: zero new Pint/PHPStan findings.

---

### Task 4: Обновить canonical docs и rollout evidence

**Files:**

- Modify: `docs/importer.md`
- Modify: `docs/queues.md`
- Modify: `docs/performance.md`
- Modify:
  `docs/superpowers/plans/2026-07-24-seasonvar-importer-improvement-master-plan.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Preserve:
  `docs/superpowers/specs/2026-07-25-seasonvar-active-run-query-optimization-design.md`

- [x] **Step 1: Обновить importer/queue owners**

Зафиксировать:

- recovery select читает `batchSize + 1`, но dispatch-ит не больше batch;
- sentinel row не меняется;
- finalizer получает projected eager-loaded group без повторного SQL;
- dispatch exception conditional restore сохраняет follow-up;
- legacy claim lane остаётся отдельной.

- [x] **Step 2: Обновить performance owner**

Добавить измеренное evidence:

- baseline `SCAN`, p50 `124,902 ms`, p95 `128,302 ms`;
- clone index build `20,634 s`;
- indexed clone p50 `2,518 ms`, p95 `2,897 ms`;
- observed group N+1 `80–95` reads устранён;
- значения являются локальной диагностикой, не production SLA.

- [x] **Step 3: Обновить master/current plan и compliance matrix**

Отметить Task 48 implementation status по фактическим RED/GREEN/verification
результатам. Production migration activation оставить `unresolved_operator_gate`.
Commit/push оставить `unresolved_shared_worktree`, если clean hook всё ещё
блокируется foreign changes.

- [x] **Step 4: Проверить и обновить README**

В technical importer overview описать bounded indexed recovery. Если изменение
реально доставлено в working application, добавить датированный русский
visitor-result без внутреннего обещания latency: восстановление обновлений
каталога после потери transport выполняет меньше запросов и не дублирует
работу. Не редактировать managed `project-docs` block вручную.

- [x] **Step 5: Добавить отдельный русский CHANGELOG entry**

Запись должна перечислить sentinel, reuse groups, additive index,
RED/GREEN/query-plan evidence и production rollout gate. Прежние записи не
сокращать и не переписывать.

---

### Task 5: Финальная verification и безопасная доставка

**Files:**

- Verify all task-owned files.
- Do not modify `.env`, production SQLite, Redis state, workers or queues.

- [x] **Step 1: Перечитать применимые canonical requirements**

Повторно прочитать `docs/requirements/index.md`, performance, caching,
production operations, maintenance/upgrades, system integration, importer и
queue owners. Сверить каждую строку Task 48 compliance matrix.

- [x] **Step 2: Найти остаточные duplicate/legacy paths**

Run repository searches для:

```bash
rg -n "hasRemainingDueWork|hasRemainingLegacyDueWork|duePreparedPages|seasonvar_prepared_run_status_updated_id_idx" app database tests docs
rg -n "titleGroups\\(\\).*find|signalTitleGroup" app/Services/Seasonvar
```

Проверить dependencies до любого удаления; unrelated legacy не менять.

- [x] **Step 3: Выполнить scoped и broad checks**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/SeasonvarActiveRunReconciliationTest.php
php artisan test tests/Feature/SeasonvarActiveRunQueryPlanTest.php
php artisan test --filter=SeasonvarQueueJobContractTest
php artisan test --filter=Seasonvar
composer analyse
php artisan test
php artisan project:docs-refresh --check --no-interaction
bash scripts/ci-check.sh docs
git diff --check
```

Expected: scoped Seasonvar suite, query plan, queue contract, Pint, PHPStan and
docs checks green. Любой existing unrelated full-suite failure фиксируется
дословно как unresolved и не маскируется.

- [x] **Step 4: Проверить production rollout без mutation**

Не запускать migration на production. Зафиксировать operator checklist:
verified backup/restore, disk+WAL headroom, paused SQLite writers,
`migrate --force`, `quick_check`, `foreign_key_check`, schema/index inventory,
exact `EXPLAIN`, graceful worker restart, bounded canary и resume.

- [x] **Step 5: Commit/push gate**

Run:

```bash
git status --short --branch
```

Commit и обычный push разрешены только в existing `main`, когда exact
task-owned manifest проходит canonical hooks без поглощения foreign changes.
Не использовать `--no-verify`, stash, reset, worktree, alternate branch или
force push. При сохранении общего dirty tree завершить реализацию локально и
честно оставить delivery `unresolved_shared_worktree`.

## Execution result

- Query-plan RED: `SCAN seasonvar_import_prepared_pages`; migration отсутствует.
- Query-count RED: `3` group SELECT вместо `1`, `2` prepared-ledger SELECT
  вместо `1`.
- Focused GREEN: reconciliation `11/62`, query plan/rollback `2/6`, queue
  contract `2/83`.
- Wide Seasonvar GREEN: `283` теста, `1736` утверждений.
- Scoped/dirty-check Pint и `COMPOSER_ALLOW_SUPERUSER=1 composer analyse`
  прошли без ошибок.
- Full PHPUnit: `1644` теста, `1632` успешны, `11` skipped, один прежний
  unrelated account-session failure; отдельный повтор воспроизвёл тот же
  отказ.
- `git diff --check` прошёл. Штатный `project:docs-refresh` добавил task-owned
  migration в managed inventory; повторный `--check` и docs CI прошли.
- README и русский CHANGELOG сверены с фактическим результатом.
- Production migration не запускалась и остаётся `Pending`.
- Git-gate проверен на `main...origin/main [ahead 34]`: staged paths
  отсутствуют, а многочисленные foreign tracked/untracked changes блокируют
  canonical clean-worktree hook. Commit/push не выполняются.
