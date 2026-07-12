# Parallel Seasonvar Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Redis-backed queued mode to `php artisan seasonvar:import` that safely feeds ten background workers without downloading the same Seasonvar page twice and refreshes changed source metadata.

**Architecture:** A short-lived coordinator discovers URLs, atomically leases eligible `SourcePage` rows, and dispatches one scalar queue job per leased page to a dedicated Redis queue. Page jobs verify their lease before HTTP, serialize writes for seasons of the same Seasonvar title through Redis locks, update run counters atomically, and wake one finalizer after all run leases disappear.

**Tech Stack:** PHP 8.5, Laravel 13.19, Laravel Redis queues and locks, SQLite WAL, PHPUnit 12.5, Laravel HTTP fakes, systemd, cron.

## Global Constraints

- Work only on the existing `main` branch; do not create branches or worktrees.
- `php artisan seasonvar:import` remains the only public Seasonvar import command.
- Redis stores the `seasonvar-import` queue and importer locks; SQLite remains the catalog database.
- No production dependency is added and `.env` is not edited.
- Source requests remain restricted to `https://seasonvar.ru/` and never download video files.
- Ten cron invocations per day do not mean ten requests per page; the default successful-page freshness interval becomes 24 hours.
- Sync URL import and its existing output remain backward compatible.
- New migrations are additive and reversible; deployed migrations are not edited.
- External HTTP never runs inside a catalog database transaction.
- Every PHP behavior change follows red-green TDD and is formatted with `./vendor/bin/pint --dirty --format agent`.
- Tests use PHPUnit, `RefreshDatabase`, `Http::fake()`, and `Http::preventStrayRequests()`.

## File Map

- `database/migrations/2026_07_12_230000_add_parallel_import_claims_to_source_pages_table.php`: page lease columns and indexes.
- `database/migrations/2026_07_12_230100_add_execution_mode_to_seasonvar_import_runs_table.php`: distinguish sync processes from queued runs.
- `app/Models/SourcePage.php`: lease attributes, casts, and claim-run relation.
- `app/Models/SeasonvarImportRun.php`: execution mode attribute and claimed-page relation.
- `config/seasonvar.php`, `.env.example`: Redis queue, lock store, lease, retry, and 24-hour refresh defaults.
- `app/Services/Seasonvar/SeasonvarPageClaimManager.php`: atomic acquire, extend, ownership, release, recovery, and outstanding-count operations.
- `app/Services/Seasonvar/SeasonvarImportRunRecorder.php`: atomic concurrent counter updates and terminal run state.
- `app/Services/Seasonvar/SeasonvarImportGroupKey.php`: stable title lock key from Seasonvar external ID with URL-hash fallback.
- `app/Jobs/ImportSeasonvarSourcePage.php`: one leased-page worker job.
- `app/Jobs/FinalizeSeasonvarQueuedImport.php`: one deferred finalizer per queued run.
- `app/Services/Seasonvar/SeasonvarQueuedImportDispatcher.php`: discovery, eligibility, leasing, Redis dispatch, and empty-run completion.
- `app/Services/Seasonvar/SeasonvarImportPipeline.php`: reusable post-queue maintenance/finalization entry point.
- `app/Console/Commands/ImportSeasonvar.php`: explicit `--queued` coordinator mode while retaining sync mode.
- `tests/Feature/SeasonvarParallelImportTest.php`: claim, dispatch, worker, lock, recovery, finalization, and command coverage.
- `tests/Feature/SeasonvarParsePageCommandTest.php`: changed-poster refresh regression.
- `deploy/systemd/seasonvar-import-worker@.service`: reliable worker template.
- `README.md`, `docs/architecture.md`, `docs/performance.md`, `docs/deployment.md`, `docs/testing.md`, `docs/MAINTENANCE_LOG.md`: operations and architecture documentation.

---

### Task 1: Add persistent page leases and queued-run identity

**Files:**
- Create: `database/migrations/2026_07_12_230000_add_parallel_import_claims_to_source_pages_table.php`
- Create: `database/migrations/2026_07_12_230100_add_execution_mode_to_seasonvar_import_runs_table.php`
- Modify: `app/Models/SourcePage.php`
- Modify: `app/Models/SeasonvarImportRun.php`
- Modify: `config/seasonvar.php`
- Modify: `.env.example`
- Create: `tests/Feature/SeasonvarParallelImportTest.php`

