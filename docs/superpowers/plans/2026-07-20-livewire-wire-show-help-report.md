# Livewire `wire:show` Help Report Plan

**Goal:** Preserve the small help-report form DOM while toggling visibility with Livewire 4 `wire:show`.

**Architecture:** Keep the existing server property/actions and report boundary. Replace only the Blade add/remove condition with CSS visibility, add initial cloaking and accessible control linkage.

**Tech Stack:** PHP 8.5, Laravel 13.20, Livewire 4.3.3, Blade, PHPUnit 12.5.

### Task 1: Reproduce the add/remove contract

**Files:**
- Create: `tests/Unit/LivewireWireShowContractTest.php`

- [x] Require one modifier-free `wire:show` on the help report form.
- [x] Require initial cloak and `aria-controls`/`aria-expanded` linkage.
- [x] Reject the old conditional wrapper and preserve submit/reset behavior.
- [x] Run RED against the current `@if` implementation.

### Task 2: Implement the narrow show boundary

**Files:**
- Modify: `resources/views/livewire/help-center/article.blade.php`

- [x] Render the form continuously with `wire:show` and `wire:cloak`.
- [x] Keep toggle/cancel/submit actions and translated form semantics unchanged.
- [x] Run GREEN and related help/frontend contracts.

### Task 3: Synchronize and verify

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: `docs/help-center.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`

- [x] Document selection rule, accessibility, browser behavior and rollback.
- [x] Update visitor-facing README because report-form interaction changes.
- [x] Run Pint, related tests, Vite, managed docs and diff/legacy scans.
- [ ] Run the consolidated full suite and assess Git delivery from `main`.

## Self-review

- Visibility is presentation only; report authority remains server-side.
- Hidden form has no autofocus and does not create a dialog/focus trap.
- Native dialog and transition-owned create form are not rewritten.
