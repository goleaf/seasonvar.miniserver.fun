# Unified Catalog Filters Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the `/titles` sidebar/mobile dialog with one full-width «Точный подбор» block containing every catalog filter.

**Architecture:** Render the heading, unified filter block and results as sibling `catalog-live` islands. The filter island is deferred: its placeholder renders the complete advanced form with a loading state, then the loaded fragment adds the contextual facet groups. All visible inputs share one GET/Livewire form, while `CatalogTitlesViewModel::filterFormState()` preserves only state that has no visible control in that form. Same-name islands must remain siblings because Livewire resolves a deferred island by name.

**Tech Stack:** PHP 8.5, Laravel 13.19, Livewire 4 islands, Blade components, Tailwind CSS 4.3, vanilla JavaScript, PHPUnit 12.5, Playwright.

## Global Constraints

- Visible UI copy remains Russian.
- Do not add production dependencies or change query semantics.
- Preserve deferred facet loading, route-scoped filter composition, `wire:model.live`, GET fallback and browser history.
- Do not add internal scrolling; mobile uses normal page scroll.
- Keep actor/director API comboboxes, contextual counts and controls at least 44px high.
- Work only on existing `main`; preserve unrelated dirty changes.

---

### Task 1: Lock the unified filter contract with failing tests

**Files:**
- Modify: `tests/Feature/CatalogVisualSystemTest.php`
- Modify: `tests/browser/catalog.spec.js`

**Interfaces:**
- Consumes: existing `/titles` SSR, `data-catalog-advanced-filters`, deferred `catalog-live` island.
- Produces: markup contract `#catalog-filters[data-catalog-unified-filters]` and browser interaction through its native `<summary>`.

- [x] **Step 1: Replace sidebar/dialog assertions with the unified block contract**

Update the catalog filter feature test to require one full-width details block and forbid the retired UI:

```php
$this->assertSame(1, substr_count($content, 'id="catalog-filters"'));
$this->assertStringContainsString('data-catalog-unified-filters', $content);
$this->assertStringNotContainsString('<dialog', $content);
$this->assertStringNotContainsString('data-catalog-filter-dialog-open', $content);
$this->assertStringNotContainsString('overflow-y-auto', $content);
$this->assertStringNotContainsString('lg:grid-cols-[260px_minmax(0,1fr)]', $content);
$this->assertMatchesRegularExpression(
    '/data-catalog-advanced-filters.*@island\(name: \'catalog-live\', defer: true\)/s',
    file_get_contents(resource_path('views/catalog/titles.blade.php')),
);
```

Inspect `resources/views/components/catalog/title-filters.blade.php` and require `Годы`, `Тип публикации`, `Субтитры`, the taxonomy loop, responsive columns and no nested `<form>`.

- [x] **Step 2: Add active-filter disclosure coverage**

Create a genre, request `/titles?genre[]=drama`, and assert the exact block is rendered with `open` and the all-filter count:

```php
$genre = Genre::query()->create(['name' => 'Драма', 'slug' => 'drama']);
$title = CatalogTitle::factory()->create();
$title->genres()->attach($genre);

$content = $this->get(route('titles.index', ['genre' => ['drama']]))
    ->assertOk()
    ->getContent();

$this->assertMatchesRegularExpression('/<details[^>]*id="catalog-filters"[^>]*open/s', $content);
$this->assertStringContainsString('data-catalog-filter-count', $content);
```

- [x] **Step 3: Update the JavaScript and Playwright expectations**

Require `AbortController` and combobox keyboard handling, but forbid `showModal()` and `returnFocus`. In `tests/browser/catalog.spec.js`, click `#catalog-filters > summary`, assert the details has `open`, wait for `[data-catalog-filter-groups]`, and keep geometry, touch-target and axe assertions.

- [x] **Step 4: Run focused tests to verify RED**

Run:

```bash
php artisan test --filter='CatalogVisualSystemTest'
```

Expected: failures mentioning the still-present dialog/sidebar and absent unified filter attributes.

---

### Task 2: Build the single full-width filter form

**Files:**
- Modify: `resources/views/catalog/titles.blade.php`
- Modify: `resources/views/components/catalog/title-filters.blade.php`
- Modify: `app/View/ViewModels/CatalogTitlesViewModel.php`
- Modify: `lang/ru/catalog.php`
- Modify: `resources/js/app.js`
- Test: `tests/Feature/CatalogVisualSystemTest.php`

**Interfaces:**
- Consumes: `CatalogTitlesViewModel::activeFilterCount()`, `hasActiveFilters()`, `filterFormState()`, `$this->catalogFacets`, and existing Livewire actions.
- Produces: one GET form with all current request keys inside a sibling deferred `catalog-live` island.

- [x] **Step 1: Remove the two-column shell and dialog**

Change the page root to a normal vertical stack:

```blade
<section class="space-y-5">
```

