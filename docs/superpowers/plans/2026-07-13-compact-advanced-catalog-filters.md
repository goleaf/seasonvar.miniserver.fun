# Compact Advanced Catalog Filters Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the oversized flat advanced-filter grid with four compact, explanatory groups and improve the directly adjacent mobile output controls without changing filter semantics or URLs.

**Architecture:** Keep `CatalogSeriesFilters` as the canonical Livewire URL/form state, add one exact-group reset boundary there, and expose count/reset-query presentation data through `CatalogTitlesViewModel`. Keep `resources/views/catalog/titles.blade.php` declarative and reuse the existing `applyFilters`, sorting, view, page-size, and alphabet actions.

**Tech Stack:** PHP 8.5, Laravel 13.19, Livewire 4.3, Blade, Tailwind CSS 4.3, PHPUnit 12.5.

## Global Constraints

- Work only on the existing `main` branch and preserve unrelated user changes.
- Do not add dependencies or edit `.env`.
- Visible copy is Russian and the interface remains light-only.
- Do not add Volt, `@php`, PHP tags, database queries, cache calls, service resolution, or variable-preparation blocks to Blade.
- Keep every control at least 44 pixels high and prevent horizontal scrolling at 320 pixels.
- Preserve every existing request key, Livewire model, validation rule, query meaning, and ordinary GET fallback.
- Short number inputs may be `w-full` on narrow mobile screens but must receive an explicit compact `sm:w-*` width.
- Do not redesign unrelated title/player/importer pages as part of this plan.

---

### Task 1: Exact advanced-filter state and reset boundary

**Files:**
- Modify: `app/Livewire/Forms/CatalogSeriesFilters.php`
- Modify: `app/Livewire/CatalogSeries.php`
- Modify: `app/View/ViewModels/CatalogTitlesViewModel.php`
- Test: `tests/Unit/CatalogTitlesViewModelTest.php`
- Test: `tests/Feature/CatalogPageTest.php`

**Interfaces:**
- Produces: `CatalogSeriesFilters::resetAdvancedFilters(): void`.
- Produces: `CatalogSeries::resetAdvancedFilters(): void` as the public Livewire action.
- Produces: `CatalogTitlesViewModel::advancedFilterCount(): int` and `advancedFiltersResetQuery(): array<string, mixed>`.
- Preserves: existing `resetAdvanced(string $key): bool` for individual active chips.

- [ ] **Step 1: Add failing ViewModel tests for count and fallback reset URL**

Append to `tests/Unit/CatalogTitlesViewModelTest.php`:

```php
public function test_advanced_filter_count_and_reset_query_cover_only_exact_selection_state(): void
{
    $viewModel = new CatalogTitlesViewModel(
        search: 'Мамочка',
        sort: 'year_desc',
        year: null,
        requestedYear: '',
        invalidYear: false,
        activeTaxonomies: collect(),
        selectedTaxonomies: collect(),
        activeFilterSlugs: [],
        invalidFilterSlugs: [],
        titleContext: null,
        catalogQueryState: [
            'q' => 'Мамочка',
            'genre' => ['comedy'],
            'year_from' => '2010',
            'rating_min' => '7.5',
            'quality' => ['1080p', '720p'],
            'letter' => 'М',
            'sort' => 'year_desc',
        ],
    );

    $this->assertSame(4, $viewModel->advancedFilterCount());
    $this->assertTrue($viewModel->hasAdvancedFilters());
    $this->assertSame([
        'q' => 'Мамочка',
        'genre' => ['comedy'],
        'letter' => 'М',
        'sort' => 'year_desc',
    ], $viewModel->advancedFiltersResetQuery());
}
```

- [ ] **Step 2: Add a failing Livewire test for grouped reset isolation**

Append to `tests/Feature/CatalogPageTest.php`:

```php
public function test_livewire_catalog_resets_only_exact_selection_filters(): void
{
    CatalogTitle::factory()->create();

    $genre = Genre::query()->create([
        'name' => 'Комедия',
        'slug' => 'comedy',
    ]);

    Livewire::test(CatalogSeries::class)
        ->set('filters.search', 'Мамочка')
        ->set('filters.genre', [$genre->slug])
        ->set('filters.yearFrom', 2010)
        ->set('filters.yearTo', 2024)
        ->set('filters.seasonsMin', 2)
        ->set('filters.episodesMax', 100)
        ->set('filters.ratingSource', 'imdb')
        ->set('filters.ratingMin', 7.5)
        ->set('filters.votesMin', 1000)
        ->set('filters.video', 'available')
        ->set('filters.updated', 'month')
        ->set('filters.qualities', ['1080p', '720p'])
        ->call('resetAdvancedFilters')
        ->assertSet('filters.search', 'Мамочка')
        ->assertSet('filters.genre', ['comedy'])
        ->assertSet('filters.yearFrom', '')
        ->assertSet('filters.yearTo', '')
        ->assertSet('filters.seasonsMin', '')
        ->assertSet('filters.episodesMax', '')
        ->assertSet('filters.ratingSource', '')
        ->assertSet('filters.ratingMin', '')
        ->assertSet('filters.votesMin', '')
        ->assertSet('filters.video', '')
        ->assertSet('filters.updated', '')
        ->assertSet('filters.qualities', [])
        ->assertSet('paginators.page', 1);
}
```

