# List-Only Catalog Interface Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace catalog content grids with one responsive list/table presentation, remove web `view` state, and show uncropped `2:3` posters on mobile, tablet, and desktop.

**Architecture:** `x-ui.poster-frame` gains an explicit cover/contain fit contract, while `x-ui.poster-card` and `x-catalog.title-card` expose list, compact, recommendation, and technical stats layouts instead of a public grid layout. Web catalog state no longer carries `view`; all content surfaces reuse a vertical divided-list shell, while structural CSS Grid for forms, navigation, counters, and technical dashboards remains intact.

**Tech Stack:** PHP 8.5, Laravel 13.19, Livewire 4.3, Blade, Tailwind CSS 4.3, PHPUnit 12.5, Playwright.

## Global Constraints

- Work only on the existing `main` branch; do not create worktrees, branches, or subagents.
- Visible interface text remains Russian and Blade remains query-free and free of inline PHP.
- Do not add production dependencies or change the read-only mobile API response shape.
- Content collections are list-only; CSS Grid remains allowed for structural forms, navigation, counters, page layout, player/admin controls, and technical stats.
- List posters use exact `2:3`, `object-contain`, no overscan, and widths `4rem` / `5rem` / `6rem` at base / `sm` / `md`.
- Interactive controls keep a minimum effective `44×44 px` target and no internal scrolling is introduced.
- Follow RED → verify failure → GREEN → verify pass for every behavior change.

---

### Task 1: Shared uncropped list poster contract

**Files:**
- Modify: `tests/Unit/BladeTemplateTest.php`
- Modify: `tests/Feature/CatalogVisualSystemTest.php`
- Modify: `app/View/Components/Ui/PosterFrame.php`
- Modify: `resources/views/components/ui/poster-frame.blade.php`
- Modify: `app/View/Components/Ui/PosterCard.php`
- Modify: `resources/views/components/ui/poster-card.blade.php`
- Modify: `app/View/Components/Catalog/TitleCard.php`
- Create: `resources/views/components/catalog/title-card-list.blade.php`
- Delete: `resources/views/components/catalog/title-card-grid.blade.php`
- Delete: `resources/views/components/catalog/title-card-horizontal.blade.php`
- Modify: `resources/views/components/catalog/title-card-recommendation.blade.php`
- Modify: `resources/views/livewire/stats-dashboard.blade.php`

**Interfaces:**
- Consumes: existing `src`, `alt`, `loading`, `overscan`, title aggregates, and eager-loaded card relations.
- Produces: `PosterFrame::$fit`, `PosterFrame::imageClasses()`, and poster-card layouts `list|compact|recommendation|stats`; `TitleCard` defaults to `list`.

- [x] **Step 1: Write failing component tests for contain/list behavior**

Replace the poster tests with assertions equivalent to:

```php
public function test_poster_frame_supports_uncropped_contain_without_overscan(): void
{
    $this->blade('<x-ui.poster-frame src="https://media.example.com/poster.jpg" alt="Постер" fit="contain" :overscan="false" class="aspect-[2/3]" />')
        ->assertSee('object-contain object-center', false)
        ->assertDontSee('object-cover', false)
        ->assertDontSee('scale-[1.02]', false);
}

public function test_poster_card_exposes_list_contract_without_public_grid_layout(): void
{
    foreach (['list', 'compact', 'recommendation', 'stats'] as $layout) {
        $this->blade('<x-ui.poster-card :layout="$layout" alt="Постер">Описание</x-ui.poster-card>', compact('layout'))
            ->assertSee('data-ui-poster-layout="'.$layout.'"', false);
    }

    $this->blade('<x-ui.poster-card layout="grid" alt="Постер">Описание</x-ui.poster-card>')
        ->assertSee('data-ui-poster-layout="list"', false);
}
```

- [x] **Step 2: Run the component tests and verify RED**

Run: `php artisan test tests/Unit/BladeTemplateTest.php tests/Feature/CatalogVisualSystemTest.php --filter='poster|Poster'`

Expected: failures because `fit`, `imageClasses()`, list fallback, and `2:3` recommendation/list classes do not exist.

