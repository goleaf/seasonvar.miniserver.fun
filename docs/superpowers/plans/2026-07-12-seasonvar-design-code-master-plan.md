# Seasonvar Design And Code Completion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the unfinished Seasonvar catalog UI/code work by upgrading the public catalog, title pages, shared visual system, search/facet behavior, metadata surfaces, performance, documentation, and final Git publication without creating new test files.

**Architecture:** Preserve the current Laravel request -> page builder -> query/service -> ViewModel -> Blade architecture. Keep controllers thin, keep SQL and state shaping out of Blade, reuse existing Blade components first, and split only the large public UI surfaces where it directly reduces duplication or mobile/accessibility risk.

**Tech Stack:** PHP 8.5, Laravel 13.19, Blade components, Tailwind CSS 4.3 CSS-first configuration, Vite 8, local FontAwesome/Plyr/HLS assets, SQLite for local verification, existing PHPUnit test suite only.

## Global Constraints

- Do not create new test files. Verification uses existing tests, build commands, formatting, browser/read-only checks, and manual QA notes.
- Do not add Pest, `npm run lint`, CDN assets, external fonts, new production dependencies, fake public content, demo marketing copy, downloaded video files, or seeders for catalog data.
- Visible public UI text stays in Russian.
- Keep the public interface light-only: page background `bg-slate-50`, panels `bg-white`, borders `border-slate-200`, shadows `shadow-slate-200/60`, primary accent around emerald.
- Use local FontAwesome icons through Vite; no CDN.
- Do not use Blade `@php` / `@endphp`; prepare data in controllers, services, view models, or Blade component classes.
- Do not execute database queries from Blade.
- Use existing components from `resources/views/components` before adding new ones.
- Use Tailwind v4 utilities and CSS-first `@theme`; do not add `tailwind.config.js`.
- Keep `CatalogController` thin and keep business logic in `app/Services/Catalog`, `app/Services/Seasonvar`, `app/Services/Media`, or `app/View`.
- Keep route model binding for `CatalogTitle` by `slug`.
- Add database indexes only through additive/reversible migrations.
- Do not run destructive commands like `migrate:fresh`, `db:wipe`, `queue:clear`, `cache:clear`, `git reset --hard`, or destructive checkout.
- Do not edit `.env`; use `.env.example` or config only if environment documentation changes.
- Final publication target remains: format/build/verify, then commit and push all intended changed files.

---

## Current State Snapshot

The following work is unfinished or not fully consolidated:

- Existing detailed plans under `docs/superpowers/plans/` are still unchecked:
  - `2026-07-12-catalog-visual-system.md`
  - `2026-07-12-all-seasons-catalog.md`
  - `2026-07-12-catalog-facets-title-metadata.md`
  - `2026-07-12-catalog-search-core.md`
  - `2026-07-11-stats-issue-rows.md`
- `impeccable` context reports no root `PRODUCT.md` / `DESIGN.md`; this plan proceeds from actual code and project docs instead of blocking on new product docs.
- The public UI already has a light visual system, but it is spread across page templates and a few shared components.
- `resources/views/layouts/app.blade.php` is large and owns many SEO/public blocks directly.
- `resources/views/catalog/titles.blade.php` mixes search header, active filters, metrics, sorting, view switch, alphabet nav, advanced filters, facet sidebar, result list, empty state, and pagination in one file.
- `resources/views/catalog/show.blade.php` is functional but has dense playback, media variant, season, recommendation, taxonomy, and FAQ sections in one file.
- `CatalogTitlesPageBuilder` already normalizes many filter/search concerns, but still resolves selected taxonomies and facet display state inline.
- `CatalogTitleQuery` already caches search candidates in request scope, but the all-seasons/facet plans still need a single coherent execution order.
- User requirement for this execution: no new test files. Existing tests can still be run as verification.

## File Map

### Existing files to modify

- `resources/css/app.css`
  - Keep Tailwind v4 import and `@theme`.
  - Refine shared tokens, focus state, motion reduction, card containment, player surface, and responsive utility classes.
- `resources/views/layouts/app.blade.php`
  - Keep document head and layout data variables.
  - Move repeated public SEO blocks into Blade components only after confirming no data prep is needed in Blade.
- `resources/views/components/layout/site-header.blade.php`
  - Preserve search, navigation, and responsive behavior.
  - Improve active/current state and mobile wrapping if needed.
- `resources/views/components/layout/site-footer.blade.php`
  - Keep real project routes only.
  - Avoid link farms and fake links.
- `resources/views/components/ui/panel.blade.php`
  - Keep the `x-ui.panel` contract.
  - Make panel title, subtitle, icon, padding, and attributes consistent.
- `resources/views/components/ui/taxonomy-chip.blade.php`
  - Preserve taxonomy/href/count/muted/active behavior.
  - Ensure long Russian names wrap and remain tappable.
- `resources/views/components/ui/status-pill.blade.php`
  - Standardize state colors and compact variants.
- `resources/views/components/title-card.blade.php`
  - Maintain one main card tab-stop.
  - Keep taxonomy links above stretched link.
  - Improve metadata labels and mobile density.
- `resources/views/components/title-list-row.blade.php`
  - Keep compact/readable modes.
  - Ensure no overflow in long titles/original titles.
- `resources/views/components/title-poster.blade.php`
  - Keep `object-contain` default.
  - Preserve accessible alt behavior.
- `resources/views/components/catalog/episode-link.blade.php`
  - Keep route/query behavior and selected episode state.
  - Improve media availability labels.
- `resources/views/catalog/index.blade.php`
  - Keep search-first home page.
  - Reduce repeated link markup and ensure real-data blocks stay above text-heavy feeds.
- `resources/views/catalog/titles.blade.php`
  - Decompose into components and keep mobile order: search/results first, filters second.
  - Keep all GET state through `CatalogTitlesViewModel`.
- `resources/views/catalog/show.blade.php`
  - Decompose playback/season/reference/recommendation sections only where it reduces risk.
  - Keep viewing controls above SEO/reference sections.
