# Catalog Search Query Plan Optimization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the pathological SQLite FTS nested-loop plan with filter-only ID matching for aggregates and materialized BM25 ranking for result cards, while submitting Livewire search only on Enter or the search button.

**Architecture:** `CatalogTitleSearch` exposes separate filter-only and ranked query builders. `CatalogTitleQuery::filteredTitles()` selects the boundary through an explicit `rankSearch` flag: the main result list joins materialized ranked candidates, while counts and facets use a compact `WHERE IN (FTS rowid)` subquery. Existing publication scopes, legacy fallback, URL state, relevance order and FTS index lifecycle remain intact.

**Tech Stack:** PHP 8.5, Laravel 13.19, Livewire 4.3, SQLite 3.46 FTS5, PHPUnit 12.5, Laravel Pint 1.29, Blade, Tailwind CSS 4.3.

## Global Constraints

- Work only on the existing `main` branch; do not create a branch or worktree.
- Preserve all unrelated user changes and do not bypass the project Git guard.
- Keep SQLite as the only relational database and install no Composer/npm search dependency.
- Keep all FTS user input parameterized; never interpolate raw `q` into SQL.
- Keep visible UI text in Russian and keep database/cache/service calls out of Blade.
- Use PHPUnit classes with `RefreshDatabase`; Pest is not installed.
- Run focused tests before the broad suite, Pint after PHP changes and `npm run build` after the Blade change.

---

### Task 1: Split filter-only FTS matching from ranked candidates

**Files:**
- Modify: `tests/Feature/CatalogTitleSearchTest.php`
- Modify: `app/Services/Catalog/Search/CatalogTitleSearch.php`

**Interfaces:**
- Consumes: `CatalogSearchQuery::$ftsExpression`, `CatalogTitleSearch::isReady()`.
- Produces: `CatalogTitleSearch::matchingTitleIdsQuery(CatalogSearchQuery $search): ?Illuminate\Database\Query\Builder` and a materialization boundary on `candidateQuery()`.

- [ ] **Step 1: Write the failing filter-only and materialization tests**

Add this test to `CatalogTitleSearchTest`:

```php
public function test_filter_only_matching_avoids_rank_columns_and_ranked_candidates_keep_materialization_boundary(): void
{
    $title = CatalogTitle::factory()->create(['title' => 'План полнотекстового поиска']);
    app(CatalogSearchIndexer::class)->indexTitleIds([$title->id]);
    $this->markReady(1);
    $query = app(CatalogSearchQueryParser::class)->parse('полнотекстового поиска');
    $search = app(CatalogTitleSearch::class);

    $matching = $search->matchingTitleIdsQuery($query);
    $ranked = $search->candidateQuery($query);

    $this->assertNotNull($matching);
    $this->assertNotNull($ranked);
    $this->assertSame([$title->id], $matching->pluck('catalog_title_id')->all());

    $matchingSql = mb_strtolower($matching->toSql());
    $this->assertStringContainsString('catalog_title_search_fts.rowid as catalog_title_id', $matchingSql);
    $this->assertStringContainsString('catalog_title_search_fts match ?', $matchingSql);
    $this->assertStringNotContainsString('catalog_title_search_documents', $matchingSql);
    $this->assertStringNotContainsString('bm25', $matchingSql);
    $this->assertStringNotContainsString('order by', $matchingSql);

    $rankedSql = mb_strtolower($ranked->toSql());
    $this->assertStringContainsString('bm25', $rankedSql);
    $this->assertStringContainsString('limit 9223372036854775807', $rankedSql);
}
```

Extend `test_non_ready_or_version_mismatched_state_returns_null_for_legacy_fallback()` so every non-ready state also asserts:

```php
$this->assertNull($search->matchingTitleIdsQuery($query));
```

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
php artisan test tests/Feature/CatalogTitleSearchTest.php --filter=test_filter_only_matching_avoids_rank_columns_and_ranked_candidates_keep_materialization_boundary
```

Expected: FAIL because `matchingTitleIdsQuery()` does not exist.

- [ ] **Step 3: Implement the two query boundaries**

Add this method to `CatalogTitleSearch`:

```php
public function matchingTitleIdsQuery(CatalogSearchQuery $search): ?Builder
{
    if (! $this->isReady() || $search->ftsExpression === '') {
        return null;
    }

    return DB::table('catalog_title_search_fts')
        ->whereRaw('catalog_title_search_fts MATCH ?', [$search->ftsExpression])
        ->selectRaw('catalog_title_search_fts.rowid AS catalog_title_id');
}
```

Append the SQLite materialization boundary to the existing ranked builder after its final `orderByDesc()`:

```php
->orderByDesc('catalog_title_search_documents.catalog_title_id')
->limit(PHP_INT_MAX);
```

This limit does not truncate results on 64-bit PHP; it prevents SQLite from flattening the ranked derived table when `CatalogTitleQuery` joins it.

- [ ] **Step 4: Run the complete search-service test and verify GREEN**

Run:

```bash
php artisan test tests/Feature/CatalogTitleSearchTest.php
```

Expected: all `CatalogTitleSearchTest` tests PASS.

- [ ] **Step 5: Format and commit the query-boundary change**

Run:

```bash
./vendor/bin/pint --format agent app/Services/Catalog/Search/CatalogTitleSearch.php tests/Feature/CatalogTitleSearchTest.php
git add app/Services/Catalog/Search/CatalogTitleSearch.php tests/Feature/CatalogTitleSearchTest.php
git commit -m "perf: split catalog FTS matching and ranking"
```

Expected: Pint succeeds and the commit contains only the service and its focused test.

---

### Task 2: Route result lists and aggregate queries through the correct boundary

**Files:**
- Create: `tests/Feature/CatalogSearchQueryPlanTest.php`
- Modify: `app/Services/Catalog/CatalogTitleQuery.php`
- Modify: `app/Services/Catalog/CatalogTitlesPageBuilder.php`

**Interfaces:**
- Consumes: `CatalogTitleSearch::matchingTitleIdsQuery()`, `CatalogTitleSearch::candidateQuery()`, `CatalogSearchQuery::isReady()`.
- Produces: `CatalogTitleQuery::filteredTitles(CatalogTitlesCriteria $criteria, ?User $user, ?string $exceptTaxonomyType = null, bool $rankSearch = false): Builder`.

- [ ] **Step 1: Create the failing integration regression test**

Create `CatalogSearchQueryPlanTest` with this complete test class:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogSearchIndexStatus;
use App\Http\Requests\CatalogTitlesRequest;
use App\Models\CatalogSearchIndexState;
use App\Models\CatalogTitle;
use App\Services\Catalog\CatalogTitleQuery;
use App\Services\Catalog\CatalogTitlesCriteria;
use App\Services\Catalog\Search\CatalogSearchIndexer;
use App\Services\Catalog\Search\CatalogSearchQueryParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogSearchQueryPlanTest extends TestCase
{
    use RefreshDatabase;

    public function test_aggregate_search_uses_filter_only_fts_while_result_search_materializes_ranking(): void
    {
        $title = CatalogTitle::factory()->create(['title' => 'План быстрого поиска']);
        app(CatalogSearchIndexer::class)->indexTitleIds([$title->id]);
        CatalogSearchIndexState::query()->findOrFail(CatalogSearchIndexState::SINGLETON_ID)->update([
            'version' => CatalogSearchIndexer::INDEX_VERSION,
            'status' => CatalogSearchIndexStatus::Ready,
            'source_count' => 1,
            'document_count' => 1,
            'completed_at' => now(),
        ]);
        $request = CatalogTitlesRequest::create('/titles', 'GET', ['q' => 'быстрого поиска']);
        $request->setUserResolver(static fn () => null);
        $search = app(CatalogSearchQueryParser::class)->parse($request->normalizedSearch());
        $criteria = CatalogTitlesCriteria::fromRequest($request, $search, null, false);
        $titles = app(CatalogTitleQuery::class);

        $aggregate = $titles->filteredTitles($criteria, null);
        $ranked = $titles->filteredTitles($criteria, null, rankSearch: true);

        $aggregateSql = mb_strtolower($aggregate->toSql());
        $rankedSql = mb_strtolower($ranked->toSql());
        $this->assertStringContainsString(' in (select catalog_title_search_fts.rowid as catalog_title_id', $aggregateSql);
        $this->assertStringNotContainsString('bm25', $aggregateSql);
        $this->assertStringContainsString('inner join (select', $rankedSql);
        $this->assertStringContainsString('bm25', $rankedSql);
        $this->assertStringContainsString('limit 9223372036854775807', $rankedSql);
        $this->assertSame([$title->id], $aggregate->pluck('catalog_titles.id')->all());
        $this->assertSame([$title->id], $ranked->pluck('catalog_titles.id')->all());

        $plan = DB::select('EXPLAIN QUERY PLAN '.$ranked->toSql(), $ranked->getBindings());
        $details = collect($plan)->pluck('detail')->implode("\n");
        $this->assertMatchesRegularExpression('/CO-ROUTINE catalog_search_candidates|MATERIALIZE catalog_search_candidates/', $details);
    }
}
```

- [ ] **Step 2: Run the new test and verify RED**

Run:

```bash
php artisan test tests/Feature/CatalogSearchQueryPlanTest.php
```

Expected: FAIL because `filteredTitles()` has no `rankSearch` named parameter and the aggregate SQL still uses the ranked join.

