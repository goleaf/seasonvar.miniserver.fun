# Seasonvar Unbounded Parallel Full-Title Refresh Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make opening any episode dispatch an uncapped parallel fan-out across every known and newly discovered Seasonvar season page, then safely consolidate all prepared facts into one canonical catalog title and reuse the same prepare/apply path in the global importer.

**Architecture:** A run-scoped title group owns idempotent prepared-page rows. Page jobs fetch, parse, expand playlists, and check media independently without taking the title write lock; one generic group finalizer later applies prepared payloads in deterministic order under the canonical title lock, records a source/local manifest, merges verified duplicates, and completes refresh state. Redis is delivery/retry infrastructure only: every discovered page is dispatched immediately and there is no application-level chunk, semaphore, or concurrency cap.

**Tech Stack:** PHP 8.5, Laravel 13.19 queues/Redis locks, Eloquent, SQLite `IMMEDIATE` transactions, Livewire 4.3 browser trigger, PHPUnit 12.5, Laravel HTTP fakes, systemd template workers.

## Global Constraints

- Work only on the existing `main` branch; do not create branches or worktrees.
- Preserve `php artisan seasonvar:import` as the only public Seasonvar import command.
- Keep every source page inside normalized `https://seasonvar.ru/` URLs.
- Never download video files; store only external URL and media metadata.
- Do not impose an application-level limit on pages dispatched for one title.
- Keep the 15-minute freshness window per `CatalogTitle`, beginning only after `completed` fan-in.
- Do not delete local-only seasons, episodes, or media because one source snapshot is incomplete.
- Use additive reversible migrations, short transactions, scalar queue payloads, retries, timeouts, and sanitized errors.
- Use `Http::fake()` plus `Http::preventStrayRequests()` in every new external-request test.
- Write each behavior test first and observe the expected failure before implementation.
- Run Pint after PHP edits and focused tests before broad importer/full-suite verification.

---

## File Map

**Persistence**

- `database/migrations/2026_07_13_200000_create_seasonvar_import_title_groups.php` — run-scoped group and prepared-page staging tables.
- `app/Enums/SeasonvarImportTitleGroupStatus.php` — group lifecycle.
- `app/Enums/SeasonvarPreparedPageStatus.php` — page lifecycle.
- `app/Models/SeasonvarImportTitleGroup.php` — group relationships/casts.
- `app/Models/SeasonvarImportPreparedPage.php` — prepared payload and status.
- `app/Models/SeasonvarImportRun.php` — `titleGroups()` and `preparedPages()` relationships.

**Preparation and application**

- `app/DTOs/Seasonvar/SeasonvarFetchedPage.php` — validated in-process HTTP result; never queued.
- `app/DTOs/Seasonvar/SeasonvarPreparedCatalogPage.php` — normalized serializable staging payload.
- `app/DTOs/Seasonvar/SeasonvarTitleManifest.php` — bounded comparison counters/keys.
- `app/DTOs/Seasonvar/SeasonvarCatalogData.php` — exact `toArray()` round trip.
- `app/Services/Seasonvar/SeasonvarSourcePageFetcher.php` — HTTP, crawl metadata, raw snapshot.
- `app/Services/Seasonvar/SeasonvarPreparedMediaResolver.php` — playlist expansion and availability preparation without catalog writes.
- `app/Services/Seasonvar/SeasonvarCatalogPagePreparer.php` — HTML parser plus dynamic season URL discovery.
- `app/Services/Seasonvar/SeasonvarTitleManifestBuilder.php` — source/local before/after manifest.
- `app/Services/Seasonvar/SeasonvarCatalogImporter.php` — shared `applyPreparedPage()`; existing `parsePage()` delegates to prepare/apply.

**Fan-out/fan-in**

- `app/Services/Seasonvar/SeasonvarImportTitleGroupDispatcher.php` — create/adopt groups, add URLs idempotently, dispatch all pages immediately.
- `app/Jobs/PrepareSeasonvarImportTitlePage.php` — one source page per worker.
- `app/Jobs/FinalizeSeasonvarImportTitleGroup.php` — deterministic apply and terminal state.
- `app/Jobs/RefreshSeasonvarCatalogTitle.php` — short visitor coordinator.
- `app/Jobs/ImportSeasonvarSourcePage.php` — backward-compatible queued payload delegates into group preparation.
- `app/Services/Seasonvar/CatalogTitleRefreshStateStore.php` — partial terminal state.
- `app/Services/Seasonvar/SeasonvarQueuedImportDispatcher.php` — global grouping and uncapped page dispatch.
- `app/Jobs/FinalizeSeasonvarQueuedImport.php` — waits for title-group finalizers before global maintenance.
- `app/Services/Seasonvar/SeasonvarImportStorageMaintenance.php` — bounded staging retention.

**Operations/docs/tests**

- `deploy/systemd/seasonvar-title-refresh-worker@.service` — dedicated high-priority worker template.
- `.env.example`, `config/seasonvar.php` — queue delay/retention settings, no concurrency setting.
- `docs/{architecture,DATA_RELATIONS,environment,importer,queues,deployment,testing}.md`, `CHANGELOG.md` — operational contract.
- `tests/Feature/SeasonvarParallelTitleRefreshPersistenceTest.php`
- `tests/Feature/SeasonvarCatalogPagePreparationTest.php`
- `tests/Feature/SeasonvarCatalogPreparedApplyTest.php`
- `tests/Feature/SeasonvarImportTitleGroupDispatcherTest.php`
- `tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php`
- Existing `tests/Feature/{RefreshSeasonvarCatalogTitleJobTest,SeasonvarParallelImportTest,CatalogTitleBackgroundRefreshTest,SeasonvarParsePageCommandTest}.php`.