- `resources/views/catalog/stats.blade.php`
  - Keep Livewire dashboard route and public shell.
- `resources/views/livewire/stats-dashboard.blade.php`
  - Keep `wire:poll.1s`.
  - Improve mobile/tablet rows if needed without exposing remote poster URLs.
- `resources/views/vendor/pagination/tailwind.blade.php`
  - Keep Russian labels and light-only controls.
- `resources/js/app.js`
  - Keep one Vite entrypoint.
  - Add only small progressive enhancement if HTML alone remains functional.
- `resources/js/player.js`
  - Preserve Plyr/HLS lazy setup and local sprite.
- `app/View/ViewModels/CatalogTitlesViewModel.php`
  - Add query helpers, labels, and component-ready arrays instead of doing logic in Blade.
- `app/View/ViewModels/CatalogShowViewModel.php`
  - Add playback/season/relation arrays for components if needed.
- `app/Services/Catalog/CatalogTitlesPageBuilder.php`
  - Keep request orchestration; extract local private helpers if the method stays too wide.
- `app/Services/Catalog/CatalogTitlePageBuilder.php`
  - Keep eager loading and selected media logic out of Blade.
- `app/Services/Catalog/CatalogTitleQuery.php`
  - Keep search/filter constraints and performance-sensitive subqueries here.
- `app/Services/Catalog/CatalogFacetQuery.php`
  - Own aggregate facet retrieval and counts.
- `app/Http/Requests/CatalogTitlesRequest.php`
  - Own GET normalization, validation, and bounded arrays.
- `app/Enums/CatalogSort.php`
  - Keep sort labels/icons centralized.
- `docs/UI_STANDARDS.md`
  - Update only factual public UI standards that changed.
- `docs/frontend.md`
  - Update only factual frontend asset/build behavior that changed.
- `docs/views.md`
  - Update component and no-inline-PHP conventions after decomposition.
- `docs/CODE_STANDARDS.md`
  - Update only managed/factual implementation notes if needed.
- `docs/DATA_RELATIONS.md`
  - Update only if metadata/import behavior changes.
- `docs/MAINTENANCE_LOG.md`
  - Add concise maintenance entry after implementation.
- `README.md`
  - Keep project-specific docs; no Laravel starter text.

### Candidate files to create

Create only when the related task starts and the component removes real duplication:

- `resources/views/components/catalog/filter-section.blade.php`
  - Render one facet group with label, icon, records, active state, and counts.
- `resources/views/components/catalog/filter-link.blade.php`
  - Render one filter/taxonomy/year link with active/inactive classes and count summary.
- `resources/views/components/catalog/active-filters.blade.php`
  - Render selected search/title/year/taxonomy/exclusion/advanced/invalid chips.
- `resources/views/components/catalog/catalog-toolbar.blade.php`
  - Render sort buttons, view switch, page-size switch, and result metrics.
- `resources/views/components/catalog/alphabet-nav.blade.php`
  - Render alphabet navigation with active state.
- `resources/views/components/catalog/advanced-filters.blade.php`
  - Render the advanced GET form using ViewModel-prepared state.
- `resources/views/components/catalog/empty-results.blade.php`
  - Render honest empty/insufficient search states and reset actions.
- `resources/views/components/catalog/playback-panel.blade.php`
  - Render player, selected episode state, playback groups, and all media variants.
- `resources/views/components/catalog/season-list.blade.php`
  - Render seasons and episode links without DB access.
- `resources/views/components/catalog/title-reference.blade.php`
  - Render taxonomy rows, actors, year, and top taxonomy chips.
- `resources/views/components/catalog/recommendations-panel.blade.php`
  - Render direct recommendations and fallback genre/year recommendations.
- `resources/views/components/seo/table-of-contents.blade.php`
  - Render layout table of contents from already-prepared `$seoSections`.
- `resources/views/components/seo/summary.blade.php`
  - Render SEO summary/related links from already-prepared `$seo`.
- `resources/views/components/seo/topic-links.blade.php`
  - Render topic terms from already-prepared collections.
- `resources/views/components/seo/glossary.blade.php`
  - Render semantic glossary from already-prepared collections.

Do not create new test files in any task.

---

## Execution Order

### Phase 0: Baseline, Scope, And Safety

**Purpose:** Freeze the current state before touching code and define the first implementation slice.

**Files:**
- Read: `AGENTS.md`
- Read: `docs/UI_STANDARDS.md`
- Read: `docs/frontend.md`
- Read: `docs/views.md`
- Read: `resources/views/catalog/*.blade.php`
- Read: `resources/views/components/**/*.blade.php`
- Read: `app/Services/Catalog/*.php`
- Read: `app/View/ViewModels/*.php`
- Modify: none

**Interfaces:**
- Consumes: current clean git tree and existing plans.
- Produces: an execution checkpoint and exact starting surface.

- [ ] **Step 1: Confirm worktree state**

Run:

```bash
git status --short
git branch --show-current
```

Expected: know whether the tree is clean and which branch will receive the work.

- [ ] **Step 2: Confirm no generated or user changes are mixed in**

Run:

```bash
git diff --stat
git diff --name-only
```

Expected: no unrelated changed files. If files are dirty, preserve them and avoid overwriting.

- [ ] **Step 3: Record current unresolved plan sources**

Run:

```bash
rg -n "^- \\[ \\]" docs/superpowers/plans
```

Expected: unchecked items remain in existing plans; this master plan owns ordering, not deletion.

- [ ] **Step 4: Pick first implementation slice**

Default slice:

```text
Start with shared UI decomposition and /titles UI because it improves visible UX, reduces Blade size, and prepares search/facet work without changing importer data.
```

- [ ] **Step 5: Do not create new test files**

Implementation rule for every later task:

```text
Existing tests may be run. Existing test files may be read. New test files must not be created.
```

### Phase 1: Shared Visual Tokens And Component Vocabulary

**Purpose:** Make the UI foundation consistent before touching page-level layouts.

