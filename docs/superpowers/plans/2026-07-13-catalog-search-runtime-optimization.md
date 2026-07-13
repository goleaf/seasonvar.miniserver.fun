# Catalog Search Runtime Optimization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `/titles` fast under real crawler and Livewire load by combining Redis-backed crawl limits, FTS-first two-phase pagination, lazy facet loading, reusable FTS match materialization and safe SQLite runtime maintenance.

**Architecture:** Stable catalog URLs remain indexable, while interactive URL state is `nofollow` and rate-limited. Ready FTS searches page candidate IDs from an FTS-rooted `CROSS JOIN`, then hydrate only current-page cards. Facets are loaded by an explicit Livewire action and reuse one application-materialized FTS ID set through SQLite `json_each(?)`.

**Tech Stack:** PHP 8.5, Laravel 13.19, Livewire 4.3, SQLite 3.46 FTS5/JSON1, Redis limiter, PHPUnit 12.5, Blade, Tailwind CSS 4.3, Playwright.

## Global Constraints

- Work only on the existing `main` branch; do not create branches or worktrees.
- Preserve pre-existing user changes and do not bypass the Seasonvar Git guard.
- Keep SQLite as the only database and add no Composer/npm production dependency.
- Keep FTS expressions parameterized and expose no raw importer/search state.
- Keep visible UI copy in Russian and queries out of Blade.
- Do not clear queues, caches or production data.
- Use PHPUnit, run focused tests before broad tests, run Pint after PHP edits and `npm run build` after Blade/Tailwind edits.

---

### Task 1: Crawl policy and Redis-backed query limiter

**Files:**
- Modify: `tests/Feature/SitemapAndRobotsTest.php`
- Modify: `tests/Feature/CatalogSearchPageTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `public/robots.txt`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `routes/web.php`
- Modify: `config/catalog.php`
- Modify: `.env.example`

**Interfaces:**
- Produces named limiter `catalog-query` and route middleware `throttle:catalog-query`.
- Produces `catalog.query_rate_limit.human_per_minute` and `catalog.query_rate_limit.bot_per_minute` integer config.

- [ ] **Step 1: Add failing crawler/limiter tests**

Assert that `public/robots.txt` contains `User-agent: ClaudeBot`, `Disallow: /titles?`, and `Crawl-delay`. Assert that the `/titles` route contains the named `throttle:catalog-query` middleware while the framework limiter continues to use `config('cache.limiter') = redis-limiter`. Configure the bot budget to `1`, send two query GETs with the same ClaudeBot/IP and assert `200`, then `429`; assert canonical `/titles` remains available.

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/SitemapAndRobotsTest.php tests/Feature/CatalogSearchPageTest.php --filter='robots|limiter'`

Expected: FAIL because crawler directives and named middleware do not exist.

- [ ] **Step 3: Implement minimal limiter and robots policy**

Do not enable `throttleWithRedis()`: this project intentionally keeps standard `ThrottleRequests` backed by the dedicated `cache.limiter` Redis store/connection. In `AppServiceProvider`, define:

```php
RateLimiter::for('catalog-query', function (Request $request): Limit {
    $isBot = preg_match('/(?:bot|crawler|spider|slurp)/i', (string) $request->userAgent()) === 1;
    $budget = (int) config($isBot
        ? 'catalog.query_rate_limit.bot_per_minute'
        : 'catalog.query_rate_limit.human_per_minute');

    return Limit::perMinute(max(1, $budget))
        ->by(($request->user()?->getAuthIdentifier() ?? $request->ip()).'|'.($isBot ? 'bot' : 'human'));
});
```

Attach `throttle:catalog-query` to all `CatalogSeries` routes. Add conservative defaults to config and `.env.example`. Add ClaudeBot/query rules without removing Host/Sitemap declarations.

- [ ] **Step 4: Run GREEN**

Run: `php artisan test tests/Feature/SitemapAndRobotsTest.php tests/Feature/CatalogSearchPageTest.php --filter='robots|limiter'`

Expected: PASS.

---

### Task 2: SEO nofollow boundary and state-link markup

