# Catalog Search, Sorting, Pagination, and URL State Implementation Plan

> **Execution note:** Follow the existing approved search design in `docs/superpowers/specs/2026-07-12-catalog-search-overhaul-design.md`. This increment hardens the current SQL driver and Livewire contract; FTS5 indexing remains its separately deployable rollout.

**Goal:** Make catalog search, allowlisted sorting, pagination, and shareable Livewire URL state behave as one validated system, including malformed input and out-of-range page recovery.

**Architecture:** `CatalogTitlesRequest` remains the normalization and validation boundary. `CatalogSearchQueryParser` and `CatalogTitleQuery` remain the only search/query implementation, while `CatalogSeries` owns URL-backed UI state and exceptional paginator recovery. Blade submits state and renders results but performs no queries. The current Eloquent/SQL search is retained; the main input uses a longer debounce so it does not issue one leading-wildcard query per keystroke.

**Stack:** PHP 8.5, Laravel 13.19, Livewire 4.3, SQLite 3.46/FTS5-capable, PHPUnit 12.5, Tailwind 4.3, Vite 8.

---

## Task 1: Lock the malformed-input and pagination contract with existing tests

**Files:**
- Modify: `tests/Unit/CatalogTitlesRequestTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `tests/Feature/CatalogValidationTest.php`
- Modify: `tests/Feature/CatalogVisualSystemTest.php`

1. Add request assertions for Unicode whitespace normalization, scalar and array-shaped `q`, unsupported sort keys, and ignored `direction` input.
2. Add Livewire assertions for invalid/negative/array page input, out-of-range page recovery, stable sort state, and page reset after search/sort/filter changes.
3. Add HTTP assertions that duplicate pivot matches do not inflate totals or duplicate cards.
4. Change the existing visual contract assertion to the selected Livewire debounce.
5. Run the focused tests and confirm the new cases fail for the intended reasons before implementation.

## Task 2: Harden normalized request and URL state

**Files:**
- Modify: `app/Http/Requests/CatalogTitlesRequest.php`
- Modify: `app/Livewire/Forms/CatalogSeriesFilters.php`
- Modify: `app/Livewire/CatalogSeries.php`

1. Normalize Unicode whitespace with the existing NFKC-aware search normalizer and preserve the 2–80 character validation contract.
2. Convert malformed non-scalar URL values to safe defaults before Livewire query construction; unsupported sort keys fall back to `updated` and directions remain encoded only in allowlisted sort keys.
3. Keep every URL-backed property small and normalized, preserve `history: true`, and reset `page` on any committed search/filter/sort mutation.
4. Detect a paginator current page above `lastPage()`, move to the final valid page (or page 1 for zero results), and rebuild once only on that exceptional request.

## Task 3: Improve SQL search safety and cost without duplicating the query layer

**Files:**
- Modify: `app/Services/Catalog/CatalogTitleQuery.php`
- Create: `database/migrations/2026_07_12_230000_add_catalog_alias_search_index.php`

1. Keep visibility constraints at the start of exact and broad candidate subqueries.
2. Include exact external provider IDs in the indexed exact-match branch while retaining title, original title, aliases, actors, directors, genres, and other existing taxonomy matches in the single query layer.
3. Add the additive `(name_hash, catalog_title_id)` alias index identified by the approved design; do not backfill or alter importer data.
4. Confirm candidate selection remains subquery-based so pivot matches cannot duplicate `catalog_titles` or paginator totals.
5. Inspect representative SQL and `EXPLAIN QUERY PLAN` for exact IDs, aliases, and relation searches.

## Task 4: Tune the Livewire search interaction

**Files:**
- Modify: `resources/views/catalog/titles.blade.php`

1. Use a 650 ms Livewire debounce for the main search input, keeping the normal form submit fallback and URL `q` contract.
2. Preserve filters, sort, view, and per-page state when search commits.
3. Keep loading, validation, empty-state, and stable `wire:key` behavior unchanged.

## Task 5: Documentation, verification, and delivery

**Files:**
- Modify: `docs/catalog-search.md`
- Modify: `docs/validation.md`
- Modify: `docs/testing.md`
- Modify: `CHANGELOG.md`

1. Document search fields, SQL fallback limits, URL format, sort map, 650 ms debounce, page recovery, duplicate prevention, and the separate FTS5 rollout.
2. Run `./vendor/bin/pint --dirty --format agent`.
3. Run focused request, search, catalog, Livewire, and visual tests.
4. Run the complete supported PHP test suite and `npm run build`.
5. Run migration status and an isolated SQLite migration check without touching the live database.
6. Inspect `git diff`, exclude concurrent importer work, commit logical changes on `main`, push `main` without force, and confirm only the other session's preserved files remain if it is still active.