**Interfaces:**
- Produces source-page attributes `import_claim_token`, `import_claimed_at`, `import_claim_expires_at`, and `import_claim_run_id`.
- Produces run attribute `execution_mode` with values `sync` and `queue`.
- Produces config keys `seasonvar.queue.connection`, `queue`, `lock_store`, `claim_seconds`, `worker_timeout`, `retry_window_seconds`, and `finalizer_delay_seconds`.

- [ ] **Step 1: Write the failing schema/config/model test**

Create the test class with `RefreshDatabase` and this first method:

```php
public function test_parallel_import_schema_and_defaults_are_available(): void
{
    $page = SourcePage::factory()->create([
        'import_claim_token' => 'claim-token',
        'import_claimed_at' => now(),
        'import_claim_expires_at' => now()->addHour(),
    ]);
    $run = SeasonvarImportRun::query()->create([
        'mode' => 'sitemap',
        'execution_mode' => 'queue',
        'status' => 'running',
        'started_at' => now(),
    ]);

    $page->update(['import_claim_run_id' => $run->id]);

    $this->assertSame('claim-token', $page->fresh()->import_claim_token);
    $this->assertTrue($page->fresh()->import_claimed_at->equalTo($page->import_claimed_at));
    $this->assertTrue($page->fresh()->import_claim_expires_at->equalTo($page->import_claim_expires_at));
    $this->assertTrue($page->fresh()->importClaimRun->is($run));
    $this->assertSame('queue', $run->fresh()->execution_mode);
    $this->assertSame('redis', config('seasonvar.queue.connection'));
    $this->assertSame('seasonvar-import', config('seasonvar.queue.queue'));
    $this->assertSame('redis', config('seasonvar.queue.lock_store'));
    $this->assertSame(86400, config('seasonvar.queue.claim_seconds'));
    $this->assertSame(24, config('seasonvar.import.refresh_after_hours'));
}
```

- [ ] **Step 2: Run the test and verify RED**

Run: `php artisan test --filter=test_parallel_import_schema_and_defaults_are_available`

Expected: FAIL because the parallel-import columns do not exist.

- [ ] **Step 3: Generate and implement the two additive migrations**

Run Laravel's migration generator twice, then rename the generated files to the exact paths in this task before editing. The `up()` bodies must add:

```php
Schema::table('source_pages', function (Blueprint $table): void {
    $table->string('import_claim_token', 64)->nullable();
    $table->timestamp('import_claimed_at')->nullable();
    $table->timestamp('import_claim_expires_at')->nullable()->index();
    $table->foreignId('import_claim_run_id')
        ->nullable()
        ->constrained('seasonvar_import_runs')
        ->nullOnDelete();
    $table->index(
        ['page_type', 'import_claim_expires_at', 'id'],
        'source_pages_parallel_import_candidates_index',
    );
    $table->index(
        ['import_claim_run_id', 'import_claim_token'],
        'source_pages_parallel_import_run_index',
    );
});
```

```php
Schema::table('seasonvar_import_runs', function (Blueprint $table): void {
    $table->string('execution_mode', 16)->default('sync')->after('mode')->index();
});
```

The source-page `down()` must drop `source_pages_parallel_import_candidates_index` and `source_pages_parallel_import_run_index`, call `dropConstrainedForeignId('import_claim_run_id')`, then drop the token and timestamp columns. The run migration drops only `execution_mode`.

- [ ] **Step 4: Add exact model attributes, casts, and relations**

Add the four lease attributes to `SourcePage` fillable, cast the two timestamps to `datetime`, and add:

```php
/**
 * @return BelongsTo<SeasonvarImportRun, $this>
 */
public function importClaimRun(): BelongsTo
{
    return $this->belongsTo(SeasonvarImportRun::class, 'import_claim_run_id');
}
```

Add `execution_mode` to `SeasonvarImportRun` fillable and add:

```php
/**
 * @return HasMany<SourcePage, $this>
 */
public function claimedSourcePages(): HasMany
{
    return $this->hasMany(SourcePage::class, 'import_claim_run_id');
}
```

- [ ] **Step 5: Add the queue configuration without editing `.env`**

Use these exact defaults in `config/seasonvar.php`:

