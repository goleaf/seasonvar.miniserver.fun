# Seasonvar Bounded Batch Dispatch Query Optimization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Снизить стоимость регистрации 100 serial-страниц с измеренных 1 239 SQL-запросов до не более 120, сохранив точную идемпотентность, crash-resume, queue contracts и текущую семантику каталога Seasonvar.

**Architecture:** `SeasonvarQueuedImportDispatcher` выполняет discovery и только один bounded batch; `SeasonvarImportDispatchBatcher` регистрирует максимум `min(100, seasonvar.import.chunk_size)` страниц, используя unique run/page prepared-ledger как authority возобновления. Serial claims, groups, prepared rows и агрегированные counters фиксируются в одной короткой retry-aware transaction; Redis jobs отправляются только после commit. `SeasonvarActiveRunReconciler` продолжает incomplete dispatch и due outbox, а `PrepareSeasonvarImportTitlePage` начинает provider HTTP только после database CAS `queued -> preparing`.

**Tech Stack:** PHP 8.5, Laravel 13.22, Eloquent/query builder, SQLite/MySQL/PostgreSQL-compatible SQL, Laravel queues, PHPUnit 12.5, Pint 1.29.

Execution status: `inline_tdd_execution_in_progress` (user selected the inline
path on 25.07.2026).

## Global Constraints

- Работать только в существующей ветке `main`; не создавать branch, worktree или PR.
- Не изменять `.env`, dependencies, public routes, queue names, scalar ID-only job constructors, retry/backoff/timeout, provider/media boundaries или catalog apply semantics.
- Не применять production migrations, не управлять workers, не очищать queue/cache и не выполнять ручной DML рабочей базы.
- Batch size: `max(1, min(100, (int) config('seasonvar.import.chunk_size', 100)))`.
- Unique `(seasonvar_import_run_id, source_page_id)` prepared-ledger является authority; single high-water cursor запрещён из-за overlapping planner reasons.
- Все новые raw SQL identifiers являются compile-time constants из model table names; provider/request data используется только как bindings.
- Общий batch claim token всегда проверяется вместе с exact `source_page_id` и `seasonvar_import_run_id`.
- В общем грязном worktree запрещено индексировать чужие hunks. Каждый commit-step выполняется только если `git diff -- <manifest>` доказывает полное владение всеми перечисленными файлами; иначе статус `unresolved_shared_worktree` сохраняется.
- Перед каждой production-affecting task повторно проверить rollback/data-safety gates в design spec; фактическая activation остаётся отдельным operator action.

---

### Task 1: Add durable progress, outbox and run/page uniqueness schema

**Files:**

- Create: `database/migrations/2026_07_25_130000_add_batch_dispatch_progress_to_seasonvar_import.php`
- Modify: `app/Models/SeasonvarImportRun.php`
- Modify: `app/Models/SeasonvarImportPreparedPage.php`
- Create: `tests/Feature/SeasonvarImportDispatchQueryPlanTest.php`

**Interfaces:**

- Consumes: existing `seasonvar_import_runs`, `seasonvar_import_prepared_pages`, pending Task 48 recovery index.
- Produces: `SeasonvarImportRun::$last_progress_at`; `SeasonvarImportPreparedPage::$last_enqueue_attempt_at`; `enqueue_attempts`; unique `seasonvar_prepared_run_source_unique`; due index `seasonvar_prepared_outbox_due_idx`.
- Preserves: `seasonvar_prepared_run_status_updated_id_idx`, group/page unique index and all existing rows.

- [x] **Step 1: Write the RED migration/query-plan tests**

Add tests that:

1. assert the new columns, casts, unique index and due-outbox column order;
2. execute an indexed due query and require `seasonvar_prepared_outbox_due_idx` in SQLite `EXPLAIN QUERY PLAN`;
3. call migration `down()`, insert the same run/page under two different groups, assert `up()` throws `LogicException` without deleting either row, then remove only the test duplicate and restore `up()` in `finally`;
4. call `down()`/`up()` on a clean fixture and assert rows and Task 48 index survive.

Use this exact due query contract:

```php
$query = SeasonvarImportPreparedPage::query()
    ->select(['id', 'seasonvar_import_title_group_id'])
    ->where('seasonvar_import_run_id', $run->id)
    ->where('status', SeasonvarPreparedPageStatus::Queued->value)
    ->where(function (Builder $query) use ($cutoff): void {
        $query->whereNull('last_enqueue_attempt_at')
            ->orWhere('last_enqueue_attempt_at', '<=', $cutoff);
    })
    ->orderBy('id')
    ->limit(101);
```

- [x] **Step 2: Run the focused test and confirm RED**

Run:

```bash
php artisan test --filter=SeasonvarImportDispatchQueryPlanTest
```

Expected: failure because the migration, columns and indexes do not exist.

- [x] **Step 3: Create the additive migration**

Generate the timestamped migration shell with Artisan, then fill it using `apply_patch`:

```bash
php artisan make:migration add_batch_dispatch_progress_to_seasonvar_import --no-interaction
```

If Artisan chooses a different current-time suffix, use an `apply_patch`
`*** Move to:` change so the final canonical path is exactly
`2026_07_25_130000_add_batch_dispatch_progress_to_seasonvar_import.php`.

The `up()` implementation must perform the duplicate preflight before any DDL:

