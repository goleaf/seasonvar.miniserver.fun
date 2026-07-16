# Targeted Media-Size Cache Invalidation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace importer-wide cache fan-out after a file-size metadata write with the exact affected title/player cache generation bump.

**Architecture:** `LicensedMediaFileSizeMetadataWriter` remains the single persistence boundary and `CatalogCacheInvalidator` remains the single catalogue cache mutation owner. A new focused invalidator method advances only `CacheDomain::TitleDetail` scope `title:{id}` with existing transaction deferral and telemetry conventions; general importer changes retain their current collection propagation and warming behavior.

**Tech Stack:** PHP 8.5, Laravel 13.19, Eloquent/DB transaction callbacks, existing Redis-backed `CacheVersionRegistry`, `CacheTelemetry`, Pint and Larastan.

## Global Constraints

- Work only on the existing `main`; do not create a branch, worktree or pull request.
- Preserve all unrelated staged, unstaged and untracked work in the shared repository.
- Do not create, modify or execute automated tests.
- Do not change schema, configuration, routes, authorization, streaming, Range, translations, frontend assets or dependencies.
- Do not mutate production catalogue/cache/queue state for verification.
- Keep `CatalogCacheInvalidator` as the only catalogue cache mutation boundary.
- Keep `LicensedMediaFileSizeMetadataWriter` as the only persistence boundary shared by inspection and download-time correction.
- Include a meaningful `README.md` update with the product commit as required by current `AGENTS.md`.

---

### Task 1: Add the focused cache invalidation boundary

**Files:**
- Modify: `app/Services/Catalog/CatalogCacheInvalidator.php`
- Modify: `app/Services/Media/LicensedMediaFileSizeMetadataWriter.php`

**Interfaces:**
- Consumes: positive catalogue title ID after a successful material file-size conditional update.
- Produces: `CatalogCacheInvalidator::titlePlaybackMetadataChanged(int $titleId): void`.

- [ ] **Step 1: Add the public focused method**

Add beside `importedTitleChanged()`:

```php
public function titlePlaybackMetadataChanged(int $titleId): void
{
    if ($titleId < 1) {
        return;
    }

    $invalidate = fn () => $this->invalidateTitlePlaybackMetadataNow($titleId);

    if (DB::transactionLevel() > 0) {
        DB::afterCommit($invalidate);

        return;
    }

    $invalidate();
}
```

- [ ] **Step 2: Add the exact private mutation**

Add beside `invalidateImportedTitleNow()`:

```php
private function invalidateTitlePlaybackMetadataNow(int $titleId): void
{
    $this->versions->bump(CacheDomain::TitleDetail, 'title:'.$titleId);
    $this->telemetry->increment(CacheDomain::TitleDetail, 'playback-metadata-invalidation');
}
```

Do not call collection invalidation or `dispatchWarm()` from this method.

- [ ] **Step 3: Route material file-size writes through the focused method**

Replace only this call in `LicensedMediaFileSizeMetadataWriter`:

```php
$this->cache->importedTitleChanged($source->catalogTitleId);
```

with:

```php
$this->cache->titlePlaybackMetadataChanged($source->catalogTitleId);
```

Preserve the successful conditional update, material comparison, source matching and in-memory model synchronization unchanged.

- [ ] **Step 4: Inspect the dependency boundary**

Run:

```bash
rg -n "importedTitleChanged|titlePlaybackMetadataChanged|collections->titleChanged|dispatchWarm" app/Services/Catalog/CatalogCacheInvalidator.php app/Services/Media/LicensedMediaFileSizeMetadataWriter.php
```

Expected: media writer references only `titlePlaybackMetadataChanged`; general importer path still references collection propagation and warming; focused private method references neither.