```php
'queue' => [
    'connection' => env('SEASONVAR_QUEUE_CONNECTION', 'redis'),
    'queue' => env('SEASONVAR_QUEUE_NAME', 'seasonvar-import'),
    'lock_store' => env('SEASONVAR_QUEUE_LOCK_STORE', 'redis'),
    'claim_seconds' => (int) env('SEASONVAR_QUEUE_CLAIM_SECONDS', 86400),
    'worker_timeout' => (int) env('SEASONVAR_QUEUE_WORKER_TIMEOUT', 900),
    'retry_window_seconds' => (int) env('SEASONVAR_QUEUE_RETRY_WINDOW_SECONDS', 21600),
    'finalizer_delay_seconds' => (int) env('SEASONVAR_QUEUE_FINALIZER_DELAY_SECONDS', 60),
],
```

Change the existing refresh default from `168` to `24` in `config/seasonvar.php` and `.env.example`. Add all seven `SEASONVAR_QUEUE_*` keys to `.env.example` with the same values.

- [ ] **Step 6: Run the focused test and verify GREEN**

Run: `php artisan test --filter=test_parallel_import_schema_and_defaults_are_available`

Expected: PASS.

- [ ] **Step 7: Format and commit**

Run: `./vendor/bin/pint --dirty --format agent`

Run: `git status --short --branch && git add . && git commit -m "feat: add parallel import lease state"`

Expected: commit succeeds on `main` with no unstaged or untracked files.

---

### Task 2: Implement atomic lease ownership and stable title keys

**Files:**
- Create: `app/Services/Seasonvar/SeasonvarPageClaimManager.php`
- Create: `app/Services/Seasonvar/SeasonvarImportGroupKey.php`
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`

**Interfaces:**
- Produces `claim(SourcePage $page, int $runId, ?int $seconds = null): ?string`.
- Produces `owns(int $pageId, int $runId, string $token): bool`.
- Produces `extend(int $pageId, int $runId, string $token, int $seconds): bool`.
- Produces `release(int $pageId, int $runId, string $token): bool`.
- Produces `recoverExpired(): int` and `outstandingForRun(int $runId): int`.
- Produces `SeasonvarImportGroupKey::forUrl(string $url, string $urlHash): string`.

- [ ] **Step 1: Write failing claim and group-key tests**

Add these tests:

```php
public function test_page_claim_is_atomic_owned_and_recoverable_after_expiry(): void
{
    $run = $this->queuedRun();
    $page = SourcePage::factory()->create();
    $claims = app(SeasonvarPageClaimManager::class);

    $token = $claims->claim($page, $run->id, 60);

    $this->assertNotNull($token);
    $this->assertTrue($claims->owns($page->id, $run->id, $token));
    $this->assertNull($claims->claim($page, $run->id, 60));
    $this->assertFalse($claims->release($page->id, $run->id, 'wrong-token'));

    $page->update(['import_claim_expires_at' => now()->subSecond()]);

    $this->assertSame(1, $claims->recoverExpired());
    $this->assertSame(0, $claims->outstandingForRun($run->id));
    $this->assertNotNull($claims->claim($page->fresh(), $run->id, 60));
}

public function test_import_group_key_uses_external_id_and_hash_fallback(): void
{
    $keys = app(SeasonvarImportGroupKey::class);

    $this->assertSame(
        'seasonvar-title:47915',
        $keys->forUrl('https://seasonvar.ru/serial-47915-Test-4-season.html', 'hash-a'),
    );
    $this->assertSame(
        'seasonvar-page:hash-b',
        $keys->forUrl('https://seasonvar.ru/catalog/test.html', 'hash-b'),
    );
}
```

Add a private `queuedRun()` helper returning a persisted queue-mode `SeasonvarImportRun`.

- [ ] **Step 2: Run the tests and verify RED**

Run: `php artisan test --filter='test_page_claim_is_atomic|test_import_group_key'`

Expected: FAIL because both services are missing.

- [ ] **Step 3: Implement `SeasonvarPageClaimManager` with conditional updates**

Use `Str::uuid()->toString()` for tokens. Every mutation must include page ID, run ID, and token where applicable. The claim query must group this availability condition:

```php
->where(function (Builder $query): void {
    $query->whereNull('import_claim_token')
        ->orWhereNull('import_claim_expires_at')
        ->orWhere('import_claim_expires_at', '<=', now());
})
```

`recoverExpired()` clears all four lease columns only where `import_claim_token` is non-null and `import_claim_expires_at <= now()`. `owns()` requires a non-expired timestamp. `extend()` updates `import_claimed_at` and expiry only for the matching live token. `outstandingForRun()` counts non-expired claims for the run.

- [ ] **Step 4: Implement `SeasonvarImportGroupKey`**

Use this exact extraction rule:

```php
public function forUrl(string $url, string $urlHash): string
{
    $path = (string) parse_url($url, PHP_URL_PATH);

    if (preg_match('~^/serial-(\d+)-~u', $path, $matches) === 1) {
        return 'seasonvar-title:'.$matches[1];
    }

    return 'seasonvar-page:'.$urlHash;
}
```

- [ ] **Step 5: Run tests, format, and commit**

Run: `php artisan test --filter='test_page_claim_is_atomic|test_import_group_key'`

Expected: PASS.

Run: `./vendor/bin/pint --dirty --format agent`

Run: `git status --short --branch && git add . && git commit -m "feat: claim Seasonvar pages atomically"`

---

### Task 3: Add concurrent run counters and the leased page job

**Files:**
- Create: `app/Services/Seasonvar/SeasonvarImportRunRecorder.php`
- Create: `app/Jobs/ImportSeasonvarSourcePage.php`
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`