```php
if (DB::table('seasonvar_import_prepared_pages')
    ->select(['seasonvar_import_run_id', 'source_page_id'])
    ->groupBy('seasonvar_import_run_id', 'source_page_id')
    ->havingRaw('COUNT(*) > 1')
    ->exists()
) {
    throw new LogicException(
        'Нельзя включить unique run/page ledger: найдены повторяющиеся prepared rows.',
    );
}

Schema::table('seasonvar_import_runs', function (Blueprint $table): void {
    $table->timestamp('last_progress_at')->nullable()->index();
});

Schema::table('seasonvar_import_prepared_pages', function (Blueprint $table): void {
    $table->timestamp('last_enqueue_attempt_at')->nullable();
    $table->unsignedInteger('enqueue_attempts')->default(0);
    $table->unique(
        ['seasonvar_import_run_id', 'source_page_id'],
        'seasonvar_prepared_run_source_unique',
    );
    $table->index(
        ['seasonvar_import_run_id', 'status', 'last_enqueue_attempt_at', 'id'],
        'seasonvar_prepared_outbox_due_idx',
    );
});
```

The reversible `down()` order is: drop due index and unique, drop prepared columns, drop `seasonvar_import_runs_last_progress_at_index`, then drop `last_progress_at`. Never drop Task 48 index.

- [x] **Step 4: Add typed model contracts**

Add fillable entries, PHPDoc and casts:

```php
// SeasonvarImportRun
/** @property Carbon|null $last_progress_at */
'last_progress_at',
'last_progress_at' => 'datetime',

// SeasonvarImportPreparedPage
/** @property Carbon|null $last_enqueue_attempt_at */
/** @property int $enqueue_attempts */
'last_enqueue_attempt_at',
'enqueue_attempts',
'last_enqueue_attempt_at' => 'datetime',
'enqueue_attempts' => 'integer',
```

Mirror the database default in `SeasonvarImportPreparedPage`:

```php
protected $attributes = [
    'enqueue_attempts' => 0,
];
```

- [x] **Step 5: Run GREEN schema verification**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=SeasonvarImportDispatchQueryPlanTest
php artisan test --filter=SeasonvarActiveRunQueryPlanTest
```

Expected: both focused classes pass; the old recovery index remains present.

- [x] **Step 6: Evaluate the shared-worktree ownership gate**

```bash
git status --short --branch
git diff --check -- database/migrations/2026_07_25_130000_add_batch_dispatch_progress_to_seasonvar_import.php app/Models/SeasonvarImportRun.php app/Models/SeasonvarImportPreparedPage.php tests/Feature/SeasonvarImportDispatchQueryPlanTest.php
git add database/migrations/2026_07_25_130000_add_batch_dispatch_progress_to_seasonvar_import.php app/Models/SeasonvarImportRun.php app/Models/SeasonvarImportPreparedPage.php tests/Feature/SeasonvarImportDispatchQueryPlanTest.php
git commit -m "perf(import): add durable batch dispatch ledger"
```

Expected shared-tree result today: do not stage; record `unresolved_shared_worktree`.

Task 1 result: `completed`; 5 focused migration/model/query-plan tests
(18 assertions) and 2 Task 48 recovery-index tests (6 assertions) pass.
Commit remains `unresolved_shared_worktree`; no files were staged.

---

### Task 2: Make planner selection resumable through the prepared ledger

**Files:**

- Modify: `app/Services/Seasonvar/SeasonvarRefreshPlanner.php`
- Modify: `tests/Feature/SeasonvarImportDispatchQueryPlanTest.php`

**Interfaces:**

- Consumes: active run ID and existing planner reason order.
- Produces: every planner query excludes exact prepared rows for that run; ordered tail selection accepts persisted source-page IDs.
- Preserves: existing freshness/retry/availability predicates, metadata handler order and sync-import behavior.

- [x] **Step 1: Write RED tests for exact anti-join and overlapping reasons**

Add tests proving:

- a page already prepared for run A is skipped for run A but remains eligible for run B;
- a page matching both `missing_data` and another attention reason is returned once before registration and zero times after its ledger row is inserted;
- persisted sitemap-tail IDs are emitted in stored order, ignore external URL order changes, and exclude a row already registered for the run;
- the SQLite plan for the anti-join uses `seasonvar_prepared_run_source_unique`.

- [x] **Step 2: Run RED**

```bash
php artisan test --filter=SeasonvarImportMaintenanceTest
php artisan test --filter=SeasonvarImportDispatchQueryPlanTest
```

Expected: new assertions fail because planner queries only compare `last_import_run_id` and forced tail accepts URLs.

- [x] **Step 3: Add a reusable ledger exclusion scope inside the planner**

Use Eloquent subquery, not an unbounded PHP ID set:

```php
/** @return Builder<SourcePage> */
private function withoutPreparedLedgerRow(
    Builder $query,
    ?int $importRunId,
): Builder {
    if ($importRunId === null) {
        return $query;
    }

    return $query->whereNotIn(
        (new SourcePage)->qualifyColumn('id'),
        SeasonvarImportPreparedPage::query()
            ->select('source_page_id')
            ->where('seasonvar_import_run_id', $importRunId),
    );
}
```

Apply it to `baseQuery()`, `forcedUrlQuery()` and every non-serial `metadataPageChunks()` query. Retain the in-call `$selectedIds` de-duplication because multiple reason queries can overlap before a transaction commits.

- [x] **Step 4: Add persisted-ID tail traversal**

Add:

```php
/**
 * @param  list<int>  $sourcePageIds
 * @return iterable<Collection<int, SourcePage>>
 */
public function forcedPageChunksForIds(
    array $sourcePageIds,
    int $chunkSize,
    ?int $importRunId = null,
    ?callable $progress = null,
): iterable
```

Load IDs in chunks of 500, map by integer ID, reconstruct the exact input order, apply the ledger exclusion, and yield chunks capped by `$chunkSize`. Keep `forcedPageChunksForUrls()` for backward compatibility until the repository-wide legacy scan proves it has no other consumer.

- [x] **Step 5: Run GREEN**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=SeasonvarImportDispatchQueryPlanTest
php artisan test --filter=SeasonvarImportMaintenanceTest
```

