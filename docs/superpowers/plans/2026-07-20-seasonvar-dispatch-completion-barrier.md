# Seasonvar Dispatch Completion Barrier Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Не позволять global queued import завершаться до окончания выбора/dispatch страниц и безопасно возобновить преждевременно завершённый production run `#964` через application service.

**Architecture:** Новый global queued run получает durable `summary.dispatch_completed=false`; dispatcher атомарно объединяет актуальный summary и меняет флаг на `true` только после полного fan-out. Global finalizer блокируется только на явном `false`, сохраняя compatibility для legacy rows без ключа. Идемпотентный recovery service повторно открывает только подтверждённый premature-terminal run под тем же барьером, повторно dispatch-ит его nonterminal staging jobs и лишь затем разрешает обычную финализацию.

**Execution reconciliation:** При implementation recovery transaction/eligibility перенесены из нового service в существующий `SeasonvarGlobalImportRunCoordinator::resumePrematurelyFinalized()`, чтобы переиспользовать canonical distributed start-lock и global single-flight. `SeasonvarPrematurelyFinalizedRunRecovery` оставлен узкой fan-out boundary; он повторно ставит persisted work и открывает barrier. Empty dispatch сохраняет marker и terminal status одной row-locked transaction. Это усиливает plan без изменения его public/schema/dependency scope.

**Tech Stack:** PHP 8.5, Laravel 13.20, Eloquent transactions/row locks, Redis queue uniqueness, SQLite-compatible PHPUnit 12.5 tests.

## Global Constraints

- Работать только в существующей ветке `main`; не создавать branch/worktree/PR.
- Не добавлять migration или dependency; marker хранится только в существующем JSON-cast поле `seasonvar_import_runs.summary`.
- `php artisan seasonvar:import` остаётся единственной публичной командой импорта.
- Не выполнять `queue:clear`, `cache:clear`, direct production SQL или удаление claims/staging rows.
- Отсутствующий `dispatch_completed` сохраняет legacy finalizer behavior; только строгое boolean `false` блокирует завершение.
- Marker `true` не обходит существующие gates live claims, nonterminal title groups и global finalizer lock.
- Summary обновляется под database row lock с merge от свежего persisted значения, чтобы concurrent counters/checkpoints не терялись.
- Recovery `#964` выполняется только после code verification, отсутствия другого active global run и safe read-only preflight.
- README меняется только осмысленно; `CHANGELOG.md` и обычный README-текст остаются на русском языке.

---

### Task 1: RED regressions for the durable lifecycle marker