---

### Task 1: Persist Run-Scoped Title Groups and Prepared Pages

**Files:**
- Create: `database/migrations/2026_07_13_200000_create_seasonvar_import_title_groups.php`
- Create: `app/Enums/SeasonvarImportTitleGroupStatus.php`
- Create: `app/Enums/SeasonvarPreparedPageStatus.php`
- Create: `app/Models/SeasonvarImportTitleGroup.php`
- Create: `app/Models/SeasonvarImportPreparedPage.php`
- Modify: `app/Models/SeasonvarImportRun.php`
- Test: `tests/Feature/SeasonvarParallelTitleRefreshPersistenceTest.php`

**Interfaces:**
- Produces: `SeasonvarImportRun::titleGroups(): HasMany`, `SeasonvarImportTitleGroup::preparedPages(): HasMany`.
- Produces: group statuses `discovering`, `running`, `finalizing`, `completed`, `partial`, `failed`.
- Produces: page statuses `queued`, `preparing`, `prepared`, `failed`, `applied`.
- Produces page transition methods `markPreparing()`, `markPrepared(array $payload, array $warnings, string $contentHash, int $parserVersion)`, `markFailed(string $sanitizedError)`, and `markApplied()`.

- [ ] **Step 1: Write the failing persistence test**

```php
public function test_group_and_prepared_page_are_run_scoped_and_idempotent(): void
{
    $run = SeasonvarImportRun::query()->create([
        'mode' => 'url', 'execution_mode' => 'queue', 'status' => 'running',
    ]);
    $title = CatalogTitle::factory()->create();
    $page = SourcePage::factory()->create();
    $group = SeasonvarImportTitleGroup::query()->create([
        'seasonvar_import_run_id' => $run->id,
        'catalog_title_id' => $title->id,
        'group_key_hash' => hash('sha256', 'family'),
        'queue_name' => 'seasonvar-title-refresh',
        'status' => SeasonvarImportTitleGroupStatus::Discovering,
    ]);
    $prepared = $group->preparedPages()->create([
        'seasonvar_import_run_id' => $run->id,
        'source_page_id' => $page->id,
        'status' => SeasonvarPreparedPageStatus::Queued,
    ]);

    $this->assertTrue($run->fresh()->titleGroups->first()->is($group));
    $this->assertSame(SeasonvarPreparedPageStatus::Queued, $prepared->status);
    $this->assertDatabaseCount('seasonvar_import_prepared_pages', 1);
}
```

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/SeasonvarParallelTitleRefreshPersistenceTest.php`

Expected: FAIL because the migration/models/enums do not exist.

- [ ] **Step 3: Add the reversible migration**

Create `seasonvar_import_title_groups` with nullable `catalog_title_id`, run FK, 64-byte group hash, queue name, lifecycle status, expected/prepared/failed/applied counters, sanitized `last_error`, lifecycle timestamps, unique `(seasonvar_import_run_id, group_key_hash)`, and `(catalog_title_id,status,id)` index.

Create `seasonvar_import_prepared_pages` with run/group/source-page FKs, lifecycle status, content hash, parser version, JSON payload/warnings, prepared/applied timestamps, unique `(seasonvar_import_title_group_id,source_page_id)`, and `(seasonvar_import_title_group_id,status,id)` index. `down()` drops prepared pages before groups.

```php
$table->unique(
    ['seasonvar_import_run_id', 'group_key_hash'],
    'seasonvar_import_title_groups_run_key_unique',
);
$table->unique(
    ['seasonvar_import_title_group_id', 'source_page_id'],
    'seasonvar_import_prepared_pages_group_page_unique',
);
```

- [ ] **Step 4: Add typed models/enums and relationships**

Use enum casts for status, `array` casts for payload/warnings, integer casts for counters/parser version, and datetime casts for lifecycle fields. Add `titleGroups()` and `preparedPages()` to `SeasonvarImportRun`.

- [ ] **Step 5: Run GREEN and formatter**

Run:

```bash
./vendor/bin/pint --format agent app/Enums/SeasonvarImportTitleGroupStatus.php app/Enums/SeasonvarPreparedPageStatus.php app/Models/SeasonvarImportTitleGroup.php app/Models/SeasonvarImportPreparedPage.php app/Models/SeasonvarImportRun.php database/migrations/2026_07_13_200000_create_seasonvar_import_title_groups.php tests/Feature/SeasonvarParallelTitleRefreshPersistenceTest.php
php artisan test tests/Feature/SeasonvarParallelTitleRefreshPersistenceTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Enums/SeasonvarImportTitleGroupStatus.php app/Enums/SeasonvarPreparedPageStatus.php app/Models/SeasonvarImportRun.php app/Models/SeasonvarImportTitleGroup.php app/Models/SeasonvarImportPreparedPage.php database/migrations/2026_07_13_200000_create_seasonvar_import_title_groups.php tests/Feature/SeasonvarParallelTitleRefreshPersistenceTest.php
git commit -m "feat: persist Seasonvar title refresh groups"
```

---

### Task 2: Split Source Fetching From Catalog Writes

**Files:**
- Create: `app/DTOs/Seasonvar/SeasonvarFetchedPage.php`
- Create: `app/Services/Seasonvar/SeasonvarSourcePageFetcher.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Test: `tests/Feature/SeasonvarCatalogPagePreparationTest.php`
- Test: `tests/Feature/SeasonvarParsePageCommandTest.php`

