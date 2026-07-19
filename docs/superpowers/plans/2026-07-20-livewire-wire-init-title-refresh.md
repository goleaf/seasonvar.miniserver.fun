# Conditional Livewire `wire:init` Title Refresh Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Emit the title-page `wire:init` request only when the server state permits a useful background refresh.

**Architecture:** `CatalogTitleRefreshCoordinator` owns one typed eligibility predicate used both while rendering and after acquiring the dispatch lock. `CatalogTitleDetail` exposes only a render-local boolean, while Blade keeps `wire:init` and active `wire:poll` as independent attributes.

**Tech Stack:** PHP 8.5, Laravel 13.20, Livewire 4.3.3, PHPUnit 12.5, Blade, Vite 8.

## Global Constraints

- Work only on existing `main`; do not create a branch or worktree.
- Do not add dependencies, migrations, config keys, cache identities, user-facing strings, inline CSS or business JavaScript.
- Preserve full public SSR/SEO, policy/visibility boundaries, queue job identity, distributed lock and `wire:poll.3s.visible`.
- Treat the render boolean only as an optimization; `request()` must recheck eligibility under lock.
- Modify files only with `apply_patch`; do not disturb unrelated staged or unstaged changes.

---

### Task 1: Prove unnecessary initialization requests

**Files:**
- Modify: `tests/Feature/CatalogTitleLiveRefreshTest.php`

**Interfaces:**
- Consumes: `CatalogTitleRefreshStateStore::queued()`, `completed()` and the public `titles.show` route.
- Produces: route-level contracts for conditional `wire:init` and independent polling.

- [x] **Step 1: Add failing route tests**

Add separate tests that prepare an active state, a fresh completed state and a title with an empty source URL. Assert active HTML omits `wire:init="startRefresh"` but retains `wire:poll.3s.visible="refreshCatalog"`; assert fresh/no-source HTML omit both attributes and no job is queued.

- [x] **Step 2: Run the focused test and verify RED**

Run: `php artisan test tests/Feature/CatalogTitleLiveRefreshTest.php`

Expected: the new negative `wire:init` assertions fail because the root attribute is currently unconditional; existing stale-title assertions remain valid.

### Task 2: Centralize eligibility and conditionally render `wire:init`

**Files:**
- Modify: `app/Services/Seasonvar/CatalogTitleRefreshCoordinator.php`
- Modify: `app/Livewire/CatalogTitleDetail.php`
- Modify: `resources/views/livewire/catalog-title-detail.blade.php`
- Test: `tests/Feature/CatalogTitleLiveRefreshTest.php`

**Interfaces:**
- Consumes: `CatalogTitleRefreshState::isActive()` and `isFresh(int $minutes)`.
- Produces: `CatalogTitleRefreshCoordinator::shouldRequest(CatalogTitle $catalogTitle, CatalogTitleRefreshState $state): bool` and view variable `refreshShouldInitialize`.

- [x] **Step 1: Add the coordinator predicate**

Implement a public typed method returning true only when `source_url` is non-empty, the state is not active and it is not fresh according to `seasonvar.title_refresh.fresh_minutes`.

- [x] **Step 2: Reuse the predicate under lock**

After `request()` reads the current state under the existing lock, return it when `shouldRequest()` is false. Preserve the early no-source fallback and all error handling.

- [x] **Step 3: Prepare the render-local hint**

Pass `'refreshShouldInitialize' => $this->refreshes->shouldRequest($title, $refreshState)` from `CatalogTitleDetail::render()`.

- [x] **Step 4: Condition the Blade attribute**

Render `wire:init="startRefresh"` only inside `@if ($refreshShouldInitialize)`. Keep the existing active polling condition separate and unchanged.

- [x] **Step 5: Run GREEN tests**

Run: `php artisan test tests/Feature/CatalogTitleLiveRefreshTest.php tests/Feature/CatalogTitleBackgroundRefreshTest.php tests/Unit/FrontendAssetContractTest.php`

Expected: all selected tests pass; stale title queues once, active title polls without init, fresh/no-source titles do neither.

### Task 3: Record permanent contracts and verify delivery

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/frontend.md`
- Modify: `docs/importer.md`
- Modify: `docs/performance.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`

**Interfaces:**
- Consumes: the verified route/component behavior from Tasks 1–2.
- Produces: owner documentation, visitor history, technical changelog and final compliance evidence.

- [x] **Step 1: Update owner documentation**

Document that `wire:init` is reserved for the optional post-SSR side effect, is omitted for active/fresh/no-source states, has no modifiers, and remains protected by an authoritative lock recheck. Record that full-page lazy loading is intentionally not used because public SSR/SEO must remain complete.

- [x] **Step 2: Update visitor and technical histories**

Add a Russian visitor-facing README bullet under 20.07.2026 and a separate Russian CHANGELOG bullet without rewriting prior entries.

- [x] **Step 3: Format and run focused verification**

Run targeted Pint on the coordinator, component and feature test; run the two refresh suites, `FrontendAssetContractTest`, `TitleBackgroundRefreshDocumentationTest`, `npm run build`, `php artisan project:docs-refresh --check` and `git diff --check`.

- [x] **Step 4: Scan for regressions and duplicates**

Search repository application code for `wire:init`, `shouldRequest`, competing unconditional title refresh initialization, unsupported modifiers, direct provider URL exposure and stale documentation claims.

- [x] **Step 5: Run the full suite and finalize compliance**

Run: `php artisan test --compact`

Expected: record exact pass/fail evidence. Re-read applicable requirements, update every matrix status honestly, verify `README.md`, then commit/push only if `main` and the shared index allow task-only staging without capturing unrelated work.

## Self-review

- Spec coverage: each state, authority boundary, SSR/SEO requirement, rollback and documentation owner maps to Tasks 1–3.
- Placeholder scan: no deferred implementation marker or unspecified code path remains.
- Type consistency: `shouldRequest(CatalogTitle, CatalogTitleRefreshState): bool` and `refreshShouldInitialize` are used consistently throughout the plan.