Expected: anti-join, overlap and ordered-ID tail tests pass.

- [x] **Step 6: Evaluate the conditional commit gate**

```bash
git status --short --branch
git diff --check -- app/Services/Seasonvar/SeasonvarRefreshPlanner.php tests/Feature/SeasonvarImportDispatchQueryPlanTest.php
git add app/Services/Seasonvar/SeasonvarRefreshPlanner.php tests/Feature/SeasonvarImportDispatchQueryPlanTest.php
git commit -m "perf(import): resume planner from prepared ledger"
```

Task-specific planner coverage stays in the new query-plan test file so the
foreign dirty `SeasonvarImportMaintenanceTest.php` remains untouched; its
existing suite still runs as regression verification.

Task 2 result: `completed`; 9 query-plan/planner tests (24 assertions) and 43
existing maintenance tests (256 assertions) pass. Commit remains
`unresolved_shared_worktree`; no files were staged.

---

### Task 3: Register serial pages in one bounded transaction

**Files:**

- Create: `app/DTOs/Seasonvar/SeasonvarImportDispatchBatch.php`
- Create: `app/Services/Seasonvar/SeasonvarImportDispatchBatcher.php`
- Modify: `app/Services/Seasonvar/SeasonvarPageClaimManager.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportTitleGroupDispatcher.php`
- Create: `tests/Feature/SeasonvarImportDispatchBatcherTest.php`

**Interfaces:**

- Consumes: run ID, current planner, group-key/title precedence, current claim duration, existing `SeasonvarDatabaseTransaction`.
- Produces:

```php
final readonly class SeasonvarImportDispatchBatch
{
    public function __construct(
        public int $registeredPages,
        public int $jobsDispatched,
        public bool $hasMore,
        public bool $dispatchCompleted,
    ) {}
}
```

```php
public function dispatchNext(int $runId): SeasonvarImportDispatchBatch;
```

- Preserves: `SeasonvarImportTitleGroupDispatcher::start()`, `addUrls()`, `adoptPage()` public behavior for targeted refresh and dynamically discovered seasons.

- [ ] **Step 1: Write RED batch behavior tests**

Cover exactly:

1. 100 serial pages / 10 families create 100 prepared rows, 10 groups, 100 exact claims and correct aggregate counters;
2. repeating `dispatchNext()` for the same exhausted run creates no row/counter duplicate;
3. one pre-claimed page is excluded from staging;
4. cancelled/terminal run registers nothing;
5. forced transaction exception leaves no claim, group, prepared row or counter;
6. two existing titles matching direct/season hashes choose the minimum title ID per page;
7. multiple pages in one family choose the first non-null title by page ID and never overwrite a non-null group title;
8. batch size is capped at 100 even if configuration is larger;
9. no `PrepareSeasonvarImportTitlePage` job is observable before transaction commit.

- [ ] **Step 2: Run RED**

```bash
php artisan test --filter=SeasonvarImportDispatchBatcherTest
```

Expected: class/DTO missing.

- [ ] **Step 3: Add bulk claim ownership**

Keep existing `claim()` unchanged for non-serial compatibility and add:

```php
/**
 * @param  list<int>  $pageIds
 * @return array{token: string, page_ids: list<int>}
 */
public function claimMany(array $pageIds, int $runId, ?int $seconds = null): array
```

Implementation contract:

- normalize to unique positive integer IDs;
- generate one `Str::uuid()` token;
- one conditional update of the selected `source_pages.id` rows guarded by
  the existing free/expired-claim predicate;
- one confirming select guarded by exact run ID, batch token and the normalized
  selected page IDs;
- return stable ascending owned IDs;
- no row is considered owned from update count alone.

- [ ] **Step 4: Implement the batch DTO and transaction boundary**

Inject:

```php
public function __construct(
    private readonly SeasonvarRefreshPlanner $refreshPlanner,
    private readonly SeasonvarPageClaimManager $claims,
    private readonly SeasonvarImportGroupKey $groupKeys,
    private readonly SeasonvarDatabaseTransaction $transactions,
    private readonly SeasonvarImportFinalizationDispatcher $finalizers,
) {}
```

`dispatchNext()` must:

- load only a bounded candidate set by walking planner chunks until capacity or exhaustion;
- keep non-serial rows on the existing per-page claim/job path;
- pass serial rows to one `SeasonvarDatabaseTransaction::run()` call with
  `attempts: 3` and `baseDelayMilliseconds: 100`;
- lock the exact run and require `mode=sitemap`, `execution_mode=queue`, `status=running`, `discovery_completed=true`, `dispatch_completed=false`;
- use the prepared-ledger query again under lock before claiming;
- call `claimMany()` and stage only confirmed owned page IDs.

Use this stable result rule:

```php
$hasMore = $candidateCount === $batchSize;
$dispatchCompleted = ! $hasMore && $plannerExhausted;
```

When `$dispatchCompleted` is true, repeat the active/discovery checks under the run lock before setting the marker. Never infer completion from prepared-row count.

- [ ] **Step 5: Implement grouped title identity resolution**

Use a fixed number of grouped queries:

- direct `CatalogTitle` candidates for `(source_id, source_page_id|source_url_hash)`;
- `Season` candidates by `source_url_hash`, joined to their titles;
- reduce candidates in PHP by page ID, choosing the minimum title ID.

For each group hash, choose the first non-null per-page title in ascending source-page ID. Bulk insert missing groups, select all batch groups once, and update only groups with `catalog_title_id IS NULL`.

