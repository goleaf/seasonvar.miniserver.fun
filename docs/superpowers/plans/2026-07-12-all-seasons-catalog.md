# All Seasons Faceted Catalog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn `/titles` into a fast, shareable, accessible all-seasons catalog with alphabet navigation, complete real-data filters, honest search states, compact facets, view controls, and stable pagination.

**Architecture:** Preserve `CatalogTitlesRequest → CatalogTitlesPageBuilder → CatalogTitleQuery/CatalogFacetQuery → CatalogTitlesViewModel → Blade`. Normalize all GET state into an immutable criteria object, materialize non-empty legacy search candidates once per request, reuse them for result/facet queries, and progressively enhance one server-rendered filter form with small vanilla JavaScript.

**Tech Stack:** PHP 8.5, Laravel 13.19, Eloquent/SQLite, Blade, Tailwind CSS 4.3, Vite 8, vanilla JavaScript, FontAwesome 7, PHPUnit 12.5, Playwright CLI.

## Global Constraints

- Visible UI and validation messages are Russian and describe only real data.
- No production dependency is added; `.env` and the production-like database are not modified.
- Existing scalar query URLs and clean year/taxonomy routes remain compatible.
- Repeated values use OR inside one dimension and AND between dimensions; each repeated dimension is limited to 20 unique values.
- Search never falls back to unrelated titles; all public queries and facets use `published()`.
- Blade contains no database queries, `@php`, inline JavaScript/CSS, truncation utilities, raw source/media URLs, snapshots, importer state, secrets, or stack traces.
- UI remains light-only and uses existing shared components/tokens; title cards have one title tab-stop.
- Work in the current shared dirty checkout, preserve concurrent visual-system changes, and use exact-file diff checkpoints instead of commits.
- FTS5, importer backfill, title-page restructuring, autocomplete, typo suggestions, accounts, and write endpoints are outside this plan.

## File Structure

- `app/Enums/CatalogSort.php`: single source of allowed sort values, Russian labels, icons, and default state.
- `app/Services/Catalog/CatalogTitlesCriteria.php`: immutable normalized browse/search/presentation state.
- `app/Http/Requests/CatalogTitlesRequest.php`: scalar/array normalization and abuse-resistant validation.
- `app/Services/Catalog/CatalogTitleQuery.php`: candidate materialization and result constraints.
- `app/Services/Catalog/CatalogFacetQuery.php`: bounded contextual relation/year facets.
- `app/Services/Catalog/CatalogTitlesPageBuilder.php`: resolve grouped slugs once and orchestrate results/facets/SEO.
- `app/View/ViewModels/CatalogTitlesViewModel.php`: query-string state, active chips, labels, and reset URLs.
- `app/Services/Catalog/CatalogSeoBuilder.php`: stable canonical/noindex logic without fallback claims.
- `resources/views/components/catalog/filter-panel.blade.php`: one GET filter form.
- `resources/views/components/catalog/facet-group.blade.php`: native disclosure/checklist.
- `resources/views/catalog/titles.blade.php`: page composition, alphabet, toolbar, results, empty states.
- `resources/js/catalog-filters.js`: optional drawer/facet-search/copy-link enhancement.
- `resources/js/app.js`: conditional dynamic import.
- `resources/css/app.css`: drawer/content-visibility responsive rules.
- `database/migrations/2026_07_12_000001_add_catalog_browse_indexes.php`: reversible measured browse indexes.

---

### Task 1: Normalize All Catalog GET State

**Files:**

- Create: `app/Enums/CatalogSort.php`
- Create: `app/Services/Catalog/CatalogTitlesCriteria.php`
- Modify: `app/Http/Requests/CatalogTitlesRequest.php`
- Modify: `tests/Unit/CatalogTitlesRequestTest.php`
- Modify: `tests/Feature/CatalogValidationTest.php`

**Interfaces:**

- Produces `CatalogTitlesRequest::years(): list<int>`.
- Produces `CatalogTitlesRequest::filterSlugs(): array<string, list<string>>`.
- Produces `CatalogTitlesRequest::excludedFilterSlugs(): array{country:list<string>,genre:list<string>}`.
- Produces typed accessors for ranges, rating, media, alphabet, updated period, view, per-page, and `CatalogSort`.
- Produces immutable `CatalogTitlesCriteria::fromRequest(CatalogTitlesRequest $request, CatalogSearchQuery $search, ?int $titleContextId, bool $invalidTitleContext): self`.

