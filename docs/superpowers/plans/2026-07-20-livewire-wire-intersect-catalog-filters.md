# Accessible Livewire `wire:intersect` Catalog Filters Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve the catalog's one-time lazy filter-island request while exposing its loading placeholder as an accessible status.

**Architecture:** Livewire 4.3.3 remains the sole viewport observer owner through `@island(..., lazy: true)`, which generates `wire:intersect.once="__lazyLoadIsland"`. Blade adds only ARIA semantics to the existing placeholder; no component action, JavaScript observer, query, cache or route changes.

**Tech Stack:** PHP 8.5, Laravel 13.20, Livewire 4.3.3, PHPUnit 12.5, Blade, Tailwind CSS 4.3, Vite 8.

## Global Constraints

- Work only on existing `main`; do not create a branch or worktree.
- Do not add dependencies, migrations, config keys, public actions, cache identities, queries, user-facing strings, inline CSS or business JavaScript.
- Preserve server-rendered catalog results, named pagination, canonical routes, SEO and browser history.
- Keep Livewire as the sole observer owner; do not author `IntersectionObserver`, `x-intersect` or a direct call to the internal `__lazyLoadIsland` method.
- Modify files only with `apply_patch`; preserve unrelated staged and unstaged changes.

---

### Task 1: Prove the missing accessible viewport-loading status

**Files:**
- Modify: `tests/Feature/CatalogVisualSystemTest.php`

**Interfaces:**
- Consumes: public `titles.index` response and Livewire's generated lazy-island markup.
- Produces: a route-level contract for exactly one `.once` trigger and one accessible busy filter placeholder.

- [x] **Step 1: Add the failing test**

Add this focused method to `CatalogVisualSystemTest`:

```php
public function test_catalog_lazy_filter_island_announces_one_time_viewport_loading(): void
{
    $content = $this->get(route('titles.index'))->assertOk()->getContent();

    $this->assertSame(1, substr_count($content, 'wire:intersect.once="__lazyLoadIsland"'));
    $this->assertMatchesRegularExpression(
        '/<div(?=[^>]*wire:intersect\.once="__lazyLoadIsland")(?=[^>]*id="catalog-filters")(?=[^>]*aria-busy="true")[^>]*>/s',
        $content,
    );
    $this->assertStringContainsString(
        'data-catalog-facets-loading role="status" aria-live="polite"',
        $content,
    );
}
```

- [x] **Step 2: Run the focused test and verify RED**

Run: `php artisan test tests/Feature/CatalogVisualSystemTest.php --filter=test_catalog_lazy_filter_island_announces_one_time_viewport_loading`

Expected: one assertion fails because the loading element has `aria-live="polite"` but no `role="status"`; the route and generated `.once` assertion pass.

### Task 2: Add the minimal placeholder semantics

**Files:**
- Modify: `resources/views/catalog/titles.blade.php`
- Test: `tests/Feature/CatalogVisualSystemTest.php`

**Interfaces:**
- Consumes: existing `@island(name: 'catalog-live', lazy: true)` placeholder and translation `catalog.catalog.filters.loading`.
- Produces: `data-catalog-facets-loading role="status" aria-live="polite"` without any new Livewire action or state.

- [x] **Step 1: Implement the minimal Blade change**

Change the placeholder loading element to:

```blade
<div data-catalog-facets-loading role="status" aria-live="polite" class="flex min-h-24 items-center justify-center gap-2 rounded-control bg-white px-4 py-5 text-sm font-bold text-slate-600">
```

- [x] **Step 2: Run the focused test and verify GREEN**

Run: `php artisan test tests/Feature/CatalogVisualSystemTest.php --filter=test_catalog_lazy_filter_island_announces_one_time_viewport_loading`

Expected: 1 test passes with all assertions.

- [x] **Step 3: Run the neighboring catalog contract tests**

Run: `php artisan test tests/Feature/CatalogVisualSystemTest.php --filter='catalog_search_ui_automatically_loads_unified_filter_island|catalog_lazy_filter_island_announces_one_time_viewport_loading'`

Expected: both tests pass; the initial response remains bounded and the deferred fragment still exposes the filter controls.

### Task 3: Record owners and verify the integrated result

**Files:**
- Modify: `docs/architecture.md`
- Modify: `docs/frontend.md`
- Modify: `docs/catalog-search.md`
- Modify: `docs/views.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`

**Interfaces:**
- Consumes: verified behavior from Tasks 1–2 and official Livewire 4 `wire:intersect` guidance.
- Produces: canonical directive ownership, visitor history, technical changelog and final compliance evidence.

- [x] **Step 1: Update canonical owner documentation**

Document that the catalog lazy island owns the sole application of `wire:intersect.once`, keeps results in SSR, announces its busy placeholder, and intentionally retains normal pagination. Record that direct internal-action calls, manual observers and infinite scroll are outside this boundary.

- [x] **Step 2: Update README and CHANGELOG**

Add one Russian visitor-facing README bullet under 20.07.2026 describing accessible automatic filter loading, and a separate Russian technical CHANGELOG bullet naming `wire:intersect.once`, lazy island and the regression test. Do not rewrite or merge previous entries.

- [x] **Step 3: Run task-scoped verification**

Run the focused and full `CatalogVisualSystemTest`, related catalog page tests, `npm run build`, `php artisan project:docs-refresh --check` and task-scoped `git diff --check`. Pint is not required because no PHP production syntax changes; inspect the PHP test formatting manually and use Pint only if the modified test is reported.

- [x] **Step 4: Scan for regressions and duplicates**

Search application Blade/JS/PHP for `wire:intersect`, `IntersectionObserver`, `x-intersect`, `__lazyLoadIsland`, competing `loadMore` actions, stale filter placeholder copy and removed pagination links. Verify no secrets, provider URLs or client-trusted access state entered the diff.

- [x] **Step 5: Run the full suite and finalize compliance**

Run: `php artisan test --compact`

Expected: record exact output and distinguish task failures from concurrent shared-snapshot failures. Re-read applicable requirements and owner docs, update every matrix status honestly, verify README placement, then commit/push only if the existing `main` index permits task-only staging without capturing unrelated work.

## Self-review

- Spec coverage: official modifiers, observer ownership, ARIA semantics, SSR/pagination/SEO, rollback and every cross-feature domain are covered by Tasks 1–3.
- Placeholder scan: no `TBD`, `TODO`, deferred code path or unspecified behavior remains.
- Type consistency: no new PHP API exists; the exact Blade attributes and test selectors match across tasks.
