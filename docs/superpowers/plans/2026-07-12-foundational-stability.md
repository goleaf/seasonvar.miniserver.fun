# Foundational Stability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore a green `main` baseline, correct catalog facet aggregation, and enforce one public publication boundary for every `CatalogTitle` route binding.

**Architecture:** Preserve the existing Form Request → PageBuilder → Query/ViewModel flow. Correct the reverse-pivot metadata at the focused facet query boundary, keep grouped filter semantics aligned with the documented contract, and put the universal public `is_published` constraint in `CatalogTitle` route binding while retaining query-service defense in depth.

**Tech Stack:** PHP 8.5.7, Laravel 13.19.0, Livewire 4.3.3, SQLite, Vite 8.1.4, Tailwind CSS 4.3.2, PHPUnit 12.5.31.

## Global Constraints

- Work only on the existing `main` branch.
- Preserve existing user changes and add no production dependency.
- Do not create new test files; extend existing PHPUnit tests.
- Keep visible interface text Russian and neutral.
- Run focused tests before the full suite and Pint after PHP edits.

---

### Task 1: Repair published taxonomy facet aggregation

**Files:**

- Modify: `app/Services/Catalog/CatalogFacetQuery.php`
- Modify: `tests/Feature/CatalogPageTest.php`

**Interfaces:**

- `CatalogFacetQuery::taxonomies(string $filterType, ?int $limit = null): Collection` must join a catalog title through the related pivot key and expose the taxonomy through the foreign pivot key of the reverse relation.

- [x] Reproduce the failure with `test_home_page_lists_country_filters_without_four_item_cap`.
- [x] Rename the pivot variables to reflect their actual sides and correct the join/group/select mapping.
- [x] Run the focused test and verify all six related countries are returned.

### Task 2: Restore existing filter, view-model, UI, polling, and proxy contracts

**Files:**

- Modify: `tests/Feature/CatalogAdvancedFilterTest.php`
- Modify: `tests/Unit/CatalogTitlesViewModelTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `resources/views/catalog/titles.blade.php`
- Modify: `README.md`

**Interfaces:**

- Years use OR; multiple selected values inside one relation dimension use AND.
- Direct `CatalogTitlesViewModel` construction supplies grouped selected taxonomy state.
- Public view labels do not use the prohibited `Карточка` terminology.
- Stats polling is `wire:poll.15s.visible`.
- The poster responder test isolates HTTP response behavior from DNS guard behavior.

- [x] Reproduce every stale contract in the full baseline suite.
- [x] Align existing fixtures/assertions with current documented contracts.
- [x] Run the affected existing test classes.

### Task 3: Centralize the public title route-binding boundary

**Files:**

- Modify: `app/Models/CatalogTitle.php`
- Modify: `app/Http/Controllers/CatalogController.php`
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `docs/architecture.md`
- Modify: `docs/security.md`

**Interfaces:**

- `CatalogTitle::resolveRouteBindingQuery($query, $value, $field = null)` returns a builder constrained by both route key and `published()`.
- All current public `CatalogTitle` bindings return `404` for unpublished rows, including `titles.show`, API show, and `stats.poster`.

- [x] Add a failing regression to the existing `CatalogPageTest` proving `stats.poster` cannot bind an unpublished title and sends no HTTP request.
- [x] Run that exact test and verify RED against the current responder route.
- [x] Implement the binding override and remove the now-redundant controller publication check.
- [x] Run the focused publication tests and verify GREEN.

### Task 4: Document, format, and verify

**Files:**

- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: this living plan as steps complete.

- [x] Update the architecture/security documentation and maintenance log.
- [x] Run `./vendor/bin/pint --dirty --format agent`.
- [x] Run focused test classes, `php artisan test`, Composer validation/audit, npm audit/build, documentation check, and diff checks.
- [x] Inspect the complete diff and confirm that only the intended implementation, existing tests, and project documentation changed.

### Task 5: Clarify empty catalog reset actions

**Files:**

- Modify: `resources/views/catalog/titles.blade.php`
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/MAINTENANCE_LOG.md`

**Interfaces:**

- The empty `/titles` state keeps three distinct actions:
  - `Очистить поиск` removes only `q`.
  - `Убрать фильтры` removes filter/title/year state while preserving the current search.
  - `Показать весь каталог` opens clean `/titles`.
- The sidebar global reset may still use `Сбросить фильтры` because it intentionally opens the clean catalog from the filter form.

- [x] Rename the empty-state filter-preserving reset label from `Сбросить фильтры` to `Убрать фильтры`.
- [x] Document the exact reset labels in `docs/UI_STANDARDS.md`.
- [x] Record the UI behavior in `docs/MAINTENANCE_LOG.md`.
- [x] Verify with non-test commands only: Blade syntax through Laravel render, Vite build, HTTP smoke, and browser click smoke.