- [ ] **Step 1: Write request normalization tests**

Add a unit test that resolves a real Form Request:

```php
$request = CatalogTitlesRequest::create('/titles', 'GET', [
    'year' => ['2024', '2023', '2024'],
    'country' => ['rossiya', 'kanada', 'rossiya'],
    'actor' => 'ivan-ivanov',
    'exclude_country' => ['ssha'],
    'year_from' => '2010',
    'year_to' => '2024',
    'seasons_min' => '2',
    'episodes_max' => '100',
    'rating_source' => 'imdb',
    'rating_min' => '7.5',
    'votes_min' => '1000',
    'video' => 'available',
    'subtitles' => 'available',
    'quality' => ['1080p', '720p'],
    'updated' => 'month',
    'letter' => 'Ж',
    'view' => 'list',
    'per_page' => '48',
    'sort' => 'imdb_desc',
]);
$request->setContainer(app())->setRedirector(app('redirect'));
$request->validateResolved();

$this->assertSame([2024, 2023], $request->years());
$this->assertSame(['rossiya', 'kanada'], $request->filterSlugs()['country']);
$this->assertSame(['ivan-ivanov'], $request->filterSlugs()['actor']);
$this->assertSame(['ssha'], $request->excludedFilterSlugs()['country']);
$this->assertSame([2010, 2024], [$request->yearFrom(), $request->yearTo()]);
$this->assertSame(7.5, $request->ratingMin());
$this->assertSame(['1080p', '720p'], $request->qualities());
$this->assertSame('list', $request->view());
$this->assertSame(48, $request->perPage());
$this->assertSame(CatalogSort::ImdbRating, $request->sort());
```

Add data-provider cases for every scalar legacy relation and `year=2024` becoming one-element lists.

- [ ] **Step 2: Write validation abuse tests**

Cover 21 repeated values, nested arrays, malformed slugs, unsupported quality/letter/view/per-page/sort, year outside 1900…next year, negative counts/votes, rating outside 0…10, `from > to`, and the same country/genre included and excluded. Assert redirect/error JSON behavior already established by `CatalogValidationTest` and Russian messages such as `Выбрано слишком много значений фильтра.` and `Начало диапазона не может быть больше конца.`.

- [ ] **Step 3: Run RED**

```bash
php artisan test --filter=CatalogTitlesRequestTest
php artisan test --filter=CatalogValidationTest
```

Expected: new accessors/classes are missing and array/range cases fail.

- [ ] **Step 4: Add the backed sort enum**

Implement cases:

```php
enum CatalogSort: string
{
    case Updated = 'updated';
    case YearDesc = 'year_desc';
    case YearAsc = 'year_asc';
    case EpisodesDesc = 'episodes_desc';
    case SeasonsDesc = 'seasons_desc';
    case VideoDesc = 'with_video';
    case TitleAsc = 'title_asc';
    case TitleDesc = 'title_desc';
    case KinopoiskRating = 'kinopoisk_desc';
    case ImdbRating = 'imdb_desc';
    case Popularity = 'popularity_desc';
}
```

Add `label()` and `icon()` match methods using concise Russian text and local FontAwesome class names. `Updated` is the default.

- [ ] **Step 5: Normalize and validate bounded input**

In `prepareForValidation()`, turn scalar repeated inputs into lists, trim scalar members, remove empty values, and de-duplicate without truncating. Preserve nested values so validation rejects them. Declare array + `field.*` rules for years, all `CatalogFilterType::values()`, `exclude_country`, `exclude_genre`, and quality. Use `Rule::enum(CatalogSort::class)` and `Rule::in()` for finite fields. Use `after()` for range order and include/exclude intersection.

Keep `year(): ?int` returning the sole selected year for legacy callers and `requestedYear(): string` returning the first scalar representation only; downstream code migrates to `years()` in Task 2.

- [ ] **Step 6: Implement immutable criteria**

