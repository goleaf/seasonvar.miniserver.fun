# Catalog People Live Search Spinner Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Исправить постоянный spinner и перевести поиск актёров/режиссёров на realtime Livewire 4.3.3 state с grouped `catalog-live` islands.

**Architecture:** `CatalogSeries::$optionSearch` становится единственным состоянием web-поиска людей. Debounced property update перерисовывает одноимённые islands, targeted loading wrapper показывает spinner только для активного поля, а duplicate page JavaScript удаляется без изменения публичного API.

**Tech Stack:** PHP 8.5, Laravel 13.20, Livewire 4.3.3, Blade, Tailwind CSS 4.3, Vite 8, PHPUnit 12.5, Playwright Chromium.

## Global Constraints

- Только существующая `main`; без branch/worktree/dependency/`.env`/database/cache mutation.
- Сохранить `/titles`, `/api/catalog/people`, URL query contract, RU/EN parity и 44 px responsive controls.
- Сначала красный PHPUnit regression, затем минимальный production fix.

---

### Task 1: Regression contract

**Files:**
- Modify: `tests/Feature/CatalogVisualSystemTest.php`
- Test: `tests/Feature/CatalogVisualSystemTest.php`

**Interfaces:**
- Consumes: Blade and Vite source.
- Produces: `test_catalog_people_search_uses_targeted_livewire_loading_inside_the_catalog_island()`.

- [x] **Step 1: Write the failing test**

Assert exact `wire:model.live.debounce.300ms`, `wire:loading.delay`/property target, absence of `fa-spinner fa-spin hidden`, old combobox markers and `loadCatalogPeopleComboboxes`.

- [x] **Step 2: Run RED**

Run `php artisan test --filter=test_catalog_people_search_uses_targeted_livewire_loading_inside_the_catalog_island`; expected and observed failure is the missing Livewire binding on the legacy Blade input.

### Task 2: Canonical Livewire UI

**Files:**
- Modify: `resources/views/components/catalog/title-filters.blade.php`
- Modify: `tests/Feature/CatalogPageTest.php`

**Interfaces:**
- Consumes: `CatalogSeries::$optionSearch`, `updated()`, `catalogFacets()`.
- Produces: debounced actor/director fields, exact loading target and realtime checkboxes.

- [x] **Step 1: Bind the input**

Use `wire:model.live.debounce.300ms="optionSearch.{{ $filterType }}"` and a sibling status wrapper with `wire:loading.delay wire:target="optionSearch.{{ $filterType }}"`.

- [x] **Step 2: Restore realtime selection**

Use `wire:model.live` plus `wire:replace.self` for years, publication types, subtitles and taxonomy checkboxes.

- [x] **Step 3: Prove result updates**

Create matching/non-matching titles, set searched actor filter through Livewire and assert only the matching title remains in `catalogPage()`.

### Task 3: Remove duplicate page JavaScript

**Files:**
- Modify: `resources/js/app.js`
- Modify: `tests/Feature/CatalogVisualSystemTest.php`

**Interfaces:**
- Consumes: Task 2 Livewire state.
- Produces: catalog initializer without people-only fetch/combobox state.

- [x] **Step 1: Delete legacy consumer**

Remove `initializedPeopleComboboxes`, `peopleFilterUrl`, `loadCatalogPeopleComboboxes` and its initializer call; retain the independent API route/service.

- [x] **Step 2: Update the script contract**

Assert the page script contains none of the deleted people-combobox identifiers and still contains no modal code.

### Task 4: Documentation and verification

**Files:**
- Modify: `docs/catalog-search.md`, `docs/frontend.md`, `docs/forms.md`, `docs/UI_STANDARDS.md`
- Modify: `README.md`, `CHANGELOG.md`, `docs/plans/current-task-plan.md`

**Interfaces:**
- Consumes: verified UI behavior.
- Produces: canonical contract, visitor history, compliance and rollback evidence.

- [x] **Step 1: Update canonical and visitor docs**

Record Livewire ownership, separate compatible API, exact loading target, responsive behavior and code/assets-only rollback.

- [x] **Step 2: Run final verification**

Run focused PHPUnit, targeted Pint, `npm run build`, docs check, legacy scan and Playwright at 390/768/1440 px. Record any unrelated full-suite or external Git failure honestly.

- [ ] **Step 3: Deliver on main**

Confirm task-only diff, commit on clean `main`, attempt configured push, and never stage concurrent foreign work.