- [x] **Step 3: Implement the shared fit and list layouts**

Implement `PosterFrame` normalization and class construction:

```php
public string $fit;

public function __construct(..., string $fit = 'cover', public bool $overscan = true)
{
    $this->fit = in_array($fit, ['cover', 'contain'], true) ? $fit : 'cover';
}

public function imageClasses(): string
{
    return collect([
        'absolute inset-0 h-full w-full object-center',
        $this->fit === 'contain' ? 'object-contain' : 'object-cover',
        $this->fit === 'cover' && $this->overscan ? 'scale-[1.02]' : null,
    ])->filter()->implode(' ');
}
```

Make non-stats poster-card layouts use padded two-column rows and contained `2:3` media. Keep the existing vertical technical stats card behind the renamed `stats` layout. Pass `fit="contain"` and `overscan=false` for `list|compact|recommendation`; pass cover/overscan for `stats`.

Rename the catalog list template, render `recommendation` separately, and route `list|compact` to `title-card-list`. Remove the old grid template and change the default/fallback to `list`.

- [x] **Step 4: Run component tests and verify GREEN**

Run: `php artisan test tests/Unit/BladeTemplateTest.php tests/Feature/CatalogVisualSystemTest.php --filter='poster|Poster'`

Expected: all selected tests pass with contain/no-overscan list output and retained default cover behavior.

- [ ] **Step 5: Commit the shared component cycle**

```bash
git add app/View/Components/Ui app/View/Components/Catalog resources/views/components/ui resources/views/components/catalog resources/views/livewire/stats-dashboard.blade.php tests/Unit/BladeTemplateTest.php tests/Feature/CatalogVisualSystemTest.php
git commit -m "refactor: add uncropped catalog list rows"
```

### Task 2: Restore homepage latest-updates list

**Files:**
- Modify: `tests/Feature/CatalogVisualSystemTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `resources/views/catalog/index.blade.php`
- Modify: `resources/views/components/catalog/latest-media-card.blade.php`

**Interfaces:**
- Consumes: existing `CatalogHomePageBuilder` values `latestByDate`, `latestMedia`, and `videoTitles`.
- Produces: `data-home-latest-updates-list` and one divided list per homepage content section.

- [x] **Step 1: Write failing homepage list tests**

Add assertions that the rendered home page contains `data-home-latest-updates-list`, date headings, `data-ui-poster-layout="list"`, and no `data-home-latest-updates-grid` or «Лента обновлений по датам». Create differently dated titles and assert newest titles appear in the restored panel.

- [x] **Step 2: Run homepage tests and verify RED**

Run: `php artisan test tests/Feature/CatalogVisualSystemTest.php tests/Feature/CatalogPageTest.php --filter=home`

Expected: failures because the current page renders `$featuredTitles` in a five-column grid and retains the duplicate date feed.

- [x] **Step 3: Implement the homepage divided lists**

Render `latestByDate` inside «Последние обновления»:

```blade
<div data-home-latest-updates-list class="divide-y divide-slate-200">
    @forelse ($latestByDate as $date => $titlesForDate)
        <div class="bg-slate-50 px-4 py-2 text-sm font-bold text-slate-600">{{ $date }}</div>
        @foreach ($titlesForDate as $catalogTitle)
            <x-catalog.title-card :title="$catalogTitle" layout="list" :show-description="false" />
        @endforeach
    @empty
        <div class="p-6 text-sm text-slate-500">Сериалы пока не добавлены.</div>
    @endforelse