The update predicate must remain:

```php
SeasonvarImportTitleGroup::query()
    ->whereKey($groupId)
    ->whereNull('catalog_title_id')
    ->update(['catalog_title_id' => $catalogTitleId]);
```

Use one bounded `CASE` expression keyed by bound group IDs and bound deltas for
`expected_pages`. All values are bindings; table/column names come from model
instances.

- [ ] **Step 6: Bulk prepared rows and aggregate run progress**

Under the same locked run:

- select existing run/page IDs once;
- `insertOrIgnore()` the owned rows with `queued`, `warnings=[]`, enqueue attempts `0`;
- select the batch prepared projection once;
- compute newly inserted page IDs as after-minus-before while all prepared writers use the same run lock;
- update group `expected_pages` by new rows only;
- update run `selected += newCount`, `last_progress_at=now()`, `last_heartbeat_at=now()` and bounded numeric `dispatch_batches` evidence.

Refactor `SeasonvarImportTitleGroupDispatcher::attachUrl()` to lock its run row before prepared insertion, so targeted/dynamic writers serialize with batch registration. Keep its public return and unlimited URL behavior.
Set `last_progress_at=now()` when `start()` creates a targeted title-refresh
run.

- [ ] **Step 7: Dispatch only after commit and record enqueue attempts**

Outside the transaction:

```php
PrepareSeasonvarImportTitlePage::dispatch($preparedPageId)
    ->onConnection((string) config('seasonvar.queue.connection', 'redis'))
    ->onQueue($queueName)
    ->afterCommit();
```

Collect successful IDs and update them once:

```php
SeasonvarImportPreparedPage::query()
    ->whereIn('id', $successfulIds)
    ->update([
        'last_enqueue_attempt_at' => now(),
        'enqueue_attempts' => DB::raw('enqueue_attempts + 1'),
    ]);
```

Do not update `last_progress_at` for enqueue-only success. Leave failed dispatches with due outbox state and report only sanitized class/run/page context.
When `hasMore=true`, dispatch exactly one
`ReconcileSeasonvarQueuedImportRun($runId)` after commit. This batcher-owned
continuation is the only planner continuation for that batch.

- [ ] **Step 8: Run focused GREEN and measure the first profile**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=SeasonvarImportDispatchBatcherTest
php artisan test --filter=SeasonvarImportTitleGroupDispatcherTest
```

Expected: batch atomicity/idempotency/title precedence and targeted refresh compatibility pass.

- [ ] **Step 9: Conditional commit**

```bash
git status --short --branch
git diff --check -- app/DTOs/Seasonvar/SeasonvarImportDispatchBatch.php app/Services/Seasonvar/SeasonvarImportDispatchBatcher.php app/Services/Seasonvar/SeasonvarPageClaimManager.php app/Services/Seasonvar/SeasonvarImportTitleGroupDispatcher.php tests/Feature/SeasonvarImportDispatchBatcherTest.php
git add app/DTOs/Seasonvar/SeasonvarImportDispatchBatch.php app/Services/Seasonvar/SeasonvarImportDispatchBatcher.php app/Services/Seasonvar/SeasonvarPageClaimManager.php app/Services/Seasonvar/SeasonvarImportTitleGroupDispatcher.php tests/Feature/SeasonvarImportDispatchBatcherTest.php
git commit -m "perf(import): batch serial dispatch registration"
```

---

### Task 4: Bound coordinator discovery and resume incomplete dispatch

**Files:**

- Modify: `app/Services/Seasonvar/SeasonvarQueuedImportDispatcher.php`
- Modify: `app/Services/Seasonvar/SeasonvarGlobalImportRunCoordinator.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportRunRecorder.php`
- Modify: `app/Services/Seasonvar/SeasonvarActiveRunReconciler.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportPipeline.php`
- Modify: `app/Jobs/StartSeasonvarQueuedImport.php`
- Modify: `app/Jobs/ReconcileSeasonvarQueuedImportRun.php`
- Modify: `app/Jobs/FinalizeSeasonvarQueuedImport.php`
- Modify: `app/Jobs/FinalizeSeasonvarImportTitleGroup.php`
- Modify: `app/DTOs/Seasonvar/SeasonvarActiveRunReconciliationResult.php`
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`
- Modify: `tests/Feature/SeasonvarActiveRunReconciliationTest.php`

**Interfaces:**

- Consumes: `SeasonvarImportDispatchBatcher::dispatchNext(int)`.
- Produces: explicit `discovery_completed`, persisted ordered `sitemap_tail_page_ids`, bounded continuation and real `last_progress_at`.
- Preserves: one public import command, `StartSeasonvarQueuedImport(int)`, `ReconcileSeasonvarQueuedImportRun(int)`, finalizer jobs and active-run lock.

- [ ] **Step 1: Write RED lifecycle/resume tests**

Add cases proving:

- `--no-discovery` run starts with `discovery_completed=true`; discovery run starts false;
- successful mirror/store records true and ordered internal tail IDs without raw URL/hash summary fields;
- first coordinator call registers at most one batch and schedules one reconciliation continuation;
- `StartSeasonvarQueuedImport` accepts `running + dispatch_completed=false` but rejects terminal/completed/other-mode runs;
- active reconciler calls the batcher instead of changing incomplete dispatch to true from ledger count;
- two reconciliation passes resume the remainder without duplicates and only planner exhaustion sets completion;
- incomplete discovery never completes dispatch;
- status polls, watchdog wake and enqueue-only replay do not change `last_progress_at`;
- real discovery/registration/preparation counters do change it;
- empty initial run still reaches the existing immediate completed outcome.

- [ ] **Step 2: Run RED**