**Interfaces:**
- Produces: `SeasonvarSourcePageFetcher::fetch(SourcePage $page, ?int $importRunId = null, ?callable $progress = null): SeasonvarFetchedPage`.
- `SeasonvarFetchedPage` exposes `sourcePageId`, `body`, `contentHash`, `httpStatus`, `contentChanged`, `snapshotId`.
- Existing `SeasonvarCatalogImporter::parsePage()` must use this service without changing synchronous command behavior.

- [ ] **Step 1: Write a failing fetch-boundary test**

```php
public function test_fetcher_stores_snapshot_without_writing_catalog_rows(): void
{
    Http::preventStrayRequests();
    Http::fake(['https://seasonvar.ru/*' => Http::response('<html>source</html>', 200)]);
    $run = SeasonvarImportRun::query()->create([
        'mode' => 'url', 'execution_mode' => 'queue', 'status' => 'running',
    ]);
    $page = SourcePage::factory()->create(['url' => $this->seasonUrl(), 'page_type' => 'serial']);

    $fetched = app(SeasonvarSourcePageFetcher::class)->fetch($page, $run->id);

    $this->assertSame(hash('sha256', '<html>source</html>'), $fetched->contentHash);
    $this->assertDatabaseHas('source_page_snapshots', [
        'source_page_id' => $page->id,
        'seasonvar_import_run_id' => $run->id,
    ]);
    $this->assertDatabaseCount('catalog_titles', 0);
}
```

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/SeasonvarCatalogPagePreparationTest.php --filter=fetcher`

Expected: FAIL because `SeasonvarSourcePageFetcher` is missing.

- [ ] **Step 3: Implement the fetcher**

Move only HTTP response handling, page crawl metadata, content hash calculation, successful-status validation, and `SourcePageSnapshot::updateOrCreate()` from `SeasonvarCatalogImporter::parsePage()` into the new service. Keep URL logging sanitized exactly as existing progress callbacks require. Throw `SeasonvarSourceRequestException::forStatus()` after storing diagnostic snapshot for non-2xx responses.

- [ ] **Step 4: Delegate existing parsePage to the fetcher**

Inject `SeasonvarSourcePageFetcher` into `SeasonvarCatalogImporter`; replace its inline fetch/snapshot block with:

```php
$fetched = $this->pageFetcher->fetch($page, $importRunId, $progress);
$body = $fetched->body;
$contentHash = $fetched->contentHash;
$contentChanged = $fetched->contentChanged;
```

Do not change parser/database/media behavior in this task.

- [ ] **Step 5: Run GREEN and synchronous regression**

Run:

```bash
php artisan test tests/Feature/SeasonvarCatalogPagePreparationTest.php
php artisan test tests/Feature/SeasonvarParsePageCommandTest.php
./vendor/bin/pint --format agent app/DTOs/Seasonvar/SeasonvarFetchedPage.php app/Services/Seasonvar/SeasonvarSourcePageFetcher.php app/Services/Seasonvar/SeasonvarCatalogImporter.php tests/Feature/SeasonvarCatalogPagePreparationTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/DTOs/Seasonvar/SeasonvarFetchedPage.php app/Services/Seasonvar/SeasonvarSourcePageFetcher.php app/Services/Seasonvar/SeasonvarCatalogImporter.php tests/Feature/SeasonvarCatalogPagePreparationTest.php
git commit -m "refactor: separate Seasonvar source page fetching"
```

---

### Task 3: Prepare Fully Resolved Page Payloads Without Catalog Writes

**Files:**
- Create: `app/DTOs/Seasonvar/SeasonvarPreparedCatalogPage.php`
- Create: `app/Services/Seasonvar/SeasonvarPreparedMediaResolver.php`
- Create: `app/Services/Seasonvar/SeasonvarCatalogPagePreparer.php`
- Modify: `app/DTOs/Seasonvar/SeasonvarCatalogData.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Test: `tests/Feature/SeasonvarCatalogPagePreparationTest.php`
- Test: `tests/Unit/SeasonvarCatalogParserTest.php`

**Interfaces:**
- Produces: `SeasonvarCatalogData::toArray(): array<string,mixed>` with keys accepted unchanged by `fromParsed()`.
- Produces: `SeasonvarCatalogPagePreparer::prepare(SourcePage $page, ?int $runId = null, ?callable $progress = null): SeasonvarPreparedCatalogPage`.
- Produces: `SeasonvarPreparedCatalogPage::toPayload()` and `::fromPayload(array $payload)`.
- Payload includes validated catalog data, resolved media candidates, discovered direct season URLs, content hash, parser version, source-page ID, and sanitized warning counters.

- [ ] **Step 1: Write failing round-trip and preparation tests**

