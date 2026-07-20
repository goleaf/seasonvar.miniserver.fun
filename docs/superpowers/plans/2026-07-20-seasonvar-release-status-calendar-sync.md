# Seasonvar Release Status Calendar Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert a fully normalized Seasonvar season status into an idempotent provider calendar event and safely queue the last 1–1000 serial URLs from the current XML through the existing importer.

**Architecture:** A focused `SeasonvarReleaseObservationSynchronizer` runs inside the existing catalog transaction after episodes are bulk-saved. A bounded queued-only `--sitemap-tail` selector reuses sitemap mirroring, source-page storage, global single-flight, claims, title groups and finalizers; normal scheduled imports automatically keep events current.

**Tech Stack:** PHP 8.5, Laravel 13.20, Eloquent/SQLite, Laravel queues/Artisan, PHPUnit 12.5.

## Global Constraints

- Work only on the existing `main`; do not create branches or worktrees.
- Keep `php artisan seasonvar:import` as the only public Seasonvar import command.
- Do not add dependencies, migrations, `.env` changes, a scheduler or a second importer pipeline.
- Never infer the next episode date or write provider translation availability into `Episode::released_at`.
- Keep raw Seasonvar URLs out of calendar references, run summaries, notifications and public payloads.
- Preserve manual locks and the existing release-source priority hierarchy.
- Use tests before implementation, `Http::preventStrayRequests()` for network-facing tests and `./vendor/bin/pint --dirty --format agent` after PHP changes.
- Do not stage, unstage or commit unrelated shared-worktree changes; a task-only commit is allowed only after exact status/diff review proves isolation.

---

### Task 1: Normalize provider observations into calendar entries

**Files:**
- Create: `app/Services/Seasonvar/SeasonvarReleaseObservationSynchronizer.php`
- Create: `tests/Feature/SeasonvarReleaseObservationSynchronizerTest.php`
- Modify: `app/Observers/LicensedMediaReleaseScheduleObserver.php`

**Interfaces:**
- Consumes: `synchronize(CatalogTitle $catalogTitle, Season $season, SourcePage $sourcePage): ?ReleaseScheduleEntry`.
- Produces: one exact-date provider `translation_release`, `subtitle_release` or `episode_release`, plus revision/correction/cache/notification side effects on material change.

- [x] **Step 1: Write failing creation/classification tests**

Create factories for one published title, regular season and exact episode. Set season fields to `2026-07-19`, episode `3`, translation `Coldfilm`, raw status `19.07.2026 3 серия (Coldfilm) из 8`, then assert:

```php
$entry = app(SeasonvarReleaseObservationSynchronizer::class)
    ->synchronize($title, $season, $sourcePage);

$this->assertSame(ReleaseScheduleEntryType::TranslationRelease, $entry?->entry_type);
$this->assertSame(ReleaseScheduleSource::Provider, $entry?->source);
$this->assertSame(ReleaseDatePrecision::ExactDate, $entry?->precision);
$this->assertSame('2026-07-19', $entry?->date_value?->toDateString());
$this->assertSame($episode->id, $entry?->episode_id);
$this->assertSame('Coldfilm', $entry?->translation_name);
$this->assertNull($episode->fresh()->released_at);
```

Add separate assertions that `Субтитры` maps to `SubtitleRelease` without a translation identity, while an empty translation maps to provider `EpisodeRelease`.

- [x] **Step 2: Run the test and verify RED**

Run:

```bash
php artisan test --filter=SeasonvarReleaseObservationSynchronizerTest
```

Expected: FAIL because `SeasonvarReleaseObservationSynchronizer` does not exist.

- [x] **Step 3: Implement the smallest synchronizer**

The service must:

```php
public function synchronize(
    CatalogTitle $catalogTitle,
    Season $season,
    SourcePage $sourcePage,
): ?ReleaseScheduleEntry
```

It checks `ReleaseCalendarSchema::ready()`, non-empty raw status, valid `latest_episode_released_at`, positive `episodes_released`, matching title/season identity and an existing regular episode. It classifies subtitles with `/\b(?:субтитр\p{L}*|subtitles?|subs?)\b/iu`, builds the existing logical key, locks the current entry, rejects locked/higher-priority rows, clears incompatible partial/datetime fields, writes exact-date state and one `ReleaseScheduleCorrection` with reason `seasonvar_release_status_sync`. Cache and recent-window notification work is registered after commit only for a material write.

- [x] **Step 4: Add failing idempotency/priority/correction tests**

Assert:

