# Livewire `wire:offline` Technical-Issue Submit Implementation Plan

**Goal:** Prevent a futile offline submit of the long technical-issue form while preserving the existing global connectivity banner and the user's local draft.

**Architecture:** The layout-owned Vite runtime remains responsible for the single localized offline/restored announcement. One component-scoped `wire:offline.attr="disabled"` augments the existing loading guard on the form submit button.

**Tech Stack:** PHP 8.5, Laravel 13.20, Livewire 4.3.3, Blade, PHPUnit 12.5, Vite 8.

## Constraints

- Work only on existing `main`; preserve unrelated staged/unstaged changes.
- Do not add packages, service workers, offline persistence, browser caches, routes, schema, public state or new visible copy.
- Keep the global Vite `online`/`offline` listeners and restored-state banner.
- Keep server-side authorization, validation, upload and submission boundaries unchanged.

### Task 1: Lock the ownership contract

**Files:**
- Create: `tests/Unit/LivewireWireOfflineContractTest.php`

- [x] Add one passing assertion group for the existing global layout/runtime owner.
- [x] Add one failing assertion group requiring exactly one `wire:offline.attr="disabled"` on the technical-issue submit button alongside its loading target.
- [x] Run the test and record RED.

### Task 2: Add the scoped offline guard

**Files:**
- Modify: `resources/views/livewire/technical-issues/form-page.blade.php`
- Test: `tests/Unit/LivewireWireOfflineContractTest.php`

- [x] Add `wire:offline.attr="disabled"` to the final submit button only.
- [x] Run the contract test and relevant technical-issue route/component tests for GREEN.
- [x] Run targeted Pint and Vite build.

### Task 3: Synchronize owners and verify

**Files:**
- Modify: `docs/technical-issues.md`
- Modify: `docs/frontend.md`
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/views.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`

- [x] Document the global/local ownership split, unchanged service-worker/offline-storage boundary and rollback.
- [x] Review `README.md`; change it only if visitor-visible product state materially changed.
- [x] Run managed-docs, diff/whitespace, legacy scans, related tests and full PHPUnit; assess Git delivery on the shared `main` snapshot.

## Self-review

- The plan uses the documented `.attr` modifier and keeps the directive inside a Livewire component.
- No duplicate announcement, fake offline persistence or client-trusted access state is introduced.
- Every behavior change has an exact static contract and existing server boundaries remain authoritative.