```php
public function test_preparer_resolves_all_season_urls_without_catalog_writes(): void
{
    Http::preventStrayRequests();
    Http::fake([
        'https://seasonvar.ru/serial-*' => Http::response($this->fixture('season-with-nine-links.html')),
        'https://seasonvar.ru/playls2/*' => Http::response($this->fixture('playlist.json')),
        '*' => Http::response('', 206),
    ]);
    $page = SourcePage::factory()->create(['url' => $this->seasonUrl()]);

    $prepared = app(SeasonvarCatalogPagePreparer::class)->prepare($page, 91);
    $roundTrip = SeasonvarPreparedCatalogPage::fromPayload($prepared->toPayload());

    $this->assertCount(9, $roundTrip->discoveredSeasonUrls);
    $this->assertNotEmpty($roundTrip->catalogData->episodes);
    $this->assertNotEmpty($roundTrip->catalogData->media);
    $this->assertDatabaseCount('catalog_titles', 0);
}
```

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/SeasonvarCatalogPagePreparationTest.php --filter=preparer`

Expected: FAIL because the preparer/DTO do not exist.

- [ ] **Step 3: Implement exact DTO serialization**

`SeasonvarCatalogData::toArray()` must emit every constructor field using parser snake-case keys. `SeasonvarPreparedCatalogPage::fromPayload()` must call `SeasonvarCatalogData::fromParsed()` so corrupted staging data fails validation before database writes.

- [ ] **Step 4: Extract playlist/media preparation**

Move the network-only parts of `importParsedPlaylists()`, `parseExternalPlaylistItem()`, and `parseSeasonvarPlaylistItem()` into `SeasonvarPreparedMediaResolver::resolve(array $media, ?callable $progress = null): array{media:list<array<string,mixed>>, warnings:list<array{type:string}>}`. Resolve `.m3u`, `.m3u8`, and Seasonvar JSON playlist candidates, normalize/guard URLs, deduplicate stable candidates, and perform due availability checks in the page worker. Store only normalized health fields, never response bodies or secrets, in the prepared media item.

- [ ] **Step 5: Implement the preparer and preserve sync mode**

The preparer calls the fetcher, parser, DTO validation, and media resolver. Direct season links come only from normalized `catalogData->seasons[*].source_url` accepted by `SeasonvarUrl`. Do not change synchronous `parsePage()` in this task beyond the fetcher delegation from Task 2; Task 4 switches it atomically to the completed prepare/apply pair so no intermediate commit references a missing apply method.

- [ ] **Step 6: Run GREEN and parser regressions**

Run:

```bash
php artisan test tests/Feature/SeasonvarCatalogPagePreparationTest.php tests/Unit/SeasonvarCatalogParserTest.php
./vendor/bin/pint --format agent app/DTOs/Seasonvar/SeasonvarCatalogData.php app/DTOs/Seasonvar/SeasonvarPreparedCatalogPage.php app/Services/Seasonvar/SeasonvarPreparedMediaResolver.php app/Services/Seasonvar/SeasonvarCatalogPagePreparer.php app/Services/Seasonvar/SeasonvarCatalogImporter.php tests/Feature/SeasonvarCatalogPagePreparationTest.php
```

Expected: PASS and zero stray HTTP requests.

- [ ] **Step 7: Commit**

```bash
git add app/DTOs/Seasonvar app/Services/Seasonvar/SeasonvarPreparedMediaResolver.php app/Services/Seasonvar/SeasonvarCatalogPagePreparer.php app/Services/Seasonvar/SeasonvarCatalogImporter.php tests/Feature/SeasonvarCatalogPagePreparationTest.php
git commit -m "feat: prepare Seasonvar pages without catalog writes"
```

---

### Task 4: Apply Prepared Pages and Build Source/Local Manifests

**Files:**
- Create: `app/DTOs/Seasonvar/SeasonvarTitleManifest.php`
- Create: `app/Services/Seasonvar/SeasonvarTitleManifestBuilder.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Test: `tests/Feature/SeasonvarCatalogPreparedApplyTest.php`
- Test: `tests/Feature/SeasonvarParsePageCommandTest.php`

**Interfaces:**
- Produces: `SeasonvarCatalogImporter::applyPreparedPage(SourcePage $page, SeasonvarPreparedCatalogPage $prepared, ?CatalogTitle $preferredCatalogTitle = null, ?int $importRunId = null, ?callable $progress = null): array{catalog_title:CatalogTitle,media_attached:int,media_updated:int,media_skipped:int,media_failed:int}`.
- Produces: `SeasonvarTitleManifestBuilder::fromPrepared(Collection $pages): SeasonvarTitleManifest`.
- Produces: `SeasonvarTitleManifestBuilder::fromCatalog(CatalogTitle $title): SeasonvarTitleManifest`.
- `SeasonvarTitleManifest::comparison(SeasonvarTitleManifest $local): array<string,int>` returns bounded counts only.

- [ ] **Step 1: Write failing no-network apply tests**

```php
public function test_prepared_pages_apply_to_one_canonical_title_without_http(): void
{
    Http::preventStrayRequests();
    $canonical = CatalogTitle::factory()->create();
    $prepared = $this->preparedPagesForSeasons([1 => 20, 2 => 20, 9 => 11]);

    foreach ($prepared as [$page, $payload]) {
        app(SeasonvarCatalogImporter::class)->applyPreparedPage(
            $page,
            $payload,
            $canonical,
            101,
        );
    }

    $this->assertSame(3, $canonical->seasons()->count());
    $this->assertSame(51, $canonical->episodes()->count());
    $this->assertDatabaseCount('catalog_titles', 1);
}
```

