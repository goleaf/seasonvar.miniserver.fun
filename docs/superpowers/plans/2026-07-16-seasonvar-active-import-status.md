# Seasonvar Active Import Status Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `php artisan seasonvar:import --status` select the canonical active global sitemap import across sync/queue execution modes and display its persisted live video-size counters separately from the timestamped cached backlog aggregate.

**Architecture:** Preserve `SeasonvarQueueStatus` as the single read boundary for queue/status command data, but reuse `SeasonvarGlobalImportRunCoordinator::activeRun()` for authoritative active lifecycle selection and fall back only to the latest sitemap lifecycle. Extend the existing typed DTO with scalar run metadata; keep formatting in the console command and leave the database, scheduler, importer execution, networking and admin dashboard unchanged.

**Tech Stack:** PHP 8.5, Laravel 13.19, Eloquent, Redis queue metrics, Symfony Console through Laravel Artisan, Laravel Pint and Larastan.

## Global Constraints

- Work only on the existing `main` branch; do not create a branch, worktree or pull request.
- Do not create, modify or execute automated tests for this continuation.
- Do not mutate production data, clear caches, stop the active importer or make external media requests.
- Preserve the existing Redis queue metric meanings and `seasonvar:import` as the only public importer command.
- Do not force-refresh the large `licensed_media` aggregate; its capture timestamp remains the freshness authority.
- Do not expose remote URLs, process commands, signed query strings, errors or secrets.
- Commit only task-owned hunks; preserve the unrelated staged/unstaged technical-issue and profile work in the shared tree.

---

### Task 1: Canonical global run projection

**Files:**
- Modify: `app/DTOs/Seasonvar/SeasonvarQueueStatusData.php`
- Modify: `app/Services/Seasonvar/SeasonvarQueueStatus.php`

**Interfaces:**
- Consumes: `SeasonvarGlobalImportRunCoordinator::activeRun(): ?SeasonvarImportRun` and persisted `SeasonvarImportRun` counters.
- Produces: the existing `SeasonvarQueueStatusData` plus `runExecutionMode`, `lastHeartbeatAt`, `mediaSizesChecked`, `mediaSizesKnown`, `mediaSizesUnknown`, `mediaSizesUnsupported`, `mediaSizeChecksFailed` and `mediaSizeKnownBytes` readonly fields.

- [x] **Step 1: Preserve queue transport selection for queued metrics**

Keep the existing `$activeRuns` query, `activeRuns` count, queue sizes and live-claim count unchanged. Do not use a targeted queued URL run as the global projection.

- [x] **Step 2: Select the canonical global lifecycle**

Inject `SeasonvarGlobalImportRunCoordinator` into `SeasonvarQueueStatus`. Resolve:

```php
$run = $this->globalRuns->activeRun()
    ?? SeasonvarImportRun::query()
        ->where('mode', 'sitemap')
        ->latest('id')
        ->first();
```

If no sitemap lifecycle exists, return nullable run fields and zero counters. Targeted URL runs remain in the administrative history but never acquire a global label.

- [x] **Step 3: Extend the typed DTO without presentation logic**

Add nullable execution-mode/heartbeat fields and integer size counters. Map values directly from the selected model, casting nullable model counters to integers and never formatting bytes in the service.

- [x] **Step 4: Reproduce selection through a read-only one-off application call**

Run a bounded Artisan/Tinker read that prints selected run ID, mode, status and size count. Expected production result while the observed import remains active: global run `#887`, `sync`, `running`, with a non-decreasing `mediaSizesChecked` value; it must not select completed URL run `#889`.

### Task 2: Honest console status presentation

**Files:**
- Modify: `app/Console/Commands/ImportSeasonvar.php`

**Interfaces:**
- Consumes: the extended `SeasonvarQueueStatusData` and existing `HumanFileSizeFormatter`.
- Produces: Russian read-only console rows for the canonical global lifecycle and its exact/human size counters.

- [x] **Step 1: Clarify the global lifecycle labels**

Rename `Основной active/last run` to `Глобальный active/last run`. Add execution mode and heartbeat rows. Keep queue connection/pending/delayed/reserved/live-claim/active-queued rows unchanged.

- [x] **Step 2: Show live file-size progress from the selected run**

Add rows for checked, known, unknown, unsupported and failed size inspections. Format known bytes with the existing formatter while retaining exact bytes:

```php
sprintf(
    '%s (%d байт)',
    $fileSizes->format($status->mediaSizeKnownBytes, 'ru') ?? '0 B',
    $status->mediaSizeKnownBytes,
)
```