```bash
php artisan test --filter=SeasonvarParallelImportTest
php artisan test --filter=SeasonvarActiveRunReconciliationTest
```

Expected: old dispatcher drains all pages; reconciler prematurely flips `dispatch_completed`.

- [ ] **Step 3: Seed explicit lifecycle markers**

In `SeasonvarGlobalImportRunCoordinator::acquire()` create:

```php
'last_progress_at' => now(),
'summary' => [
    'discover' => $discover,
    'discovery_completed' => ! $discover,
    'dispatch_completed' => false,
    'dispatch_batches' => 0,
    'provider' => 'seasonvar',
    'page_types' => $pageTypes,
    'sitemap_tail_limit' => $sitemapTailLimit,
],
```

Set `last_progress_at=now()` on `acquireSync()` as well, so every newly created
run has the same lifecycle-start meaning. Existing rows remain compatible
through the nullable column.

Legacy fallback is limited to rows without the key:

```php
$discoveryCompleted = array_key_exists('discovery_completed', $summary)
    ? $summary['discovery_completed'] === true
    : ! (bool) ($summary['discover'] ?? true);
```

- [ ] **Step 4: Separate progress from observer heartbeat**

Change recorder signature:

```php
public function mergeSummary(
    int $runId,
    array $values,
    bool $markProgress = false,
): ?SeasonvarImportRun
```

Only set `last_progress_at` when `$markProgress` is true. `addCounters()` sets it only when at least one positive counter is applied. `heartbeat()` never sets it.

Update existing direct durable transitions as well:

- preparation success/failure counters set progress in
  `PrepareSeasonvarImportTitlePage`;
- applied-page and title-group terminal checkpoints set progress in
  `FinalizeSeasonvarImportTitleGroup`;
- queued finalization checkpoint, terminal completion and terminal failure set
  progress in `SeasonvarImportPipeline`/`FinalizeSeasonvarQueuedImport`;
- wait/poll paths that currently call `heartbeat()` remain observer-only.

- [ ] **Step 5: Refactor queued dispatcher to discovery plus one batch**

`dispatchRun()` accepts either:

- `queued` queue run, atomically transitioning it to `running`; or
- existing `running` queue run with `dispatch_completed=false`.

If discovery is not complete, mirror/store, resolve selected tail URLs to existing internal source-page IDs in exact XML order, then call:

```php
$this->runs->mergeSummary($run->id, [
    'discovery_completed' => true,
    'expired_claims_recovered' => $recovered,
    'sitemap_tail_page_ids' => $tailPageIds,
    'sitemap_tail_selected' => $tailPageIds !== null ? count($tailPageIds) : null,
], markProgress: true);
```

Never persist `sitemap_tail_urls` or hashes. Then call the batcher exactly once.
If it reports more work, do not enqueue a second continuation because the
batcher already owns that after-commit action. If an empty initial dispatch is
complete, retain the current locked immediate completion and cache invalidation
path.

- [ ] **Step 6: Replace premature reconciliation with real continuation**

Change reconciliation result to:

```php
public function __construct(
    public bool $eligible,
    public bool $dispatchRecovered,
    public int $pagesRegistered,
    public int $jobsDispatched,
    public bool $hasRemainingDueWork,
) {}
```

For `dispatch_completed=false`:

1. require completed discovery;
2. call `dispatchNext($runId)`;
3. set `dispatchRecovered=true` only when pages were registered or completion was durably proven;
4. then process due outbox and legacy non-serial claims within remaining watchdog capacity;
5. rely on the batcher continuation when `hasMore=true`; otherwise schedule
   one continuation only when due outbox remains or a dispatch failure was
   restored.

Remove `durableDispatchProgressAt()` logic that equates stale ledger count with completed dispatch.

- [ ] **Step 7: Keep jobs ID-only and broaden only valid resume state**

`StartSeasonvarQueuedImport::handle()` must accept:

```php
$resumable = $run->execution_mode === 'queue'
    && (
        $run->status === SeasonvarImportStatus::Queued->value
        || (
            $run->status === SeasonvarImportStatus::Running->value
            && data_get($run->summary, 'dispatch_completed') === false
        )
    );
```

Do not change constructor, unique ID, connection, queue, retry or timeout. `ReconcileSeasonvarQueuedImportRun` remains a scalar run-ID job.