**Files:**
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`

**Interfaces:**
- Consumes: существующие `SeasonvarGlobalImportRunCoordinator::acquire()`, `SeasonvarQueuedImportDispatcher::dispatch()` и `FinalizeSeasonvarQueuedImport::handle()`.
- Produces: regressions для initial `false`, final `true`, legacy missing-marker compatibility и explicit-false finalizer barrier.

- [x] **Step 1: Add the finalizer race regression**

Добавить test, который создаёт global queued run без claims/groups, но с незавершённым dispatch:

```php
public function test_global_finalizer_waits_until_page_dispatch_is_completed(): void
{
    config(['seasonvar.queue.lock_store' => 'array']);
    $run = $this->queuedRun();
    $run->update(['summary' => ['dispatch_completed' => false]]);
    $pipeline = Mockery::mock(SeasonvarImportPipeline::class);
    $pipeline->shouldNotReceive('finalizeQueuedRun');
    $job = (new FinalizeSeasonvarQueuedImport($run->id))->withFakeQueueInteractions();

    $job->handle(
        app(SeasonvarPageClaimManager::class),
        $pipeline,
        app(SeasonvarImportRunRecorder::class),
        app(CatalogCacheInvalidator::class),
    );

    $this->assertSame('running', $run->fresh()->status);
    $this->assertNotNull($run->fresh()->last_heartbeat_at);
    $job->assertNotReleased();
}
```

- [x] **Step 2: Add coordinator/dispatcher lifecycle assertions**

В existing dispatcher test проверить, что persisted run после полного dispatch имеет `dispatch_completed=true`. Добавить zero-selection test, который через `SeasonvarGlobalImportRunCoordinator::acquire()` сначала видит `false`, затем вызывает `dispatchRun()` и проверяет terminal run с `true`:

```php
public function test_empty_queued_dispatch_records_the_completed_barrier_before_finishing(): void
{
    config(['seasonvar.queue.lock_store' => 'array']);
    Queue::fake();
    $result = app(SeasonvarGlobalImportRunCoordinator::class)->acquire(
        force: false,
        discover: false,
    );

    $this->assertFalse(data_get($result->run->summary, 'dispatch_completed'));

    $run = app(SeasonvarQueuedImportDispatcher::class)->dispatchRun($result->run);

    $this->assertSame('completed', $run->status);
    $this->assertTrue(data_get($run->summary, 'dispatch_completed'));
    $this->assertSame(0, $run->selected);
}
```

Existing `test_finalizer_completes_run_after_all_claims_are_released` остаётся legacy assertion: helper `queuedRun()` не получает marker, и pipeline обязан вызываться.

- [x] **Step 3: Run RED tests**

Run:

```bash
php artisan test tests/Feature/SeasonvarParallelImportTest.php --filter='test_global_finalizer_waits_until_page_dispatch_is_completed|test_empty_queued_dispatch_records_the_completed_barrier_before_finishing|test_dispatcher_queues_each_eligible_page_once_across_repeated_runs'
```

Expected: FAIL на explicit-false finalizer test и initial/final marker assertions, подтверждая отсутствие barrier implementation.

---

### Task 2: Atomic dispatch barrier implementation

**Files:**
- Modify: `app/Services/Seasonvar/SeasonvarGlobalImportRunCoordinator.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportRunRecorder.php`
- Modify: `app/Services/Seasonvar/SeasonvarQueuedImportDispatcher.php`
- Modify: `app/Jobs/FinalizeSeasonvarQueuedImport.php`
- Test: `tests/Feature/SeasonvarParallelImportTest.php`

**Interfaces:**
- Produces: `SeasonvarImportRunRecorder::mergeSummary(int $runId, array $values): ?SeasonvarImportRun`.
- Consumes: existing JSON `SeasonvarImportRun::$summary`, status strings and `SeasonvarPageClaimManager` gates.

- [x] **Step 1: Create every new global queued run with an explicit closed barrier**

В summary внутри `SeasonvarGlobalImportRunCoordinator::acquire()` добавить:

```php
'dispatch_completed' => false,
```

Sync и targeted `mode=url` runs не менять.

- [x] **Step 2: Add an atomic summary merge boundary**

В `SeasonvarImportRunRecorder` добавить typed method:

```php
/**
 * @param  array<string, mixed>  $values
 */
public function mergeSummary(int $runId, array $values): ?SeasonvarImportRun
{
    return DB::transaction(function () use ($runId, $values): ?SeasonvarImportRun {
        $run = SeasonvarImportRun::query()->lockForUpdate()->find($runId);

        if ($run === null) {
            return null;
        }

        $run->summary = array_merge($run->summary ?? [], $values);
        $run->last_heartbeat_at = now();
        $run->save();

        return $run->fresh();
    }, 3);
}
```

Добавить test, который сохраняет `['concurrent_key' => 'preserved']`, вызывает `mergeSummary($id, ['dispatch_completed' => true])` и проверяет оба ключа.

- [x] **Step 3: Open the barrier only after complete fan-out**

В `SeasonvarQueuedImportDispatcher::dispatchRun()` заменить stale model summary assignment/save на recorder merge после `dispatchEligiblePages()` и counter update:

```php
$run = $this->runs->mergeSummary($run->id, [
    'expired_claims_recovered' => $recovered,
    'queued_pages' => $selected,
    'sitemap_tail_selected' => $sitemapTailUrls !== null ? count($sitemapTailUrls) : null,
    'dispatch_completed' => true,
]) ?? $run->fresh();

if ($run->status !== SeasonvarImportStatus::Running->value) {
    return $run->refresh();
}
```

Для `selected===0` terminal update выполняется только после этого merge. Для `selected>0` dispatcher больше не выполняет отдельный stale `$run->save()` перед delayed global finalizer.

- [x] **Step 4: Gate both finalizer checks on explicit false**

В `FinalizeSeasonvarQueuedImport` до claims/groups checks и повторно после global lock refresh добавить:

```php
if ($this->dispatchIsIncomplete($run)) {
    $runs->heartbeat($run->id);

    return;
}
```

Добавить private helper:

```php
private function dispatchIsIncomplete(SeasonvarImportRun $run): bool
{
    return data_get($run->summary, 'dispatch_completed') === false;
}
```

- [x] **Step 5: Run GREEN focused tests and format PHP**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/SeasonvarParallelImportTest.php --filter='dispatch|finalizer|global'
```

Expected: all selected tests PASS; explicit `false` blocks, missing key remains compatible, dispatcher persists `true`.