**Files:**
- Modify: `tests/Feature/CatalogSearchPageTest.php`
- Modify: `tests/Feature/CatalogPageTest.php`
- Modify: `app/Services/Catalog/CatalogSeoBuilder.php`
- Modify: `resources/views/catalog/titles.blade.php`

**Interfaces:**
- Interactive catalog/directory metadata returns `noindex,nofollow,max-image-preview:large,max-snippet:-1,max-video-preview:-1`.
- Query-state links include `rel="nofollow"`; card and directory links do not.

- [ ] **Step 1: Add failing SEO/markup tests**

Add assertions for `noindex,nofollow` on `/titles?q=...` and directory interactive URLs. Assert sort/view/page-size/alphabet/filter removal links contain `rel="nofollow"`; assert title-card href remains free of forced nofollow.

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/CatalogSearchPageTest.php tests/Feature/CatalogPageTest.php --filter='nofollow|robots'`

Expected: FAIL on current `noindex,follow` and missing link attributes.

- [ ] **Step 3: Implement SEO and Blade boundary**

Change both complex branches in `CatalogSeoBuilder` to `noindex,nofollow,...`. Add literal `rel="nofollow"` only to links whose href is generated from `CatalogTitlesViewModel` state-query helpers or removes active query state.

- [ ] **Step 4: Run GREEN and build**

Run: `php artisan test tests/Feature/CatalogSearchPageTest.php tests/Feature/CatalogPageTest.php --filter='nofollow|robots'`

Run: `npm run build`

Expected: tests and Vite build PASS.

---

### Task 3: FTS-rooted ranked query

**Files:**
- Modify: `tests/Feature/CatalogSearchQueryPlanTest.php`
- Modify: `app/Services/Catalog/CatalogTitleQuery.php`

**Interfaces:**
- `filteredTitles(..., bool $rankSearch = false, ?CatalogSearchMatchSet $searchMatches = null): Builder<CatalogTitle>`.
- Ready ranked builders use `FROM (<candidate query>) AS catalog_search_candidates CROSS JOIN catalog_titles` and `whereColumn` on the title primary key.

- [ ] **Step 1: Add failing EXPLAIN and SQL-shape tests**

Assert ranked SQL begins with the candidate subquery, contains `cross join "catalog_titles"`, and returns the same IDs as filter-only matching. Assert `EXPLAIN QUERY PLAN` scans/co-routines candidates before `SEARCH catalog_titles USING INTEGER PRIMARY KEY`.

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/CatalogSearchQueryPlanTest.php`

Expected: FAIL because the current builder starts from `catalog_titles` and `joinSub`.

- [ ] **Step 3: Refactor the query root**

Split visibility into a reusable constraint method and initialize the ranked builder as:

```php
$query = CatalogTitle::query()
    ->fromSub($rankedCandidates, $alias)
    ->crossJoin('catalog_titles')
    ->whereColumn('catalog_titles.id', $alias.'.catalog_title_id');
$this->constrainVisible($query, $user);
$this->rankedSearchAliases[spl_object_id($query)] = $alias;
```

Apply search only once, then apply the existing year, title-context, relation and advanced constraints unchanged.

- [ ] **Step 4: Run GREEN**

Run: `php artisan test tests/Feature/CatalogSearchQueryPlanTest.php tests/Feature/CatalogSearchAcceptanceTest.php tests/Feature/CatalogTitleSearchTest.php`

Expected: PASS with preserved ranking behavior.

---

### Task 4: Two-phase current-page card hydration

**Files:**
- Modify: `tests/Feature/CatalogSearchQueryPlanTest.php`
- Modify: `tests/Feature/CatalogSearchPageTest.php`
- Modify: `app/Services/Catalog/CatalogTitlesPageBuilder.php`

**Interfaces:**
- Ranked phase returns paginator IDs with existing total/appends.
- Hydration phase loads `CatalogTitle` cards for only those IDs, restores ID order and calls `setCollection()` on the paginator.

- [ ] **Step 1: Add failing query-log regression test**

