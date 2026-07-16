# Strict Media Backlog Format Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove untrusted full-URL extension substring inference from the legacy media-size backlog while preserving every currently eligible production direct file and all downstream safety checks.

**Architecture:** `licensed_media.format` remains the normalized SQL preselection authority and the effective URL must still be HTTP(S). `ExternalMediaFileType` remains the canonical final playlist/direct classifier before metadata HTTP and download delivery, so the SQL query becomes narrower without weakening defense in depth.

**Tech Stack:** PHP 8.5, Laravel 13.19, Eloquent, SQLite production audit, Laravel Pint and Larastan.

## Global Constraints

- Work only on existing `main`; do not create a branch, worktree or pull request.
- Do not create, modify or execute automated tests.
- Do not stop the active importer, mutate production catalogue data, clear caches or issue external media requests.
- Keep `seasonvar:import` as the only public importer command.
- Keep HTTP(S), soft-delete, freshness, stable ID ordering, configured limits and canonical PHP classification unchanged.
- Do not add a migration, index, dependency or database-specific URL parser.
- Commit only task-owned hunks and preserve unrelated shared-tree work.

---

### Task 1: Strict SQL preselection

**Files:**
- Modify: `app/Services/Media/LicensedMediaFileSizeBacklog.php`

**Interfaces:**
- Consumes: normalized `licensed_media.format`, configured `playback.downloads.allowed_formats`, effective `playback_url|path` and existing due-state fields.
- Produces: the existing `LicensedMediaFileSizeBacklog::query(bool $force = false): Builder` with a strict stored-format eligibility predicate.

- [ ] **Step 1: Record the read-only production equivalence evidence**

Run aggregate SQL for stored direct HTTP(S), current wildcard eligibility, wildcard-only rows, playlist-marker conflicts and non-HTTP rows. Expected audited values: stored direct `873561`, current eligibility `873561`, wildcard-only `0`, playlist conflicts `0`, non-HTTP `0`.

- [ ] **Step 2: Remove only the URL extension wildcard loop**

Replace the eligibility format closure with the direct predicate:

```php
return LicensedMedia::query()
    ->whereIn('format', $formats)
    ->where(function (Builder $query): void {
        $query->whereRaw(self::EFFECTIVE_URL_SQL.' LIKE ?', ['http://%'])
            ->orWhereRaw(self::EFFECTIVE_URL_SQL.' LIKE ?', ['https://%']);
    });
```

Do not change `applyDueConstraint()`, configured format normalization, cache identity or status aggregation.

- [ ] **Step 3: Inspect generated SQL without executing external work**

Run a read-only application call for `app(LicensedMediaFileSizeBacklog::class)->query()->toSql()`. Expected: `format in (...)`, HTTP(S) predicates and freshness predicates remain; no `%.mp4%`, `%.m4v%`, `%.mov%`, `%.webm%`, `%.mkv%` or `%.avi%` predicate exists.

- [ ] **Step 4: Reconfirm production count equivalence**

Run the strict stored-format HTTP(S) aggregate again. Expected eligible total remains `873561`; no row is mutated.

### Task 2: Documentation and decision record

**Files:**
- Modify: `CHANGELOG.md`
- Modify: `docs/importer.md`
- Modify: `docs/performance.md`
- Modify: `docs/superpowers/specs/2026-07-16-strict-media-backlog-format-design.md`
- Modify: `docs/superpowers/plans/2026-07-16-strict-media-backlog-format.md`

**Interfaces:**
- Consumes: final query contract and measured equivalence evidence.
- Produces: concise operator/developer documentation for the trusted format boundary.

- [ ] **Step 1: Document importer eligibility semantics**

State in `docs/importer.md` that SQL backlog selection trusts normalized stored direct format plus HTTP(S), never query-string extension text, while `ExternalMediaFileType` repeats playlist/direct validation before networking.

- [ ] **Step 2: Document performance impact**

State in `docs/performance.md` that redundant wildcard extension comparisons were removed from the full eligibility scan and record the `873561 / 0 fallback-only` production audit without claiming an unmeasured timing improvement.

- [ ] **Step 3: Add the current-date changelog entry**

Record the stricter backlog classification, unchanged production eligibility and unchanged downstream safety/streaming contracts.

- [ ] **Step 4: Mark the implementation checklist accurately**

Mark only completed steps, add exact verification evidence and keep commit/push unchecked until each operation succeeds.

### Task 3: Non-test verification, isolated commit and push

**Files:**
- Verify every Task 1–2 file.

**Interfaces:**
- Consumes: strict query and documentation.
- Produces: task-only commits pushed to `origin/main`.

- [ ] **Step 1: Run syntax, formatting and static analysis**

Run `php -l app/Services/Media/LicensedMediaFileSizeBacklog.php`, path-targeted `./vendor/bin/pint --format agent` and focused Larastan. Expected: no syntax error, Pint pass and zero static-analysis errors.

- [ ] **Step 2: Run read-only application verification**

Run generated-SQL inspection, production count equivalence, `php artisan seasonvar:import --status`, `php artisan schedule:list` and `php artisan route:list --path=download`. Expected: strict SQL, count `873561`, working status, unchanged `500/480` schedule and protected download route.

- [ ] **Step 3: Review exact scope**

Run `git diff --check`, conflict/placeholder/debug/secret/source-URL searches and inspect the task patch. Confirm no test, migration, dependency or binary was added or modified.

- [ ] **Step 4: Commit task-only changes**

Use an isolated Git index and commit message:

```text
fix: trust normalized formats for media backlog
```

- [ ] **Step 5: Push and close the plan**

Push `main` to `origin/main`, verify local/remote hashes, mark this checklist complete in a one-file closure commit and push again.

## Final changed-file list

- [ ] `app/Services/Media/LicensedMediaFileSizeBacklog.php`
- [ ] `CHANGELOG.md`
- [ ] `docs/importer.md`
- [ ] `docs/performance.md`
- [ ] `docs/superpowers/specs/2026-07-16-strict-media-backlog-format-design.md`
- [ ] `docs/superpowers/plans/2026-07-16-strict-media-backlog-format.md`
