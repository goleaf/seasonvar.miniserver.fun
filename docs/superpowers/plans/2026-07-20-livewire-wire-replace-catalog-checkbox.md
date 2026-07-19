# Livewire `wire:replace` Catalog Checkbox Plan

**Goal:** Characterize the existing narrow `wire:replace.self` inventory and prevent replacement from spreading beyond live leaf checkboxes.

**Architecture:** Keep four template patterns on catalog contextual-filter checkbox inputs. Preserve normal morphing for surrounding UI and the existing keyed `wire:ignore` lifecycle for Plyr/HLS.

**Tech Stack:** PHP 8.5, Laravel 13.20, Livewire 4.3.3, Blade, PHPUnit 12.5.

### Task 1: Characterize the exact inventory

**Files:**
- Create: `tests/Unit/LivewireWireReplaceContractTest.php`

- [x] Require exactly four `wire:replace.self` template patterns.
- [x] Require every replacement owner to be a leaf checkbox with `wire:model.live`.
- [x] Reject bare subtree replacement, custom elements and shadow DOM.
- [x] Preserve the keyed `wire:ignore` player lifecycle instead of replacement.

### Task 2: Record the permanent selection rule

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: `docs/audits/livewire-report.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`

- [x] Document the narrow checkbox scope and rejected speculative uses.
- [x] Review `README.md` without adding visitor history for unchanged behavior.

### Task 3: Verify the characterization

- [x] Run targeted Pint and replacement/catalog/player asset contracts.
- [x] Run managed-doc, diff, legacy and frontend build gates.
- [ ] Run the full test suite and assess Git delivery from `main`.

## Self-review

- No production directive is added or removed without behavioral evidence.
- Replacement remains leaf-only and does not consume surrounding focus/draft state.
- Player ownership remains one keyed `wire:ignore` lifecycle.
