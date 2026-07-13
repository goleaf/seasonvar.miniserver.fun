# Catalog Filter Islands Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Automatically load the full catalog filter panel and update selected filters, contextual counts, result cards, and URLs live through linked Livewire 4 islands without restoring heavy facet SQL to the first SSR.

**Architecture:** Keep `CatalogSeries` as the single state owner. Split result and facet data boundaries in `CatalogTitlesPageBuilder`, expose each boundary as a request-cached Livewire computed property, and render a deferred filter island plus an eager result island under the same name so Livewire updates them atomically. Move the two island bodies into explicit class-backed Blade components so each isolated render receives only its computed render-local data and the deferred placeholder evaluates no facet queries.

**Tech Stack:** PHP 8.5, Laravel 13.19, Livewire 4.3.3 Islands, SQLite FTS5/JSON1, Blade, Tailwind CSS 4.3, PHPUnit 12.5, Playwright CLI.

## Global Constraints

- Work only on the existing `main` branch; do not create branches or worktrees.
- Keep SQLite and install no production dependency.
- The first SSR must continue to render result cards without executing contextual facet queries.
- Desktop filters are always visible; mobile filters remain in the existing native dialog and load automatically.
- Search text remains submit-driven; checkbox/select filters update immediately through Livewire.
- User-visible copy remains Russian and Blade performs no database query or inline PHP.
- Preserve FTS-first ranking, two-phase card hydration, one materialized FTS match set per facet load, `noindex,nofollow`, and nofollow UI-state links.

---

### Task 1: Separate result and facet query boundaries

**Files:**
- Modify: `app/Services/Catalog/CatalogTitlesPageBuilder.php`
- Test: `tests/Feature/CatalogAdvancedFilterTest.php`
- Test: `tests/Feature/CatalogSearchQueryPlanTest.php`

**Interfaces:**
- Consumes: `CatalogTitlesRequest`, route taxonomy state, validation state, actor/director facet searches.
- Produces: backward-compatible `data(..., bool $includeFacets = true) : array` and new `facets(...) : array`; the Livewire result computed explicitly passes `includeFacets: false`.

- [ ] **Step 1: Write failing result/facet boundary tests**

Add query-log assertions that `data()` executes no `context_titles_count`/facet UNION SQL, while `facets()` returns the selected country with its contextual count and executes one `CatalogTitleSearch::materializeMatches()` MATCH query for ready FTS.

```php
$page = $builder->data($request, includeFacets: false);
$this->assertTrue($page['filterTaxonomies']->flatten()->isEmpty());

$facets = $builder->facets($request);
$this->assertTrue($facets['filterView']->isActiveTaxonomy('country', $country));
$this->assertSame(1, $facets['filterTaxonomies']->get('country')->firstWhere('slug', $country->slug)->context_titles_count);
```

- [ ] **Step 2: Run focused tests and verify RED**

Run:

```bash
php artisan test tests/Feature/CatalogAdvancedFilterTest.php tests/Feature/CatalogSearchQueryPlanTest.php
```

Expected: failure because `facets()` does not exist and the new boundary assertions are not implemented.

- [ ] **Step 3: Extract a shared normalized context and implement both boundaries**

Inside `CatalogTitlesPageBuilder`, move request normalization/taxonomy resolution into a private `context()` method. Keep result pagination, SEO, suggestions, and `CatalogTitlesViewModel` in `data()`. Move only the current `if ($includeFacets)` body into:

```php
/** @return array<string, mixed> */
public function facets(
    CatalogTitlesRequest $request,
    ?string $type = null,
    ?string $taxonomy = null,
    bool $invalidInput = false,
    array $facetSearch = [],
): array {
    $context = $this->context($request, $type, $taxonomy, $invalidInput);
    return $this->buildFacetData(
        context: $context,
        facetSearch: $facetSearch,
        searchMatches: $this->titleSearch->materializeMatches($context['searchQuery']),
    );
}
```