Also create a test proving a local-only episode survives a complete apply and is counted as `local_only`, not deleted.

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/SeasonvarCatalogPreparedApplyTest.php`

Expected: FAIL because `applyPreparedPage()` and manifest classes do not exist.

- [ ] **Step 3: Extract the database apply block**

Move parser-result database work from `parsePage()` into `applyPreparedPage()`: preferred identity, editorial fields, relations, aliases, ratings, recommendation signals, reviews, seasons, episodes, prepared media/health state, title-page reconciliation, and search index. Do not call external HTTP services from this method.

`parsePage()` becomes exactly: prepare current page, apply prepared page, return existing result shape.

- [ ] **Step 4: Implement stable manifests**

Use keys:

```php
$seasonKey = $kind.'|'.$number.'|'.$sourceUrlHash;
$episodeKey = $seasonKind.'|'.$seasonNumber.'|'.$episodeKind.'|'.$episodeNumber;
$mediaKey = $sourceMediaKey;
```

The comparison returns `source_seasons`, `local_seasons`, `source_episodes`, `local_episodes`, `source_media`, `local_media`, `missing_local`, and `local_only`. Never include raw URLs/keys in run summary.

- [ ] **Step 5: Run GREEN and synchronous compatibility**

Run:

```bash
php artisan test tests/Feature/SeasonvarCatalogPreparedApplyTest.php tests/Feature/SeasonvarParsePageCommandTest.php
./vendor/bin/pint --format agent app/DTOs/Seasonvar/SeasonvarTitleManifest.php app/Services/Seasonvar/SeasonvarTitleManifestBuilder.php app/Services/Seasonvar/SeasonvarCatalogImporter.php tests/Feature/SeasonvarCatalogPreparedApplyTest.php
```

Expected: PASS with no source HTTP during prepared apply.

- [ ] **Step 6: Commit**

```bash
git add app/DTOs/Seasonvar/SeasonvarTitleManifest.php app/Services/Seasonvar/SeasonvarTitleManifestBuilder.php app/Services/Seasonvar/SeasonvarCatalogImporter.php tests/Feature/SeasonvarCatalogPreparedApplyTest.php
git commit -m "refactor: apply prepared Seasonvar catalog pages"
```

---

### Task 5: Dispatch Every Season Page Immediately and Discover More Dynamically

**Files:**
- Create: `app/Services/Seasonvar/SeasonvarImportTitleGroupDispatcher.php`
- Create: `app/Jobs/PrepareSeasonvarImportTitlePage.php`
- Modify: `app/Services/Seasonvar/SeasonvarPageClaimManager.php`
- Test: `tests/Feature/SeasonvarImportTitleGroupDispatcherTest.php`

**Interfaces:**
- Produces: `SeasonvarImportTitleGroupDispatcher::start(CatalogTitle $title, string $queue): SeasonvarImportTitleGroup`.
- Produces: `SeasonvarImportTitleGroupDispatcher::addUrls(SeasonvarImportTitleGroup $group, array $urls): int` returning newly dispatched count.
- Produces: `SeasonvarImportTitleGroupDispatcher::adoptPage(SeasonvarImportRun $run, SourcePage $page, string $queue, ?CatalogTitle $title = null): SeasonvarImportPreparedPage` for global/backlog compatibility.
- Job payload: only `preparedPageId`.

- [ ] **Step 1: Write failing uncapped fan-out tests**

```php
public function test_nine_urls_dispatch_nine_independent_jobs_without_chunking(): void
{
    Queue::fake();
    $title = $this->titleWithSeasonUrls(range(1, 9));

    $group = app(SeasonvarImportTitleGroupDispatcher::class)
        ->start($title, 'seasonvar-title-refresh');

    Queue::assertPushed(PrepareSeasonvarImportTitlePage::class, 9);
    $this->assertSame(9, $group->fresh()->expected_pages);
    $this->assertSame(9, $group->preparedPages()->count());
}
```

Add tests for 50 URLs, URL deduplication, invalid host rejection, and a prepared parent discovering a tenth URL before releasing its claim.

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/SeasonvarImportTitleGroupDispatcherTest.php`

Expected: FAIL because dispatcher/job do not exist.

- [ ] **Step 3: Implement idempotent addUrls**

Normalize all URLs, upsert `SourcePage`, use prepared-row `firstOrCreate`, atomically increment `expected_pages` only for created rows, and dispatch one job per created row with `afterCommit()`. Do not call `chunk()`, `take()`, semaphore middleware, or a concurrency limiter.

- [ ] **Step 4: Implement the preparation job**

`PrepareSeasonvarImportTitlePage` implements `ShouldQueue` and `ShouldBeUnique`, sets configured connection/row queue, timeout/retry window/backoff, and unique ID `seasonvar-prepared-page:{id}`. In `handle()`:

```php
$preparedRow = SeasonvarImportPreparedPage::query()
    ->with(['group.run', 'sourcePage.source'])
    ->findOrFail($this->preparedPageId);
$token = $claims->claim($preparedRow->sourcePage, $preparedRow->seasonvar_import_run_id);
if ($token === null) { $this->release(30); return; }

try {
    $payload = $preparer->prepare($preparedRow->sourcePage, $preparedRow->seasonvar_import_run_id);
    $dispatcher->addUrls($preparedRow->group, $payload->discoveredSeasonUrls);
    $preparedRow->markPrepared(
        $payload->toPayload(),
        $payload->warnings,
        $payload->contentHash,
        SeasonvarCatalogParser::METADATA_VERSION,
    );
} finally {
    $claims->release($preparedRow->source_page_id, $preparedRow->seasonvar_import_run_id, $token);
}
```