Create more than one page of FTS matches, request 24 results, capture SQL, and assert the ranked page query has no `seasons_count`, `episodes_count` or `published_media_count` correlated subqueries. Assert the hydration query contains at most the 24 page IDs and response order matches ranked IDs.

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/CatalogSearchQueryPlanTest.php --filter='hydrates|card_counts'`

Expected: FAIL because current `withCount()` executes before pagination.

- [ ] **Step 3: Implement two-phase pagination**

For ready search only, build/sort a select-ID query, apply rating aggregates only when required for ID sorting, paginate with the precomputed total, then query cards via `whereKey($pageIds)`, existing relation loads and `publicCardCounts()`. Restore order using `$pageIds->flip()` and replace the paginator collection. Keep the current one-phase path for legacy/non-search queries.

- [ ] **Step 4: Run GREEN**

Run: `php artisan test tests/Feature/CatalogSearchQueryPlanTest.php tests/Feature/CatalogSearchPageTest.php tests/Feature/CatalogSearchAcceptanceTest.php`

Expected: PASS.

---

### Task 5: One reusable materialized FTS match set for facets

**Files:**
- Create: `app/Services/Catalog/Search/CatalogSearchMatchSet.php`
- Modify: `tests/Feature/CatalogTitleSearchTest.php`
- Modify: `tests/Feature/CatalogSearchQueryPlanTest.php`
- Modify: `app/Services/Catalog/Search/CatalogTitleSearch.php`
- Modify: `app/Services/Catalog/CatalogTitleQuery.php`
- Modify: `app/Services/Catalog/CatalogFacetQuery.php`

**Interfaces:**
- `CatalogTitleSearch::materializeMatches(CatalogSearchQuery $search): ?CatalogSearchMatchSet`.
- `CatalogSearchMatchSet::fromIds(iterable $ids): self`, `json(): string`, `isEmpty(): bool`.
- `filteredTitles(..., ?CatalogSearchMatchSet $searchMatches = null)` uses `json_each(?)` instead of another `MATCH`.

- [ ] **Step 1: Add failing value-object and query tests**

Assert duplicate/non-integer IDs normalize to unique positive integers, empty sets produce `1 = 0`, and reused facet SQL contains `json_each(?)` without `MATCH`. Count query log statements containing `MATCH` during a facet-enabled page build and require exactly one materialization query.

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/CatalogTitleSearchTest.php tests/Feature/CatalogSearchQueryPlanTest.php --filter='materialize|match_set'`

Expected: FAIL because the value object and method do not exist.

- [ ] **Step 3: Implement match materialization**

Materialize with the existing filter-only builder and `pluck('catalog_title_id')`. In `CatalogTitleQuery`, bind the JSON using:

```php
$query->whereIn('catalog_titles.id', function (QueryBuilder $query) use ($searchMatches): void {
    $query->selectRaw('CAST(value AS INTEGER)')
        ->fromRaw('json_each(?)', [$searchMatches->json()]);
});
```

Thread the optional match set through taxonomy groups, selected context counts, years, publication types and subtitle availability.

- [ ] **Step 4: Run GREEN**

Run: `php artisan test tests/Feature/CatalogTitleSearchTest.php tests/Feature/CatalogSearchQueryPlanTest.php tests/Feature/CatalogAdvancedFilterTest.php`

Expected: PASS.

---

### Task 6: Lazy Livewire facets and reduced initial payload

**Files:**
- Modify: `tests/Feature/CatalogSearchPageTest.php`
- Modify: `tests/Feature/CatalogVisualSystemTest.php`
- Modify: `app/Livewire/CatalogSeries.php`
- Modify: `app/Services/Catalog/CatalogTitlesPageBuilder.php`
- Modify: `resources/views/catalog/titles.blade.php`
- Modify: `lang/ru/catalog.php`
- Modify: `lang/en/catalog.php`

**Interfaces:**
- Public `bool $facetsLoaded = false`.
- Livewire action `loadFacets(): void` with existing catalog-search limiter enforcement.
- `CatalogTitlesPageBuilder::data(Request $request, bool $includeFacets = true): array`.
- View variable `facetsLoaded` and Russian load/retry/loading copy.

- [ ] **Step 1: Add failing Livewire/query-budget tests**

Assert initial Livewire render has no facet checkbox controls and no taxonomy UNION query. Call `loadFacets()` and assert controls/context counts appear. Call `applySearch()` and assert `facetsLoaded` becomes false again. Preserve tests that pass direct builder calls by keeping `includeFacets=true` as the default.