- [ ] **Step 8: Run GREEN lifecycle tests**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=SeasonvarParallelImportTest
php artisan test --filter=SeasonvarActiveRunReconciliationTest
php artisan test --filter=SeasonvarQueueJobContractTest
```

Expected: lifecycle/resume tests and unchanged queue contracts pass.

- [ ] **Step 9: Conditional commit**

```bash
git status --short --branch
git diff --check -- app/Services/Seasonvar/SeasonvarQueuedImportDispatcher.php app/Services/Seasonvar/SeasonvarGlobalImportRunCoordinator.php app/Services/Seasonvar/SeasonvarImportRunRecorder.php app/Services/Seasonvar/SeasonvarActiveRunReconciler.php app/Services/Seasonvar/SeasonvarImportPipeline.php app/Jobs/StartSeasonvarQueuedImport.php app/Jobs/ReconcileSeasonvarQueuedImportRun.php app/Jobs/FinalizeSeasonvarQueuedImport.php app/Jobs/FinalizeSeasonvarImportTitleGroup.php app/DTOs/Seasonvar/SeasonvarActiveRunReconciliationResult.php tests/Feature/SeasonvarParallelImportTest.php tests/Feature/SeasonvarActiveRunReconciliationTest.php
git add app/Services/Seasonvar/SeasonvarQueuedImportDispatcher.php app/Services/Seasonvar/SeasonvarGlobalImportRunCoordinator.php app/Services/Seasonvar/SeasonvarImportRunRecorder.php app/Services/Seasonvar/SeasonvarActiveRunReconciler.php app/Services/Seasonvar/SeasonvarImportPipeline.php app/Jobs/StartSeasonvarQueuedImport.php app/Jobs/ReconcileSeasonvarQueuedImportRun.php app/Jobs/FinalizeSeasonvarQueuedImport.php app/Jobs/FinalizeSeasonvarImportTitleGroup.php app/DTOs/Seasonvar/SeasonvarActiveRunReconciliationResult.php tests/Feature/SeasonvarParallelImportTest.php tests/Feature/SeasonvarActiveRunReconciliationTest.php
git commit -m "fix(import): resume incomplete queued dispatch"
```

---

### Task 5: Make prepared-page delivery and retry database-idempotent

**Files:**

- Modify: `app/Models/SeasonvarImportPreparedPage.php`
- Modify: `app/Jobs/PrepareSeasonvarImportTitlePage.php`
- Modify: `app/Services/Seasonvar/SeasonvarActiveRunReconciler.php`
- Modify: `tests/Feature/SeasonvarImportTitleGroupDispatcherTest.php`
- Modify: `tests/Feature/SeasonvarActiveRunReconciliationTest.php`

**Interfaces:**

- Produces: atomic `beginPreparing(): bool`, `returnToQueue(): bool`.
- Preserves: `PrepareSeasonvarImportTitlePage(int)`, queue uniqueness, provider failure classification, finalizer signaling and exact source claim release.

- [ ] **Step 1: Write RED CAS/outbox tests**

Add tests proving:

- two instances delivered for the same queued row allow exactly one `queued -> preparing`;
- a fresh `preparing` delivery is a no-op and does not release another worker's claim;
- transient provider failure returns the exact row to `queued` before rethrow;
- permanent failure remains `failed`;
- stale `preparing` is reset to `queued` only after existing retry/worker window;
- due `queued` rows use `last_enqueue_attempt_at`, not `updated_at`;
- successful redispatch increments `enqueue_attempts` once in a bulk update;
- failed Redis dispatch leaves the row due and schedules a continuation.

- [ ] **Step 2: Run RED**

```bash
php artisan test --filter=SeasonvarImportTitleGroupDispatcherTest
php artisan test --filter=SeasonvarActiveRunReconciliationTest
```

Expected: transient row remains `preparing`; duplicate delivery can start work again.

- [ ] **Step 3: Implement model CAS methods**

```php
public function beginPreparing(): bool
{
    $changed = self::query()
        ->whereKey($this->id)
        ->where('status', SeasonvarPreparedPageStatus::Queued->value)
        ->update([
            'status' => SeasonvarPreparedPageStatus::Preparing->value,
            'last_error' => null,
            'updated_at' => now(),
        ]);

    if ($changed === 1) {
        $this->status = SeasonvarPreparedPageStatus::Preparing;
    }

    return $changed === 1;
}

public function returnToQueue(): bool
{
    return self::query()
        ->whereKey($this->id)
        ->where('status', SeasonvarPreparedPageStatus::Preparing->value)
        ->update([
            'status' => SeasonvarPreparedPageStatus::Queued->value,
            'updated_at' => now(),
        ]) === 1;
}
```

Remove or stop using the non-atomic `markPreparing()`.

- [ ] **Step 4: Reorder job ownership safely**

In `handle()`:

1. return after orphan-claim cleanup for terminal `prepared|applied|failed`;
2. require active run;
3. call `beginPreparing()`; false means no-op without touching a fresh worker claim;
4. resolve/extend or acquire exact source claim;
5. if no claim, `returnToQueue()` and release the queue job for 30 seconds;
6. on transient exception, call `returnToQueue()` before rethrow;
7. finally release only the exact token owned by this execution.

Do not perform provider HTTP before successful CAS and claim ownership.

- [ ] **Step 5: Refactor reconciler outbox handling**

Use two bounded paths:

- stale preparing reset selected by `(run_id,status,updated_at,id)` and existing stale window;
- queued dispatch selected by `(run_id,status,last_enqueue_attempt_at,id)` and transport replay cutoff.

Fetch `batchSize + 1`, eager-load each group once, dispatch scalar IDs, then bulk update successful IDs:

```php
SeasonvarImportPreparedPage::query()
    ->whereIn('id', $successfulIds)
    ->where('status', SeasonvarPreparedPageStatus::Queued->value)
    ->update([
        'last_enqueue_attempt_at' => $attemptedAt,
        'enqueue_attempts' => DB::raw('enqueue_attempts + 1'),
    ]);
```

Enqueue attempts and no-op wakeups must not touch run `last_progress_at`.

- [ ] **Step 6: Run GREEN**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=SeasonvarImportTitleGroupDispatcherTest
php artisan test --filter=SeasonvarActiveRunReconciliationTest
php artisan test --filter=SeasonvarQueueJobContractTest
```

Expected: CAS, transient retry, stale recovery and outbox tests pass with unchanged job contract.

- [ ] **Step 7: Conditional commit**

```bash
git status --short --branch
git diff --check -- app/Models/SeasonvarImportPreparedPage.php app/Jobs/PrepareSeasonvarImportTitlePage.php app/Services/Seasonvar/SeasonvarActiveRunReconciler.php tests/Feature/SeasonvarImportTitleGroupDispatcherTest.php tests/Feature/SeasonvarActiveRunReconciliationTest.php
git add app/Models/SeasonvarImportPreparedPage.php app/Jobs/PrepareSeasonvarImportTitlePage.php app/Services/Seasonvar/SeasonvarActiveRunReconciler.php tests/Feature/SeasonvarImportTitleGroupDispatcherTest.php tests/Feature/SeasonvarActiveRunReconciliationTest.php
git commit -m "fix(import): guard prepared work with database CAS"
```