The child dispatch occurs before parent claim release. `failed()` writes only sanitized terminal state/counters.

- [ ] **Step 5: Run GREEN and claim regressions**

Run:

```bash
php artisan test tests/Feature/SeasonvarImportTitleGroupDispatcherTest.php tests/Feature/SeasonvarParallelImportTest.php --filter=claim
./vendor/bin/pint --format agent app/Services/Seasonvar/SeasonvarImportTitleGroupDispatcher.php app/Jobs/PrepareSeasonvarImportTitlePage.php app/Services/Seasonvar/SeasonvarPageClaimManager.php tests/Feature/SeasonvarImportTitleGroupDispatcherTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Seasonvar/SeasonvarImportTitleGroupDispatcher.php app/Jobs/PrepareSeasonvarImportTitlePage.php app/Services/Seasonvar/SeasonvarPageClaimManager.php tests/Feature/SeasonvarImportTitleGroupDispatcherTest.php
git commit -m "feat: fan out every Seasonvar season page"
```

---

### Task 6: Consolidate Prepared Pages With One Safe Finalizer

**Files:**
- Create: `app/Jobs/FinalizeSeasonvarImportTitleGroup.php`
- Modify: `app/Services/Seasonvar/CatalogTitleRefreshStateStore.php`
- Modify: `app/Models/SeasonvarImportTitleGroup.php`
- Test: `tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php`
- Test: `tests/Feature/SeasonvarTitleMergeTest.php`

**Interfaces:**
- Produces: `FinalizeSeasonvarImportTitleGroup(int $groupId)`.
- Produces: `CatalogTitleRefreshStateStore::partial(int $catalogTitleId, ?int $runId = null): CatalogTitleRefreshState`.
- Finalizer queue is read from the group row; unique ID `seasonvar-title-group-finalizer:{groupId}`.
- Extends `SeasonvarImportTitleGroupDispatcher::start()` and first `adoptPage()` to dispatch exactly one delayed group finalizer; later dynamic URLs reuse it through the release loop.

- [ ] **Step 1: Write failing deterministic finalizer tests**

Test that finalizer releases while any page is `queued/preparing`, applies shuffled seasons in numeric order after all are terminal, takes the canonical group lock only for apply, keeps one canonical title, stores manifest counts, and marks partial when one page failed.

```php
$job = (new FinalizeSeasonvarImportTitleGroup($group->id))->withFakeQueueInteractions();
$job->handle(/* real services */);
$job->assertReleased(delay: config('seasonvar.title_refresh.finalizer_delay_seconds'));
```

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php`

Expected: FAIL because finalizer does not exist.

- [ ] **Step 3: Implement terminal accounting and lock scope**

If `queued/preparing` rows exist or `expected_pages !== prepared+failed`, heartbeat and release. Otherwise atomically claim `finalizing`, acquire the canonical `SeasonvarImportGroupKey` lock, load validated payloads, and sort by canonical/current page, minimum regular season number, then source-page ID.

- [ ] **Step 4: Apply, compare, merge, and complete**

Build source/local-before manifests, call `applyPreparedPage()` for every prepared row with one preferred canonical title, merge verified duplicates, build local-after manifest, update bounded run summary/counters, invalidate catalog cache, mark page rows applied, and set terminal group/run/refresh states. Visitor groups already have `catalog_title_id`; for a new global group, apply the first deterministic payload with `preferredCatalogTitle=null`, store its returned title ID on the group inside the same finalizing transaction, and pass that exact title to every remaining payload.

`completed` requires zero failed pages and zero preparation warnings. Otherwise use `partial`; never call `CatalogTitleRefreshStateStore::completed()` for partial groups.

- [ ] **Step 5: Run GREEN and merge regressions**

Run:

```bash
php artisan test tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php tests/Feature/SeasonvarTitleMergeTest.php
./vendor/bin/pint --format agent app/Jobs/FinalizeSeasonvarImportTitleGroup.php app/Services/Seasonvar/CatalogTitleRefreshStateStore.php app/Models/SeasonvarImportTitleGroup.php tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Jobs/FinalizeSeasonvarImportTitleGroup.php app/Services/Seasonvar/CatalogTitleRefreshStateStore.php app/Models/SeasonvarImportTitleGroup.php tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php
git commit -m "feat: finalize parallel Seasonvar title groups"
```

---

### Task 7: Convert Visitor Refresh to Short Coordinator Fan-Out

**Files:**
- Modify: `app/Jobs/RefreshSeasonvarCatalogTitle.php`
- Modify: `tests/Feature/RefreshSeasonvarCatalogTitleJobTest.php`
- Modify: `tests/Feature/CatalogTitleBackgroundRefreshTest.php`
- Modify: `tests/Feature/CatalogTitleLiveRefreshTest.php`

**Interfaces:**
- `RefreshSeasonvarCatalogTitle::handle(SeasonvarImportTitleGroupDispatcher $groups, SeasonvarUrl $urls, CatalogTitleRefreshStateStore $states): void`.
- It creates the run/group and dispatches `FinalizeSeasonvarImportTitleGroup`; it no longer calls synchronous `SeasonvarImportPipeline::run()` or holds the title lock during source HTTP.

- [ ] **Step 1: Rewrite job expectations first**

Assert the coordinator:

- stays unique per catalog title;
- uses `seasonvar-title-refresh`;
- rejects non-Seasonvar saved URL;
- marks state running with the new run ID;
- dispatches one page job per canonical/known season URL immediately;
- dispatches one group finalizer;
- performs zero HTTP/catalog mutations itself.

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/RefreshSeasonvarCatalogTitleJobTest.php tests/Feature/CatalogTitleBackgroundRefreshTest.php`