- [ ] **Step 3: Add the ranked-context argument and filter-only path**

Change the `filteredTitles()` signature to:

```php
public function filteredTitles(
    CatalogTitlesCriteria $criteria,
    ?User $user,
    ?string $exceptTaxonomyType = null,
    bool $rankSearch = false,
): Builder {
```

Pass `$rankSearch` into `applySearchFilter()`. Replace that private method signature and its FTS section with:

```php
private function applySearchFilter(
    Builder $query,
    CatalogSearchQuery $search,
    ?int $titleContextId,
    ?User $user,
    bool $rankSearch,
): void {
    if ($search->state === CatalogSearchState::Empty) {
        return;
    }

    if ($search->state === CatalogSearchState::Insufficient) {
        if ($titleContextId === null) {
            $query->whereRaw('1 = 0');
        }

        return;
    }

    if ($search->terms === []) {
        return;
    }

    if ($rankSearch && ($rankedCandidates = $this->titleSearch->candidateQuery($search)) !== null) {
        $alias = 'catalog_search_candidates';
        $query->joinSub(
            $rankedCandidates,
            $alias,
            $alias.'.catalog_title_id',
            '=',
            'catalog_titles.id',
        );
        $this->rankedSearchAliases[spl_object_id($query)] = $alias;

        return;
    }

    if (($matchingTitleIds = $this->titleSearch->matchingTitleIdsQuery($search)) !== null) {
        $query->whereIn('catalog_titles.id', $matchingTitleIds);

        return;
    }

    $query->whereIn('catalog_titles.id', $this->searchCandidateIdsQuery($search, $user));
}
```

Update the existing invocation near the start of `filteredTitles()`:

```php
$this->applySearchFilter(
    $query,
    $criteria->search,
    $criteria->titleContextId,
    $user,
    $rankSearch,
);
```

- [ ] **Step 4: Enable ranking only for the primary card query**

Change the primary query construction in `CatalogTitlesPageBuilder::data()` to:

```php
$catalogTitles = $this->query->filteredTitles(
    $criteria,
    $request->user(),
    rankSearch: $searchQuery->isReady(),
)
    ->select($cardColumns)
```

All `CatalogFacetQuery`, year, publication, subtitle and context-count calls keep the default `rankSearch: false` and therefore receive filter-only FTS matching.

- [ ] **Step 5: Run query-plan, page and acceptance tests**

Run:

```bash
php artisan test tests/Feature/CatalogSearchQueryPlanTest.php tests/Feature/CatalogTitleSearchTest.php tests/Feature/CatalogSearchPageTest.php tests/Feature/CatalogSearchAcceptanceTest.php tests/Feature/CatalogAdvancedFilterTest.php
```

Expected: all selected tests PASS; exact/alias/transliteration/people/category ordering and contextual facets remain unchanged.

- [ ] **Step 6: Format and commit the integration change**

Run:

```bash
./vendor/bin/pint --format agent app/Services/Catalog/CatalogTitleQuery.php app/Services/Catalog/CatalogTitlesPageBuilder.php tests/Feature/CatalogSearchQueryPlanTest.php
git add app/Services/Catalog/CatalogTitleQuery.php app/Services/Catalog/CatalogTitlesPageBuilder.php tests/Feature/CatalogSearchQueryPlanTest.php
git commit -m "perf: use filter-only FTS for catalog aggregates"
```

Expected: Pint succeeds and the commit contains only query integration and its test.

---

### Task 3: Submit Livewire catalog search explicitly

**Files:**
- Modify: `tests/Feature/CatalogVisualSystemTest.php`
- Modify: `resources/views/catalog/titles.blade.php`

**Interfaces:**
- Consumes: existing `wire:submit="applySearch"` and `CatalogSeries::applySearch()`.
- Produces: deferred `wire:model="filters.search"`; Enter/button submit remains the only full catalog-search action.

- [ ] **Step 1: Change the UI regression test first**

Replace the live debounce assertion in `test_catalog_exposes_livewire_controls_loading_feedback_and_stable_rows()` with:

```php
$this->assertStringContainsString('wire:model="filters.search"', $content);
$this->assertStringNotContainsString('wire:model.live.debounce.650ms="filters.search"', $content);
$this->assertStringContainsString('wire:submit="applySearch"', $content);
```

- [ ] **Step 2: Run the focused visual test and verify RED**

Run:

```bash
php artisan test tests/Feature/CatalogVisualSystemTest.php --filter=test_catalog_exposes_livewire_controls_loading_feedback_and_stable_rows
```

Expected: FAIL because the Blade view still contains `wire:model.live.debounce.650ms`.

- [ ] **Step 3: Defer the search model until submit**