**Files:**
- Modify: `resources/css/app.css`
- Modify: `resources/views/components/ui/panel.blade.php`
- Modify: `resources/views/components/ui/taxonomy-chip.blade.php`
- Modify: `resources/views/components/ui/status-pill.blade.php`
- Modify: `resources/views/components/title-poster.blade.php`
- Modify: `resources/views/components/title-card.blade.php`
- Modify: `resources/views/components/title-list-row.blade.php`
- Modify: `app/View/Components/TitleCard.php`
- Modify: `app/View/Components/TitleListRow.php`
- Modify: `app/View/Components/Ui/TaxonomyChip.php`
- Modify: `app/View/Components/Ui/StatusPill.php`

**Interfaces:**
- Consumes: existing props used by public pages.
- Produces: unchanged Blade component API with improved layout stability.

- [ ] **Step 1: Audit component API usage**

Run:

```bash
rg -n "<x-(ui\\.panel|ui\\.taxonomy-chip|ui\\.status-pill|title-card|title-list-row|title-poster)" resources/views
```

Expected: exact list of call sites before component edits.

- [ ] **Step 2: Tighten shared CSS tokens**

Edit `resources/css/app.css` only within `@theme`, `@layer base`, `@layer components`, and existing player/focus/reduced-motion blocks.

Allowed changes:

```css
@theme {
    --radius-control: 0.75rem;
    --radius-panel: 1rem;
    --shadow-panel: 0 16px 38px -30px oklch(0.42 0.08 183 / 0.42), 0 1px 2px oklch(0.2 0.02 250 / 0.06);
    --shadow-panel-hover: 0 22px 48px -32px oklch(0.5 0.13 183 / 0.48), 0 8px 18px -16px oklch(0.2 0.02 250 / 0.16);
}
```

Do not introduce dark mode, purple/blue gradients, decorative grid backgrounds, or CDN fonts.

- [ ] **Step 3: Make `x-ui.panel` the single panel vocabulary**

Keep props:

```blade
@props(['title' => null, 'subtitle' => null, 'pad' => true, 'icon' => null])
```

Component behavior:

```text
Panel section keeps rounded light surface.
Header supports icon/title/subtitle.
Slot padding remains controlled by `pad`.
Attributes keep merging through `$attributes->merge`.
No route, request, or database logic is added.
```

- [ ] **Step 4: Fix chip and pill wrapping**

Acceptance:

```text
Long taxonomy names wrap with `break-words`.
Chips keep min-height near 32-36px.
Interactive chips have hover and focus-visible states.
Muted chips stay readable at >= 4.5:1 contrast.
No text truncation utilities are used for public names.
```

- [ ] **Step 5: Keep title cards one-tab-stop**

Acceptance:

```text
The card title link remains the primary stretched link.
Taxonomy links remain separately clickable above the stretched link using z-index.
Poster remains object-contain.
Card metadata does not overflow at 320px viewport.
```

- [ ] **Step 6: Verify with existing checks**

Run after PHP/Blade/CSS edits:

```bash
./vendor/bin/pint --dirty --format agent
npm run build
php artisan test --filter=BladeTemplateTest
php artisan test --filter=CatalogBladeComponentTest
```

Expected:

```text
No new test files.
Build succeeds.
Existing Blade/component tests pass.
No `@php`/`@endphp` introduced.
```

- [ ] **Step 7: Commit checkpoint**

Commit message:

```bash
git add resources/css/app.css resources/views/components app/View/Components
git commit -m "Improve shared catalog UI components"
```

Only run commit when the implementation is complete and user still wants commit/push.

### Phase 2: Layout And Public SEO Block Decomposition

**Purpose:** Reduce layout size and keep public SEO sections declarative without changing SEO data generation.

**Files:**
- Modify: `resources/views/layouts/app.blade.php`
- Create: `resources/views/components/seo/table-of-contents.blade.php`
- Create: `resources/views/components/seo/summary.blade.php`
- Create: `resources/views/components/seo/topic-links.blade.php`
- Create: `resources/views/components/seo/glossary.blade.php`
- Modify: `app/View/ViewData/AppLayoutData.php` only if a component needs prepared values that are currently computed inline.
- Modify: `docs/views.md`

**Interfaces:**
- Consumes: existing layout variables from `AppLayoutData`.
- Produces: smaller layout, same public data, no Blade PHP logic.

- [ ] **Step 1: Identify layout sections that are pure rendering**

Run:

```bash
rg -n "id=\"(table-of-contents|seo-summary|key-topics|semantic-glossary)|data-seo-summary|seoSearchUrl|topicTerms|semanticGlossary" resources/views/layouts/app.blade.php app/View/ViewData/AppLayoutData.php
```

Expected: know every variable that each SEO component needs.

- [ ] **Step 2: Extract table of contents component**

Create `resources/views/components/seo/table-of-contents.blade.php` with props:

```blade
@props(['sections'])
```

Rules:

```text
Accept only a prepared collection/array.
Do not call `request()` inside the component if AppLayoutData can prepare visibility.
Keep visible text Russian.
Keep links and itemprop attributes unchanged unless invalid.
```

- [ ] **Step 3: Extract summary component**

Create `resources/views/components/seo/summary.blade.php` with props:

```blade
@props(['seo'])
```

Rules:

```text
Render `seo_text` and `related_links`.
Keep limits equivalent to current layout.
Escape output with `{{ }}`.
Do not add marketing copy.
```

- [ ] **Step 4: Extract topic links component**

Create `resources/views/components/seo/topic-links.blade.php` with props:

```blade
@props(['terms', 'searchUrl'])
```

Rules:

```text
`searchUrl` must be a prepared callable or prepared URL array from AppLayoutData.
If passing a callable is awkward, prepare an array of `['term' => string, 'url' => string]`.
Do not call database or route discovery loops in Blade.
```

- [ ] **Step 5: Extract glossary component**

Create `resources/views/components/seo/glossary.blade.php` with props:

```blade
@props(['items'])
```

Rules:

```text
Keep DefinedTerm schema.
Keep responsive grid.
Keep public text readable.
```

- [ ] **Step 6: Replace extracted layout markup with components**

Target replacement style:

```blade
<x-seo.table-of-contents :sections="$seoSections" />
<x-seo.summary :seo="$seo" />
<x-seo.topic-links :terms="$topicTerms" :search-url="$seoSearchUrl" />
<x-seo.glossary :items="$semanticGlossary" />
```

If component namespace differs, keep Laravel anonymous component conventions.

- [ ] **Step 7: Verify layout output**

Run:

```bash
php artisan test --filter=BladeTemplateTest
php artisan test --filter=CatalogPageTest
php artisan test --filter=SitemapAndRobotsTest
npm run build
```

Expected: existing public pages still render; no new tests created.

- [ ] **Step 8: Commit checkpoint**

Commit message:

```bash
git add resources/views/layouts/app.blade.php resources/views/components/seo docs/views.md app/View/ViewData/AppLayoutData.php
git commit -m "Extract public SEO layout sections"
```

### Phase 3: Home Page Polish Without Fake Content

**Purpose:** Keep the home page search-first and real-data driven while reducing repeated markup and improving mobile scanning.

**Files:**
- Modify: `resources/views/catalog/index.blade.php`
- Modify: `app/Services/Catalog/CatalogHomePageBuilder.php`
- Modify: `resources/views/components/title-card.blade.php`
- Modify: `resources/views/components/title-list-row.blade.php`
- Optional create: `resources/views/components/catalog/quick-link.blade.php`
- Optional create: `resources/views/components/catalog/latest-media-row.blade.php`

**Interfaces:**
- Consumes: `$stats`, `$featuredTitles`, `$latestMedia`, `$videoTitles`, `$latestByDate`, `$countries`, `$genres`, `$yearBuckets`, `$subtitleTag`.
- Produces: same route output with tighter markup and stable responsive order.

- [ ] **Step 1: Keep top-of-page order**

Required order:

```text
1. Search and quick links.
2. Real catalog metrics.
3. Poster/video update blocks.
4. Date feed and taxonomy navigation.
```

- [ ] **Step 2: Remove repeated quick-link markup if it stays duplicated**

If the same quick link appears in hero and sidebar, create `resources/views/components/catalog/quick-link.blade.php` with props:

```blade
@props(['href', 'icon', 'variant' => 'default', 'count' => null])
```

Acceptance:

```text
No fake links.
No `#` URLs.
Minimum tap height is 44px.
Text wraps on mobile.
```

- [ ] **Step 3: Improve latest media rows only with real media data**

If extracting, create `resources/views/components/catalog/latest-media-row.blade.php` with props:

```blade
@props(['media'])
```

Acceptance:

```text
Render title, season, episode, quality, translation, format, date only from the media relation.
Do not expose raw external video URLs.
Keep route to `titles.show` with episode/media parameters.
```

- [ ] **Step 4: Reduce generic gradient dominance**

Allowed approach:

```text
Keep light emerald/cyan atmospheric background only as a subtle surface accent.
Do not introduce a hero marketing block.
Do not turn the home page into a landing page.
```

- [ ] **Step 5: Verify home page**

Run:

```bash
php artisan test --filter=CatalogPageTest
php artisan test --filter=CatalogVisualSystemTest
npm run build
```

Expected: existing tests pass; UI stays Russian and light.

- [ ] **Step 6: Commit checkpoint**

Commit message:

```bash
git add resources/views/catalog/index.blade.php resources/views/components/catalog app/Services/Catalog/CatalogHomePageBuilder.php
git commit -m "Polish catalog home page"
```

### Phase 4: `/titles` Catalog Workspace Decomposition

**Purpose:** Make `/titles` easier to maintain, faster to scan, and safer on mobile without changing URL semantics.

**Files:**
- Modify: `resources/views/catalog/titles.blade.php`
- Create: `resources/views/components/catalog/filter-section.blade.php`
- Create: `resources/views/components/catalog/filter-link.blade.php`
- Create: `resources/views/components/catalog/active-filters.blade.php`
- Create: `resources/views/components/catalog/catalog-toolbar.blade.php`
- Create: `resources/views/components/catalog/alphabet-nav.blade.php`
- Create: `resources/views/components/catalog/advanced-filters.blade.php`
- Create: `resources/views/components/catalog/empty-results.blade.php`
- Modify: `app/View/ViewModels/CatalogTitlesViewModel.php`
- Modify: `app/Services/Catalog/CatalogTitlesPageBuilder.php` only for prepared component state.

**Interfaces:**
- Consumes:
  - `CatalogTitlesViewModel::filterQuery(string $filterType, ?string $slug): array`
  - `CatalogTitlesViewModel::yearQuery(?int $year): array`
  - `CatalogTitlesViewModel::sortQuery(string $sort): array`
  - `CatalogTitlesViewModel::viewQuery(string $view): array`
  - `CatalogTitlesViewModel::perPageQuery(int $perPage): array`
  - `CatalogTitlesViewModel::alphabetQuery(string $letter): array`
  - `CatalogTitlesViewModel::withoutCatalogState(string $key): array`
- Produces:
  - Same GET query behavior.
  - Same paginator.
  - Smaller page template.

- [ ] **Step 1: Measure current page size and query behavior read-only**

Run with local dev data only:

```bash
php artisan route:list --name=titles
php artisan test --filter=CatalogSearchPageTest
php artisan test --filter=CatalogAdvancedFilterTest
```

Expected: current behavior known before changes; no new test files.

- [ ] **Step 2: Create filter link component**

Create `resources/views/components/catalog/filter-link.blade.php` with props:

```blade
@props([
    'href',
    'icon',
    'label',
    'active' => false,
    'count' => null,
    'total' => null,
])
```

Acceptance:

```text
Active state uses emerald.
Inactive state uses white/slate.
Count and total render as readable compact text.
Long labels wrap.
No database calls.
```

- [ ] **Step 3: Create filter section component**

Create `resources/views/components/catalog/filter-section.blade.php` with props:

```blade
@props([
    'title',
    'icon',
    'items',
    'empty' => 'Нет данных.',
])
```

Acceptance:

```text
Receives fully prepared item arrays or models plus URLs from parent.
Does not infer model relations.
No queries.
No `@php`.
```

- [ ] **Step 4: Move active chips into component**

Create `resources/views/components/catalog/active-filters.blade.php` with props:

```blade
@props([
    'search',
    'titleContext',
    'year',
    'requestedYear',
    'invalidYear',
    'selectedTaxonomies',
    'excludedTaxonomies',
    'invalidFilterSlugs',
    'filterView',
])
```

Acceptance:

```text
Component renders the exact current active chips.
Reset actions remain distinct: clear search, reset filters, show whole catalog.
Russian labels remain unchanged or clearer.
No logic moves into raw PHP.
```

- [ ] **Step 5: Move toolbar into component**

Create `resources/views/components/catalog/catalog-toolbar.blade.php` with props:

```blade
@props([
    'titles',
    'sort',
    'view',
    'perPage',
    'filterView',
])
```

Acceptance:

```text
Renders found count, page count, current sort, sort buttons, view switch, page-size switch.
Controls wrap cleanly at 320px.
No text truncation for Russian labels.
```

- [ ] **Step 6: Move alphabet nav into component**

Create `resources/views/components/catalog/alphabet-nav.blade.php` with props:

```blade
@props(['filterView'])
```

Acceptance:

```text
`latin` displays as `A-Z` or `A–Z` consistently with project typography.
Each link has minimum hit target near 36px.
Active state is visible.
```

- [ ] **Step 7: Move advanced filters into component**

Create `resources/views/components/catalog/advanced-filters.blade.php` with props:

```blade
@props([
    'filterView',
    'titleContext',
    'search',
    'sort',
    'view',
    'perPage',
])
```

Acceptance:

```text
All hidden state stays equivalent.
Numeric ranges keep min/max constraints.
Quality checkboxes preserve current allowed values.
Submit button remains a normal GET form submit.
No JavaScript is required for functionality.
```

- [ ] **Step 8: Move empty state into component**

Create `resources/views/components/catalog/empty-results.blade.php` with props:

```blade
@props([
    'search',
    'insufficientSearch',
    'filterView',
    'titleContext',
    'year',
    'invalidYear',
])
```

Acceptance:

```text
Insufficient search, zero search, zero filters, and full-empty catalog are distinct.
No unrelated fallback cards appear for zero search.
Text remains simple Russian, no importer/parser terminology.
```

- [ ] **Step 9: Recompose `catalog/titles.blade.php`**

Target page structure:

```text
section grid
  main content first in DOM on mobile
    header/search panel
    active filters component
    toolbar component
    alphabet component
    advanced filters component
    result grid/list or empty component
    pagination
  aside filters second on mobile, first on desktop
    years filter section
    taxonomy filter sections