**Interfaces:**
- Produces `SeasonvarImportRunRecorder::addCounters(int $runId, array $counters): void` using atomic `column = column + value` SQL expressions.
- Produces `ImportSeasonvarSourcePage::__construct(int $sourcePageId, int $importRunId, string $claimToken, string $groupKey, bool $force = false)`.
- Page job uses `SeasonvarCatalogImporter::parsePages(Collection $pages, ?callable $progress, bool $force, ?int $importRunId): array`.

- [ ] **Step 1: Write failing worker tests**

Add one test proving a stale job performs no HTTP and one proving a live job parses exactly its page:

```php
public function test_worker_with_wrong_claim_token_does_not_request_source(): void
{
    Http::preventStrayRequests();
    Http::fake();
    $run = $this->queuedRun();
    $page = SourcePage::factory()->create();

    (new ImportSeasonvarSourcePage(
        sourcePageId: $page->id,
        importRunId: $run->id,
        claimToken: 'wrong-token',
        groupKey: 'seasonvar-title:1',
    ))->handle(
        app(SeasonvarPageClaimManager::class),
        app(SeasonvarCatalogImporter::class),
        app(SeasonvarImportRunRecorder::class),
    );

    Http::assertNothingSent();
    $this->assertSame(0, $run->fresh()->parsed);
}
```

For the live-claim test, fake one valid Seasonvar page response, obtain the token through the claim manager, call the job's `handle()`, then assert one request, `parsed = 1`, `selected = 1`, and cleared lease columns. Reuse a minimal valid HTML helper in this test file rather than making a real request.

- [ ] **Step 2: Run the worker tests and verify RED**

Run: `php artisan test --filter='test_worker_with_wrong_claim_token|test_worker_with_live_claim'`

Expected: FAIL because the recorder and job do not exist.

- [ ] **Step 3: Implement atomic run counter updates**

Whitelist these columns inside `SeasonvarImportRunRecorder`: `cycles`, `discovered`, `stored`, `selected`, `parsed`, `failed`, `media_attached`, `media_updated`, `media_skipped`, `media_failed`. Convert every delta to a non-negative integer and issue one query:

```php
SeasonvarImportRun::query()->whereKey($runId)->update(
    collect($counters)
        ->only(self::COUNTER_COLUMNS)
        ->map(fn (mixed $value, string $column): Expression => DB::raw(
            $column.' + '.max(0, (int) $value),
        ))
        ->all(),
);
```

Return early for an empty sanitized array.

- [ ] **Step 4: Implement the queue job**

The job implements `ShouldQueue` and uses `Dispatchable`, `InteractsWithQueue`, `Queueable`, and `SerializesModels`. In the constructor, call `onConnection()` and `onQueue()` from `seasonvar.queue` config. Set `$tries = 0`, `$timeout` from config, a serialized retry-until timestamp from config, and `backoff(): array` returning `[60, 300, 900]`.

At the beginning of `handle()`, return if `owns()` is false. Extend the page lease for `worker_timeout + 300`, then acquire this Redis lock without blocking:

```php
$lock = Cache::store((string) config('seasonvar.queue.lock_store', 'redis'))
    ->lock($this->groupKey, $this->timeout + 300);

if (! $lock->get()) {
    $claims->extend($this->sourcePageId, $this->importRunId, $this->claimToken, 3600);
    $this->release(30);

    return;
}
```