- [ ] **Step 6: Commit the independently verified barrier**

```bash
git status --short --branch
git add app/Jobs/FinalizeSeasonvarQueuedImport.php app/Services/Seasonvar/SeasonvarGlobalImportRunCoordinator.php app/Services/Seasonvar/SeasonvarImportRunRecorder.php app/Services/Seasonvar/SeasonvarQueuedImportDispatcher.php tests/Feature/SeasonvarParallelImportTest.php
git commit -m "fix: guard queued import dispatch completion"
```

Expected: commit hook passes on `main`; no unrelated files are staged.

---

### Task 3: Idempotent application recovery for premature terminal runs

**Files:**
- Create: `app/Services/Seasonvar/SeasonvarPrematurelyFinalizedRunRecovery.php`
- Modify: `app/Services/Seasonvar/SeasonvarGlobalImportRunCoordinator.php`
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`

**Interfaces:**
- Consumes: `SeasonvarGlobalImportRunCoordinator::resumePrematurelyFinalized(int $runId): ?SeasonvarImportRun`, `SeasonvarImportRunRecorder::mergeSummary()`, `PrepareSeasonvarImportTitlePage`, `SeasonvarImportFinalizationDispatcher::signalTitleGroup()` and `signalGlobalRun()`.
- Produces: `SeasonvarPrematurelyFinalizedRunRecovery::recover(int $runId): bool`; no route, command option or admin control is added.

- [x] **Step 1: Write the failing recovery test**

Создать completed sitemap/queue run с preserved summary key, `finished_at`, одной running title group, одной queued prepared row и live exact-run claim. Вызвать отсутствующий service и проверить:

```php
$recovered = app(SeasonvarPrematurelyFinalizedRunRecovery::class)->recover($run->id);

$this->assertTrue($recovered);
$this->assertSame('running', $run->fresh()->status);
$this->assertNull($run->fresh()->finished_at);
$this->assertTrue(data_get($run->fresh()->summary, 'dispatch_completed'));
$this->assertSame('kept', data_get($run->fresh()->summary, 'existing_key'));
Queue::assertPushed(
    PrepareSeasonvarImportTitlePage::class,
    fn (PrepareSeasonvarImportTitlePage $job): bool => $job->preparedPageId === $prepared->id,
);
Queue::assertPushed(
    FinalizeSeasonvarImportTitleGroup::class,
    fn (FinalizeSeasonvarImportTitleGroup $job): bool => $job->groupId === $group->id,
);
Queue::assertPushed(
    FinalizeSeasonvarQueuedImport::class,
    fn (FinalizeSeasonvarQueuedImport $job): bool => $job->importRunId === $run->id,
);
```

Добавить guard assertion: обычный completed run без nonterminal prepared rows и live claims возвращает `false` и не меняется.

- [x] **Step 2: Run the recovery RED test**

Run:

```bash
php artisan test tests/Feature/SeasonvarParallelImportTest.php --filter='prematurely_finalized'
```

Expected: FAIL because `SeasonvarPrematurelyFinalizedRunRecovery` does not exist.

- [x] **Step 3: Implement guarded, repeatable recovery**

Создать final service со следующим полным contract (namespace/imports соответствуют указанным классам):

```php
final class SeasonvarPrematurelyFinalizedRunRecovery
{
    public function __construct(
        private readonly SeasonvarImportRunRecorder $runs,
        private readonly SeasonvarImportFinalizationDispatcher $finalizers,
    ) {}

    public function recover(int $runId): bool
    {
        $run = DB::transaction(function () use ($runId): ?SeasonvarImportRun {
            $run = SeasonvarImportRun::query()->lockForUpdate()->find($runId);

            if (! $this->isRecoverable($run)) {
                return null;
            }

            $summary = $run->summary ?? [];
            $recovery = is_array(data_get($summary, 'premature_finalization_recovery'))
                ? data_get($summary, 'premature_finalization_recovery')
                : [];
            $run->fill([
                'status' => SeasonvarImportStatus::Running->value,
                'finished_at' => null,
                'last_error' => null,
                'last_heartbeat_at' => now(),
                'summary' => array_merge($summary, [
                    'dispatch_completed' => false,
                    'premature_finalization_recovery' => array_merge($recovery, [
                        'started_at' => $recovery['started_at'] ?? now()->toIso8601String(),
                    ]),
                ]),
            ])->save();

            return $run->fresh();
        }, 3);

        if ($run === null) {
            return false;
        }

        $requeued = $this->dispatchPreparedPages($run);
        $groups = $this->signalTitleGroups($run);
        $run = $this->runs->mergeSummary($run->id, [
            'dispatch_completed' => true,
            'premature_finalization_recovery' => array_merge(
                (array) data_get($run->fresh()->summary, 'premature_finalization_recovery', []),
                [
                    'dispatch_completed_at' => now()->toIso8601String(),
                    'prepared_pages_requeued' => $requeued,
                    'title_groups_signalled' => $groups,
                ],
            ),
        ]);

        if ($run !== null) {
            $this->finalizers->signalGlobalRun($run);
        }

        return true;
    }

