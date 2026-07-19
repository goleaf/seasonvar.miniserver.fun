# Livewire `wire:sort` Collection Editor Plan

**Goal:** Add pointer/touch drag sorting to the manual collection editor without weakening the existing keyboard controls or server ordering boundary.

**Architecture:** Translate Livewire's page-local position into a bounded absolute window. Persist one item move through the existing policy/rate-limit/transaction/content-version/cache service and retain up/down buttons.

**Tech Stack:** PHP 8.5, Laravel 13.20, Livewire 4.3.3, Blade, PHPUnit 12.5.

### Task 1: Define RED contracts

**Files:**
- Create: `tests/Feature/LivewireWireSortContractTest.php`

- [x] Require one sortable list, stable item IDs, handle, ignored controls and existing up/down actions.
- [x] Prove a service move persists a new order inside a bounded window.
- [x] Prove cross-window item movement is rejected without mutation.
- [x] Run RED before implementation.

### Task 2: Implement bounded persistence

**Files:**
- Modify: `app/Services/Collections/CatalogCollectionItemService.php`
- Modify: `app/Livewire/Collections/CatalogCollectionEditor.php`
- Modify: `resources/views/livewire/collections/catalog-collection-editor.blade.php`

- [x] Add a locked, authorized `moveWithinWindow` operation with bounded changed-row updates.
- [x] Add the component page-offset handler and keep content version/status behavior.
- [x] Add sort container/item/handle/ignore markup while preserving up/down/remove controls.
- [x] Run GREEN, existing collection tests and Pint.

### Task 3: Synchronize documentation and verify

**Files:**
- Modify: `lang/ru/collections.php`
- Modify: `lang/en/collections.php`
- Modify: `docs/architecture.md`
- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: collection audit/owner docs
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`

- [x] Document drag as enhancement, up/down baseline, pagination bound and server trust boundary.
- [x] Update localized hint and visitor history.
- [ ] Run translation parity, focused/related/full tests, build, managed docs, diff/legacy and Git gates.

## Self-review

- Drag payload never supplies collection identity, arbitrary columns or a full unbounded order.
- Current and target item indices must be in one page window under lock.
- Automatic sort modes, unavailable items and cross-collection membership are unchanged.
