# Bounded Livewire `wire:poll` Active-State Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lock polling to the two existing visible active-state workflows and make canonical `/stats` documentation match its requestless snapshot runtime.

**Architecture:** Production Blade remains unchanged because title refresh and importer already use explicit `.visible` intervals with terminal removal. A static PHPUnit contract inventories those directives and drives a documentation-only correction for `/stats`.

**Tech Stack:** PHP 8.5, Laravel 13.20, Livewire 4.3.3, PHPUnit 12.5, Blade, Vite 8.

## Global Constraints

- Work only on existing `main`; do not create a branch or worktree.
- Do not add dependencies, WebSockets, JavaScript timers, routes, schema, cache keys, user-facing strings or production configuration.
- Preserve exact title `3s.visible` and importer `5s.visible` actions and server-owned terminal checks.
- Do not add bare polling or `.keep-alive`; preserve Livewire background and viewport throttling.
- Modify files only with `apply_patch`; preserve unrelated staged and unstaged changes.

---

### Task 1: Reproduce the stale polling contract

**Files:**
- Create: `tests/Unit/LivewireWirePollContractTest.php`

**Interfaces:**
- Consumes: application Blade files and canonical frontend/architecture/performance/UI owners.
- Produces: exact inventory and requestless `/stats` documentation contract.

- [x] **Step 1: Add the static contract test**

Create a test that reads all files under `resources/views`, asserts exactly two `wire:poll.` occurrences, exact `wire:poll.3s.visible="refreshCatalog"` and `wire:poll.5s.visible="refreshRuns"`, no `.keep-alive`, no bare `wire:poll`, and no poll in `stats-dashboard.blade.php`. Then require `architecture.md`, `performance.md`, and `UI_STANDARDS.md` to contain `` `/stats` не использует `wire:poll` ``, and require `frontend.md` to name both exact active-state directives.

- [x] **Step 2: Verify RED**

Run: `php artisan test tests/Unit/LivewireWirePollContractTest.php`

Expected: runtime inventory assertions pass; documentation assertions fail because three owners still claim `wire:poll.15s.visible` for `/stats`.

### Task 2: Correct canonical polling owners

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/frontend.md`
- Modify: `docs/performance.md`
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Test: `tests/Unit/LivewireWirePollContractTest.php`

**Interfaces:**
- Consumes: existing runtime and cache warmer/invalidation behavior.
- Produces: one canonical bounded polling inventory without rewriting historical entries.

- [x] **Step 1: Replace stale owner claims**

State that `/stats` does not use `wire:poll`, reads a warmed snapshot once per request, and updates through existing invalidation/planned warming. Add a frontend bounded-polling section naming both active-state directives and rejecting bare/keep-alive polling.

- [x] **Step 2: Add maintenance correction evidence**

Prepend a dated `20.07.2026` maintenance entry explaining that the audit corrected current owner docs while preserving older historical records of the previous stats implementation.

- [x] **Step 3: Verify GREEN**

Run: `php artisan test tests/Unit/LivewireWirePollContractTest.php tests/Feature/CatalogTitleLiveRefreshTest.php tests/Feature/CatalogPageTest.php --filter='wire_poll|refresh|import_manager|stats_page'`

Expected: the static contract and relevant runtime scenarios pass.

### Task 3: Document and verify delivery

**Files:**
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `docs/superpowers/plans/2026-07-20-livewire-wire-poll-bounded-active-state.md`

**Interfaces:**
- Consumes: verified runtime/docs contract.
- Produces: Russian technical history and final compliance evidence.

- [x] **Step 1: Add a Russian CHANGELOG entry**

Record the exact two poll boundaries, requestless stats page, official throttle choices, static RED/GREEN evidence, unchanged production behavior, and README no-change review.

- [x] **Step 2: Run verification and legacy scans**

Run targeted Pint for the new test, full static/related tests, `npm run build`, managed docs check, task-scoped diff/whitespace gates, and repository searches for bare polling, keep-alive, stale `15s.visible`, client timers and duplicate status refresh paths.

- [x] **Step 3: Run the full suite and assess Git delivery**

Run `php artisan test --compact`, record exact output, re-read applicable requirements and owners, confirm README remains accurate without a fake visitor entry, and commit/push only if the shared `main` index and complete suite are clean.

## Self-review

- Spec coverage: official interval/background/visible/keep-alive behavior, two runtime boundaries, requestless stats, cache lifecycle, rollback and cross-feature domains map to Tasks 1–3.
- Placeholder scan: no TODO/TBD, unspecified implementation or deferred behavior remains.
- Type consistency: the plan introduces no application API; exact directive strings match repository Blade.