The readonly object stores the parsed search, normalized filter/range/media/rating/alphabet/presentation values, title context ID/invalid flag, and derives `updatedAfter(): ?CarbonImmutable`. It exposes `hasContentFilters(): bool`, `activeFilterCount(): int`, `withoutYears(): self`, and `withoutRelation(string $type): self` using explicit copy construction.

- [ ] **Step 7: Verify GREEN and format**

```bash
php artisan test --filter=CatalogTitlesRequestTest
php artisan test --filter=CatalogValidationTest
./vendor/bin/pint --dirty --format agent
git diff --check -- app/Enums/CatalogSort.php app/Services/Catalog/CatalogTitlesCriteria.php app/Http/Requests/CatalogTitlesRequest.php tests/Unit/CatalogTitlesRequestTest.php tests/Feature/CatalogValidationTest.php
```

Expected: focused suites pass and diff check is empty.

---

### Task 2: Apply Search Once and Filter Correctly

**Files:**

- Modify: `app/Services/Catalog/CatalogTitleQuery.php`
- Modify: `app/Services/Catalog/CatalogTitlesPageBuilder.php`
- Create: `tests/Feature/CatalogAdvancedFilterTest.php`
- Modify: `tests/Feature/CatalogSearchPageTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`

**Interfaces:**

- `CatalogTitleQuery::searchCandidateIds(CatalogSearchQuery $search, ?int $titleContextId): ?Collection` returns `null` for empty search and a reusable integer ID collection for ready/insufficient states.
- `CatalogTitleQuery::filteredTitles(Collection $includedGroups, Collection $excludedGroups, array $invalidSlugs, CatalogTitlesCriteria $criteria, ?string $exceptType, ?Collection $candidateIds): Builder` returns a published query without executing it.
- `CatalogTitlesPageBuilder` parses search once, materializes it at most once, bulk-resolves requested slugs, and removes fallback.

- [ ] **Step 1: Write OR/AND and exclusion tests**

Create fixtures proving two selected years and two selected countries are OR, while country+actor are AND. Add country/genre exclusion cases and a table-driven test across every `CatalogFilterType` registry entry. Assert unknown valid include/exclude slugs and unknown/unpublished title context fail closed.

```php
$this->get('/titles?year[]=2023&year[]=2024&country[]=rossiya&country[]=kanada&actor[]=ivan-petrov')
    ->assertSeeText('Россия с Иваном')
    ->assertSeeText('Канада с Иваном')
    ->assertDontSeeText('Россия без Ивана')
    ->assertDontSeeText('Сериал 2022');
```

- [ ] **Step 2: Write range/rating/media/alphabet tests**

Cover inclusive year/seasons/episodes ranges, IMDb/Kinopoisk threshold and votes, common quality OR, watchable video, subtitles, `updated=week`, Russian letter, `Е`+`Ё`, Latin and `#`. Cover contradictory valid combinations returning an empty paginator rather than an error/fallback.

- [ ] **Step 3: Write the single-materialization regression**

Listen to DB queries during `GET /titles?q=Петров` and assert the broad exact/search candidate query is not repeated by relation/year facet contexts. Also assert a nonsense ready query and a stopword-only query do not render an unrelated fixture title.

- [ ] **Step 4: Run RED**

```bash
php artisan test --filter=CatalogAdvancedFilterTest
php artisan test --filter=CatalogSearchPageTest
```

Expected: multi/range/media/alphabet behavior is absent and fallback test fails.

- [ ] **Step 5: Materialize search candidates once**

Move exact/broad search execution behind `searchCandidateIds()`. Return `null` only for `CatalogSearchState::Empty`; return title context ID for an insufficient title-scoped query; otherwise return an empty collection for insufficient input. Reuse existing normalized legacy variants and exact-title priority. `filteredTitles()` constrains by `whereKey($candidateIds)` and never re-runs text matching.

- [ ] **Step 6: Apply grouped catalog constraints**

For included groups use one pivot `whereIn` subquery per type; for excluded country/genre use `whereNotIn`. Apply search inferred year as a hard intersection with selected/range years. Implement count ranges with grouped season/episode subqueries and `HAVING`; ratings/media use bound `whereIn` subqueries. Apply alphabet with bound expressions, including both `Е` and `Ё`. Keep all raw fragments static and bind user-derived values.