```php
$first = $sync->synchronize($title, $season, $sourcePage);
$second = $sync->synchronize($title, $season, $sourcePage);
$this->assertSame($first?->id, $second?->id);
$this->assertSame(1, $first?->fresh()->revision);
$this->assertSame(1, $first?->corrections()->count());
```

Then change only the date and assert revision `2`, one logical row and two corrections. Create locked/editorial rows and assert no overwrite. Use a missing episode and incomplete raw status and assert `null` with zero entries.

- [x] **Step 5: Run RED, implement priority/idempotency, then run GREEN**

Run the same focused class after each minimal change. Expected final result: all synchronizer tests PASS.

- [x] **Step 6: Cover provider-to-portal precision upgrade**

Add a regression proving `LicensedMediaReleaseScheduleObserver` upgrades the same translation logical key from provider exact date to portal exact datetime while clearing `date_value`, `date_end`, year/month/quarter. Modify the observer fill payload to explicitly null incompatible exact-date/partial fields.

- [x] **Step 7: Run focused calendar tests**

```bash
php artisan test --filter='SeasonvarReleaseObservationSynchronizerTest|ReleaseCalendarDefaultViewTest'
```

Expected: PASS.

---

### Task 2: Invoke synchronization in the existing catalog transaction

**Files:**
- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Modify: `tests/Feature/SeasonvarCatalogPreparedApplyTest.php`

**Interfaces:**
- Consumes: Task 1 `SeasonvarReleaseObservationSynchronizer::synchronize(...)`.
- Produces: every parsed current-season observation reaches the calendar only after the matching episode is bulk-saved.

- [x] **Step 1: Add a failing prepared-page integration test**

Build `SeasonvarCatalogData` with current season `1`, three episodes and:

```php
'latest_episode_released_at' => '2026-07-19',
'episodes_released' => 3,
'episodes_total' => 8,
'translation_name' => 'Coldfilm',
'release_status_text' => '19.07.2026 3 серия (Coldfilm) из 8',
```

Call `applyPreparedPage()` and assert the imported third episode owns one provider translation event. Also include a sibling season row with stale fields and assert only `currentSeasonNumber` is synchronized.

- [x] **Step 2: Run RED**

```bash
php artisan test --filter=SeasonvarCatalogPreparedApplyTest
```

Expected: the new assertion fails with zero calendar entries.

- [x] **Step 3: Inject and call the synchronizer**

Add constructor injection and, immediately after `syncEpisodes(...)` inside `SeasonvarDatabaseTransaction`, call:

```php
$currentSeason = $seasons[$data->currentSeasonNumber] ?? null;

if ($currentSeason instanceof Season) {
    $this->releaseObservations->synchronize($catalogTitle, $currentSeason, $page);
}
```

- [x] **Step 4: Run GREEN and parser regressions**

```bash
php artisan test --filter='SeasonvarCatalogPreparedApplyTest|SeasonvarCatalogParserTest|SeasonvarImportTitleGroupFinalizerTest'
```

Expected: PASS.

---

### Task 3: Add bounded queued XML-tail selection

**Files:**
- Modify: `app/Console/Commands/ImportSeasonvar.php`
- Modify: `app/Services/Seasonvar/SeasonvarGlobalImportRunCoordinator.php`
- Modify: `app/Services/Seasonvar/SeasonvarQueuedImportDispatcher.php`
- Modify: `app/Services/Seasonvar/SeasonvarRefreshPlanner.php`
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`

**Interfaces:**
- Consumes: `--sitemap-tail=1..1000` only with `--queued --force` and discovery.
- Produces: safe summary scalar `sitemap_tail_limit`; bounded page chunks for the exact serial URL tail; existing queue jobs/groups only.

- [x] **Step 1: Add failing command validation tests**

Assert success delegation for:

```php
$this->artisan('seasonvar:import', [
    '--queued' => true,
    '--force' => true,
    '--sitemap-tail' => 1000,
])->assertExitCode(0);
```

Assert failure for `0`, `1001`, absent `--queued`, absent `--force`, `--no-discovery`, URL, `--forever`, `--sleep`, media/inventory/status modes and a non-serial `--page-type`.

- [x] **Step 2: Run RED**

```bash
php artisan test --filter='SeasonvarParallelImportTest::test_queued_command_accepts_a_bounded_sitemap_tail|SeasonvarParallelImportTest::test_sitemap_tail_rejects_incompatible_options'
```

Expected: FAIL because the option is unknown.

- [x] **Step 3: Add the command option and safe scalar propagation**

Extend the existing signature with:

```text
{--sitemap-tail= : Принудительно обработать последние 1–1000 serial URL из актуального XML в queued-режиме}
```

Normalize only canonical integer text, enforce the compatibility matrix, and pass the optional limit as the fourth dispatcher argument. Extend coordinator `acquire()` to store only `sitemap_tail_limit` in `summary`.

- [x] **Step 4: Add failing exact-selection test**

Fake sitemap mirror output with actor/static entries interleaved with four serial URLs. Request tail `3`; assert claims/groups belong only to the final three serial URLs in original XML order and summary contains counts/limit but no URL list.

- [x] **Step 5: Implement planner/dispatcher selection**

Add:

```php
public function forcedPageChunksForUrls(
    array $urls,
    int $chunkSize,
    ?int $importRunId = null,
): iterable
```

Normalize the dispatcher tail by reverse scan, keep distinct serial URLs, restore XML order, query hashes in chunks no larger than `500`, eager-load the bounded source projection, key by hash, and emit only matched rows in requested order/chunk size. Do not store the URL list in run summary.

- [x] **Step 6: Run queued importer tests**

```bash
php artisan test --filter=SeasonvarParallelImportTest
php artisan test --filter=SeasonvarQueueJobContractTest
```

Expected: PASS, including unchanged two/three-argument dispatcher mocks.

---

### Task 4: Verification, documentation and controlled production backfill

**Files:**
- Modify: `docs/release-calendar.md`
- Modify: `docs/importer.md`
- Modify: `docs/queues.md`
- Modify: `docs/deployment.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Produces: verified product behavior, operational rollback, user-visible history and an auditable queued run for the last 1000 XML serial URLs.