Inside `try/finally`, load `SourcePage::with('source')->find($sourcePageId)`. If missing, increment `failed`. Otherwise call `parsePages(collect([$page]), null, $force, $runId)` and pass its exact counters plus `selected => 1` to the recorder. Release the matching page claim and Redis title lock in `finally`.

Implement `failed(?Throwable $exception): void` to release only the matching claim and log page ID, run ID, group key, exception class, and message. `retryUntil()` returns a Carbon instance created from the serialized timestamp.

- [ ] **Step 5: Run the worker tests and verify GREEN**

Run: `php artisan test --filter='test_worker_with_wrong_claim_token|test_worker_with_live_claim'`

Expected: PASS with no stray HTTP requests.

- [ ] **Step 6: Add a lock-contention assertion**

Acquire the job's group lock from the configured array test store, run the job with fake queue interactions, and assert `assertReleased(delay: 30)` and `Http::assertNothingSent()`. Configure `seasonvar.queue.lock_store` to `array` in test setup so tests never depend on the local Redis daemon.

Run: `php artisan test --filter=test_worker_releases_itself_when_title_lock_is_held`

Expected: PASS.

- [ ] **Step 7: Format and commit**

Run: `./vendor/bin/pint --dirty --format agent`

Run: `git status --short --branch && git add . && git commit -m "feat: process leased Seasonvar pages in queue"`

---

### Task 4: Dispatch eligible pages once and finalize queued runs

**Files:**
- Create: `app/Services/Seasonvar/SeasonvarQueuedImportDispatcher.php`
- Create: `app/Jobs/FinalizeSeasonvarQueuedImport.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportPipeline.php`
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`

**Interfaces:**
- Produces `SeasonvarQueuedImportDispatcher::dispatch(bool $force = false, bool $discover = true): SeasonvarImportRun`.
- Produces `SeasonvarImportPipeline::finalizeQueuedRun(SeasonvarImportRun $run, ?callable $progress = null): SeasonvarImportRun`.
- Produces `FinalizeSeasonvarQueuedImport::__construct(int $importRunId)`.

- [ ] **Step 1: Write failing dispatcher tests**

Use `Queue::fake()`, create two eligible serial pages, and call the dispatcher. Assert:

```php
Queue::assertPushedOn('seasonvar-import', ImportSeasonvarSourcePage::class);
Queue::assertPushedTimes(ImportSeasonvarSourcePage::class, 2);
Queue::assertPushed(FinalizeSeasonvarQueuedImport::class, 1);
$this->assertSame(2, $run->fresh()->selected);
$this->assertSame(2, SourcePage::query()->where('import_claim_run_id', $run->id)->count());
```

Call the dispatcher a second time and assert no new page job is pushed for the already claimed IDs. Add a separate expired-claim case proving the page is dispatched again after `recoverExpired()`.

- [ ] **Step 2: Run dispatcher tests and verify RED**

Run: `php artisan test --filter='test_dispatcher_|test_expired_claim'`

Expected: FAIL because the dispatcher and finalizer do not exist.

- [ ] **Step 3: Implement the dispatcher**

Inject `SeasonvarCatalogImporter`, `SeasonvarSitemapMirror`, `SeasonvarRefreshPlanner`, `SeasonvarPageClaimManager`, `SeasonvarImportRunRecorder`, and `SeasonvarImportGroupKey`.

Create a queue-mode run, recover expired claims, optionally mirror/store sitemap URLs, then iterate the existing planner chunks. For each page:

1. call `claim()`;
2. skip when it returns null;
3. construct the stable group key;
4. dispatch `ImportSeasonvarSourcePage` to configured Redis connection/queue;
5. release the claim and increment `failed` if dispatch throws;
6. aggregate selected/discovery counters per chunk instead of one counter query per page.

When selected is zero, set `status = completed`, `cycles = 1`, and `finished_at = now()`. Otherwise dispatch `FinalizeSeasonvarQueuedImport($run->id)` with the configured delay.

The dispatcher must not use `Bus::batch()`: batch metadata would add one SQLite write per completed page and unique batch-job constraints would not protect this workload.

- [ ] **Step 4: Extract queued finalization from the existing pipeline**

Add a public `finalizeQueuedRun()` that invokes the existing private maintenance methods in this exact order:

1. storage pruning;
2. parsed-source status backfill;
3. media metadata backfill;
4. media source-key backfill;
5. media availability backlog;
6. invalid relation cleanup;
7. title merge;
8. recommendation rebuild.

Use existing `addRunCounters()` once with `cycles => 1`, media counters, and the same summary keys used by `runCycle()`. Mark the run `completed` and set `finished_at`. On exception, mark it `failed`, save `last_error`, set `finished_at`, and rethrow.

- [ ] **Step 5: Implement the finalizer job**

The job uses the configured Redis connection/queue, `$tries = 0`, `$timeout = 900`, `backoff()` of `[60, 300, 900]`, and a two-day retry deadline. It implements `ShouldBeUnique`, returns `seasonvar-finalizer:{runId}` from `uniqueId()`, and returns `Cache::store(config('seasonvar.queue.lock_store'))` from `uniqueVia()`.

In `handle()`:

```php
$run = SeasonvarImportRun::query()->find($this->importRunId);