- [ ] **Step 2: Run RED**

Run: `php artisan test tests/Feature/CatalogSearchPageTest.php tests/Feature/CatalogVisualSystemTest.php --filter='facet|filter'`

Expected: FAIL because the component always builds/render facets.

- [ ] **Step 3: Implement lazy state and builder short path**

Pass `$this->facetsLoaded` only from `CatalogSeries::render()`. When false, return empty typed collections/options and skip all facet/count methods. `loadFacets()` validates/rate-limits then sets true. `applySearch()` sets false. Keep selected chips/removal controls outside the lazy options.

- [ ] **Step 4: Implement accessible loading UI**

On desktop show a compact button `Показать фильтры`. On mobile keep the existing dialog trigger, add `wire:click="loadFacets"`, a loading status, and placeholder content until the response arrives. Use existing components/classes and no new JavaScript dependency.

- [ ] **Step 5: Run GREEN and build**

Run: `php artisan test tests/Feature/CatalogSearchPageTest.php tests/Feature/CatalogVisualSystemTest.php tests/Feature/CatalogAdvancedFilterTest.php`

Run: `npm run build`

Expected: PASS.

---

### Task 7: Safe runtime maintenance and worker budget

**Files:**
- Modify: `deploy/systemd/seasonvar-import-worker@.service` only if its current dirty implementation requires a documented cap.
- Modify: `deploy/systemd/seasonvar-title-refresh-worker@.service` only if its current untracked implementation requires a documented cap.
- Modify: `docs/deployment.md`
- Modify: `docs/queues.md`

**Interfaces:**
- Four-CPU deployment keeps at most four import and eight title-refresh instances unless benchmarked otherwise.
- Cache compilation runs as `www` or finishes with runtime ownership repair.

- [ ] **Step 1: Inspect active jobs/process identity**

Read-only checks: active import runs, Redis queue sizes, `systemctl list-units`, target PID command line, disk free space and SQLite integrity/backup destination.

- [ ] **Step 2: Stop only confirmed runaway/over-budget processes**

If PID `2076080` still has the recorded actor-count sqlite command, terminate it gracefully and verify exit. Stop/disable only worker instances above the 4+8 budget after confirming no active write phase; never clear queues.

- [ ] **Step 3: Repair runtime ownership**

Run `chown -R www:www storage/framework/views bootstrap/cache`, verify writable files as `www`, and document the deployment boundary.

- [ ] **Step 4: Backup and maintain SQLite**

Create a timestamped online `.backup` with `sqlite3`, verify `PRAGMA quick_check`, execute `PRAGMA wal_checkpoint(PASSIVE)`, execute `PRAGMA optimize`, then verify quick check and database availability. Abort before PRAGMA writes if backup or active-write checks fail.

---

### Task 8: Documentation, formatting, regression and browser QA

**Files:**
- Modify: `docs/catalog-search.md`
- Modify: `docs/frontend.md`
- Modify: `docs/deployment.md`
- Modify: `docs/queues.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/MAINTENANCE_LOG.md`

- [ ] **Step 1: Update topic-owner documentation**

Document crawl boundaries, limiter budgets, two-phase FTS, lazy facets, worker caps, maintenance commands and measured before/after data. Do not manually edit managed `project-docs` blocks.

- [ ] **Step 2: Format PHP**

Run: `./vendor/bin/pint --dirty --format agent`

Expected: PASS.

- [ ] **Step 3: Run focused and full tests**

Run focused search/SEO/Livewire tests, then `php artisan test`.

Expected: zero failures.

- [ ] **Step 4: Run documentation/build checks**

Run: `php artisan project:docs-refresh --check`

Run: `npm run build`

Expected: PASS.

- [ ] **Step 5: Run production-like benchmark and Playwright QA**

Measure direct GET and Livewire search for `мама`, record SQL query count/time, HTML/payload bytes and EXPLAIN order. Test desktop/mobile opening filters, applying search and console/network errors using the project Playwright workflow.

- [ ] **Step 6: Commit on main if the Git guard is clean**

Run `git status --short --branch`, ensure `main`, stage only authorized work and commit. If pre-existing user changes still prevent the guard, report the exact blocker and do not bypass the hook.