    private function isRecoverable(?SeasonvarImportRun $run): bool
    {
        if ($run === null
            || $run->mode !== 'sitemap'
            || $run->execution_mode !== 'queue'
        ) {
            return false;
        }

        $isInterruptedRecovery = $run->status === SeasonvarImportStatus::Running->value
            && data_get($run->summary, 'dispatch_completed') === false
            && is_array(data_get($run->summary, 'premature_finalization_recovery'));

        if ($isInterruptedRecovery) {
            return true;
        }

        if (! in_array($run->status, [
            SeasonvarImportStatus::Completed->value,
            SeasonvarImportStatus::Partial->value,
        ], true)) {
            return false;
        }

        $hasNonterminalPages = $run->preparedPages()
            ->whereIn('status', [
                SeasonvarPreparedPageStatus::Queued->value,
                SeasonvarPreparedPageStatus::Preparing->value,
            ])
            ->exists();
        $hasActiveGroups = $run->titleGroups()
            ->whereIn('status', [
                SeasonvarImportTitleGroupStatus::Discovering->value,
                SeasonvarImportTitleGroupStatus::Running->value,
                SeasonvarImportTitleGroupStatus::Finalizing->value,
            ])
            ->exists();
        $hasLiveClaims = $run->claimedSourcePages()
            ->whereNotNull('import_claim_token')
            ->where('import_claim_expires_at', '>', now())
            ->exists();

        return $hasNonterminalPages || $hasActiveGroups || $hasLiveClaims;
    }

    private function dispatchPreparedPages(SeasonvarImportRun $run): int
    {
        $requeued = 0;

        SeasonvarImportPreparedPage::query()
            ->select(['id', 'seasonvar_import_title_group_id'])
            ->with('group:id,queue_name')
            ->where('seasonvar_import_run_id', $run->id)
            ->whereIn('status', [
                SeasonvarPreparedPageStatus::Queued->value,
                SeasonvarPreparedPageStatus::Preparing->value,
            ])
            ->orderBy('id')
            ->chunkById(100, function ($pages) use (&$requeued): void {
                foreach ($pages as $page) {
                    PrepareSeasonvarImportTitlePage::dispatch((int) $page->id)
                        ->onConnection((string) config('seasonvar.queue.connection', 'redis'))
                        ->onQueue((string) $page->group->queue_name)
                        ->afterCommit();
                    $requeued++;
                }
            });

        return $requeued;
    }

    private function signalTitleGroups(SeasonvarImportRun $run): int
    {
        $signalled = 0;

        SeasonvarImportTitleGroup::query()
            ->where('seasonvar_import_run_id', $run->id)
            ->whereIn('status', [
                SeasonvarImportTitleGroupStatus::Discovering->value,
                SeasonvarImportTitleGroupStatus::Running->value,
                SeasonvarImportTitleGroupStatus::Finalizing->value,
            ])
            ->orderBy('id')
            ->chunkById(100, function ($groups) use (&$signalled): void {
                foreach ($groups as $group) {
                    $signalled += $this->finalizers->signalTitleGroup($group) ? 1 : 0;
                }
            });

        return $signalled;
    }
}
```

Подключить imports `SeasonvarImportStatus`, `SeasonvarImportTitleGroupStatus`, `SeasonvarPreparedPageStatus`, `PrepareSeasonvarImportTitlePage`, `SeasonvarImportPreparedPage`, `SeasonvarImportRun`, `SeasonvarImportTitleGroup` и `DB`. Любое исключение до final summary merge оставляет durable `false`, поэтому повторный вызов безопасно повторяет unique jobs.

- [x] **Step 4: Run recovery and neighboring lifecycle tests**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/SeasonvarParallelImportTest.php
php artisan test tests/Feature/SeasonvarImportMaintenanceTest.php
```

Expected: both files PASS; normal retry/cancel/stale recovery behavior remains unchanged.

- [ ] **Step 5: Commit recovery boundary**