if ($run === null || $run->status !== 'running' || $run->execution_mode !== 'queue') {
    return;
}

if ($claims->outstandingForRun($run->id) > 0) {
    $this->release((int) config('seasonvar.queue.finalizer_delay_seconds', 60));

    return;
}

$pipeline->finalizeQueuedRun($run);
$statsSnapshots->refresh();
```

`failed()` marks only a still-running queued run failed and logs the exception.

- [ ] **Step 6: Add finalizer tests and verify GREEN**

Test that a live claim releases the finalizer without invoking pipeline finalization. Test that zero claims calls `finalizeQueuedRun()` once and refreshes stats. Use mocks for pipeline and stats snapshots.

Run: `php artisan test --filter='test_dispatcher_|test_expired_claim|test_finalizer_'`

Expected: PASS.

- [ ] **Step 7: Format and commit**

Run: `./vendor/bin/pint --dirty --format agent`

Run: `git status --short --branch && git add . && git commit -m "feat: dispatch and finalize queued Seasonvar imports"`

---

### Task 5: Expose queued mode through the existing public command

**Files:**
- Modify: `app/Console/Commands/ImportSeasonvar.php`
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`
- Modify: `tests/Feature/SeasonvarImportMaintenanceTest.php`

**Interfaces:**
- Adds CLI flag `--queued` to `seasonvar:import`.
- Queued mode consumes `SeasonvarQueuedImportDispatcher::dispatch(force: bool, discover: bool): SeasonvarImportRun`.
- Sync mode keeps all existing arguments/options and lock recovery behavior.

- [ ] **Step 1: Write failing command tests**

Mock the dispatcher and assert:

```php
$this->artisan('seasonvar:import', ['--queued' => true, '--no-discovery' => true])
    ->expectsOutputToContain('поставлено в очередь')
    ->assertExitCode(0);
```

Verify the dispatcher receives `force: false, discover: false`. Add validation tests proving `--queued` with a URL, `--forever`, or `--sleep` exits with failure and a Russian explanation. Add a lock test with `seasonvar.queue.lock_store = array`: a held `seasonvar-import-coordinator` lock makes the second queued invocation exit successfully without dispatching.

- [ ] **Step 2: Run command tests and verify RED**

Run: `php artisan test --filter='test_queued_command|test_queued_mode_rejects|test_queued_command_skips'`

Expected: FAIL because `--queued` is not in the signature.

- [ ] **Step 3: Add the queued command branch**

Extend the signature with:

```text
{--queued : Поставить подходящие страницы в Redis-очередь для параллельной обработки}
```

Validate incompatible inputs before acquiring a lock. For a valid queued invocation, acquire `seasonvar-import-coordinator` from the configured Redis lock store for 300 seconds, call the dispatcher, print run ID and selected count, and release the lock in `finally`.

Do not register signal handlers or populate Linux process metadata for queued mode. Preserve the current global sync lock and process inspector path unchanged for non-queued calls, except filter orphan recovery to `execution_mode = sync` so an asynchronous run is never marked failed because it has no coordinator PID.

- [ ] **Step 4: Run command and existing lock tests**

Run: `php artisan test --filter='test_queued_command|test_queued_mode_rejects|test_queued_command_skips|test_it_skips_successfully_when_import_lock_is_held|test_it_releases_orphaned_import_lock'`

Expected: PASS.

- [ ] **Step 5: Format and commit**

Run: `./vendor/bin/pint --dirty --format agent`

Run: `git status --short --branch && git add . && git commit -m "feat: add queued mode to Seasonvar import command"`

---

### Task 6: Prove source refresh updates changed posters