- [ ] **Step 3: Run the focused tests and confirm the missing methods fail**

Run:

```bash
php artisan test tests/Unit/CatalogTitlesViewModelTest.php tests/Feature/CatalogPageTest.php --filter='advanced_filter_count|resets_only_exact_selection'
```

Expected: FAIL because `advancedFilterCount()`, `advancedFiltersResetQuery()`, and `resetAdvancedFilters()` do not exist.

- [ ] **Step 4: Add the exact reset method to the Form Object**

Add to `CatalogSeriesFilters`:

```php
public const ADVANCED_REQUEST_PROPERTIES = [
    'year_from' => 'yearFrom',
    'year_to' => 'yearTo',
    'seasons_min' => 'seasonsMin',
    'seasons_max' => 'seasonsMax',
    'episodes_min' => 'episodesMin',
    'episodes_max' => 'episodesMax',
    'rating_source' => 'ratingSource',
    'rating_min' => 'ratingMin',
    'votes_min' => 'votesMin',
    'video' => 'video',
    'updated' => 'updated',
];

public function resetAdvancedFilters(): void
{
    foreach (self::ADVANCED_REQUEST_PROPERTIES as $property) {
        $this->{$property} = '';
    }

    $this->qualities = [];
}
```

Refactor `resetAdvanced()` to read scalar properties from `ADVANCED_REQUEST_PROPERTIES`, while retaining the existing special `letter` mapping:

```php
$property = self::ADVANCED_REQUEST_PROPERTIES[$key] ?? match ($key) {
    'letter' => 'letter',
    default => null,
};
```

- [ ] **Step 5: Add the public Livewire action**

Add to `CatalogSeries` next to `resetAdvanced()`:

```php
public function resetAdvancedFilters(): void
{
    $this->filters->resetAdvancedFilters();

    foreach (array_keys(CatalogSeriesFilters::ADVANCED_REQUEST_PROPERTIES) as $key) {
        $this->resetErrorBag($key);
    }

    $this->resetErrorBag('quality');
    $this->resetPage();
}
```

- [ ] **Step 6: Add exact count and fallback query methods to the ViewModel**

Replace `hasAdvancedFilters()` with:

```php
public function hasAdvancedFilters(): bool
{
    return $this->advancedFilterCount() > 0;
}

public function advancedFilterCount(): int
{
    $scalarCount = collect(array_keys(CatalogSeriesFilters::ADVANCED_REQUEST_PROPERTIES))
        ->filter(fn (string $key): bool => $this->scalarState($key) !== '')
        ->count();

    return $scalarCount + count($this->listState('quality'));
}

/** @return array<string, mixed> */
public function advancedFiltersResetQuery(): array
{
    $query = $this->sortQuery($this->sort);

    foreach ([...array_keys(CatalogSeriesFilters::ADVANCED_REQUEST_PROPERTIES), 'quality'] as $key) {
        unset($query[$key]);
    }

    return $query;
}
```

Import `App\Livewire\Forms\CatalogSeriesFilters` at the top of the ViewModel.

- [ ] **Step 7: Run focused tests**

Run the command from Step 3.

Expected: PASS for both new tests.

- [ ] **Step 8: Format and commit the state boundary**

```bash
./vendor/bin/pint --dirty --format agent
git add app/Livewire/Forms/CatalogSeriesFilters.php app/Livewire/CatalogSeries.php app/View/ViewModels/CatalogTitlesViewModel.php tests/Unit/CatalogTitlesViewModelTest.php tests/Feature/CatalogPageTest.php
git commit -m "feat: add exact catalog filter reset boundary"
```

### Task 2: Four compact semantic filter groups

**Files:**
- Modify: `resources/views/catalog/titles.blade.php:430-548`
- Modify: `tests/Feature/CatalogVisualSystemTest.php`

**Interfaces:**
- Consumes: `advancedFilterCount()` and `advancedFiltersResetQuery()` from Task 1.
- Consumes: existing Livewire models and `applyFilters` action unchanged.
- Produces: four fieldsets marked with `data-catalog-advanced-group` and stable group values `period`, `volume`, `rating`, `video`.

