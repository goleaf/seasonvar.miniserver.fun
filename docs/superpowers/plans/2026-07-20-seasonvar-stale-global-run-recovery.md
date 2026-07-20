# Seasonvar Stale Global Run Recovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Разрешить `php artisan seasonvar:import` автоматически продолжать работу, когда единственной преградой является устаревший queued global run без живых page claims.

**Architecture:** `SeasonvarGlobalImportRunCoordinator` остаётся единственной global single-flight boundary. Под существующим коротким start-lock он сначала применяет уже установленный stale predicate (`running` queued execution, просроченный heartbeat, отсутствие живых claims), переводит такие строки в `failed`, затем повторно выбирает активный run и при его отсутствии резервирует новый. `SeasonvarImportAdminService` делегирует тому же coordinator recovery/count contract, чтобы CLI и admin не расходились.

**Production discovery:** stale recovery alone is insufficient. Run `#944` has no live claims and all 655 title groups are terminal. Its 18,553 media-metadata chunk events represent repeated full traversals of a real 579,334-row backlog, not a broken cursor. Each attempt spent about four minutes on media/local maintenance, reached merge, then the v6 full shadow recommendation build exceeded the 900-second job timeout. A versioned pre-recommendation checkpoint removed the repeated maintenance, but production build `#76` still consumed the full fresh timeout and did not complete. Поэтому конечный queue contract должен не только возобновлять maintenance, но и запрещать unbounded full fallback внутри finalizer: scoped rebuild выполняется, а full requirement возвращает `deferred` с сохранением dirty rows для controlled synchronous maintenance path.

**Tech Stack:** PHP 8.5, Laravel 13.20 atomic cache locks, Eloquent, SQLite, PHPUnit 12.5.

## Global Constraints

- Единственная публичная команда импорта — `php artisan seasonvar:import`.
- Работа выполняется только в существующей ветке `main`; worktree и новая ветка запрещены project requirements.
- Активный run с живым claim нельзя закрывать, даже если heartbeat старый.
- Targeted URL/import inventory/status modes не входят в global sitemap recovery.
- Schema, routes, translations, dependencies, cache keys и lock names не меняются.
- Полные video body не читаются и не сохраняются.

---

### Task 1: Regression contract for the CLI symptom

**Files:**
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`

**Interfaces:**
- Consumes: `seasonvar:import --no-discovery`, `SeasonvarImportRun`, configured `seasonvar.queue.stale_after_minutes`.
- Produces: regression test proving that an old queued global run without a live claim is failed and a new sync run completes.

- [x] **Step 1: Write the failing test**

```php
public function test_sync_command_recovers_a_stale_queued_global_run_without_live_claims(): void
{
    Http::preventStrayRequests();
    config(['seasonvar.queue.stale_after_minutes' => 60]);
    $stale = SeasonvarImportRun::query()->create([
        'mode' => 'sitemap',
        'execution_mode' => 'queue',
        'status' => 'running',
        'started_at' => now()->subHours(3),
        'last_heartbeat_at' => now()->subHours(3),
    ]);

    $this->artisan('seasonvar:import', ['--no-discovery' => true])
        ->expectsOutputToContain('Готово')
        ->assertExitCode(0);

    $this->assertSame('failed', $stale->fresh()->status);
    $this->assertNotNull($stale->finished_at);
    $this->assertSame(1, SeasonvarImportRun::query()
        ->where('mode', 'sitemap')
        ->where('execution_mode', 'sync')
        ->where('status', 'completed')
        ->count());
}
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_sync_command_recovers_a_stale_queued_global_run_without_live_claims`

Expected: FAIL because the command reports the stale run as active and does not create a sync run.

### Task 2: Canonical stale recovery under the start lock

**Files:**
- Modify: `app/Services/Seasonvar/SeasonvarGlobalImportRunCoordinator.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportAdminService.php`
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`

**Interfaces:**
- Consumes: `SeasonvarImportRun::claimedSourcePages()`, `seasonvar.queue.stale_after_minutes`, existing global start-lock.
- Produces: `SeasonvarGlobalImportRunCoordinator::recoverStale(): int` and `staleCount(): int`; unchanged `acquire()` / `acquireSync()` result DTO.

- [x] **Step 1: Add the live-claim safety test**

```php
public function test_global_start_preserves_a_stale_heartbeat_run_with_a_live_claim(): void
{
    config([
        'seasonvar.queue.lock_store' => 'array',
        'seasonvar.queue.stale_after_minutes' => 60,
    ]);
    $run = SeasonvarImportRun::query()->create([
        'mode' => 'sitemap',
        'execution_mode' => 'queue',
        'status' => 'running',
        'started_at' => now()->subHours(3),
        'last_heartbeat_at' => now()->subHours(3),
    ]);
    $page = SourcePage::factory()->create();
    $this->assertNotNull(app(SeasonvarPageClaimManager::class)->claim($page, $run->id, 3600));

    $result = app(SeasonvarGlobalImportRunCoordinator::class)->acquireSync(false, false);

    $this->assertFalse($result->created);
    $this->assertTrue($result->run->is($run));
    $this->assertSame('running', $run->fresh()->status);
}
```

- [x] **Step 2: Implement one stale predicate in the coordinator**

```php
public function recoverStale(): int
{
    return $this->staleRuns()->update([
        'status' => SeasonvarImportStatus::Failed->value,
        'last_error' => 'Запуск остановлен автоматически: heartbeat давно не обновлялся и активных задач не осталось.',
        'finished_at' => now(),
        'updated_at' => now(),
    ]);
}

public function staleCount(): int
{
    return $this->staleRuns()->count();
}
```

