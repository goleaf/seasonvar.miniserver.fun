# Livewire `wire:ref` Title-to-Player Event Plan

**Goal:** Scope the catalog refresh event from `CatalogTitleDetail` to its one nested `CatalogTitlePlayer` using Livewire 4 refs.

**Architecture:** Keep the existing event/listener/payload but replace class-wide targeting with a parent-scoped child ref. Add a static contract before implementation and retain existing feature behavior tests.

**Tech Stack:** PHP 8.5, Laravel 13.20, Livewire 4.3.3, Blade, PHPUnit 12.5.

### Task 1: Reproduce the missing scoped ref

**Files:**
- Create: `tests/Unit/LivewireWireRefContractTest.php`

- [x] Require exactly one application `wire:ref`, named `player`, on the keyed child component.
- [x] Require `->to(ref: 'player')` and reject class-wide player targeting.
- [x] Run RED against the current class target.

### Task 2: Implement the scoped event target

**Files:**
- Modify: `resources/views/livewire/catalog-title-detail.blade.php`
- Modify: `app/Livewire/CatalogTitleDetail.php`

- [x] Add the child ref and switch the dispatch target without changing event name/payload.
- [x] Run GREEN, existing title-refresh tests and the child listener behavior test.
- [x] Run Pint for changed PHP/test files.

### Task 3: Synchronize and verify

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`

- [x] Document ref scope, listener defense and unchanged selector/browser lifecycles.
- [x] Review README without adding visitor history unless behavior becomes visitor-visible.
- [x] Run Vite, managed docs, diff/legacy scans, related/full tests and Git assessment.

## Self-review

- Ref is unique inside its parent and does not replace `wire:key`.
- Authorization and payload validation remain server-owned.
- No global selector, inline script, dynamic ref or new event is introduced.