</div>
```

Convert `latestMedia` and `videoTitles` wrappers to one-column `divide-y`, make latest-media use `layout="list"`, and remove the duplicate date-feed panel.

- [x] **Step 4: Run homepage tests and verify GREEN**

Run: `php artisan test tests/Feature/CatalogVisualSystemTest.php tests/Feature/CatalogPageTest.php --filter=home`

Expected: selected tests pass and the homepage has no content grid marker.

- [ ] **Step 5: Commit the homepage cycle**

```bash
git add resources/views/catalog/index.blade.php resources/views/components/catalog/latest-media-card.blade.php tests/Feature/CatalogVisualSystemTest.php tests/Feature/CatalogPageTest.php
git commit -m "feat: restore homepage update lists"
```

### Task 3: Remove `/titles` grid state and render only list rows

**Files:**
- Modify: `tests/Unit/CatalogTitlesRequestTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `tests/Feature/CatalogVisualSystemTest.php`
- Modify: `tests/browser/catalog.spec.js`
- Modify: `app/Http/Requests/CatalogTitlesRequest.php`
- Modify: `app/Http/Requests/Api/V1/CatalogTitleIndexRequest.php`
- Modify: `app/Livewire/Forms/CatalogSeriesFilters.php`
- Modify: `app/Livewire/CatalogSeries.php`
- Modify: `app/Services/Catalog/CatalogTitlesCriteria.php`
- Modify: `app/Services/Catalog/CatalogTitlesPageBuilder.php`
- Modify: `app/Services/Catalog/CatalogSeoBuilder.php`
- Modify: `app/View/ViewModels/CatalogTitlesViewModel.php`
- Modify: `resources/views/catalog/titles.blade.php`
- Modify: `lang/ru/catalog.php`
- Modify: `lang/en/catalog.php`
- Modify: `app/Services/Catalog/Api/V1/CatalogTitleIndexQuery.php`

**Interfaces:**
- Consumes: all existing search/filter/sort/page-size/alphabet URL state except `view`.
- Produces: a fixed list result shell and query builders that drop legacy `view` without changing other filters or API resources.

- [x] **Step 1: Write failing request, Livewire, and render tests**

Update tests to assert:

```php
$request = CatalogTitlesRequest::create('/titles', 'GET', ['view' => 'grid', 'sort' => 'title_asc']);
$request->setContainer(app())->setRedirector(app('redirect'));
$request->validateResolved();
$this->assertArrayNotHasKey('view', $request->catalogQueryState());

Livewire::withQueryParams(['view' => 'grid'])
    ->test(CatalogSeries::class)
    ->assertSeeHtml('data-catalog-results-list')
    ->assertDontSeeHtml('data-catalog-view-option')
    ->assertDontSeeHtml('setView');
```

Assert `/titles` always contains descriptions, `data-ui-poster-layout="list"`, `object-contain`, and no result `grid-cols-*` classes. Remove obsolete tests of `filters.view` and `setView`.

- [x] **Step 2: Run catalog state tests and verify RED**

Run: `php artisan test tests/Unit/CatalogTitlesRequestTest.php tests/Feature/CatalogPageTest.php tests/Feature/CatalogVisualSystemTest.php --filter='catalog|titles|view'`

Expected: failures because request, criteria, form, Livewire, view model, and Blade still carry `view`.

- [x] **Step 3: Remove `view` from web state and projection logic**

Remove the `view` rule/accessor/query key, `CatalogSeriesFilters::$view`, `CatalogSeries::setView()`, `CatalogTitlesCriteria::$view`, `CatalogTitlesViewModel::$view` and `viewQuery()`, language labels, and all query-builder propagation.

Always select `description` and eager-load `latestSeason` for web list rows. Preserve the API's constant query behavior with an explicit `includeLatestSeason: false` named argument in `CatalogTitleIndexQuery`; retain `includeDescription: true` for JSON serialization.

Replace the conditional results block with:

```blade
<div data-catalog-results-list class="divide-y divide-slate-200 overflow-hidden rounded-panel border border-slate-200 bg-white">
    @foreach ($titles as $catalogTitle)
        <x-catalog.title-card wire:key="catalog-title-{{ $catalogTitle->id }}" :title="$catalogTitle" layout="list" readable />
    @endforeach
</div>
```

Keep the current query-aware `<x-ui.panel class="col-span-full border-dashed">` empty-state branch and its three reset actions unchanged, placing the new list wrapper only around the non-empty rows.

Remove `setView` from loading targets and remove desktop/mobile view controls. Update Playwright touch-target selectors to exclude `[data-catalog-view-option]` and assert only list cards exist.