Call `recoverStale()` inside both existing start-lock closures immediately before `activeRun()`. Move the exact stale query from `SeasonvarImportAdminService` into the coordinator, and make admin recovery/dashboard delegate to `recoverStale()` / `staleCount()`.

- [x] **Step 3: Run focused tests**

Run: `php artisan test --filter='SeasonvarImportMaintenanceTest|SeasonvarParallelImportTest'`

Expected: PASS, including stale recovery and live-claim preservation.

### Task 3: Make the media metadata backlog finite

**Files:**
- Modify: `app/Services/Seasonvar/SeasonvarImportPipeline.php`
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`

**Interfaces:**
- Consumes: existing nullable media metadata and the current global-finalizer stage.
- Produces: a convergent candidate set containing only rows missing deterministic format/variant identity; optional unknown quality/translation values remain honest `null` and no longer make every full import rescan them.

- [x] **Step 1: Add a failing finite-candidate test**

Create several media rows whose required format/variant identity is complete but optional `quality` and `translation_name` are legitimately unknown, plus one row with missing required format/variant identity. Run `seasonvar:import --no-discovery` and assert `last_media_metadata_backlog.media_checked` equals one, while the required fields are repaired.

- [x] **Step 2: Restrict the backlog predicate**

Remove standalone `quality IS NULL` and `translation_name IS NULL` eligibility. They are optional facts and `ExternalMediaMetadata` can legitimately return `null`; keeping them in the predicate makes the same unfillable rows eligible forever. Selected rows still receive opportunistic quality/translation inference together with deterministic format and playback variant fields.

- [x] **Step 3: Run focused regression tests**

Run:

```bash
php artisan test --filter='test_media_metadata_backfill_skips_optional_unknowns|test_it_backfills_all_missing_media_metadata_across_chunks'
```

Expected: PASS and every selected row leaves the deterministic backlog after one successful update.

### Task 4: Resume bounded queued finalization after a hard timeout

**Files:**
- Modify: `app/Services/Seasonvar/SeasonvarImportPipeline.php`
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`

**Interfaces:**
- Consumes: the current run summary and bounded results of maintenance/media/cleanup/merge stages.
- Produces: a versioned `queued_finalization_checkpoint` used only by the same run; bounded recommendation activation and all queue/lock names remain unchanged.

- [x] **Step 1: Add a failing resume test**

Seed a complete version-1 checkpoint and a media row that would be changed if maintenance ran again. Verify RED first, then require the finalizer to skip metadata maintenance, preserve checkpoint counters in the public summary, complete the bounded recommendation handoff, and remove the internal checkpoint.

- [x] **Step 2: Persist and validate the checkpoint before recommendations**

Store only bounded stage result arrays after merge. Accept only a complete checkpoint with the current exact version; malformed or foreign versions repeat authoritative maintenance. Remove the internal key on normal terminal success/failure, while a hard worker kill naturally leaves it for the next delivery.

- [x] **Step 3: Verify first-attempt ordering and retry compatibility**

Confirm the checkpoint is visible before the recommendation builder handoff and absent after completion. Preserve the existing global finalizer lock, retry window, 900-second timeout, shadow build, quality gate, active rows, cache invalidation, and dirty-title ownership.

- [x] **Step 4: Make the recommendation handoff terminal under queue timeout**

First write a RED test requiring a queued run without compatible active v6 to emit `catalog-title-recommendations-full-rebuild-deferred`, retain the checkpoint until that handoff, then complete and remove the internal checkpoint. Call `CatalogTitleRecommendationBuilder::rebuildDirty(..., allowFullRebuild: false)` only from `finalizeQueuedRun()`. This preserves scoped rebuilds, active rows, quality gate and dirty IDs; synchronous `seasonvar:import` remains the controlled owner of a catalog-wide full shadow build.

### Task 5: Documentation, verification, and delivery evidence

**Files:**
- Modify: `docs/importer.md`
- Modify: `docs/queues.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: final verified behavior and project documentation policy.
- Produces: canonical recovery contract, Russian technical history, visitor-visible command reliability note, completed compliance evidence.

- [x] **Step 1: Document the behavior and rollback**

Record that every global start performs bounded stale reconciliation under the existing start-lock, while a live claim preserves the old run. Rollback reverts code/docs only; no schema, cache flush, queue clear, or data restore is required.

- [x] **Step 2: Run formatting and focused verification**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter='SeasonvarImportMaintenanceTest|SeasonvarParallelImportTest'
php artisan test --filter='RunSeasonvarImportJobTest|SeasonvarQueueStatusTest'
php artisan project:docs-refresh --check
git diff --check
```

Evidence: exact task matrix after the bounded recommendation handoff passed 128 tests / 701 assertions; maintenance/recommendation subset passed 60 / 340; full suite passed 1,407 tests with 11 expected skips and 122,916 assertions. Pint, managed-doc check and `git diff --check` passed.

- [x] **Step 3: Verify the real stale row no longer blocks**

After tests, invoke the existing recovery boundary for the confirmed stale row only through application code, then verify with a read-only query that run `#944` is terminal and no live claims were overwritten. Do not start provider HTTP merely to prove cleanup.

Evidence 20.07.2026: run `#944` завершился `completed`, checkpoint удалён, recommendation mode=`deferred`, live claims отсутствуют. Новый bounded XML-tail run `#954` после этого был принят canonical single-flight boundary и также завершился `completed`, что подтверждает отсутствие повторной ложной блокировки.

- [ ] **Step 4: Commit and push if repository state permits**

Before commit, run `git status --short --branch`. Stage only task-owned changes. If the pre-existing mixed staged/unstaged snapshot prevents an isolated commit without altering other work, leave commit/push `unresolved` and report the blocker instead of rewriting the index.