- [ ] **Step 7: Resolve groups and remove fallback**

Bulk-resolve each requested type with `whereIn('slug', ...)`, restore request ordering, retain grouped includes/excludes, and track invalid lists. Load title context with `published()`; a requested context that does not resolve sets `invalidTitleContext=true`. Delete the second paginator query and `searchFallback` state.

Add rating aggregate aliases to the result query before pagination and keep all sort branches ending with `catalog_titles.id DESC`.

- [ ] **Step 8: Verify GREEN and format**

```bash
php artisan test --filter=CatalogAdvancedFilterTest
php artisan test --filter=CatalogSearchPageTest
php artisan test --filter=CatalogPageTest
./vendor/bin/pint --dirty --format agent
git diff --check -- app/Services/Catalog/CatalogTitleQuery.php app/Services/Catalog/CatalogTitlesPageBuilder.php tests/Feature/CatalogAdvancedFilterTest.php tests/Feature/CatalogSearchPageTest.php tests/Feature/CatalogPageTest.php
```

---

### Task 3: Build Bounded Contextual Facets and Review Browse Indexes

**Files:**

- Modify: `app/Services/Catalog/CatalogFacetQuery.php`
- Modify: `app/Services/Catalog/CatalogTitlesPageBuilder.php`
- Modify: `app/Services/Catalog/CatalogTitleQuery.php`
- Modify: `tests/Feature/CatalogAdvancedFilterTest.php`

**Interfaces:**

- `CatalogFacetQuery::taxonomies(...)` returns a bounded relation collection with `context_titles_count`.
- `CatalogFacetQuery::years(...)`, `publicationTypes(...)` and `subtitleAvailability(...)` return contextual fixed-value facets.
- Current-dimension include/exclude constraints are omitted; all other criteria and the candidate ID collection are applied.

- [x] **Step 1: Write facet behavior and query-budget tests**

Extend `CatalogAdvancedFilterTest` to assert country facets ignore the selected country but respect actor/year/media constraints, unpublished titles never contribute, selected values outside the limit remain, zero-count selected values remain removable, actor/director results are bounded, fixed publication/subtitle facets use the same own-group-excluded context, and query count does not grow with option count. Do not create a new test file.

- [x] **Step 2: Run RED**

```bash
php artisan test --filter='/facet|count|paginator/' tests/Feature/CatalogAdvancedFilterTest.php
```

Observed before implementation: the global top-N excluded a context-relevant actor, publication types had no contextual count, subtitles exposed no counts, and relation models retained the global denominator.

- [x] **Step 3: Implement grouped pivot facets**

For each registry relation, clone the context result query with that type omitted, join its selected `catalog_titles.id` subquery to the pivot, group by related ID, then join/count only matching lookup rows. Order selected-first, contextual count descending, then name; apply per-type limits and merge selected models outside the limit. Do not call lookup-model `withCount()` and do not calculate a second global count.

- [x] **Step 4: Implement year and availability summaries**

Build year buckets from a criteria copy without year selections/range; group published candidates by year and include selected years outside the normal newest-year window. Add one conditional aggregate for the availability summary shown in the filter panel, reusing the same context query.

- [x] **Step 5: Review indexes and avoid a speculative migration**

Inspect the live schema and query plans before adding indexes. The existing `catalog_titles_publication_lookup_idx`, unique title-first pivot indexes, and reverse relation-first pivot indexes cover the implemented query shapes. No migration is needed for this task; the live production-like database is not migrated.

- [x] **Step 6: Verify facets, schema, and query plans**

```bash
php artisan test --filter=CatalogAdvancedFilterTest
./vendor/bin/pint --format agent app/Services/Catalog/CatalogFacetQuery.php app/Services/Catalog/CatalogTitlesPageBuilder.php app/Services/Catalog/CatalogTitleQuery.php app/Services/Catalog/CatalogTitlesCriteria.php tests/Feature/CatalogAdvancedFilterTest.php
git diff --check -- app/Services/Catalog/CatalogFacetQuery.php app/Services/Catalog/CatalogTitlesPageBuilder.php app/Services/Catalog/CatalogTitleQuery.php tests/Feature/CatalogAdvancedFilterTest.php
```