In `resources/views/catalog/titles.blade.php`, replace:

```blade
wire:model.live.debounce.650ms="filters.search"
```

with:

```blade
wire:model="filters.search"
```

Do not change the existing GET action, `wire:submit="applySearch"`, Russian label, placeholder or loading target.

- [ ] **Step 4: Run visual and Livewire catalog tests**

Run:

```bash
php artisan test tests/Feature/CatalogVisualSystemTest.php tests/Feature/CatalogPageTest.php
```

Expected: all selected tests PASS; URL hydration, paginator reset and catalog controls remain intact.

- [ ] **Step 5: Commit the explicit-submit UI change**

Run:

```bash
git add resources/views/catalog/titles.blade.php tests/Feature/CatalogVisualSystemTest.php
git commit -m "perf: submit catalog search explicitly"
```

Expected: the commit contains only Blade behavior and its regression test.

---

### Task 4: Document, verify and benchmark the optimized cold path

**Files:**
- Modify: `docs/catalog-search.md`
- Modify: `docs/performance.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: verified SQL timings and `EXPLAIN QUERY PLAN` output from the production-like SQLite database.
- Produces: project-owned operational contract and measured before/after evidence.

- [ ] **Step 1: Update the catalog search contract**

In `docs/catalog-search.md`, replace the 650 ms live-search statement with this contract:

```markdown
Поле поиска `/titles` использует deferred Livewire state и выполняет полный поиск только по Enter или кнопке «Найти». Автодополнение не может использовать полный `CatalogTitlesPageBuilder`: для него нужен отдельный ограниченный lookup.
```

Update the FTS section to state that filter/count/facet queries use filter-only `WHERE IN (FTS rowid)`, while only the primary result list materializes exact ranks and BM25.

- [ ] **Step 2: Record the query-plan budget and measurements**

Add the following verified baseline to `docs/performance.md` and a dated entry to `docs/MAINTENANCE_LOG.md`:

```markdown
- Baseline 13.07.2026: «Игра престолов», 9 matches, current ranked join count 17 150 ms.
- Acceptance: FTS count plan does not execute `MATCH` once per visible `catalog_titles` row; SQL cold path improves by at least 20x.
- Filter-only aggregates do not compute BM25 or exact rank columns.
```

After the final benchmark, replace/add the actual optimized count, ranked count and full page-builder timings without rounding them into unmeasured claims.

- [ ] **Step 3: Add the release note**

Add one concise entry under the current date in `CHANGELOG.md`:

```markdown
- Ускорен поиск каталога SQLite FTS5: агрегаты используют отдельное совпадение ID, ранжирование материализуется только для выдачи, а Livewire отправляет полный поиск по Enter или кнопке.
```

- [ ] **Step 4: Run formatting, focused tests and documentation checks**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/CatalogTitleSearchTest.php tests/Feature/CatalogSearchQueryPlanTest.php tests/Feature/CatalogSearchPageTest.php tests/Feature/CatalogSearchAcceptanceTest.php tests/Feature/CatalogAdvancedFilterTest.php tests/Feature/CatalogVisualSystemTest.php tests/Feature/CatalogPageTest.php
php artisan project:docs-refresh --check
npm run build
```

Expected: Pint makes no unexpected changes, all selected tests PASS, docs check exits 0 and Vite build succeeds.

- [ ] **Step 5: Run the broad PHP suite**

Run:

```bash
php artisan test
```

Expected: complete PHPUnit suite PASS with zero failures. If concurrent importer work prevents an isolated run, stop and report the exact blocker rather than claiming success.

- [ ] **Step 6: Repeat the production-data benchmark**

Use a bootstrapped Laravel one-off command to measure the same `CatalogTitlesCriteria` for «Игра престолов» in three forms:

```text
aggregate filter-only count
materialized ranked count
complete CatalogTitlesPageBuilder::data()
```

Capture elapsed milliseconds and `EXPLAIN QUERY PLAN`. Acceptance requires the filter-only plan to scan the FTS virtual table as a list subquery and the ranked plan to show `CO-ROUTINE` or `MATERIALIZE` before catalog lookup. Do not mutate the SQLite database during this benchmark.

- [ ] **Step 7: Commit documentation and final verified evidence**

Run:

```bash
git add docs/catalog-search.md docs/performance.md docs/MAINTENANCE_LOG.md CHANGELOG.md
git commit -m "docs: record catalog search performance fix"
```

Expected: commit contains only search/performance documentation.

- [ ] **Step 8: Confirm branch and tree state**

Run:

```bash
git status --short --branch
git log --oneline --decorate -8
```

Expected: current branch is `main`; no task-owned files are uncommitted. Any remaining unrelated changes must be identified as a blocker and must not be staged or committed by this task.
