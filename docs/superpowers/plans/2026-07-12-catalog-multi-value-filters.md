# Catalog Multi-Value Filters Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Execute inline in the existing `main` checkout because repository rules prohibit feature branches and worktrees. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the existing Livewire catalog filter system with OR-within-group/AND-between-group semantics, bounded publication/subtitle choices, and server-side actor/director option search.

**Architecture:** Keep `CatalogSeries` responsible for URL/page state and delegate normalized filter input through `CatalogTitlesRequest`, `CatalogTitlesCriteria`, `CatalogTitlesPageBuilder`, and the single `CatalogTitleQuery` boundary. Extend `CatalogFacetQuery` for bounded server-side option search; do not introduce a parallel filter pipeline or database queries in Blade.

**Tech Stack:** PHP 8.5.7, Laravel 13.19.0, Livewire 4.3.3, SQLite, PHPUnit 12.5.31, Tailwind CSS 4.3.2, Vite 8.

## Global Constraints

- Work only on the existing `main` branch and preserve concurrent user work.
- Do not add dependencies, migrations, branches, worktrees, or new test files.
- Keep public visibility in `CatalogTitleQuery::visibleTo()` and use only bound Eloquent/query-builder values.
- Keep URL arrays normalized, unique, bounded to 20 taxonomy values and smaller fixed limits for enum groups.
- Keep Russian UI text, GET fallbacks, responsive layout, and Livewire 4-compatible `#[Url(history: true)]` state.
- Treat `Translation` as the existing voice/translation dimension; do not invent normalized language/audio-track tables.

---

### Task 1: Lock matching and normalization behavior with existing tests

**Files:**
- Modify: `tests/Feature/CatalogAdvancedFilterTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `tests/Feature/CatalogValidationTest.php`
- Modify: `tests/Unit/CatalogTitlesRequestTest.php`

**Interfaces:**
- Consumes: existing factories, `CatalogSeries`, `CatalogTitlesRequest`, and public catalog routes.
- Produces: regression coverage for OR/AND semantics, duplicate-free totals, enum arrays, invalid values, resets, paginator reset, and remote facet search.

- [ ] Replace the old same-group AND expectation with `(actor A OR actor B) AND (genre A OR genre B) AND (year A OR year B)` assertions.
- [ ] Add publication type, quality, translation, and subtitle array combinations using existing models/factories.
- [ ] Add normalization assertions for empty/duplicate/unsupported array values and missing taxonomy records.
- [ ] Add Livewire assertions that one-value removal and group reset preserve unrelated state and reset pagination.
- [ ] Add a server-side actor search assertion proving an option outside the default 24-result facet can be found.
- [ ] Run focused tests and confirm expected RED failures in current AND semantics/missing groups/search.

### Task 2: Extend normalized filter state and query semantics

**Files:**
- Create: `app/Enums/CatalogPublicationType.php`
- Modify: `app/Http/Requests/CatalogTitlesRequest.php`
- Modify: `app/Livewire/Forms/CatalogSeriesFilters.php`
- Modify: `app/Services/Catalog/CatalogTitlesCriteria.php`
- Modify: `app/Services/Catalog/CatalogTitleQuery.php`

**Interfaces:**
- `CatalogPublicationType::databaseValues(): list<string>` maps canonical URL values to stored legacy aliases.
- `CatalogTitlesRequest::publicationTypes(): list<string>` and `subtitleAvailability(): list<string>` return normalized bounded arrays.
- `CatalogTitlesCriteria::withoutPublicationTypes(): self` supports contextual facet counts.

- [ ] Validate `publication_type[]` with the backed enum and `subtitles[]` with the two supported availability values.
- [ ] Normalize values through existing repeated-query preparation and expose them in catalog query state.
- [ ] Store both arrays in the Livewire form with URL history, reset/remove support, and safe defaults.
- [ ] Change pivot filtering to one grouped `whereIn` subquery per group without a same-group `having count = selected count` requirement.
- [ ] Apply publication types and subtitle choices with bound subqueries while preserving AND between separate groups.
- [ ] Run focused request/query/Livewire tests until green.

### Task 3: Add bounded server-side options and UI controls

**Files:**
- Modify: `app/Services/Catalog/CatalogFacetQuery.php`
- Modify: `app/Services/Catalog/CatalogTitlesPageBuilder.php`
- Modify: `app/Livewire/CatalogSeries.php`
- Modify: `app/View/ViewModels/CatalogTitlesViewModel.php`
- Modify: `resources/views/catalog/titles.blade.php`

**Interfaces:**
- `CatalogFacetQuery::taxonomies(string $type, ?int $limit, ?User $user, ?string $search): Collection` performs a bound, minimum-two-character, bounded search.
- `CatalogSeries::$optionSearch` holds only `actor` and `director` ephemeral search terms and is not URL-synchronized.
- Builder render data includes bounded publication-type options and selected values.

- [ ] Add debounced `wire:model.live.debounce.350ms` actor/director option search with a maximum 80-character normalized term.
- [ ] Keep default actor/director results at 24 and searched results bounded at 24; pin already-selected records without duplicates.
- [ ] Add publication type and subtitle checkbox groups with group reset and individual active-chip removal.
- [ ] Rename the existing translation label to accurately describe stored voice/translation data.
- [ ] Keep ordinary taxonomy local search as progressive enhancement and preserve GET fallback hidden state.
- [ ] Run focused view/component tests and `npm run build`.

### Task 4: Verify, document, and deliver

**Files:**
- Modify: `README.md`
- Modify: `docs/architecture.md`
- Modify: `docs/forms.md`
- Modify: `docs/frontend.md`
- Modify: `docs/performance.md`
- Modify: `docs/validation.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Documents exact groups, OR/AND semantics, URL names, limits, and unsupported normalized language-track limitations.

- [ ] Inspect generated SQL and confirm results are unique with exact paginator totals.
- [ ] Measure query count and ensure option search remains bounded without per-option queries.
- [ ] Run Pint, PHP syntax, Laravel cache checks, focused/full PHPUnit, dependency audits, and Vite build.
- [ ] Run desktop/tablet/mobile Playwright QA for search, multi-group selections, reset, URL/history, overflow, console, and network errors.
- [ ] Inspect the complete diff, commit on `main`, push without force, and confirm a clean tree with `HEAD == origin/main`.
