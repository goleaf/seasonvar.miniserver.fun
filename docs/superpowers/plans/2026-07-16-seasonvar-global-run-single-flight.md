# Seasonvar Global Run Single-Flight Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans inline. Project `AGENTS.md` forbids branches, worktrees and subagent delegation; execute only on existing `main`. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent full synchronous and queued Seasonvar sitemap imports from running concurrently while preserving targeted URL refreshes.

**Architecture:** Extend the existing `SeasonvarGlobalImportRunCoordinator` into the shared short-lived start boundary for both execution modes. It atomically checks all active sitemap runs and reserves a correctly shaped sync run before the pipeline begins; the pipeline may execute that reserved run without creating a duplicate.

**Tech Stack:** PHP 8.5, Laravel 13.20, Eloquent, Laravel atomic cache locks, PHPUnit 12.5.

## Global Constraints

- Work only on existing `main`; do not create a branch or worktree.
- Preserve `php artisan seasonvar:import` as the only public importer command.
- Do not stop workers, retry/forget failed jobs, mutate existing production run state or edit `.env`.
- Do not block targeted URL import, status or inventory-only paths.
- Keep provider HTTP outside database transactions and keep the coordinator lock bounded to active check plus insert.
- Follow RED → GREEN for each behavior and run Pint after PHP changes.

---

### Task 1: Cross-mode lifecycle reservation

**Files:**
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`
- Modify: `app/Services/Seasonvar/SeasonvarGlobalImportRunCoordinator.php`

**Interfaces:**
- Consumes: existing `SeasonvarGlobalImportRunCoordinator::acquire()` and `SeasonvarImportRun` lifecycle fields.
- Produces: `SeasonvarGlobalImportRunCoordinator::acquireSync(bool $force, bool $forever, ?int $processId, ?string $processHost, ?string $processCommand): SeasonvarImportStartResultData` and cross-mode `activeRun()`.

- [x] **Step 1: Write failing cross-mode tests**

Add tests which create an active sync sitemap run and assert queued `acquire()` returns it with `created=false`, then call `acquireSync()` twice and assert only one active sitemap row exists. Also assert an active `mode=url` row does not block either path.

```php
$sync = SeasonvarImportRun::query()->create([
    'mode' => 'sitemap',
    'execution_mode' => 'sync',
    'status' => 'running',
    'started_at' => now(),
    'last_heartbeat_at' => now(),
]);

$result = app(SeasonvarGlobalImportRunCoordinator::class)->acquire(false, false);

$this->assertFalse($result->created);
$this->assertTrue($result->run->is($sync));
```

- [x] **Step 2: Run the focused tests and confirm RED**

Run:

```bash
php artisan test tests/Feature/SeasonvarParallelImportTest.php --filter='cross_mode|sync_reservation'
```

Expected: failure because queued active lookup ignores sync rows and `acquireSync()` does not exist.

- [x] **Step 3: Implement the shared reservation**

Add `acquireSync()` using the existing `startLock()` and create this row only when `activeRun()` is null:

```php
SeasonvarImportRun::query()->create([
    'mode' => 'sitemap',
    'execution_mode' => 'sync',
    'status' => SeasonvarImportStatus::Running->value,
    'force' => $force,
    'forever' => $forever,
    'process_id' => $processId,
    'process_host' => $processHost,
    'process_command' => $processCommand,
    'started_at' => now(),
    'last_heartbeat_at' => now(),
]);
```

Remove the execution-mode predicate from the private active sitemap query; retain `mode=sitemap` and `queued|running` status allowlist.

- [x] **Step 4: Run the focused tests and confirm GREEN**

Run the Task 1 filter again. Expected: PASS.

---

### Task 2: CLI and legacy job integration

**Files:**
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`
- Modify: `app/Console/Commands/ImportSeasonvar.php`
- Modify: `app/Jobs/RunSeasonvarImport.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportPipeline.php`

**Interfaces:**
- Consumes: Task 1 `acquireSync()`.
- Produces: optional `SeasonvarImportRun $reservedRun = null` final parameter on `SeasonvarImportPipeline::run()`; full sitemap callers pass the reservation, targeted callers keep the current call contract.

- [x] **Step 1: Write failing CLI/job tests**

Create an active queued sitemap run, replace `SeasonvarImportPipeline` with a mock that must not receive `run()`, and execute a full sync command. Assert success, a localized reuse notice, no sync row and no new run. Add the inverse legacy-job case. Add a regression asserting an active global run does not block a URL-targeted pipeline call.

```php
$pipeline = Mockery::mock(SeasonvarImportPipeline::class);
$pipeline->shouldNotReceive('run');
$this->app->instance(SeasonvarImportPipeline::class, $pipeline);

$this->artisan('seasonvar:import', ['--no-discovery' => true])
    ->expectsOutputToContain('Активный глобальный запуск')
    ->assertExitCode(0);
```

- [x] **Step 2: Run the focused tests and confirm RED**

Run:

```bash
php artisan test tests/Feature/SeasonvarParallelImportTest.php --filter='full_sync|legacy_sync_job|targeted_url'
```

Expected: full sync reaches the pipeline despite an active queued run.

- [x] **Step 3: Execute reserved sync runs**

In the CLI, after the existing process lock is acquired and before process metadata is stored, reserve only when `argument('url')` is null. If `created=false`, print one safe Russian message and return success. Pass the created run as `reservedRun` to the pipeline.

In `RunSeasonvarImport`, apply the same reservation only when `$argument === null`. If an active global run is returned, complete the wrapper job without releasing/retrying itself. Targeted URL jobs retain the existing lock/pipeline path.

In the pipeline, use a supplied reservation instead of creating a second row. Validate that it is a running sync sitemap run; otherwise throw `LogicException` before provider work.

- [x] **Step 4: Run focused and importer regression tests**

Run:

```bash
php artisan test tests/Feature/SeasonvarParallelImportTest.php
php artisan test --filter=SeasonvarImport
```

Expected: PASS with no outbound request added to lifecycle tests.

---

### Task 3: Documentation and completion gates

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/importer.md`
- Modify: `docs/queues.md`
- Modify: `docs/plans/laravel-video-portal-modernization.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: final lifecycle behavior from Tasks 1–2.
- Produces: operator contract explaining cross-mode single-flight and explicit non-mutation of currently overlapping historical runs.

- [x] **Step 1: Update owner documentation**

Document the short start-lock, active sitemap scope across both execution modes, targeted URL exception, safe reuse output and rolling-deploy limitation. Record the production evidence without process command, host or provider URL.

- [x] **Step 2: Format and statically verify changed PHP**

Run:

```bash
./vendor/bin/pint --dirty --format agent
./vendor/bin/phpstan analyse app/Console/Commands/ImportSeasonvar.php app/Jobs/RunSeasonvarImport.php app/Services/Seasonvar/SeasonvarGlobalImportRunCoordinator.php app/Services/Seasonvar/SeasonvarImportPipeline.php --no-progress
```

Expected: zero formatting or Larastan failures.

- [ ] **Step 3: Run broad verification**

Run focused tests, broader importer tests, then full `php artisan test`, `php artisan project:docs-refresh --check`, `git diff --check` and PHP syntax checks for changed files. Expected: all pass except documented existing skips.

- [ ] **Step 4: Review, commit and push**

Confirm `main`, inspect every scoped diff, stage only the files in this plan, and ensure no Recommendation V3 or Content Requests file enters the commit. Commit with `fix: prevent overlapping global imports`, push `main`, fetch and verify local/remote commit identity.