`EXPLAIN QUERY PLAN` confirmed `catalog_titles_publication_lookup_idx` plus the existing pivot indexes; `PRAGMA integrity_check` returned `ok`. Facet query count remains constant as option cardinality grows.

---

### Task 4: Preserve Query State and Honest SEO

**Files:**

- Modify: `app/View/ViewModels/CatalogTitlesViewModel.php`
- Modify: `app/Services/Catalog/CatalogSeoBuilder.php`
- Modify: `tests/Unit/CatalogTitlesViewModelTest.php`
- Modify: `tests/Feature/CatalogAdvancedFilterTest.php`
- Modify: `tests/Feature/SitemapAndRobotsTest.php`

**Interfaces:**

- ViewModel produces normalized repeated-array URLs for toggle/remove/sort/view/per-page/letter/reset actions and always removes `page` when criteria change.
- SEO flattens grouped model names for copy, indexes only clean single landings, sorts canonical arrays, and omits presentation/sort state.

- [ ] **Step 1: Write ViewModel URL tests**

Assert `filterQuery`, `excludedFilterQuery`, `yearQuery`, `letterQuery`, `sortQuery`, `viewQuery`, `perPageQuery`, `withoutSearchQuery`, `withoutFiltersQuery`, and `allCatalogQuery` preserve/clear exactly the states specified by the design. Assert duplicate values never appear and active filter count includes ranges/media/exclusions.

- [ ] **Step 2: Write canonical/robots tests**

Assert one clean year and one clean taxonomy route remain indexable, while search, multi-values, exclusion, ranges, media, rating, updated, invalid states, and title context are `noindex,follow`. Assert canonical excludes `sort`, `view`, and `per_page`, contains normalized RFC3986 arrays for content filters, and no fallback wording remains.

- [ ] **Step 3: Run RED**

```bash
php artisan test --filter=CatalogTitlesViewModelTest
php artisan test --filter=SitemapAndRobotsTest
```

- [ ] **Step 4: Implement one query-state builder**

Have the ViewModel build one normalized base array from criteria and grouped include/exclude/invalid selections. Every public URL helper clones this base, performs one explicit mutation, unsets `page`, omits defaults, and returns a stable key order. Labels/icons come from the registry and `CatalogSort`, not duplicated arrays.

- [ ] **Step 5: Update SEO signatures and copy**

Pass grouped selections/criteria into `CatalogSeoBuilder::titles()`. Flatten models only for human copy. Canonicalize clean single landing routes; otherwise build a sorted query containing only content-defining filters. Remove `searchFallback` argument, conditional branches, lead text, SEO text, and maintenance claims about nearest results.

- [ ] **Step 6: Verify GREEN**

```bash
php artisan test --filter=CatalogTitlesViewModelTest
php artisan test --filter=CatalogAdvancedFilterTest
php artisan test --filter=SitemapAndRobotsTest
./vendor/bin/pint --dirty --format agent
```

---

### Task 5: Build the Responsive Catalog Workspace

**Files:**

- Create: `resources/views/components/catalog/filter-panel.blade.php`
- Create: `resources/views/components/catalog/facet-group.blade.php`
- Modify: `resources/views/catalog/titles.blade.php`
- Modify: `resources/views/components/title-card.blade.php` only if the concurrent visual task has not already delivered the one-link contract
- Modify: `app/View/Components/TitleCard.php` only if rating display needs prepared component state
- Create: `resources/js/catalog-filters.js`
- Modify: `resources/js/app.js`
- Modify: `resources/css/app.css`
- Modify: `tests/Feature/CatalogVisualSystemTest.php`
- Modify: `tests/Feature/CatalogBladeComponentTest.php`
- Modify: `tests/Unit/FrontendAssetContractTest.php`

**Interfaces:**

- One GET form submits search, repeated facets, exclusions, ranges, media/rating state, sort, view, and per-page.
- Without JavaScript the native disclosure/filter form remains usable.
- JavaScript conditionally enhances one mobile panel, option filtering, Escape/return-focus, reset, and copy-link behavior.

- [ ] **Step 1: Wait for and integrate the concurrent visual-system checkpoint**