- [x] **Step 4: Run catalog state tests and verify GREEN**

Run: `php artisan test tests/Unit/CatalogTitlesRequestTest.php tests/Feature/CatalogPageTest.php tests/Feature/CatalogVisualSystemTest.php`

Expected: all selected tests pass; legacy `view` input is ignored and never propagated.

- [x] **Step 5: Run API catalog regression tests**

Run: `php artisan test tests/Feature/Api/V1/CatalogDiscoveryTest.php tests/Feature/Api/V1/CatalogTitleIndexTest.php`

Expected: API response shape and bounded query tests pass.

- [ ] **Step 6: Commit the catalog state cycle**

```bash
git add app/Http/Requests app/Livewire app/Services/Catalog app/View/ViewModels resources/views/catalog/titles.blade.php lang tests/Unit/CatalogTitlesRequestTest.php tests/Feature/CatalogPageTest.php tests/Feature/CatalogVisualSystemTest.php tests/browser/catalog.spec.js
git commit -m "refactor: make catalog results list only"
```

### Task 4: Convert directory hubs to divided lists

**Files:**
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `tests/Feature/CatalogVisualSystemTest.php`
- Modify: `resources/views/livewire/catalog-directory-browser.blade.php`
- Modify: `resources/views/components/catalog/directory-card.blade.php`

**Interfaces:**
- Consumes: existing directory item `name`, `detail_url`, `published_titles_count`, `year`, and stable `item_key`.
- Produces: `data-directory-results-list` with one row per value and unchanged Livewire URL/filter/pagination behavior.

- [x] **Step 1: Write a failing directory list test**

Assert a populated `/genres` response contains `data-directory-results-list`, `divide-y`, the value/count link, and no result wrapper `sm:grid-cols-2`, `md:grid-cols-3`, `lg:grid-cols-4`, or `2xl:grid-cols-6`.

- [x] **Step 2: Run the directory tests and verify RED**

Run: `php artisan test tests/Feature/CatalogPageTest.php tests/Feature/CatalogVisualSystemTest.php --filter=directory`

Expected: failure because year and non-year results use multi-column grids.

- [x] **Step 3: Implement list wrappers and rows**

Use one bordered `divide-y` wrapper for ordinary results and one per decade for years. Change `directory-card` to a full-width flex row with a minimum 44px target, complete wrapping name, count/upcoming metadata, and trailing arrow; remove card shadow and fixed `min-h-28`.

- [x] **Step 4: Run directory tests and verify GREEN**

Run: `php artisan test tests/Feature/CatalogPageTest.php tests/Feature/CatalogVisualSystemTest.php --filter=directory`

Expected: directory tests pass with stable keys, search, alphabet, decade, and pagination behavior unchanged.

- [ ] **Step 5: Commit the directory cycle**

```bash
git add resources/views/livewire/catalog-directory-browser.blade.php resources/views/components/catalog/directory-card.blade.php tests/Feature/CatalogPageTest.php tests/Feature/CatalogVisualSystemTest.php
git commit -m "refactor: render catalog directories as lists"
```

### Task 5: Convert personal collections and recommendations to uncropped lists

