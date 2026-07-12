# Livewire Series Catalog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Execute inline because repository rules require the existing `main` checkout and the user requested immediate implementation. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert the main `/titles` catalog to one maintainable Livewire 4.3 component without moving reusable queries or business rules out of the existing catalog services.

**Architecture:** `CatalogSeries` is a full-page Livewire component with `WithPagination`, a small `CatalogSeriesFilters` form object for URL state, and locked route context for year/taxonomy landing routes. It builds a normalized `CatalogTitlesRequest` and calls `CatalogTitlesPageBuilder` exactly once per render; paginator rows, facets, SEO, and Eloquent models remain render-local data.

**Tech Stack:** PHP 8.5.7, Laravel 13.19.0, Livewire 4.3.3, PHPUnit 12.5, SQLite, Blade, Tailwind CSS 4.3, Vite 8.

## Global Constraints

- Work only on the existing `main` branch and preserve all user changes.
- Do not add dependencies, migrations, or new test files.
- Keep database access out of Livewire state and Blade; reuse `CatalogTitlesPageBuilder` and `CatalogTitleQuery`.
- Keep visible text Russian and preserve existing responsive layout and GET URL contract.
- Public Livewire state contains only validated scalar values and bounded arrays; route context is locked.

---

### Task 1: Define the Livewire state and behavior contract

**Files:**
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `tests/Feature/CatalogValidationTest.php`

**Interfaces:**
- Consumes: existing catalog factories, `CatalogTitlesPageBuilder`, and Livewire PHPUnit helpers.
- Produces: regression coverage for URL hydration, filtering, reset actions, pagination reset, validation errors, and stable result keys.

- [ ] Add PHPUnit methods using `Livewire::withQueryParams()->test(CatalogSeries::class)` and existing factories.
- [ ] Assert `setPage(2)` followed by search/filter/sort changes returns `paginators.page` to `1`.
- [ ] Assert group reset removes only its bounded array and full reset restores defaults.
- [ ] Assert invalid one-character search displays the existing Russian validation message.
- [ ] Run the focused tests and confirm they fail because `CatalogSeries` does not exist.

### Task 2: Add bounded URL state and full-page orchestration

**Files:**
- Create: `app/Livewire/Forms/CatalogSeriesFilters.php`
- Create: `app/Livewire/CatalogSeries.php`
- Modify: `app/Http/Controllers/CatalogController.php`
- Modify: `routes/web.php`

**Interfaces:**
- `CatalogSeriesFilters::toRequestInput(): array<string,mixed>` emits the established request keys (`q`, `year`, taxonomy names, ranges, availability, sort, view, and `per_page`).
- `CatalogSeriesFilters::fillFromRequest(CatalogTitlesRequest $request): void` stores normalized bounded state.
- `CatalogSeries::render(): View` calls `CatalogTitlesPageBuilder::data()` once and extends `layouts.app` with the returned SEO.

- [ ] Add Livewire 4 `#[Url]` aliases to the form properties, using `except` defaults and history synchronization.
- [ ] Add `#[Locked]` route-year/type/taxonomy properties and validate route values in `mount()`.
- [ ] Reuse `CatalogTitlesRequest::validateResolved()` for normalization/errors; do not copy query rules into the component.
- [ ] Add search/filter/sort/view/page-size/alphabet/remove/reset actions and reset pagination on every applied state change.
- [ ] Route `/titles`, `/titles/year/{year}`, and `/titles/{type}/{taxonomy}` to the component; remove unused catalog-list controller orchestration.
- [ ] Run the focused tests until green.

### Task 3: Convert the existing catalog Blade to Livewire controls

**Files:**
- Modify: `resources/views/catalog/titles.blade.php`
- Modify: `resources/views/components/title-card.blade.php`
- Create: `resources/views/vendor/livewire/tailwind.blade.php`

**Interfaces:**
- Blade consumes only render data plus `filters`; it invokes component actions and performs no queries.
- Every result root has `wire:key="catalog-title-{id}"`.

- [ ] Remove the static `@extends/@section` wrapper so the existing section becomes the component root.
- [ ] Bind search with `wire:model.live.debounce.400ms`; bind draft form controls with `wire:model` and apply through `wire:submit`.
- [ ] Replace reset/sort/view/page-size/alphabet/chip links with Livewire actions while retaining safe `href` fallbacks where useful.
- [ ] Add scoped `wire:loading` states, disabled controls, accessible validation output, and a results loading overlay.
- [ ] Add stable result keys and a Russian Livewire pagination view that scrolls to the result summary.
- [ ] Run Blade/visual/component tests and `npm run build`.

### Task 4: Verify payload, query behavior, documentation, and delivery

**Files:**
- Modify: `README.md`
- Modify: `docs/architecture.md`
- Modify: `docs/frontend.md`
- Modify: `docs/testing.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Documents public state, URL aliases, one-builder-call render flow, paginator reset rules, and supported checks.

- [ ] Inspect Livewire snapshot keys and ensure no paginator, Eloquent collection, facet collection, or SEO array is serialized as public state.
- [ ] Measure initial and interactive catalog query counts and inspect response/payload size.
- [ ] Run Pint, PHP syntax lint, supported Laravel cache checks, focused tests, full PHPUnit, and Vite build.
- [ ] Run desktop/tablet/mobile Playwright QA for search, filters, resets, pagination, loading/empty/error states, overflow, console, and failed local assets.
- [ ] Inspect the full diff, commit on `main`, push `origin/main` without force, and confirm a clean tree with matching revisions.