Expected: FAIL because the old job calls the synchronous pipeline.

- [ ] **Step 3: Implement minimal coordinator**

Replace pipeline/merger/group-lock injection with `SeasonvarImportTitleGroupDispatcher`. Preserve URL normalization, queue policy, retry policy, unique ID/store, sanitized `failed()`, and per-title state. The dispatcher owns import run/group creation and all page dispatches.

- [ ] **Step 4: Run GREEN and Livewire contract tests**

Run:

```bash
php artisan test tests/Feature/RefreshSeasonvarCatalogTitleJobTest.php tests/Feature/CatalogTitleBackgroundRefreshTest.php tests/Feature/CatalogTitleLiveRefreshTest.php
./vendor/bin/pint --format agent app/Jobs/RefreshSeasonvarCatalogTitle.php tests/Feature/RefreshSeasonvarCatalogTitleJobTest.php tests/Feature/CatalogTitleBackgroundRefreshTest.php
```

Expected: PASS; 15-minute coordinator tests remain unchanged.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/RefreshSeasonvarCatalogTitle.php tests/Feature/RefreshSeasonvarCatalogTitleJobTest.php tests/Feature/CatalogTitleBackgroundRefreshTest.php tests/Feature/CatalogTitleLiveRefreshTest.php
git commit -m "feat: fan out full title refreshes from visits"
```

---

### Task 8: Move Global Queued Importer Onto the Same Group Pipeline

**Files:**
- Modify: `app/Services/Seasonvar/SeasonvarQueuedImportDispatcher.php`
- Modify: `app/Jobs/ImportSeasonvarSourcePage.php`
- Modify: `app/Jobs/FinalizeSeasonvarQueuedImport.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportStorageMaintenance.php`
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`

**Interfaces:**
- Global dispatcher calls `SeasonvarImportTitleGroupDispatcher::adoptPage()` for each claimed/selected source page using `seasonvar-import` queue.
- Legacy `ImportSeasonvarSourcePage` payloads already in Redis delegate to the same group dispatcher/preparer; the class remains loadable until old queues drain.
- Global finalizer requires zero active claims and zero nonterminal title groups before catalog-wide maintenance.

- [ ] **Step 1: Add failing global integration tests**

Assert two pages of the same URL family create one group/two page jobs, two families create two independent groups, legacy job delegates without direct parser writes, and global finalizer releases while a title group is nonterminal.

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/SeasonvarParallelImportTest.php`

Expected: targeted new assertions FAIL while existing claim/finalizer assertions remain green.

- [ ] **Step 3: Group selected global pages**

Replace direct `ImportSeasonvarSourcePage::dispatch()` in `dispatchEligiblePages()` with group `adoptPage()`. Preserve force, claims, selected counters, cancellation checks, queue connection, and after-commit semantics. No page-count cap beyond the existing catalog cycle selection policy; within each selected title, every page dispatches immediately.

- [ ] **Step 4: Preserve old Redis payload compatibility**

Change `ImportSeasonvarSourcePage::handle()` to adopt its already claimed page into a group and invoke/dispatch the generic preparation path. It must not retain an independent direct `parsePages()` catalog-write path.

- [ ] **Step 5: Gate global maintenance on group completion**

`FinalizeSeasonvarQueuedImport` releases while any group is `discovering/running/finalizing`; after terminal groups it runs only catalog-wide maintenance/recommendations. Count partial/failed groups into run completion status. Extend storage maintenance to delete expired prepared rows/groups in bounded chunks after their run retention window.

- [ ] **Step 6: Run GREEN and broad importer regressions**

Run:

```bash
php artisan test tests/Feature/SeasonvarParallelImportTest.php tests/Feature/SeasonvarParsePageCommandTest.php tests/Feature/SeasonvarImportMaintenanceTest.php
./vendor/bin/pint --format agent app/Services/Seasonvar/SeasonvarQueuedImportDispatcher.php app/Jobs/ImportSeasonvarSourcePage.php app/Jobs/FinalizeSeasonvarQueuedImport.php app/Services/Seasonvar/SeasonvarImportStorageMaintenance.php tests/Feature/SeasonvarParallelImportTest.php
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Seasonvar/SeasonvarQueuedImportDispatcher.php app/Jobs/ImportSeasonvarSourcePage.php app/Jobs/FinalizeSeasonvarQueuedImport.php app/Services/Seasonvar/SeasonvarImportStorageMaintenance.php tests/Feature/SeasonvarParallelImportTest.php
git commit -m "refactor: share parallel title groups with global import"
```

---

### Task 9: Configure Dedicated Worker Capacity and Documentation

**Files:**
- Create: `deploy/systemd/seasonvar-title-refresh-worker@.service`
- Modify: `deploy/systemd/seasonvar-import-worker@.service`
- Modify: `.env.example`
- Modify: `config/seasonvar.php`
- Modify: `docs/architecture.md`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `docs/environment.md`
- Modify: `docs/importer.md`
- Modify: `docs/queues.md`
- Modify: `docs/deployment.md`
- Modify: `docs/testing.md`
- Modify: `CHANGELOG.md`
- Modify: `tests/Unit/TitleBackgroundRefreshDocumentationTest.php`

**Interfaces:**
- Adds `seasonvar.title_refresh.finalizer_delay_seconds` and prepared retention config only; there is deliberately no `max_pages`, `concurrency`, or per-title worker limit setting.
- Dedicated systemd template listens only to `seasonvar-title-refresh`; global workers continue to listen to `seasonvar-import` with priority fallback only where documented.

- [ ] **Step 1: Write failing documentation/config assertions**

```php
$service = File::get(base_path('deploy/systemd/seasonvar-title-refresh-worker@.service'));
$this->assertStringContainsString('--queue=seasonvar-title-refresh', $service);
$this->assertStringNotContainsString('seasonvar-import', Str::after($service, '--queue='));
$this->assertArrayNotHasKey('concurrency_limit', config('seasonvar.title_refresh'));
$this->assertStringContainsString('без application-level limit', File::get(base_path('docs/queues.md')));
```

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Unit/TitleBackgroundRefreshDocumentationTest.php`

