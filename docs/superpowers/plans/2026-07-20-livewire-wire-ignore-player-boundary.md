# Livewire `wire:ignore` Player Boundary Characterization Plan

**Goal:** Lock the existing single, keyed Plyr/HLS `wire:ignore` shell without widening it or weakening it to `.self`.

**Architecture:** Production markup stays unchanged. A static PHPUnit contract inventories every Blade view, verifies the exact keyed player shell and proves representative Livewire-owned controls remain outside the ignored subtree.

**Tech Stack:** PHP 8.5, Laravel 13.20, Livewire 4.3.3, Blade, PHPUnit 12.5, Plyr, HLS.js.

### Task 1: Add characterization coverage

**Files:**
- Create: `tests/Unit/LivewireWireIgnoreContractTest.php`

- [x] Assert exactly one application `wire:ignore` and zero `wire:ignore.self` usages.
- [x] Assert the directive is on `catalog-player-media-shell-{media}-{authorization}` with `data-player-shell`.
- [x] Assert Livewire loading/selection and portal controls remain outside the ignored shell while Plyr/HLS init/destroy contracts remain present.
- [x] Run the test; it should pass immediately because production is already compliant.

### Task 2: Synchronize owners

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: `docs/audits/livewire-report.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`

- [x] Record why full ignore is required, why `.self` is insufficient and why no other widget receives the directive.
- [x] Review `README.md`; do not add visitor history because production behavior does not change.

### Task 3: Verify

- [x] Run targeted Pint, the new contract, existing player/asset tests, managed docs and task-scoped diff/legacy scans.
- [x] Run the full suite and production Vite build, then assess Git delivery on shared `main`.

## Self-review

- No third-party ownership is inferred without code evidence.
- The test distinguishes full ignore from `.self` and protects the narrow boundary.
- No application behavior, persistent state or public contract is changed.