### Task 2: Update project-owned documentation

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/architecture.md`
- Modify: `docs/caching.md`
- Modify: `docs/performance.md`
- Modify: `docs/superpowers/plans/2026-07-16-targeted-media-size-cache-invalidation.md`

**Interfaces:**
- Consumes: the exact cache dependency and read-only production counts.
- Produces: operator/developer documentation without changing public API or UI terminology.

- [ ] **Step 1: Update README visitor-facing performance wording**

Extend the current 16 July visitor-history sentence about background video sizes to state that size refresh now invalidates only the affected series page and no longer refreshes unrelated catalogue sections. Do not add internal class, cache-key or queue names.

- [ ] **Step 2: Add the current-date changelog entry**

Document the focused title/player invalidation, unchanged conditional metadata write, removal of collection lookup/general warming fan-out, and production audit counts `18597 checked media / 426 titles / 0 approved-public collection mappings` without claiming p95 improvement.

- [ ] **Step 3: Update architecture and cache ownership**

In `docs/architecture.md`, state that material size change advances only the affected title detail generation. In `docs/caching.md`, distinguish focused playback metadata invalidation from general importer invalidation and document that it performs no collection query or warming dispatch.

- [ ] **Step 4: Update the performance contract**

Record the read-only counts and explain which redundant query/fan-out is removed. State explicitly that this is workload evidence rather than measured latency/SLA.

- [ ] **Step 5: Check documentation ownership and generated blocks**

Confirm no changed line is inside `project-docs:start`/`project-docs:end`, no duplicate changelog exists, and `docs/README.md` topic ownership still points architecture/cache/performance details to the edited documents.

### Task 3: Non-test verification

**Files:**
- Verify every Task 1–2 file plus the design spec.

**Interfaces:**
- Consumes: final implementation and documentation.
- Produces: evidence-backed, task-only main-branch commits.

- [ ] **Step 1: Run PHP syntax, formatting and static analysis**

Run:

```bash
php -l app/Services/Catalog/CatalogCacheInvalidator.php
php -l app/Services/Media/LicensedMediaFileSizeMetadataWriter.php
./vendor/bin/pint app/Services/Catalog/CatalogCacheInvalidator.php app/Services/Media/LicensedMediaFileSizeMetadataWriter.php --format agent
./vendor/bin/phpstan analyse app/Services/Catalog/CatalogCacheInvalidator.php app/Services/Media/LicensedMediaFileSizeMetadataWriter.php --no-progress --memory-limit=1G
```

Expected: two clean syntax checks, Pint pass and zero Larastan errors.

- [ ] **Step 2: Run application and build verification**

Run:

```bash
php artisan seasonvar:import --status
php artisan route:list --path=download -vv
php artisan migrate:status
npm run build
```

Expected: importer status renders, the download route retains authentication/throttling/private middleware, migration inventory renders without mutation, and Vite production build exits zero.

- [ ] **Step 3: Review task scope and forbidden patterns**

Run task-only `git diff --check`, inspect exact diff/stat and search for conflict markers, `@php`, `env(`, debug calls, placeholders, remote URL input, body buffering, test/migration/dependency/binary changes. Confirm all task code remains outside Blade and no external request/cache/queue mutation was performed for verification.

- [ ] **Step 4: Commit and push only task-owned changes**

Use an isolated Git index. The product commit must contain exactly:

```text
README.md
CHANGELOG.md
app/Services/Catalog/CatalogCacheInvalidator.php
app/Services/Media/LicensedMediaFileSizeMetadataWriter.php
docs/architecture.md
docs/caching.md
docs/performance.md
docs/superpowers/plans/2026-07-16-targeted-media-size-cache-invalidation.md
```

Commit message:

```text
perf: target media size cache invalidation
```

Push directly to `origin/main`, then close the checklist in a one-file plan commit and push it.

## Verification evidence

- [ ] Read-only production audit recorded.
- [ ] Focused method has exact title scope and no collection/warming call.
- [ ] PHP lint, Pint and Larastan pass.
- [ ] Runtime status, route, migration inventory and frontend build pass.
- [ ] Task-only diff/security/scope review passes.
- [ ] Local `main`, tracking ref and remote `main` hashes match.

## Final changed-file list

- [ ] `README.md`
- [ ] `CHANGELOG.md`
- [ ] `app/Services/Catalog/CatalogCacheInvalidator.php`
- [ ] `app/Services/Media/LicensedMediaFileSizeMetadataWriter.php`
- [ ] `docs/architecture.md`
- [ ] `docs/caching.md`
- [ ] `docs/performance.md`
- [ ] `docs/superpowers/specs/2026-07-16-targeted-media-size-cache-invalidation-design.md`
- [ ] `docs/superpowers/plans/2026-07-16-targeted-media-size-cache-invalidation.md`