Expected: FAIL because the dedicated template/docs do not exist.

- [ ] **Step 3: Add the worker template and config**

Use `User=www`, existing working directory, Redis connection, timeout/retry/memory/max-time conventions, and only `--queue=seasonvar-title-refresh`. Document enabling a large IO-bound pool such as `seasonvar-title-refresh-worker@{1..32}.service`; make explicit that 32 is deployed process capacity, not an application cap and can be expanded without code changes.

- [ ] **Step 4: Update owned documentation**

Document fan-out/fan-in, staging tables, dynamic discovery, source/local manifests, partial behavior, global importer reuse, no-delete rule, queue monitoring, failure recovery, migrations, service installation, and the 15-minute completed-only window. Do not manually edit managed `project-docs` blocks.

- [ ] **Step 5: Run docs checks and tests**

Run:

```bash
php artisan project:docs-refresh --check
php artisan test tests/Unit/TitleBackgroundRefreshDocumentationTest.php
git diff --check
```

Expected: PASS and documentation already current.

- [ ] **Step 6: Commit**

```bash
git add .env.example config/seasonvar.php deploy/systemd docs CHANGELOG.md tests/Unit/TitleBackgroundRefreshDocumentationTest.php
git commit -m "docs: operate parallel Seasonvar title workers"
```

---

### Task 10: Full Verification, Production Migration, and Real-Title Audit

**Files:**
- Verify all changed files.
- No new feature code unless verification reveals a directly related defect; any fix repeats RED→GREEN first.

- [ ] **Step 1: Run formatter and focused suite**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test \
  tests/Feature/SeasonvarParallelTitleRefreshPersistenceTest.php \
  tests/Feature/SeasonvarCatalogPagePreparationTest.php \
  tests/Feature/SeasonvarCatalogPreparedApplyTest.php \
  tests/Feature/SeasonvarImportTitleGroupDispatcherTest.php \
  tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php \
  tests/Feature/RefreshSeasonvarCatalogTitleJobTest.php \
  tests/Feature/CatalogTitleBackgroundRefreshTest.php
```

Expected: PASS.

- [ ] **Step 2: Run broad importer and full suites**

```bash
php artisan test tests/Feature/SeasonvarParallelImportTest.php tests/Feature/SeasonvarParsePageCommandTest.php tests/Feature/SeasonvarTitleMergeTest.php tests/Unit/SeasonvarCatalogParserTest.php
php artisan test
php artisan project:docs-refresh --check
git diff --check
```

Expected: all PASS; record exact tests/assertions/duration.

- [ ] **Step 3: Recheck migration and branch safety**

```bash
git status --short --branch
php artisan migrate:status
php artisan route:list --path=titles
```

Expected: branch `main`, no unrelated changes, new migration pending before deploy.

- [ ] **Step 4: Deploy database and dedicated workers**

```bash
php artisan migrate --force
install -m 0644 deploy/systemd/seasonvar-title-refresh-worker@.service /etc/systemd/system/seasonvar-title-refresh-worker@.service
systemctl daemon-reload
systemctl enable --now seasonvar-title-refresh-worker@{1..32}.service
php artisan queue:restart
```

Do not stop healthy import workers until replacement title workers are active. Verify every new unit uses the expected queue and no unit has restart loops.

- [ ] **Step 5: Trigger and audit a real multi-season title**

Use the server-bound title ID for `ryzaia-8`, dispatch one refresh, then verify:

```bash
php artisan queue:monitor 'redis:seasonvar-title-refresh,redis:seasonvar-import' --max=1000
systemctl list-units 'seasonvar-title-refresh-worker@*.service' --no-pager
```

Database assertions: one canonical title, seasons 1–9, 170 episodes, 170 media records, nine prepared page rows dispatched without cap, completed state only after finalizer, bounded manifest counts, and no new failed jobs. HTTP assertions: public card shows nine seasons/170 episodes and old slugs redirect to canonical.

- [ ] **Step 6: Commit any verification-only doc evidence and confirm clean tree**

```bash
git status --short --branch
git log -10 --oneline --decorate
```

Expected: clean `main`; report commit hashes, exact verification results, worker count, queue sizes, production title counts, and any unrelated pre-existing limitation.