```bash
git status --short --branch
git add app/Services/Seasonvar/SeasonvarPrematurelyFinalizedRunRecovery.php tests/Feature/SeasonvarParallelImportTest.php
git commit -m "fix: recover premature queued import completion"
```

Expected: commit hook passes on `main`; recovery has no public route/command/schema change.

---

### Task 4: Production recovery, broad verification and delivery evidence

**Files:**
- Modify: `docs/importer.md`
- Modify: `docs/queues.md`
- Modify: `docs/requirements/production-operations.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `docs/superpowers/specs/2026-07-20-seasonvar-dispatch-completion-barrier-design.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: verified barrier and `SeasonvarPrematurelyFinalizedRunRecovery::recover(964)`.
- Produces: production evidence with terminal run, zero exact-run claims/nonterminal groups, live calendar freshness and synchronized `origin/main`.

- [x] **Step 1: Verify repository and production preflight**

Run read-only checks:

```bash
git status --short --branch
php artisan seasonvar:import --status
php artisan app:deployment-check --json
```

Через bounded Tinker query подтвердить exact `#964`, отсутствие другого active global run, counts live claims и nonterminal staging/groups. Не выводить raw source URLs, secrets или environment values.

- [ ] **Step 2: Publish the verified code and reload workers safely**

После focused tests и clean `main` push commits без force. Когда active queue jobs отсутствуют, выполнить documented graceful `php artisan queue:restart`; systemd/watchdog должны поднять workers без очистки Redis.

- [x] **Step 3: Recover exactly run `#964` through the service**

Invoke:

```bash
php artisan tinker --execute='var_export(app(\App\Services\Seasonvar\SeasonvarPrematurelyFinalizedRunRecovery::class)->recover(964));'
```

Expected: `true`. Повторно проверить, что run `running`, `dispatch_completed=true`, jobs обрабатывают только его nonterminal prepared rows, а claims остаются exact-owned до штатного release.

- [ ] **Step 4: Monitor canonical completion**

Периодически выполнять `php artisan seasonvar:import --status` и bounded aggregate queries до terminal состояния. Success criteria:

- `#964` terminal через normal finalizer;
- exact-run live claims `0`;
- active title groups `0`;
- nonterminal prepared rows `0`;
- `parsed + failed >= selected` либо documented idempotent terminal-row reconciliation объясняет exact difference;
- queue failed delta для recovery jobs `0`;
- `/calendar` остаётся HTTP 200 и содержит актуальные provider events.

- [x] **Step 5: Run code/document verification**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/SeasonvarParallelImportTest.php
php artisan test tests/Feature/SeasonvarImportMaintenanceTest.php
php artisan test --filter='SeasonvarReleaseStatus|ReleaseCalendar|SeasonvarQueuedImport'
php artisan test
php artisan project:docs-refresh --check
git diff --check
```

Expected: Pint clean; focused and full suites PASS with only pre-existing documented skips; managed docs and whitespace checks PASS.

- [x] **Step 6: Complete owners, compliance and visitor documentation**

Документировать marker lifecycle, recovery retryability, no-clear restriction, exact production evidence and rollback. Добавить отдельный русский `CHANGELOG.md` пункт. Проверить README: если visitor-facing freshness стала надёжнее, добавить краткую датированную строку без internal class names; иначе зафиксировать review только в current plan. Сохранить `## История обновлений для посетителей` последним H2.

- [ ] **Step 7: Search legacy paths and finalize Git delivery**

Run:

```bash
rg -n "dispatch_completed|FinalizeSeasonvarQueuedImport|SeasonvarPrematurelyFinalizedRunRecovery|status.*completed" app tests routes docs README.md CHANGELOG.md
git status --short --branch
git diff --cached --check
git log -5 --oneline --decorate
```

Review every match for stale direct finalization, duplicate recovery or summary overwrite; text matches alone are not deletion evidence. Commit only task files on `main`, push without force, then verify `git rev-parse HEAD` equals `git rev-parse origin/main` and the worktree is clean.

## Self-Review Evidence

- Spec coverage: marker creation, atomic transition, two finalizer gates, legacy behavior, error/retry, zero-selection, recovery, production safety, rollback and cross-feature verification each map to Tasks 1–4.
- Placeholder scan: plan contains no `TBD`, `TODO`, “implement later”, incomplete handler or undefined later interface.
- Type consistency: `mergeSummary(int, array): ?SeasonvarImportRun` and `recover(int): bool` are used consistently; marker path is always `summary.dispatch_completed`.
- Scope: no migration, dependency, public route, API, frontend asset, queue/cache clear or second importer command is introduced.