**Files:**
- Modify: `tests/Feature/Web/UserLibraryPageTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `tests/Feature/CatalogRecommendationListTest.php`
- Modify: `resources/views/livewire/library/user-library-page.blade.php`
- Modify: `resources/views/livewire/viewing-activity.blade.php`
- Modify: `resources/views/components/catalog/title-card-recommendation.blade.php`

**Interfaces:**
- Consumes: existing owner-scoped paginators, watchlist/rating actions, progress rows, recommendation ranks and reasons.
- Produces: vertical divided lists with unchanged policies/actions and contained `2:3` posters.

- [x] **Step 1: Write failing library/viewing/recommendation layout tests**

Assert authenticated watchlist, ratings, Continue Watching, history, and `/watching` output have list markers/dividers and no content wrapper `md:grid-cols-2` or `xl:grid-cols-3`. Change recommendation assertions from `aspect-[16/10]` to `aspect-[2/3]`, `object-contain`, and no overscan.

- [x] **Step 2: Run the focused tests and verify RED**

Run: `php artisan test tests/Feature/Web/UserLibraryPageTest.php tests/Feature/CatalogRecommendationListTest.php tests/Feature/CatalogPageTest.php --filter='library|watching|history|recommendation'`

Expected: failures on current multi-column wrappers and wide recommendation crop.

- [x] **Step 3: Implement personal and recommendation lists**

Replace watchlist/ratings/continue wrappers with `divide-y divide-slate-200`; render title cards with `layout="list"`; keep mutation controls in the same divided item but outside the title stretched-link boundary. Replace history `space-y` wrappers with `divide-y`. Apply the same structure in legacy `viewing-activity`.

Keep the existing ordered recommendation `<ol>`, rank, reasons, description, fallback, and stable keys; only change its poster to the shared portrait recommendation row.

- [x] **Step 4: Run the focused tests and verify GREEN**

Run: `php artisan test tests/Feature/Web/UserLibraryPageTest.php tests/Feature/CatalogRecommendationListTest.php tests/Feature/CatalogPageTest.php --filter='library|watching|history|recommendation'`

Expected: selected tests pass; owner actions and recommendation order remain correct.

- [ ] **Step 5: Commit the personal collection cycle**

```bash
git add resources/views/livewire/library/user-library-page.blade.php resources/views/livewire/viewing-activity.blade.php resources/views/components/catalog/title-card-recommendation.blade.php tests/Feature/Web/UserLibraryPageTest.php tests/Feature/CatalogRecommendationListTest.php tests/Feature/CatalogPageTest.php
git commit -m "refactor: unify portal content lists"
```

### Task 6: Update project contracts and verify the full interface

**Files:**
- Modify: `docs/UI_STANDARDS.md`
- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: any focused test file whose exact historical assertion is superseded by the approved list-only spec.
- Create/update artifacts only under: `output/playwright/`

**Interfaces:**
- Consumes: completed list-only implementation and existing Playwright/npm tooling.
- Produces: authoritative docs, formatted PHP, passing test/build suites, and responsive screenshots/reports.

- [x] **Step 1: Update authoritative documentation**

Replace grid/view/wide-crop rules with the approved contracts: one content list, `2:3` contain posters, no `view` URL state, directory/personal lists, and structural-grid exemptions. Do not edit text between `project-docs:start` and `project-docs:end` manually.

- [x] **Step 2: Run formatter and focused suites**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Unit/BladeTemplateTest.php
php artisan test tests/Unit/CatalogTitlesRequestTest.php
php artisan test tests/Feature/CatalogVisualSystemTest.php
php artisan test tests/Feature/CatalogPageTest.php
php artisan test tests/Feature/CatalogRecommendationListTest.php
php artisan test tests/Feature/Web/UserLibraryPageTest.php
```

Expected: every command exits 0 with no failures.

- [x] **Step 3: Run the full backend and frontend verification**

Run:

```bash
php artisan test
npm run build
git diff --check
```

Expected: all tests pass, Vite build exits 0, and diff check is empty.

- [x] **Step 4: Run responsive Playwright QA**

Inspect `php artisan route:list`, confirm `npx` exists, start a local server on a free port, and audit `/`, `/titles`, `/genres`, one `/titles/{slug}`, and authenticated library routes when a safe test session is available at:

```text
390×844
768×1024
1440×1200
```

For every route collect HTTP status, final URL, `h1`, panel headings, poster bounding box and computed `object-fit`, horizontal overflow, console/page errors, failed local assets, and screenshot. Store output only in `output/playwright/`.

- [ ] **Step 5: Review requirements and commit final docs/verification adjustments**

Check every requirement in `docs/superpowers/specs/2026-07-15-list-only-catalog-design.md` against the final diff and fresh command output, then commit remaining versioned changes:

```bash
git add docs tests app resources lang
git commit -m "docs: record list-only catalog contract"
```

- [ ] **Step 6: Confirm clean main worktree**

Run: `git status --short --branch`

Expected: `main` with no modified/untracked versioned files; only ignored Playwright artifacts may exist.