- [ ] **Step 1: Add a failing semantic-layout contract test**

Append to `CatalogVisualSystemTest`:

```php
public function test_advanced_catalog_filters_use_four_compact_explanatory_groups(): void
{
    CatalogTitle::factory()->create();
    $content = $this->get(route('titles.index'))->assertOk()->getContent();

    $this->assertStringContainsString('data-catalog-advanced-filters', $content);
    $this->assertSame(4, substr_count($content, 'data-catalog-advanced-group='));
    $this->assertStringContainsString('data-catalog-advanced-group="period"', $content);
    $this->assertStringContainsString('data-catalog-advanced-group="volume"', $content);
    $this->assertStringContainsString('data-catalog-advanced-group="rating"', $content);
    $this->assertStringContainsString('data-catalog-advanced-group="video"', $content);
    $this->assertStringContainsString('Точный подбор', $content);
    $this->assertStringContainsString('Уточните период, объём сериала, рейтинг и доступность видео', $content);
    $this->assertStringContainsString('Показать результаты', $content);
    $this->assertStringContainsString('Сбросить точный подбор', $content);

    foreach (['year_from', 'year_to', 'seasons_min', 'seasons_max', 'episodes_min', 'episodes_max', 'rating_min', 'votes_min'] as $name) {
        $this->assertMatchesRegularExpression('/name="'.preg_quote($name, '/').'"[^>]*class="[^"]*w-full[^"]*sm:w-/s', $content);
    }
}
```

- [ ] **Step 2: Run the new visual contract and confirm it fails**

```bash
php artisan test tests/Feature/CatalogVisualSystemTest.php --filter=advanced_catalog_filters
```

Expected: FAIL because semantic group markers and new copy are absent.

- [ ] **Step 3: Replace only the advanced `<details>` block**

Replace the existing advanced `<details>` wrapper with the exact heading, count badge,
description, four-fieldset grid, and actions described in Steps 4–8. Move the existing
hidden `q`, taxonomy, year, sort, view, per-page, and letter inputs into the new form
without changing their names or values. No temporary Blade comments or implementation
markers remain in the finished template.

- [ ] **Step 4: Implement the Period fieldset**

Use `data-catalog-advanced-group="period"`, a calendar icon, helper text, a paired year row, and the existing `updated` select. Number controls use:

```blade
class="min-h-11 w-full rounded-control border border-slate-200 bg-white px-3 py-2 text-slate-700 sm:w-28"
```

The update select uses `sm:max-w-56`, retains all existing option values, and changes the empty label to «Любое время».

- [ ] **Step 5: Implement the Volume fieldset**

Use `data-catalog-advanced-group="volume"`. Render two labelled rows, «Сезоны» and «Серии», each containing visible «от»/«до» labels and the original models/names/minimums. Use `sm:w-24` for season counts and `sm:w-28` for episode counts. Add the helper copy from the design spec.

- [ ] **Step 6: Implement the Rating fieldset**

Use `data-catalog-advanced-group="rating"`. Keep the existing rating-source values and validation bounds. Use a compact `sm:max-w-52` source select, `sm:w-24` for rating, and `sm:w-36` for votes. Add the sentence: «Высокая оценка при малом числе голосов может быть менее показательной.»

- [ ] **Step 7: Implement the Video fieldset**

Use `data-catalog-advanced-group="video"`. Keep the existing video values and use labels «Неважно», «Есть видео», «Нет видео». Preserve real quality checkboxes and add selected presentation with existing server state:

```blade
@class([
    'inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-control px-3 py-2 text-sm font-semibold transition',
    'bg-emerald-50 text-emerald-700' => in_array($quality, $filterView->listState('quality'), true),
    'bg-slate-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-700' => ! in_array($quality, $filterView->listState('quality'), true),
])
```

- [ ] **Step 8: Add proportional actions with GET fallback**

```blade
<div class="flex flex-col gap-2 border-t border-slate-200 pt-4 sm:flex-row sm:items-center">
    <button type="submit" wire:loading.attr="disabled" wire:target="applyFilters" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-emerald-700 px-4 py-2 text-sm font-bold text-white transition hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60">
        <x-ui.icon name="fa-solid fa-filter" />
        <span>Показать результаты</span>
    </button>
    <a href="{{ route('titles.index', $filterView->advancedFiltersResetQuery()) }}" wire:click.prevent="resetAdvancedFilters" class="inline-flex min-h-11 items-center justify-center gap-2 px-3 py-2 text-sm font-bold text-slate-600 hover:text-emerald-700">
        <x-ui.icon name="fa-solid fa-rotate-left" />
        <span>Сбросить точный подбор</span>
    </a>
</div>
```

- [ ] **Step 9: Add the new action to the result loading targets**