- [x] **Step 1: Format and run focused/broad tests**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter='SeasonvarReleaseObservationSynchronizerTest|SeasonvarCatalogPreparedApplyTest|SeasonvarCatalogParserTest|SeasonvarParallelImportTest|ReleaseCalendarDefaultViewTest'
php artisan test
```

Expected: PASS; any unrelated concurrent failure is diagnosed and recorded, not hidden.

- [x] **Step 2: Run static/document checks**

```bash
php artisan project:docs-refresh --check
git diff --check
rg -n "release_status_text|latest_episode_released_at|sitemap-tail|seasonvar_release_status_sync" app tests docs README.md CHANGELOG.md
```

Expected: no stale managed blocks, whitespace errors, duplicate importer command or legacy direct mapping.

- [x] **Step 3: Update visitor and operator documentation**

Document continuous calendar updates and bounded command in Russian, append a separate `2026-07-20` changelog entry, keep `История обновлений для посетителей` last in README, and mark compliance statuses only from fresh evidence.

- [x] **Step 4: Perform pre-backfill operations checks**

Read-only commands:

```bash
php artisan seasonvar:import --status
php artisan app:deployment-check --json
php artisan migrate:status
```

Proceed only when the prior global run is terminal, live claims/writers are safe, workers are healthy enough for existing queue recovery and current backup evidence satisfies the documented production write boundary. Do not clear caches/queues or alter `.env`.

- [x] **Step 5: Queue the exact bounded backfill**

```bash
php artisan seasonvar:import --queued --force --sitemap-tail=1000
```

Capture only safe run ID/count/status evidence. Monitor through `php artisan seasonvar:import --status` until terminal; use existing watchdog/finalizer recovery, never `queue:clear`.

- [x] **Step 6: Verify control pages and calendar data**

Read-only assertions must show current source observations for `serial-41165` and `serial-49406`, canonical episode-bound provider calendar entries, no write to `episodes.released_at`, one logical entry per event and visible `/calendar` output. Run desktop/mobile browser smoke only if no other browser owner is active.

Evidence 20.07.2026: targeted run `#953` обновил контрольный тайтл за пределами хвоста. XML-tail run `#954` сохранил exact limit/selection `1000/1000`, расширил группы sibling seasons до `1592/1592` и завершился `completed` с `0` page failures, `0` active/problem groups и `0` live claims. Provider → portal corrections дали по одной logical translation row `RuDub` для «Вестис» (серия 3) и «Интервью с вампиром» (серия 7), не заполняя `episodes.released_at`; filtered HTTPS calendar output показал дату, сезон, серию и перевод. Финальная focused матрица прошла 96 тестов/503 assertions, полный suite текущего снимка — 1 420 passed, 11 skipped и 122 979 assertions.

- [ ] **Step 7: Final compliance and delivery**

Re-read applicable requirements and task plan, scan all repository references for duplicate/stale paths, then inspect:

```bash
git status --short --branch
git diff --name-only
git diff --check
```

If and only if the task files can be isolated without altering the shared index, commit them on `main` with a Russian/technical scoped message and push the configured remote. Otherwise leave delivery `unresolved` and report the shared-worktree blocker honestly.