Define `buildFacetData(array $context, array $facetSearch, ?CatalogSearchMatchSet $searchMatches): array` by moving the existing taxonomy-group, missing-selected-count, year, publication-type, and subtitle queries into that method without changing their query calls. Its exact return shape is `filterView`, `filterTypes`, `selectedTaxonomies`, `filterTaxonomies`, `yearBuckets`, `publicationTypeOptions`, and `subtitleOptions`.

Keep `$includeFacets` on `data()` for existing direct callers. Replace its inline facet implementation with `array_replace($resultData, $this->facets(...))` when true; when false, retain empty facet collections for view-shape compatibility but remove the obsolete `facetsLoaded` key. Do not alter ranked-ID/card hydration.

- [ ] **Step 4: Run focused tests and verify GREEN**

Run the command from Step 2. Expected: all tests pass and query assertions retain one filter-only FTS materialization for facets.

- [ ] **Step 5: Commit the boundary**

```bash
git add app/Services/Catalog/CatalogTitlesPageBuilder.php tests/Feature/CatalogAdvancedFilterTest.php tests/Feature/CatalogSearchQueryPlanTest.php
git commit -m "refactor: separate catalog results and facets"
```

---

### Task 2: Add deferred linked islands and automatic live filters

**Files:**
- Create: `app/View/Components/Catalog/TitleFilters.php`
- Create: `app/View/Components/Catalog/TitleResults.php`
- Create: `resources/views/components/catalog/title-filters.blade.php`
- Create: `resources/views/components/catalog/title-results.blade.php`
- Modify: `app/Livewire/CatalogSeries.php`
- Modify: `resources/views/catalog/titles.blade.php`
- Test: `tests/Feature/CatalogPageTest.php`
- Test: `tests/Feature/CatalogVisualSystemTest.php`

**Interfaces:**
- Consumes: `CatalogTitlesPageBuilder::data()` and `CatalogTitlesPageBuilder::facets()`.
- Produces: computed `$this->catalogPage`, computed `$this->catalogFacets`, and two top-level islands named `catalog-live`.

- [ ] **Step 1: Replace lazy-button tests with failing islands/live-state tests**

Assert that the root HTML contains a deferred island placeholder and eager result cards, contains no «Показать фильтры», and the extracted filter component uses live models:

```php
$content = $this->get(route('titles.index'))->assertOk()->getContent();

$this->assertStringContainsString('wire:init="__lazyLoadIsland"', $content);
$this->assertStringContainsString('Загружаем фильтры', $content);
$this->assertStringNotContainsString('Показать фильтры', $content);
$this->assertStringContainsString('wire:model.live="filters.country"', $filterTemplate);
```

Replace the old `facetsLoaded` Livewire test with a selected-country regression that sets `filters.country`, asserts page reset, state retention, and only the matching title remains in computed result data.

- [ ] **Step 2: Run focused tests and verify RED**

```bash
php artisan test tests/Feature/CatalogPageTest.php tests/Feature/CatalogVisualSystemTest.php
```

Expected: failures on the removed button/state and missing island markup.

- [ ] **Step 3: Expose request-cached computed data**

Remove `$facetsLoaded`, `loadFacets()`, and all resets of that property. Add:

```php
use Livewire\Attributes\Computed;

#[Computed]
public function catalogPage(): array
{
    return $this->pages->data(
        $this->catalogRequest($this->renderInput()),
        $this->routeFilterType,
        $this->routeTaxonomy,
        $this->getErrorBag()->isNotEmpty(),
        includeFacets: false,
    );
}

#[Computed]
public function catalogFacets(): array
{
    return $this->pages->facets(
        $this->catalogRequest($this->renderInput()),
        $this->routeFilterType,
        $this->routeTaxonomy,
        $this->getErrorBag()->isNotEmpty(),
        $this->facetSearch(),
    );
}
```

Make `render()` use `$this->catalogPage`. Keep `filters.search` submit-driven. Existing `updated()` normalization and `resetPage()` remain the state boundary for live checkbox/select changes.

- [ ] **Step 4: Extract both island views through explicit class components**

Implement the component without queries:

```php
final class TitleFilters extends Component
{
    public function __construct(
        public readonly array $data,
        public readonly array $optionSearch,
    ) {}

    public function render(): View
    {
        return view('components.catalog.title-filters', [
            ...$this->data,
            'optionSearch' => $this->optionSearch,
        ]);
    }
}
```

Implement `TitleResults` with the same constructor/render pattern but only the `array $data` argument. Move the existing GET filter form into `title-filters.blade.php` and the complete result toolbar/cards/empty-state/paginator `<div class="order-1 …">` into `title-results.blade.php`. Change facet checkbox/select bindings to `wire:model.live`. Remove the JavaScript apply button and retain an ordinary Russian submit button only inside `<noscript>`.

- [ ] **Step 5: Wrap the two top-level regions in linked islands**

Use an automatic placeholder that evaluates no facet computed data:

```blade
@island(name: 'catalog-live', defer: true)
    @placeholder
        <div data-catalog-facets-loading aria-live="polite">Загружаем фильтры…</div>
    @endplaceholder

    <x-catalog.title-filters :data="$this->catalogFacets" :option-search="$this->optionSearch" />
@endisland

@island(name: 'catalog-live')
    <x-catalog.title-results :data="$this->catalogPage" />
@endisland
```

Remove `wire:click="loadFacets"` from the mobile dialog trigger. Add scoped `wire:loading` feedback for `filters.years`, taxonomy filters, publication types, subtitles, and resets.

- [ ] **Step 6: Run focused tests and verify GREEN**

Run the command from Step 2. Expected: all catalog component/UI tests pass.

- [ ] **Step 7: Commit islands**

```bash
git add app/Livewire/CatalogSeries.php app/View/Components/Catalog/TitleFilters.php app/View/Components/Catalog/TitleResults.php resources/views/catalog/titles.blade.php resources/views/components/catalog/title-filters.blade.php resources/views/components/catalog/title-results.blade.php tests/Feature/CatalogPageTest.php tests/Feature/CatalogVisualSystemTest.php
git commit -m "feat: update catalog filters through live islands"
```

---

### Task 3: Update project contracts and production verification

**Files:**
- Modify: `docs/catalog-search.md`
- Modify: `docs/frontend.md`
- Modify: `docs/views.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/MAINTENANCE_LOG.md`

**Interfaces:**
- Consumes: completed result/facet boundaries and linked islands.
- Produces: current documentation and production QA evidence.

- [ ] **Step 1: Update documentation**

Replace the manual `loadFacets()` contract with automatic `@island(defer: true)`, linked `catalog-live` updates, live selected-state behavior, GET/noscript fallback, and the reason this exact island use is permitted. Record the change in the single changelog and maintenance log.

- [ ] **Step 2: Format and run focused verification**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/CatalogAdvancedFilterTest.php tests/Feature/CatalogSearchQueryPlanTest.php tests/Feature/CatalogPageTest.php tests/Feature/CatalogVisualSystemTest.php
php artisan project:docs-refresh --check
npm run build
```

Expected: every command exits 0.

- [ ] **Step 3: Run full tests**

```bash
php artisan test
```

Expected: zero failures.

- [ ] **Step 4: Verify real browser behavior**

At 390×844, 768×1024, and 1440×1200:

1. Open `/titles` and confirm cards render before the automatic facet request completes.
2. Confirm «Показать фильтры» never appears and the country options arrive without a click.
3. Select one country and verify its checkbox stays checked, URL gains `country`, result count/cards change without navigation, and contextual counts refresh.
4. Clear the country and verify URL/results restore.
5. Confirm `document.documentElement.scrollWidth === innerWidth` and browser console has zero errors.

- [ ] **Step 5: Commit documentation and any verified corrections**

```bash
git add CHANGELOG.md docs/MAINTENANCE_LOG.md docs/catalog-search.md docs/frontend.md docs/views.md
git commit -m "docs: record automatic catalog filter islands"
```

- [ ] **Step 6: Final repository check**

```bash
git status --short --branch
git diff --check HEAD~3..HEAD
```

Expected: branch `main`, clean working tree, no whitespace errors.