---

### Task 6: Enforce the SQL query budget and regression contracts

**Files:**

- Modify: `tests/Feature/SeasonvarImportDispatchBatcherTest.php`
- Modify: `tests/Feature/SeasonvarImportDispatchQueryPlanTest.php`
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`
- Modify: `tests/Unit/SeasonvarQueueJobContractTest.php`

**Interfaces:**

- Produces: deterministic performance contract for the initial 100-page fan-out.
- Preserves: non-serial legacy job, title refresh unlimited URL behavior and all queue timeout/retry constraints.

- [ ] **Step 1: Add the deterministic query-budget fixture**

Create 100 pending serial pages in 10 title families, `Queue::fake()`, attach a `DB::listen()` collector immediately before `dispatchRun()`, and assert:

```php
$this->assertLessThanOrEqual(120, count($queries));
$this->assertSame(100, $run->fresh()->selected);
$this->assertSame(100, $run->preparedPages()->count());
$this->assertSame(10, $run->titleGroups()->count());
```

Classify normalized SQL and assert no statement shape for title/group/prepared lookup or counter update occurs 100 times.

- [ ] **Step 2: Add 10-versus-100 scaling assertion**

Run the same profiler for 10 and 100 pages in isolated test datasets. Assert the larger fixture does not add per-page title/group/prepared selects or per-page counter updates. Allow only bounded queue-object creation outside SQL accounting.

- [ ] **Step 3: Add compatibility assertions**

Confirm:

- one non-serial actor page still dispatches `ImportSeasonvarSourcePage` with ID/run/token/group key;
- targeted refresh with 50 season URLs still dispatches 50 preparation jobs;
- jobs remain on `seasonvar-import` / `seasonvar-title-refresh`;
- `retry_after > worker timeout`;
- no constructor serializes an Eloquent model or raw provider URL.

- [ ] **Step 4: Run performance and integration GREEN**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=SeasonvarImportDispatchBatcherTest
php artisan test --filter=SeasonvarImportDispatchQueryPlanTest
php artisan test --filter=SeasonvarParallelImportTest
php artisan test --filter=SeasonvarImportTitleGroupDispatcherTest
php artisan test --filter=SeasonvarQueueJobContractTest
```

Expected: 100-page profile is `<=120` SQL, at least one order below 1 239, and all compatibility assertions pass.

- [ ] **Step 5: If the ceiling fails, optimize only evidenced shapes**

Inspect the normalized collector. Allowed corrections:

- collapse repeated title/group/prepared projections into grouped queries;
- remove redundant `fresh()`/relationship reads;
- aggregate group/run updates;
- keep batch cap at 100.

Do not weaken the `<=120` assertion, remove ownership checks, enlarge transactions around Redis/HTTP or replace the prepared ledger with a cursor.

- [ ] **Step 6: Conditional commit**

```bash
git status --short --branch
git diff --check -- tests/Feature/SeasonvarImportDispatchBatcherTest.php tests/Feature/SeasonvarImportDispatchQueryPlanTest.php tests/Feature/SeasonvarParallelImportTest.php tests/Unit/SeasonvarQueueJobContractTest.php
git add tests/Feature/SeasonvarImportDispatchBatcherTest.php tests/Feature/SeasonvarImportDispatchQueryPlanTest.php tests/Feature/SeasonvarParallelImportTest.php tests/Unit/SeasonvarQueueJobContractTest.php
git commit -m "test(import): enforce bounded dispatch query budget"
```

---

### Task 7: Update documentation, verify globally and prepare operator-gated delivery

**Files:**

- Modify: `docs/importer.md`
- Modify: `docs/queues.md`
- Modify: `docs/performance.md`
- Modify: `docs/deployment.md`
- Modify: `docs/superpowers/plans/2026-07-24-seasonvar-importer-improvement-master-plan.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Verify: `docs/superpowers/specs/2026-07-25-seasonvar-batch-dispatch-query-optimization-design.md`

**Interfaces:**

- Produces: truthful current architecture, measured SQL result, migration/canary/rollback checklist and final compliance evidence.
- Preserves: managed `project-docs` blocks and visitor history ordering.

- [ ] **Step 1: Update canonical documentation from verified behavior**

Document:

- batch cap, prepared-ledger resume and no single cursor;
- explicit discovery/dispatch markers and persisted internal tail IDs;
- outbox enqueue metadata and preparation CAS;
- measured before/after query counts and exact fixture;
- pending migration and operator-only rollout sequence;
- failure recovery for partial migration, Redis outage, stale preparing, interrupted batch and code rollback.

Do not claim zero downtime, automatic backup/restore, HA, deployed migration or restarted workers.

- [ ] **Step 2: Update README and Russian changelog**

README visitor history should state only the visitor-relevant result: large queued catalog refreshes are registered in bounded resumable portions and no longer stall for hours before processing starts. Keep the history as the final H2 section and do not edit the managed block manually.

Add a dated Russian `CHANGELOG.md` entry with technical identifiers preserved exactly.

- [ ] **Step 3: Refresh managed documentation only if its source changed**

```bash
php artisan project:docs-refresh
php artisan project:docs-check
```

Expected: no managed-block drift.

- [ ] **Step 4: Run focused and broad verification**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=SeasonvarImportDispatchBatcherTest
php artisan test --filter=SeasonvarImportDispatchQueryPlanTest
php artisan test --filter=SeasonvarImportTitleGroupDispatcherTest
php artisan test --filter=SeasonvarParallelImportTest
php artisan test --filter=SeasonvarActiveRunReconciliationTest
php artisan test --filter=SeasonvarQueueJobContractTest
php artisan test --filter=Seasonvar
./vendor/bin/phpunit
```

