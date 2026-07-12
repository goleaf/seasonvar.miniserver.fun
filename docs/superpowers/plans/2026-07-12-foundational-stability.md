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

### Task 6: Remove stale invalid-filter query state from the catalog view model

**Files:**

- Modify: `app/View/ViewModels/CatalogTitlesViewModel.php`
- Modify: `app/Services/Catalog/CatalogTitlesPageBuilder.php`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: this living plan.

**Interfaces:**

- Public `/titles` links are built only from selected valid filter slugs and sanitized catalog query-state.
- `invalidFilterSlugs` may still be accepted by the constructor for compatibility, but it must not mark the page as filtered or reappear in sorting, view, year, alphabet, reset, pagination, or chip-removal URLs.
- No test files are created or edited for this task.

- [x] Keep the existing constructor argument for compatibility with current callers.
- [x] Stop merging `invalidFilterSlugs` into `allFilterSlugs`.
- [x] Stop counting `invalidFilterSlugs` as active filters in the view model.
- [x] Remove the unused invalid-filter URL helper from the view model.
- [x] Stop treating ignored invalid slugs as catalog context in the page builder.
- [x] Record the cleanup in `docs/MAINTENANCE_LOG.md`.
- [x] Verify with non-test commands only: PHP syntax lint, targeted Pint, documentation refresh, HTTP smoke for stale filter URLs, browser smoke for reset/catalog actions, and diff checks.

### Task 7: Restore local catalog smoke after pending publication schema changes

**Files:**

- Modify: `database/migrations/2026_07_12_174219_enforce_catalog_domain_publication_integrity.php`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: this living plan.

**Interfaces:**

- `/titles` must not return HTTP 500 while the working tree contains publication availability model changes.
- Additive publication columns and backfilled values must exist before the new `published()` scopes run against local SQLite.
- The SQLite table-rebuilding enforce migration stays pending until it is reviewed separately.
- No test files are created or edited for this task.

- [x] Reproduce the HTTP 500 with `/titles?genre[]=not-found-genre`.
- [x] Confirm the root cause from Laravel logs: missing `catalog_titles.publication_status` under the dirty publication scope.
- [x] Run `php artisan migrate --pretend` before mutating the database.
- [x] Remove the no-effect `use RuntimeException` statement that made the pending migration fail on Laravel's warning handling.
- [x] Apply only the additive column migration and publication backfill migration.
- [x] Confirm `2026_07_12_174219_enforce_catalog_domain_publication_integrity` remains pending.
- [x] Re-run HTTP and browser smoke for stale filters and reset/catalog actions.

### Task 8: Enforce clean main-branch Git workflow with hooks

**Files:**

- Create: `.githooks/lib/git-guard.sh`
- Create: `.githooks/pre-commit`
- Create: `.githooks/pre-push`
- Modify: `AGENTS.md`
- Modify: `README.md`
- Modify: `docs/development.md`
- Modify: `docs/CODE_STANDARDS.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: this living plan.

**Interfaces:**

- `core.hooksPath=.githooks` remains the project hook location.
- Commits are allowed only from the existing `main` branch.
- `pre-commit` blocks unstaged tracked changes and untracked files so a commit cannot leave a dirty working tree by accident.
- `pre-push` blocks push outside `main` and push with dirty tree.
- `SEASONVAR_SKIP_GIT_GUARD=1` remains an explicit emergency bypass, not normal workflow.
- No test files are created or edited for this task.

- [x] Add a shared `.githooks/lib/git-guard.sh` helper for branch and dirty-tree checks.
- [x] Add `.githooks/pre-commit` guard for `main`, unstaged tracked changes and untracked files.
- [x] Add `.githooks/pre-push` guard for `main` and clean tree.
- [x] Document the rule in AGENTS, README, development docs, CODE_STANDARDS and maintenance log.
- [x] Verify hook syntax and behavior without running PHP tests.

### Task 9: Flatten title quick-access sidebar

**Files:**

- Modify: `resources/views/catalog/show.blade.php`
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: this living plan.

**Interfaces:**

- The title page left quick-access panel keeps the same links: `Смотреть`, `Сезоны`, selected season, and `О сериале`.
- Sidebar links, the current-selection block, and season/episode/media counters do not use decorative `border-*` or `ring-*` outlines.
- Season, episode, and media counters remain visible on desktop, tablet, and mobile and include local FontAwesome icons.
- No new temporary test files are created; existing PHP tests and browser smoke verify the change.

- [x] Replace the bordered `x-ui.panel` quick-access wrapper with a flat semantic section.
- [x] Remove decorative borders from the quick sidebar count cards and current-selection block.
- [x] Add explicit icons to sidebar count cards.
- [x] Document the design rule in `docs/UI_STANDARDS.md`.
- [x] Record the change in `docs/MAINTENANCE_LOG.md`.
- [x] Verify with existing PHP tests, Vite build, responsive browser smoke, and git checks.