**Files:**
- Modify: `tests/Feature/SeasonvarParsePageCommandTest.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`

**Interfaces:**
- Preserves `SeasonvarCatalogImporter::parsePage()` hash short-circuit.
- Ensures a non-empty changed `poster_url` replaces the previous source poster.

- [ ] **Step 1: Write a failing changed-poster regression test**

Import a page whose HTML contains poster URL `https://img.example.com/old.jpg`. Advance time by 25 hours, fake the same page with a changed body and `https://img.example.com/new.jpg`, execute another import, then assert:

```php
$this->assertSame(
    'https://img.example.com/new.jpg',
    CatalogTitle::query()->where('external_id', '47915')->value('poster_url'),
);
$this->assertSame(2, Http::recorded()->count());
```

Also assert `last_changed_at` and `last_imported_at` advanced. Keep `Http::preventStrayRequests()` enabled.

- [ ] **Step 2: Run the regression and verify RED or existing GREEN**

Run: `php artisan test --filter=test_changed_source_page_updates_poster`

Expected: PASS if the existing importer already replaces a non-empty poster; otherwise FAIL with the old URL. A pre-existing PASS is acceptable here because the test locks down explicitly requested behavior rather than introducing a new importer rule.

- [ ] **Step 3: Apply the minimal importer correction only if RED**

If needed, keep the existing non-destructive null behavior and make the replacement explicit:

```php
'poster_url' => $data['poster_url'] ?: $catalogTitle->poster_url,
```

Do not clear a valid stored poster when the changed page temporarily omits it.

- [ ] **Step 4: Run importer-focused tests**

Run: `php artisan test tests/Feature/SeasonvarParsePageCommandTest.php tests/Feature/SeasonvarImportMaintenanceTest.php`

Expected: PASS with no real HTTP.

- [ ] **Step 5: Format and commit**

Run: `./vendor/bin/pint --dirty --format agent`

Run: `git status --short --branch && git add . && git commit -m "test: verify changed Seasonvar posters refresh"`

---

### Task 7: Add systemd, nohup, cron, and operations documentation

**Files:**
- Create: `deploy/systemd/seasonvar-import-worker@.service`
- Modify: `README.md`
- Modify: `docs/architecture.md`
- Modify: `docs/performance.md`
- Modify: `docs/deployment.md`
- Modify: `docs/testing.md`
- Modify: `docs/MAINTENANCE_LOG.md`

**Interfaces:**
- Systemd template starts `/usr/bin/php artisan queue:work redis --queue=seasonvar-import` as `www`.
- Cron calls only `php artisan seasonvar:import --queued` at ten exact hours.

- [ ] **Step 1: Add the systemd template**

Create this complete unit:

```ini
[Unit]
Description=Seasonvar import queue worker %i
After=network.target redis.service

[Service]
Type=simple
User=www
Group=www
WorkingDirectory=/www/wwwroot/seasonvar.miniserver.fun
ExecStart=/usr/bin/php artisan queue:work redis --queue=seasonvar-import --sleep=1 --tries=0 --timeout=900 --max-time=3600 --max-jobs=1000
ExecReload=/usr/bin/php artisan queue:restart
Restart=always
RestartSec=5
TimeoutStopSec=1200
KillSignal=SIGTERM

[Install]
WantedBy=multi-user.target
```

- [ ] **Step 2: Document exact migration and Redis readiness checks**

Document:

```bash
cd /www/wwwroot/seasonvar.miniserver.fun
redis-cli ping
php artisan migrate --force
php artisan seasonvar:import --queued
```

State that `redis-cli ping` must return `PONG` and queued command output must report the run ID and number of leased pages.

- [ ] **Step 3: Document temporary ten-worker background launch**

Use this exact temporary command:

```bash
cd /www/wwwroot/seasonvar.miniserver.fun
for worker in $(seq 1 10); do
  nohup /usr/bin/php artisan queue:work redis --queue=seasonvar-import --sleep=1 --tries=0 --timeout=900 --max-time=3600 \
    >> "storage/logs/seasonvar-worker-${worker}.log" 2>&1 &
done
```

Explain that `nohup` is for an immediate manual run; systemd is the reliable reboot-safe mode.

- [ ] **Step 4: Document reliable systemd installation**

Use:

```bash
sudo cp deploy/systemd/seasonvar-import-worker@.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now seasonvar-import-worker@{1..10}.service
systemctl --no-pager --type=service 'seasonvar-import-worker@*'
```