- [x] **Step 3: Preserve the separate cached backlog table**

Do not merge live run counters with `LicensedMediaFileSizeBacklogStatusData`. Continue displaying `Снимок построен` so operators can distinguish a current run from a bounded cached catalogue aggregate.

- [x] **Step 4: Re-run the public status command**

Run `php artisan seasonvar:import --status`. Expected: queue metrics remain present, primary global row is the active sync sitemap run when one exists, live size counters are visible, and the cached backlog snapshot retains its original capture timestamp.

### Task 3: Project documentation and checklist

**Files:**
- Modify: `docs/importer.md`
- Modify: `docs/queues.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/superpowers/plans/2026-07-16-seasonvar-active-import-status.md`

**Interfaces:**
- Consumes: the final CLI behavior and production reproduction evidence.
- Produces: one canonical importer contract plus a short queue-operation note and accurate completion evidence.

- [x] **Step 1: Document status freshness boundaries**

In `docs/importer.md`, state that CLI status prioritizes the canonical active sitemap lifecycle across sync/queue modes, exposes its persisted live size counters, and keeps those values separate from the timestamped cached global backlog snapshot.

- [x] **Step 2: Correct queue documentation**

Update `docs/queues.md` to the current scheduled `500` item / `480` second contract and state that queue transport metrics do not replace the global lifecycle projection.

- [x] **Step 3: Add one dated changelog entry**

Record the corrected active-run selection and live size visibility without duplicating the implementation specification.

- [x] **Step 4: Mark every completed plan item accurately**

Record exact verification outputs and the final changed-file list. Leave commit/push unchecked until remote confirmation succeeds.

### Task 4: Non-test verification, isolated commit and push

**Files:**
- Verify all files from Tasks 1–3.

**Interfaces:**
- Consumes: completed implementation and documentation.
- Produces: formatted/static-verified code committed and pushed directly to `origin/main` without unrelated files.

- [x] **Step 1: Run PHP and style verification**

Run `php -l` on all changed PHP files, path-targeted `./vendor/bin/pint --format agent`, and focused Larastan for the DTO, service and command. Expected: no syntax errors, no Pint changes remaining and zero static-analysis errors.

- [x] **Step 2: Run read-only Laravel verification**

Run `php artisan seasonvar:import --status`, `php artisan schedule:list`, and `php artisan route:list --path=download`. Do not execute tests. Expected: correct active global run, unchanged 500/480 schedule and authenticated download route.

- [x] **Step 3: Review the exact task diff**

Run `git diff --check`, placeholder/conflict/debug/secret searches and inspect the task-only patch. Confirm no migration, dependency, test or binary file was added.

- [x] **Step 4: Commit only task-owned changes**

Use an isolated Git index so unrelated shared-tree changes are excluded. Commit message:

```text
fix: report active Seasonvar import progress
```

- [ ] **Step 5: Push and record remote evidence**

Confirm branch `main`, push `origin main`, compare local `HEAD`, `origin/main` and `git ls-remote origin refs/heads/main`, then mark this step complete in a one-file documentation closure commit and push it.

## Verification evidence

- [x] PHP lint passed for the DTO, status service and command.
- [x] Path-targeted Pint passed.
- [x] Focused Larastan passed with `0` errors.
- [x] The read-only projection used three SQL queries and selected `#887 / sync / running`; its persisted size counter advanced from 12,458 to 12,925 during verification.
- [x] Public CLI output showed the active global lifecycle, heartbeat, exact/human live bytes and the separately captured backlog snapshot.
- [x] Scheduler remained `--media-size-limit=500 --media-size-time-budget=480` every ten minutes.
- [x] Download route remained authenticated, throttled and private.
- [x] `git diff --check`, conflict-marker, placeholder, debug, secret/source-URL and binary-scope review passed.
- [x] No migration, dependency, test or binary file was created or modified by this task; no test runner was executed.

## Final changed-file list

- [x] `app/DTOs/Seasonvar/SeasonvarQueueStatusData.php`
- [x] `app/Services/Seasonvar/SeasonvarQueueStatus.php`
- [x] `app/Console/Commands/ImportSeasonvar.php`
- [x] `docs/importer.md`
- [x] `docs/queues.md`
- [x] `CHANGELOG.md`
- [x] `docs/superpowers/specs/2026-07-16-seasonvar-active-import-status-design.md`
- [x] `docs/superpowers/plans/2026-07-16-seasonvar-active-import-status.md`