```

- [ ] **Step 10: Verify existing behavior**

Run:

```bash
php artisan test --filter=CatalogTitlesRequestTest
php artisan test --filter=CatalogTitlesViewModelTest
php artisan test --filter=CatalogSearchPageTest
php artisan test --filter=CatalogAdvancedFilterTest
php artisan test --filter=CatalogVisualSystemTest
php artisan test --filter=BladeTemplateTest
npm run build
```

Expected: existing behavior remains intact; no new test files.

- [ ] **Step 11: Commit checkpoint**

Commit message:

```bash
git add resources/views/catalog/titles.blade.php resources/views/components/catalog app/View/ViewModels/CatalogTitlesViewModel.php app/Services/Catalog/CatalogTitlesPageBuilder.php
git commit -m "Refactor catalog titles workspace"
```

### Phase 5: `/titles` Search, Facets, And Query Performance

**Purpose:** Finish the core catalog behavior behind the UI: normalized GET state, bounded multi-select filters, honest empty states, contextual facets, and query performance.

**Files:**
- Modify: `app/Http/Requests/CatalogTitlesRequest.php`
- Modify: `app/Services/Catalog/CatalogTitlesCriteria.php`
- Modify: `app/Services/Catalog/CatalogTitlesPageBuilder.php`
- Modify: `app/Services/Catalog/CatalogTitleQuery.php`
- Modify: `app/Services/Catalog/CatalogFacetQuery.php`
- Modify: `app/Services/Catalog/CatalogSeoBuilder.php`
- Modify: `app/View/ViewModels/CatalogTitlesViewModel.php`
- Modify: `app/Enums/CatalogSort.php`
- Optional create migration: `database/migrations/2026_07_12_XXXXXX_add_catalog_workspace_query_indexes.php`
- Modify: `docs/catalog-search.md`
- Modify: `docs/performance.md`

**Interfaces:**
- Consumes: normalized `CatalogTitlesRequest`, `CatalogSearchQuery`, selected/excluded taxonomy IDs, advanced filter arrays.
- Produces: one paginator, bounded facets, stable query URLs, SEO that does not claim false matches.

- [ ] **Step 1: Preserve request contract**

Keep existing rule concepts:

```text
q: nullable string min 2 max 80
year: nullable array max 20
taxonomy filters: nullable arrays max 20
quality: allowed list only
view: grid/list
per_page: 24/48/96
```

Do not allow unbounded arrays or arbitrary quality strings.

- [ ] **Step 2: Keep scalar backward compatibility**

Acceptance:

```text
`?genre=drama` normalizes to `['drama']`.
`?genre[]=drama&genre[]=komediya` remains multi-select.
Duplicate values collapse.
Empty strings are removed.
```

- [ ] **Step 3: Keep search parsing once per request**

Acceptance:

```text
`CatalogTitlesPageBuilder::data()` calls `CatalogSearchQueryParser::parse()` once.
Downstream query and SEO receive the parsed object or normalized state.
No Blade parsing.
```

- [ ] **Step 4: Keep exact search before broad legacy search**

Acceptance:

```text
Exact title/alias match wins.
If no exact match, all parsed terms must be matched through existing legacy variants.
Insufficient search without title context returns zero results, not fallback catalog.
```

- [ ] **Step 5: Use contextual facet counts**

Acceptance:

```text
Facet display count shows current context count plus global total.
Active selected records stay visible even if outside the top global limit.
Invalid filters produce zero results and visible invalid chips.
Counts are grouped queries, not per-row loops.
```

- [ ] **Step 6: Add only necessary indexes**

Before creating migration, inspect current indexes:

```bash
php artisan migrate:status
sqlite3 database/database.sqlite ".indexes catalog_titles"
sqlite3 database/database.sqlite ".indexes licensed_media"
```

Allowed index targets if missing:

```text
catalog_titles: published/year/indexed_at/title/sort combinations used by filters.
licensed_media: catalog_title_id + is_published + quality/subtitle availability patterns.
ratings: provider/rating/votes/catalog_title_id patterns.
pivot tables: related_id/catalog_title_id and catalog_title_id/related_id pairs.
```

Do not edit old migrations.

- [ ] **Step 7: Verify existing focused behavior**

Run:

```bash
php artisan test --filter=CatalogTitlesRequestTest
php artisan test --filter=CatalogSearchQueryParserTest
php artisan test --filter=CatalogSearchNormalizerTest
php artisan test --filter=CatalogSearchPageTest
php artisan test --filter=CatalogAdvancedFilterTest
php artisan test --filter=CatalogValidationTest
./vendor/bin/pint --dirty --format agent
```

Expected: no new test files; existing search/filter tests pass.

- [ ] **Step 8: Commit checkpoint**

Commit message:

```bash
git add app/Http/Requests app/Services/Catalog app/View/ViewModels app/Enums database/migrations docs/catalog-search.md docs/performance.md
git commit -m "Improve catalog search and facet queries"
```

### Phase 6: Title Page Playback And Metadata Surface

**Purpose:** Make the title page prioritize watching, make variants scannable, and show catalog metadata without raw strings or hidden queries.

**Files:**
- Modify: `resources/views/catalog/show.blade.php`
- Create: `resources/views/components/catalog/playback-panel.blade.php`
- Create: `resources/views/components/catalog/season-list.blade.php`
- Create: `resources/views/components/catalog/title-reference.blade.php`
- Create: `resources/views/components/catalog/recommendations-panel.blade.php`
- Modify: `resources/views/components/catalog/episode-link.blade.php`
- Modify: `app/View/ViewModels/CatalogShowViewModel.php`
- Modify: `app/Services/Catalog/CatalogTitlePageBuilder.php`
- Modify: `resources/js/player.js` only if player behavior must change.

**Interfaces:**
- Consumes: `$showView`, `$title`, `$seasons`, `$selectedEpisode`, `$selectedMedia`, `$mediaItems`, recommendation collections.
- Produces: same selected episode/media URL behavior with clearer UI.

- [ ] **Step 1: Keep player above reference/SEO sections**

Required order:

```text
1. Back/catalog navigation and title identity.
2. Playback panel.
3. Seasons and episodes.
4. Recommendations.
5. Reference/taxonomy details.
6. FAQ/SEO sections.
```

- [ ] **Step 2: Extract playback panel**

Create `resources/views/components/catalog/playback-panel.blade.php` with props:

```blade
@props([
    'title',
    'showView',
    'selectedEpisode',
    'selectedMedia',
    'selectedMediaUrl',
    'selectedMediaType',
    'selectedMediaFormat',
    'selectedEpisodeMediaItems',
    'mediaItems',
])
```

Acceptance:

```text
Video tag remains only when selected media URL exists.
HLS data attribute stays for m3u8.
No raw external URL is rendered outside video source/data attributes required for playback.
Empty player state stays light and actionable.
Playback option groups show quality, format, translation/voice, subtitles, and active state.
```

- [ ] **Step 3: Extract season list**

Create `resources/views/components/catalog/season-list.blade.php` with props:

```blade
@props(['title', 'seasons', 'showView'])
```

Acceptance:

```text
All seasons stay inside one CatalogTitle page.
Selected season opens by default.
Episode grid remains one column on mobile and only becomes dense on wider screens.
Episode links use existing `x-catalog.episode-link`.
```

- [ ] **Step 4: Extract reference metadata**

Create `resources/views/components/catalog/title-reference.blade.php` with props:

```blade
@props([
    'title',
    'actors',
    'taxonomyRows',
    'topTaxonomies',
])
```

Acceptance:

```text
Metadata appears as label/value rows or chips.
No raw comma-separated blobs.
Long names wrap.
All taxonomy links remain clickable.
```

- [ ] **Step 5: Extract recommendations panel**

Create `resources/views/components/catalog/recommendations-panel.blade.php` with props:

```blade
@props([
    'recommendedTitleRecommendations',
    'genreRecommendations',
    'yearRecommendations',
    'title',
])
```

Acceptance:

```text
Direct recommendation reasons remain visible when available.
Fallback genre/year recommendations use real data only.
Empty state says similar titles are not selected yet.
No fake recommendation copy.
```

- [ ] **Step 6: Verify existing title behavior**

Run:

```bash
php artisan test --filter=CatalogPageTest
php artisan test --filter=CatalogBladeComponentTest
php artisan test --filter=CatalogVisualSystemTest
php artisan test --filter=ExternalPlaylistImportTest
npm run build
```

Expected: selected episode/media routes still render; no new test files.

- [ ] **Step 7: Commit checkpoint**

Commit message:

```bash
git add resources/views/catalog/show.blade.php resources/views/components/catalog app/View/ViewModels/CatalogShowViewModel.php app/Services/Catalog/CatalogTitlePageBuilder.php resources/js/player.js
git commit -m "Refine catalog title playback page"
```

### Phase 7: Stats Dashboard Responsive Cleanup

**Purpose:** Keep `/stats` useful on phone/tablet/desktop while preserving Livewire polling and poster proxy safety.

**Files:**
- Modify: `resources/views/catalog/stats.blade.php`
- Modify: `resources/views/livewire/stats-dashboard.blade.php`
- Modify: `app/Services/Catalog/CatalogStatsPageBuilder.php`
- Modify: `app/Services/Catalog/CatalogStatsSnapshotBuilder.php`
- Modify: `app/Services/Catalog/CatalogStatsSnapshotCache.php`
- Modify: `docs/UI_STANDARDS.md`

**Interfaces:**
- Consumes: current Livewire public dashboard state.
- Produces: responsive rows/cards and no remote URL leakage.

- [ ] **Step 1: Preserve Livewire constraints**

Rules:

```text
Keep `wire:poll.1s`.
Do not expose source remote poster URLs in HTML or Livewire payload.
Use `stats.poster` internal proxy route for poster thumbnails.
```

- [ ] **Step 2: Improve mobile rows**

Acceptance:

```text
Phone: one column, no wide tables.
Tablet: two columns where useful.
XL: dense grids allowed.
Issue rows show category, title, state, and action context without overflow.
```

- [ ] **Step 3: Fix known issue row merge behavior if still present**

Reference existing plan:

```text
docs/superpowers/plans/2026-07-11-stats-issue-rows.md
```

Implementation target:

```text
`CatalogStatsPageBuilder::statsIssueRows()` must combine multiple issue categories before de-duplicating and limiting.
```

- [ ] **Step 4: Verify stats surface**

Run:

```bash
php artisan test --filter=CatalogVisualSystemTest
php artisan test --filter=CatalogPageTest
php artisan test --filter=SeasonvarProgressDateFormatTest
npm run build
```

Expected: dashboard renders without new tests.

- [ ] **Step 5: Commit checkpoint**

Commit message:

```bash
git add resources/views/catalog/stats.blade.php resources/views/livewire/stats-dashboard.blade.php app/Services/Catalog docs/UI_STANDARDS.md
git commit -m "Improve stats dashboard responsiveness"
```

### Phase 8: Seasonvar Metadata And Importer Completion

**Purpose:** Fill catalog metadata more reliably from trusted Seasonvar snapshots and media without creating separate titles for seasons.

**Files:**
- Modify: `app/Services/Seasonvar/SeasonvarCatalogParser.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportPipeline.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogRelationSyncer.php`
- Modify: `app/Services/Seasonvar/SeasonvarRelationMetadataNormalizer.php`
- Modify: `app/Services/Seasonvar/SeasonvarRefreshPlanner.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportStorageMaintenance.php`
- Modify: `app/Services/Media/ExternalPlaylistImporter.php`
- Modify: `app/Models/SourcePageSnapshot.php`
- Modify: `app/Models/SeasonvarImportRun.php`
- Optional create migration: `database/migrations/2026_07_12_XXXXXX_add_metadata_backfill_state_to_source_page_snapshots.php`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `docs/performance.md`

**Interfaces:**
- Consumes: existing snapshots, source pages, licensed media, relation synchronizer.
- Produces: local metadata backfill and version-aware refresh planning inside existing `seasonvar:import`.

- [ ] **Step 1: Preserve importer public command boundary**

Rule:

```text
`php artisan seasonvar:import` remains the only public Seasonvar import command.
```

- [ ] **Step 2: Keep seasons and episodes inside one title**

Rule:

```text
Never create separate `CatalogTitle` rows for individual seasons.
```

- [ ] **Step 3: Trust only normalized relation metadata**

Allowed relation metadata:

```text
genre, country, actor, director, age_rating, translation, status, network, studio, tag
```

Rules:

```text
Normalize names and slugs through existing normalizer/syncer.
Reject noisy fragments and invalid source URLs.
Keep Seasonvar source URLs inside `https://seasonvar.ru/`.
```

- [ ] **Step 4: Keep video files external**

Rule:

```text
Store external playback URL, quality, format, translation, subtitle state, and availability. Do not download videos.
```

- [ ] **Step 5: Add versioned local backfill only if current state lacks it**

If needed, additive migration fields:

```text
source_page_snapshots.metadata_parser_version nullable string/indexed
source_page_snapshots.metadata_backfill_attempted_version nullable string/indexed
source_page_snapshots.metadata_backfilled_at nullable timestamp
source_page_snapshots.metadata_backfill_error nullable text
```

Acceptance:

```text
Backfill reads local snapshots/media only.
Remote HTTP refresh remains planner-controlled.
Invalid snapshots do not starve later valid rows.
Summary stores counts under import run metadata/summary.
```

- [ ] **Step 6: Integrate before remote refresh planning**

Pipeline order:

```text
1. Inspect import process state.
2. Run bounded local metadata backfill.
3. Plan remote refresh candidates.
4. Import remote pages through existing crawler/http client.
5. Apply retention/storage maintenance.
6. Store summary and events.
```

- [ ] **Step 7: Verify importer without stray network**

Run existing tests only:

```bash
php artisan test --filter=SeasonvarCatalogParserTest
php artisan test --filter=ExternalPlaylistImportTest
php artisan test --filter=RunSeasonvarImportJobTest
php artisan test --filter=SeasonvarImportMaintenanceTest
php artisan test --filter=SeasonvarSitemapMirrorTest
./vendor/bin/pint --dirty --format agent
```

Expected: no new tests; existing fakes prevent external HTTP where tests cover it.

- [ ] **Step 8: Commit checkpoint**

Commit message:

```bash
git add app/Services/Seasonvar app/Services/Media app/Models database/migrations docs/DATA_RELATIONS.md docs/performance.md
git commit -m "Improve Seasonvar metadata backfill"
```

### Phase 9: Documentation Refresh And Consistency

**Purpose:** Keep project docs factual after UI/query/import changes.

**Files:**
- Modify: `README.md`
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: `docs/CODE_STANDARDS.md`
- Modify: `docs/DATA_RELATIONS.md`
- Modify: `docs/catalog-search.md`
- Modify: `docs/performance.md`
- Modify: `docs/MAINTENANCE_LOG.md`

**Interfaces:**
- Consumes: actual implemented diff.
- Produces: docs that describe the app, not Laravel starter text.

- [ ] **Step 1: Update only docs affected by actual changes**

Rules:

```text
No fake public content.
No marketing copy.
No broad rewrite of unrelated docs.
Keep Russian docs Russian.
```

- [ ] **Step 2: Refresh managed documentation blocks**

Run:

```bash
php artisan project:docs-refresh
```

Expected:

```text
Managed blocks update only intended project documentation.
No unrelated files are changed by the hook/refresh.
```

- [ ] **Step 3: Inspect exact documentation diff**

Run:

```bash
git diff -- README.md docs
```

Expected: all doc changes are factual and project-specific.

- [ ] **Step 4: Commit checkpoint**

Commit message:

```bash
git add README.md docs
git commit -m "Update catalog implementation documentation"
```

### Phase 10: Verification Without New Test Files

**Purpose:** Verify implementation using existing suite/build and browser/read-only checks.

**Files:**
- Modify: none unless verification reveals defects.

**Interfaces:**
- Consumes: completed implementation.
- Produces: known pass/fail status before final commit/push.

- [ ] **Step 1: Format dirty PHP**

Run:

```bash
./vendor/bin/pint --dirty --format agent
```

Expected: changed PHP files formatted.

- [ ] **Step 2: Run focused existing tests**

Run based on touched areas:

```bash
php artisan test --filter=BladeTemplateTest
php artisan test --filter=CatalogBladeComponentTest
php artisan test --filter=CatalogVisualSystemTest
php artisan test --filter=CatalogPageTest
php artisan test --filter=CatalogSearchPageTest
php artisan test --filter=CatalogAdvancedFilterTest
php artisan test --filter=CatalogTitlesRequestTest
php artisan test --filter=CatalogTitlesViewModelTest
```

Expected: all focused existing tests pass.

- [ ] **Step 3: Run importer-focused existing tests only if importer changed**

Run:

```bash
php artisan test --filter=SeasonvarCatalogParserTest
php artisan test --filter=ExternalPlaylistImportTest
php artisan test --filter=RunSeasonvarImportJobTest
php artisan test --filter=SeasonvarImportMaintenanceTest
```

Expected: pass; no stray external HTTP in tests.

- [ ] **Step 4: Run frontend build**

Run:

```bash
npm run build
```

Expected: Vite/Tailwind build succeeds.

- [ ] **Step 5: Run broad existing test suite if time/risk requires**

Run:

```bash
php artisan test
```

Expected: existing suite passes. If it fails outside touched scope, record exact failing tests and do not hide the failure.

- [ ] **Step 6: Browser QA on public pages**

Start local app only if needed:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Check routes:

```text
/
/titles
/titles?q=nevermatchingquery
/titles?genre[]=drama&year[]=2024
/titles?view=list&per_page=48
/stats
one real /titles/{catalogTitle:slug}
```

Viewport checks:

```text
320x700 phone
768x1024 tablet
1440x900 desktop
```

Acceptance:

```text
No horizontal scroll.
No overlapping text.
No truncated public names/descriptions.
Filters remain reachable.
Pagination remains >=44px high where required.
Playback placeholder stays light.
```

### Phase 11: Final Git Commit And Push

**Purpose:** Publish all intended files after implementation and verification.

**Files:**
- All implementation/docs files intentionally changed.
- Exclude `.env`, secrets, logs, raw private data, downloaded media, unrelated generated/cache files.

**Interfaces:**
- Consumes: clean verified diff.
- Produces: pushed branch.

- [ ] **Step 1: Inspect final diff**

Run:

```bash
git status --short
git diff --stat
git diff --name-only
```

Expected: only intended files.

- [ ] **Step 2: Inspect sensitive files**

Run:

```bash
git diff -- .env .env.* storage bootstrap/cache
```

Expected: no secrets or production-local state.

- [ ] **Step 3: Stage intended files**

Run:

```bash
git add app resources routes database docs README.md package.json package-lock.json composer.json composer.lock
```

Then unstage anything unintended if present:

```bash
git status --short
```

- [ ] **Step 4: Commit**

Use one final commit if earlier checkpoint commits were not made:

```bash
git commit -m "Complete Seasonvar catalog UI and search polish"
```

If checkpoint commits already exist, skip this or commit only remaining docs/fixes.

- [ ] **Step 5: Push current branch**

Run:

```bash
git branch --show-current
git push
```

Expected: current branch pushed to its configured upstream.

If no upstream exists:

```bash
git push -u origin "$(git branch --show-current)"
```

### Phase 12: Post-Push Report

**Purpose:** Give a concise delivery report after code is pushed.

**Files:**
- Modify: none.

**Interfaces:**
- Consumes: final git status, commit hash, verification output.
- Produces: user-facing summary.

- [ ] **Step 1: Capture final state**

Run:

```bash
git status --short
git log --oneline -n 5
```

Expected: clean or only intentionally untracked local files; latest commit visible.

- [ ] **Step 2: Report**

Report must include:

```text
Changed areas.
Commit hash.
Push target branch.
Verification commands run.
Any command not run and why.
Any known residual risk.
```

---

## Priority Queue For Actual Coding

Start in this order unless a more urgent defect appears:

1. Phase 1 shared visual tokens/components.
2. Phase 4 `/titles` decomposition.
3. Phase 5 search/facet performance.
4. Phase 6 title playback page.
5. Phase 2 layout SEO extraction.
6. Phase 3 home page polish.
7. Phase 7 stats dashboard.
8. Phase 8 metadata/importer completion.
9. Phase 9 docs refresh.
10. Phase 10 verification.
11. Phase 11 commit/push.
12. Phase 12 report.

## Self-Review

- Spec coverage: user asked for detailed unlimited plan before programming; this file defines detailed phased implementation, exact files, constraints, verification, commit, and push order.
- No-new-test-files constraint: every phase explicitly avoids creating new test files and uses existing tests/build/manual QA instead.
- Project rules: plan preserves Laravel 13 patterns, thin controllers, ViewModel/PageBuilder boundaries, light Russian UI, local assets, no Blade database queries, no CDN, no destructive commands.
- Design skills: plan applies product UI restraint, Seasonvar UI standards, Tailwind v4 rules, and Impeccable constraints for contrast, component states, responsive behavior, and anti-slop patterns.
- Placeholder scan: no task uses "TBD" or "implement later"; optional files are explicitly gated by duplication/risk.
- Type/interface consistency: component props and consumed ViewModel methods are named in the tasks that use them.