Add `sudo systemctl restart 'seasonvar-import-worker@*.service'`, `journalctl -u 'seasonvar-import-worker@*' -f`, `php artisan queue:failed`, and `php artisan queue:retry all` to the operations section.

- [ ] **Step 5: Document the exact ten-times-daily cron**

For the `www` crontab, use one line:

```cron
0 0,2,5,7,10,12,14,17,19,22 * * * cd /www/wwwroot/seasonvar.miniserver.fun && /usr/bin/php artisan seasonvar:import --queued >> storage/logs/seasonvar-cron.log 2>&1
```

Explicitly state that claims prevent duplicate dispatch, Redis title locks prevent concurrent writes for seasons of one title, and the 24-hour freshness rule prevents every cron invocation from redownloading fresh pages.

- [ ] **Step 6: Refresh managed docs and verify documentation**

Run: `php artisan project:docs-refresh`

Run: `rg -n "seasonvar-import|--queued|worker@\{1\.\.10\}|0,2,5,7,10,12,14,17,19,22" README.md docs deploy/systemd`

Expected: the queue, ten workers, and exact cron entry are all discoverable.

- [ ] **Step 7: Commit documentation and unit file**

Run: `git status --short --branch && git add . && git commit -m "docs: add parallel importer operations"`

---

### Task 8: Full verification and clean handoff

**Files:**
- Verify all files changed in Tasks 1–7.

**Interfaces:**
- Confirms schema rollback, focused behavior, full suite, formatting, command help, queue connection, and a clean `main` worktree.

- [ ] **Step 1: Run the parallel-import suite**

Run: `php artisan test tests/Feature/SeasonvarParallelImportTest.php`

Expected: all claim, dispatcher, worker, command, and finalizer tests pass.

- [ ] **Step 2: Run all importer-focused suites**

Run:

```bash
php artisan test \
  tests/Feature/RunSeasonvarImportJobTest.php \
  tests/Feature/SeasonvarImportMaintenanceTest.php \
  tests/Feature/SeasonvarParsePageCommandTest.php \
  tests/Feature/SeasonvarSitemapMirrorTest.php \
  tests/Unit/SeasonvarCatalogParserTest.php
```

Expected: all tests pass and no external request escapes an HTTP fake.

- [ ] **Step 3: Verify additive migration rollback on an isolated SQLite file**

Create an empty temporary SQLite file under `output/`, run all migrations against it, roll back exactly the last two migrations created for this feature, then migrate forward again. Do not point these commands at `database/database.sqlite`.

Run:

```bash
mkdir -p output
QA_DB="$(mktemp "$PWD/output/parallel-import-qa-XXXXXX.sqlite")"
DB_CONNECTION=sqlite DB_DATABASE="$QA_DB" php artisan migrate --force
DB_CONNECTION=sqlite DB_DATABASE="$QA_DB" php artisan migrate:rollback --step=2 --force
DB_CONNECTION=sqlite DB_DATABASE="$QA_DB" php artisan migrate --force
rm -f "$QA_DB"
```

Expected: migrate, rollback, and migrate all exit zero.

- [ ] **Step 4: Run formatter and full suite**

Run: `./vendor/bin/pint --dirty --format agent`

Run: `php artisan test`

Expected: zero failures.

- [ ] **Step 5: Verify command and runtime configuration**

Run:

```bash
php artisan seasonvar:import --help
php artisan tinker --execute="dump(config('seasonvar.queue.connection'), config('seasonvar.queue.queue'), config('seasonvar.import.refresh_after_hours'));"
redis-cli ping
```

Expected: help lists `--queued`; configuration prints `redis`, `seasonvar-import`, and `24`; Redis prints `PONG`.

- [ ] **Step 6: Review diff against the specification**

Run: `git diff HEAD~7 --stat && git diff HEAD~7 --check`

Confirm every criterion in `docs/superpowers/specs/2026-07-12-parallel-seasonvar-import-design.md` maps to code, tests, or operations documentation. Confirm no source file, secret, video file, `.env`, or production database is staged.

- [ ] **Step 7: Commit any formatter or managed-doc changes and verify clean state**

If Step 4 or docs refresh changed tracked files, run:

```bash
git status --short --branch
git add .
git commit -m "chore: finalize parallel Seasonvar importer"
```

Then run: `git status --short --branch`

Expected: `main` is clean. Do not push unless the user explicitly requests it.