In both result loading `wire:target` lists, add `resetAdvancedFilters` next to `resetAdvanced`. Do not add a new spinner or JS dependency.

- [ ] **Step 10: Run the visual contract and existing catalog UI tests**

```bash
php artisan test tests/Feature/CatalogVisualSystemTest.php tests/Feature/CatalogPageTest.php
```

Expected: PASS.

- [ ] **Step 11: Commit the semantic UI**

```bash
git add resources/views/catalog/titles.blade.php tests/Feature/CatalogVisualSystemTest.php
git commit -m "feat: redesign advanced catalog filters"
```

### Task 3: Useful adjacent mobile output controls

**Files:**
- Modify: `resources/views/catalog/titles.blade.php:399-428`
- Modify: `tests/Feature/CatalogVisualSystemTest.php`

**Interfaces:**
- Consumes: existing `viewQuery()`, `perPageQuery()`, `setView()`, and `setPerPage()`.
- Produces: mobile access to view mode and page size without duplicating state or IDs.

- [ ] **Step 1: Add a failing mobile-controls test**

Extend the existing mobile search/dialog test with:

```php
$this->assertStringContainsString('data-catalog-mobile-view-controls', $content);
$this->assertStringContainsString('data-catalog-mobile-page-size-controls', $content);
$this->assertStringContainsString('wire:click.prevent="setView(\'grid\')"', $content);
$this->assertStringContainsString('wire:click.prevent="setPerPage(48)"', $content);
```

- [ ] **Step 2: Run the focused test and confirm failure**

```bash
php artisan test tests/Feature/CatalogVisualSystemTest.php --filter=catalog_search_ui
```

Expected: FAIL because mobile view/page-size controls are absent.

- [ ] **Step 3: Add compact view and page-size rows to the existing mobile details**

After mobile sort options and before the alphabet nav, add two labelled `flex flex-wrap` rows. Reuse the same links, selected classes, GET hrefs, and Livewire actions used by desktop controls. Mark them with `data-catalog-mobile-view-controls` and `data-catalog-mobile-page-size-controls`. Keep every link `min-h-11`; use no new component or JavaScript.

- [ ] **Step 4: Run focused visual tests and commit**

```bash
php artisan test tests/Feature/CatalogVisualSystemTest.php
git add resources/views/catalog/titles.blade.php tests/Feature/CatalogVisualSystemTest.php
git commit -m "feat: expose mobile catalog output controls"
```

### Task 4: Documentation, regression suite, build, and visual QA

**Files:**
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Documents the exact UI/state/reset contract created by Tasks 1–3.
- Does not change generated `project-docs` blocks manually.

- [ ] **Step 1: Document the final contract**

Add concise project-specific rules:

- advanced filters use four semantic groups and compact number widths;
- the dedicated reset clears only exact-selection fields and quality;
- mobile output controls include sort, view, page size, and alphabet;
- all controls remain in the existing Livewire/GET boundary and Blade stays presentation-only.

Add one entry to `CHANGELOG.md` describing the user-visible redesign.

- [ ] **Step 2: Run PHP formatting and focused tests**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Unit/CatalogTitlesViewModelTest.php tests/Feature/CatalogPageTest.php tests/Feature/CatalogVisualSystemTest.php tests/Feature/CatalogValidationTest.php
```

Expected: PASS.

- [ ] **Step 3: Run Blade architecture and documentation checks**

```bash
php artisan test tests/Unit/BladeTemplateTest.php tests/Feature/CatalogBladeComponentTest.php
php artisan project:docs-refresh --check
```

Expected: PASS and «Документация уже актуальна.»

- [ ] **Step 4: Build production frontend assets**

```bash
npm run build
```

Expected: Vite production build exits 0.

- [ ] **Step 5: Run browser QA**

Use the project Playwright workflow on `/titles` at approximately 390×844, 768×1024, and 1440×1000. Verify closed/open details, active-count badge, wrapped range inputs, keyboard focus, reset behavior, loading opacity, no horizontal overflow, no console errors, and mobile view/page-size controls.

- [ ] **Step 6: Run the complete PHP suite**

```bash
php artisan test
```

Expected: complete suite PASS; infrastructure-only tests may retain their documented opt-in skips.

- [ ] **Step 7: Commit documentation and any QA-only fixes**

```bash
git add CHANGELOG.md docs/UI_STANDARDS.md docs/frontend.md docs/views.md
git commit -m "docs: document compact catalog filter controls"
```

- [ ] **Step 8: Verify branch, push, and clean state**

```bash
git status --short --branch
git branch --show-current
git push origin main
git status --porcelain
git rev-list --left-right --count origin/main...main
```

Expected: branch `main`, push succeeds, no porcelain records, divergence `0 0`.