Re-read `git status`, `.superpowers/sdd/catalog-visual-progress.md`, the shared component diffs, and focused visual tests. Do not overwrite concurrent changes. If title-card one-tab-stop is already green, reuse it unchanged and add only catalog-page assertions.

- [ ] **Step 2: Add failing rendered-contract tests**

Assert one `h1`, one catalog search landmark, no duplicate header search on `/titles`, alphabet links with `aria-current`, `Фильтры · N`, repeated names such as `country[]`, labelled range/rating/media controls, native details fallback, Russian apply/reset actions, grid/list controls, honest zero/insufficient copy, one title show URL per card, independent relation links, and no nested anchors.

- [ ] **Step 3: Render reusable facet controls**

`x-catalog.facet-group` receives prepared label/icon/items/selected/excluded state and renders a `<details>` with a labelled local search and 44 px checkbox rows. `x-catalog.filter-panel` renders year/range, all ten relation groups, exclusion controls, availability/quality/subtitles, count/rating/updated fields, sticky apply/reset actions, and hidden preserved state. Blade performs no queries or ad-hoc PHP.

- [ ] **Step 4: Recompose `/titles`**

Place hero/search first, then alphabet, toolbar, selected chips, a desktop sticky sidebar/mobile closed panel, results, and pagination. Use `x-title-card` for grid and `x-title-list-row` for list. Render state-specific empty copy and the three reset links. Use `minmax(0,1fr)`, `min-w-0`, `break-words`, and no truncation.

- [ ] **Step 5: Add progressive JavaScript**

In `app.js`, dynamically import `catalog-filters.js` only when `[data-catalog-filters]` exists. The module:

```js
export const initializeCatalogFilters = () => {
    document.querySelectorAll('[data-catalog-filters]').forEach((root) => {
        const trigger = document.querySelector(`[aria-controls="${root.id}"]`);
        const close = root.querySelector('[data-catalog-filter-close]');
        const backdrop = root.querySelector('[data-catalog-filter-backdrop]');
        let opener = null;

        const setOpen = (open) => {
            root.dataset.open = open ? 'true' : 'false';
            trigger?.setAttribute('aria-expanded', String(open));
            document.documentElement.classList.toggle('overflow-hidden', open);

            if (open) {
                opener = document.activeElement;
                close?.focus();
            } else if (opener instanceof HTMLElement) {
                opener.focus();
            }
        };

        trigger?.addEventListener('click', () => setOpen(true));
        close?.addEventListener('click', () => setOpen(false));
        backdrop?.addEventListener('click', () => setOpen(false));
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && root.dataset.open === 'true') {
                setOpen(false);
            }
        });

        root.querySelectorAll('[data-facet-search]').forEach((input) => {
            input.addEventListener('input', () => {
                const query = input.value.toLocaleLowerCase('ru').trim();
                const group = input.closest('[data-facet-group]');

                group?.querySelectorAll('[data-facet-option]').forEach((option) => {
                    option.hidden = query !== ''
                        && !option.dataset.facetOption.toLocaleLowerCase('ru').includes(query);
                });
            });
        });
    });
};
```

Add a guarded Clipboard click handler that writes `window.location.href`, swaps the visible button text to `Ссылка скопирована` for two seconds, and leaves the page URL usable when `navigator.clipboard` is unavailable. CSS applies the modal state only below `lg`; desktop ignores `data-open`, never locks scrolling, and keeps the sidebar visible.

- [ ] **Step 6: Add responsive CSS-first enhancements**

Extend existing Tailwind 4 tokens only where utilities cannot express state: enhanced drawer open/closed state, `content-visibility:auto` with safe intrinsic size, and reduced-motion-safe transitions. Keep light theme and existing `focus-visible` contract.

- [ ] **Step 7: Verify UI GREEN and build**

```bash
php artisan test --filter=CatalogVisualSystemTest
php artisan test --filter=CatalogBladeComponentTest
php artisan test --filter=FrontendAssetContractTest
npm run build
git diff --check -- resources/views/catalog/titles.blade.php resources/views/components/catalog resources/views/components/title-card.blade.php resources/js resources/css/app.css tests/Feature/CatalogVisualSystemTest.php tests/Feature/CatalogBladeComponentTest.php tests/Unit/FrontendAssetContractTest.php
```