Delete the `<dialog>`, its mobile open/close controls and the `lg:grid-cols-[260px_minmax(0,1fr)]`/sticky sidebar classes. Keep the page island in a single `<div class="min-w-0 space-y-5">`.

- [x] **Step 2: Turn «Точный подбор» into the unified form**

Give the existing details the canonical id and all-filter state:

```blade
<details
    id="catalog-filters"
    data-catalog-advanced-filters
    data-catalog-unified-filters
    class="group rounded-panel border border-slate-200 bg-white p-3 sm:p-4"
    @if ($filterView->hasActiveFilters()) open @endif
>
```

Use `$filterView->activeFilterCount()` in a `data-catalog-filter-count` badge. Extract the complete details/form into `x-catalog.unified-title-filters`, and render that component as a sibling deferred island between the control panel and results:

```blade
@island(name: 'catalog-live', defer: true)
    @placeholder
        <x-catalog.unified-title-filters
            :filter-view="$this->catalogPage['filterView']"
            loading
        />
    @endplaceholder

    <x-catalog.unified-title-filters
        :data="$this->catalogFacets"
        :option-search="$this->optionSearch"
    />
@endisland
```

Inside the component, keep the four advanced fieldsets before the facet groups. End the form with the existing submit action and a `wire:click.prevent="resetAll"` link labelled «Сбросить фильтры».

- [x] **Step 3: Make the deferred component render facet groups only**

Remove its `<form>`, hidden fields and sticky footer. Keep a live loading status, then wrap the year, publication, subtitle and taxonomy groups in:

```blade
<div data-catalog-filter-groups class="mt-4 columns-1 gap-3 lg:columns-2 2xl:columns-3">
```

Each group becomes an `inline-block w-full break-inside-avoid rounded-control border border-slate-200 bg-white p-3` section. Preserve existing `wire:model.live`, `wire:replace.self`, combobox endpoints, option search attributes, active styling and contextual counts.

- [x] **Step 4: Prevent duplicate GET parameters**

In `CatalogTitlesViewModel::filterFormState()`, remove every key represented by a visible unified-form control before returning hidden state:

```php
$visibleFilterKeys = [
    'year',
    'publication_type',
    'subtitles',
    'quality',
    ...array_keys(CatalogSeriesFilters::ADVANCED_REQUEST_PROPERTIES),
];

foreach ([...array_keys($this->typeLabels), ...$visibleFilterKeys] as $filterKey) {
    unset($query[$filterKey]);
}
```

Keep `q`, excluded taxonomies, title context, letter, sort, view and page size where applicable.

- [x] **Step 5: Remove obsolete dialog JavaScript**

Delete `returnFocus`, `initializedCatalogDialogs`, `loadCatalogFilterDialog()` and its call from `loadCatalogInterfaces()`. Leave local facet search, people combobox setup and pagination scrolling unchanged.

- [x] **Step 6: Update Russian exact-filter copy**

Set the description to `Уточните годы, тип, жанры, страны, актёров, рейтинг и доступность видео.` and the reset label to `Сбросить фильтры`.

- [x] **Step 7: Run focused tests to verify GREEN**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter='CatalogVisualSystemTest'
php artisan test --filter='CatalogPageTest'
```

Expected: all selected PHPUnit tests pass.

- [x] **Step 8: Commit the implementation**

Stage only the files and exact hunks owned by this task, preserving unrelated work, then commit:

```bash
git commit -m "feat: unify catalog filters"
```

---

### Task 3: Document and verify the production UI

**Files:**
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/catalog-search.md`
- Modify: `tests/browser/catalog.spec.js`

**Interfaces:**
- Consumes: final unified filter markup and current Playwright configuration.
- Produces: durable UI/search documentation and responsive browser evidence.

- [x] **Step 1: Update project contracts**

Replace the sidebar/mobile-dialog rules with: one full-width `#catalog-filters`, normal page scrolling, automatic opening for active filters, responsive facet columns and deferred loading inside «Точный подбор». In `docs/catalog-search.md`, replace “desktop sidebar/mobile trigger” with the unified deferred block behavior.

- [x] **Step 2: Build frontend assets**

Run:

```bash
npm run build
```

Expected: Vite exits successfully and emits production assets without unresolved Tailwind classes.

- [x] **Step 3: Run browser QA**

Run the catalog Playwright project against the local/production-compatible server and verify desktop plus mobile. Expected: the native details opens, deferred filter groups load, actor combobox remains reachable, horizontal overflow is at most 1px, and no serious/critical axe violations or browser errors occur.

- [x] **Step 4: Run the broad regression suite**

Run:

```bash
php artisan test
```

Expected: the complete test suite passes.

- [x] **Step 5: Commit documentation and browser coverage**

Stage only task-owned documentation hunks and browser test changes, then commit:

```bash
git commit -m "docs: record unified catalog filter contract"
```