Run direct PHPStan using the project’s existing documented command and exact changed PHP manifest. No frontend build is required because no Blade, CSS, JS, Tailwind or Vite file is changed.

- [ ] **Step 5: Re-profile on disposable SQLite and inspect production read-only**

Repeat the exact 100-page disposable profile and record actual totals/shapes. On production perform only:

```bash
php artisan migrate:status
git status --short --branch
```

Any schema counts, duplicate preflight or EXPLAIN check must be read-only. Do not run `migrate`, `migrate:rollback`, queue/cache mutation or worker control.

- [ ] **Step 6: Final requirements reread and legacy scan**

Re-read:

- `docs/requirements/index.md`;
- all owners applied in the design;
- `docs/importer.md`, `docs/queues.md`, `docs/performance.md`, `docs/deployment.md`;
- this plan and the approved design.

Search the whole repository for:

```bash
rg -n "dispatch_cursor_id|dispatch_completed|discovery_completed|sitemap_tail_urls|sitemap_tail_page_ids|markPreparing|last_enqueue_attempt_at|enqueue_attempts|last_progress_at|forcedPageChunksForUrls|catalogTitleForPage|catalogTitleFor" app config database docs routes tests
```

Classify every hit before deletion; retain compatible targeted-refresh paths.

- [ ] **Step 7: Update compliance matrix and delivery evidence**

Mark each Task 49 row `completed`, `already_compliant`, `not_applicable` or honest `unresolved`. Keep:

- production activation: `unresolved_operator_gate`;
- commit/push: `unresolved_shared_worktree` unless exact ownership and hook gates become provably safe.

- [ ] **Step 8: Commit and push only after the mandatory ownership gate**

If and only if all task files are owned and `main` is clean except this task:

```bash
git status --short --branch
git add app/DTOs/Seasonvar/SeasonvarImportDispatchBatch.php app/DTOs/Seasonvar/SeasonvarActiveRunReconciliationResult.php app/Jobs/PrepareSeasonvarImportTitlePage.php app/Jobs/ReconcileSeasonvarQueuedImportRun.php app/Jobs/StartSeasonvarQueuedImport.php app/Jobs/FinalizeSeasonvarQueuedImport.php app/Jobs/FinalizeSeasonvarImportTitleGroup.php app/Models/SeasonvarImportPreparedPage.php app/Models/SeasonvarImportRun.php app/Services/Seasonvar/SeasonvarActiveRunReconciler.php app/Services/Seasonvar/SeasonvarGlobalImportRunCoordinator.php app/Services/Seasonvar/SeasonvarImportDispatchBatcher.php app/Services/Seasonvar/SeasonvarImportPipeline.php app/Services/Seasonvar/SeasonvarImportRunRecorder.php app/Services/Seasonvar/SeasonvarImportTitleGroupDispatcher.php app/Services/Seasonvar/SeasonvarPageClaimManager.php app/Services/Seasonvar/SeasonvarQueuedImportDispatcher.php app/Services/Seasonvar/SeasonvarRefreshPlanner.php database/migrations/2026_07_25_130000_add_batch_dispatch_progress_to_seasonvar_import.php tests/Feature/SeasonvarImportDispatchBatcherTest.php tests/Feature/SeasonvarImportDispatchQueryPlanTest.php tests/Feature/SeasonvarImportTitleGroupDispatcherTest.php tests/Feature/SeasonvarParallelImportTest.php tests/Feature/SeasonvarActiveRunReconciliationTest.php tests/Feature/SeasonvarImportMaintenanceTest.php tests/Unit/SeasonvarQueueJobContractTest.php docs/importer.md docs/queues.md docs/performance.md docs/deployment.md docs/superpowers/plans/2026-07-24-seasonvar-importer-improvement-master-plan.md docs/superpowers/specs/2026-07-25-seasonvar-batch-dispatch-query-optimization-design.md docs/superpowers/plans/2026-07-25-seasonvar-batch-dispatch-query-optimization.md docs/plans/current-task-plan.md README.md CHANGELOG.md
git diff --cached --check
git commit -m "perf(import): batch and resume Seasonvar dispatch"
git push
```

If foreign changes remain in any listed shared file, do not stage, commit or push. Report the exact blocker without claiming delivery.

## Plan Self-Review

- Spec coverage: all sixteen required RED/GREEN behaviors from the approved
  design are assigned to Tasks 1–6; documentation, rollback and compliance are
  assigned to Task 7.
- Type consistency: the batcher accepts one integer run ID and returns one
  `SeasonvarImportDispatchBatch`; all queue constructors remain scalar integer
  IDs; claim ownership always includes page ID, run ID and token.
- Resume consistency: planner continuation has exactly one owner per batch;
  unique run/page ledger remains authority; no task introduces
  `dispatch_cursor_id`.
- Transaction consistency: provider HTTP and Redis dispatch stay outside the
  serial registration transaction; title-group dynamic writers share the run
  lock so before/after inserted-row deltas remain valid.
- Progress consistency: actual lifecycle, discovery, registration, counters,
  apply and terminal transitions update `last_progress_at`; polls, wakeups and
  enqueue-only attempts do not.
- Migration consistency: duplicate preflight occurs before DDL, Task 48 index
  is preserved, `down()` is reversible in code, and production execution
  remains operator-gated.
- Completeness review: commands, method signatures, index names, assertions
  and ownership gates are concrete; the plan contains no temporary markers,
  abbreviated code or unresolved implementation choice.