---

### Task 6: Documentation, Measurement, Browser QA, and Review

**Files:**

- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/CODE_STANDARDS.md`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: `docs/architecture.md`
- Modify: `docs/audit.md`
- Modify: `docs/catalog-search.md`
- Modify: `docs/forms.md`
- Modify: `docs/frontend.md`
- Modify: `docs/performance.md`
- Modify: `docs/testing.md`
- Modify: `docs/validation.md`
- Modify: `docs/views.md`

**Interfaces:**

- Documentation describes actual URL semantics, visible filters, contextual-only facet counts, measured performance, accessibility, and deferred FTS/importer work.
- QA artifacts stay under `output/playwright/`.

- [ ] **Step 1: Update factual project documentation**

Document scalar compatibility, repeated arrays, OR/AND/exclusion/range/rating/media/alphabet semantics, facet limits, contextual-only counts, query-state reset behavior, no-fallback search, indexes, server/no-JS behavior, and measured before/after results. Remove obsolete nearest-result and global-count claims. Do not add template marketing prose.

- [ ] **Step 2: Refresh managed blocks and inspect exact diff**

```bash
php artisan project:docs-refresh
php artisan project:docs-refresh --check
git diff --check
```

Keep only intended managed documentation changes; do not stage or commit concurrent files.

- [ ] **Step 3: Run fresh formatting and focused suites**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=CatalogTitlesRequestTest
php artisan test --filter=CatalogAdvancedFilterTest
php artisan test --filter=CatalogFacetQueryTest
php artisan test --filter=CatalogTitlesViewModelTest
php artisan test --filter=CatalogSearchPageTest
php artisan test --filter=CatalogVisualSystemTest
```

- [ ] **Step 4: Run full backend/build verification**

```bash
php artisan test
npm run build
```

Expected: zero failed tests and build exit code zero. Reproduce any failure alone and separate pre-existing/concurrent failures from this plan.

- [ ] **Step 5: Measure on an isolated database**

Copy the SQLite database to a temporary QA path, run normal additive migrations only on the copy, and record query count, TTFB, total duration, and HTML bytes for empty, exact, broad (`Петров`/`бухта`), multi-filter, rating, quality, and list/grid URLs. Compare with the recorded 20/29-query, 1–14 second, 400+ КБ baseline. Never run `migrate:fresh`, `db:wipe`, or broad import.

- [ ] **Step 6: Run Playwright QA**

Use the Seasonvar Playwright workflow at 320×720, 390×844, 768×1024, 1440×1200, and 1920×1080. Verify normal/search/zero/insufficient, multi-filter OR/AND, exclusions/ranges/rating/media, alphabet, selected-chip removal, reset, sort/view/per-page/pagination, share URL, drawer Escape/focus return, Back/Forward, 44 px targets, one tab-stop, one main/H1, no horizontal overflow, no console/page errors, and no failed local assets. Store screenshots/report under `output/playwright/`.

- [ ] **Step 7: Request independent review and fix findings**

Prepare a diff package limited to this plan’s files. Reviewer checks spec coverage, SQL semantics/bindings, validation abuse cases, publication boundary, query budgets/index evidence, canonical/noindex, private URL safety, no-JS fallback, responsive accessibility, and preservation of concurrent changes. Fix every Critical/Important finding, re-run covering tests, and re-review.

- [ ] **Step 8: Audit the plan from first item to last**

Re-read this file and the design, map every requirement to a passing test/browser/measurement result, search for unchecked scope, and report any genuinely deferred item explicitly. Completion requires all six tasks, focused/full tests, build, browser matrix, performance evidence, and clean independent review.

## Plan Self-Review

- Spec coverage: request, query, facets, SEO, UI, docs, backend/frontend/browser/performance/review gates are mapped to Tasks 1–6.
- Scope: importer, FTS, title-page and external enrichment are explicitly excluded; no hidden second subsystem remains.
- Type consistency: `CatalogTitlesCriteria`, `CatalogSort`, grouped include/exclude collections, candidate IDs, and ViewModel helpers use the same names throughout.
- Placeholder scan: no TBD/TODO/future implementation placeholder is part of the six tasks.
