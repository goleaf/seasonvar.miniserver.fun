# Homepage Latest Media Correlated Title Visibility Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> `superpowers:executing-plans` to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ускорить cold rebuild homepage snapshot, заменив глобальную
материализацию всех видимых тайтлов в `latest_media_ids` на коррелированную
проверку только текущего media candidate, без изменения ordered IDs, API,
HTML, cache или database schema.

**Architecture:** `CatalogHomeSnapshotCache` остаётся единственным владельцем
scalar homepage content snapshot. Existing `CatalogTitleQuery::visibleTo()`
получает exact `whereColumn` correlation и передаётся в portable
`whereExists()` внутри существующего `LicensedMedia` feed query; media,
season и episode visibility scopes, order, limit and cache envelope остаются
прежними.

**Tech Stack:** PHP 8.5.8, Laravel 13.22.0, Laravel Boost 2.4.13, Livewire
4.3.3, SQLite 3.46.1, PHPUnit 12.5.32, Pint 1.29.3, Larastan 3.10.0,
Tailwind CSS 4.3.2, Vite 8.1.4, Playwright 1.61.1.

## Global Constraints

- Работать только в существующей `main`; branch, worktree и PR не создавать.
- Не менять migration/schema/index/data, route, translation, cache
  resource/key/version/TTL/stale/invalidation, dependency, config/env, queue,
  scheduler, JS/CSS или service worker.
- Сохранить snapshot resource `content-index-v2`, dimensions and exact keys.
- Сохранить `published_at DESC, id DESC`, `LIMIT 12` and exact ordered media
  IDs.
- Сохранить `CatalogTitleQuery::visibleTo(null)`,
  `LicensedMedia::published()` and
  `LicensedMedia::forAvailableReleases(null)` as canonical access owners.
- Не копировать publication/audience/availability predicates в snapshot.
- Не добавлять raw user input, raw SQL, second cache/read model or fallback.
- Не включать foreign Task 76/78/79/80/81/82 staged/unstaged work в Task 83
  commit.
- README/CHANGELOG/current-plan/performance менять только точными Task 83
  hunks.
- Rollback — обычный code/docs revert; database restore, reindex, migration
  rollback, cache flush and generation bump are not required.

## File Map

- Modify: `tests/Feature/CatalogHomePerformanceTest.php` — real snapshot
  RED/GREEN SQL, exact IDs and SQLite plan contract.
- Modify: `app/Services/Catalog/CatalogHomeSnapshotCache.php` — correlated
  title visibility query.
- Modify: `docs/performance.md` — measured root cause, parity and result.
- Modify: `docs/plans/current-task-plan.md` — Task 83 live compliance.
- Modify: `README.md` — meaningful visitor history after verified result.
- Modify: `CHANGELOG.md` — dated Russian technical entry.
- Create:
  `docs/superpowers/specs/2026-07-26-homepage-latest-media-correlated-title-visibility-design.md`.
- Create:
  `docs/superpowers/plans/2026-07-26-homepage-latest-media-correlated-title-visibility.md`.

## Protected Public Contracts

- `/`, `/ru`, named/localized homepage, Livewire/Blade/SEO.
- `/api/v1/home`, full `data()` and bounded `webData()` keys and limits.
- Snapshot scalar keys and ordered values, including `latest_media_ids`.
- `CatalogHomeSnapshotCache` TieredCache resource, dimensions, generation,
  fresh/stale/negative/lock/wait and warming behavior.
- Existing media/title/season/episode publication, time, audience, soft-delete,
  Premium, region, legal, privacy and authorization boundaries.
- Existing home feed index and all SQLite/non-SQLite schemas.
- Importer/admin/search/recommendation/sitemap/notification/account flows.

### Task 1: Add the real-snapshot RED regression

**Files:**
- Modify: `tests/Feature/CatalogHomePerformanceTest.php`

**Interfaces:**
- Consumes: `CatalogHomeSnapshotCache::refresh(): array<string,mixed>`,
  `QueryExecuted::toRawSql()` and SQLite `EXPLAIN QUERY PLAN`.
- Produces: exact semantic and planner contract without timing assertions.

- [x] **Step 1: Add visible and rejected fixtures**

Create two public visible titles with ordered published media. Create one
unpublished title with a newer published media row and one visible title
whose media is authenticated-only. Use a fixed test clock and distinct
`published_at`/ID values.

- [x] **Step 2: Capture only the latest-media selector**

Register `DB::listen()` after fixture setup. Retain the single
`QueryExecuted` whose normalized SQL starts with `select id from
licensed_media` and contains
`order by published_at desc, id desc limit 12`.

- [x] **Step 3: Assert exact result and query shape**

Assert `latest_media_ids` contains only the two public rows in newest-first
order. Assert normalized SQL contains:

```sql
exists (
  select 1
  from catalog_titles
  where ... catalog_titles.id = licensed_media.catalog_title_id
)
```

and does not contain:

```sql
catalog_title_id in (select id from catalog_titles ...)
```

- [x] **Step 4: Assert SQLite query plan**

Run `EXPLAIN QUERY PLAN` over `toRawSql()` and assert:

```text
licensed_media_home_feed_idx
SEARCH catalog_titles USING INTEGER PRIMARY KEY
```

Assert the plan does not contain the title visibility `LIST SUBQUERY`.

- [x] **Step 5: Run RED**

```bash
php artisan test \
  tests/Feature/CatalogHomePerformanceTest.php \
  --filter=latest_media_uses_correlated_title_visibility
```

Expected: semantic preconditions pass and the test fails only because current
SQL uses `catalog_title_id IN (SELECT id FROM catalog_titles ...)`.

Observed: one test ran with two passing semantic/query-selection assertions
and failed exactly because the captured latest-media SQL still contained the
global title `IN (SELECT ...)` and lacked correlated title `EXISTS`.

### Task 2: Implement the minimal correlated visibility predicate

**Files:**
- Modify: `app/Services/Catalog/CatalogHomeSnapshotCache.php`

**Interfaces:**
- Consumes: existing `$media`, `CatalogTitleQuery::visibleTo(null)` and
  Query Builder `whereExists()`.
- Produces: same ordered `list<int>` under `latest_media_ids`.

- [x] **Step 1: Build the correlated title query**

Immediately before `$latestMediaIds`, create:

```php
$visibleTitle = $this->titles
    ->visibleTo(null)
    ->whereColumn(
        'catalog_titles.id',
        $media->qualifyColumn('catalog_title_id'),
    )
    ->selectRaw('1')
    ->toBase();
```

- [x] **Step 2: Replace only the global title list**

Replace:

```php
->whereIn(
    'catalog_title_id',
    $this->titles->visibleTo(null)->select('id'),
)
```

with:

```php
->whereExists($visibleTitle)
```

Do not change any other scope, selected column, order, limit or snapshot key.

- [x] **Step 3: Run focused GREEN**

```bash
php artisan test \
  tests/Feature/CatalogHomePerformanceTest.php \
  --filter=latest_media_uses_correlated_title_visibility
```

Expected: exact IDs, SQL and plan assertions pass.

Observed: the exact test passed 1/1 with seven assertions. The snapshot
returned the same ordered public media IDs, emitted correlated title
visibility, retained `licensed_media_home_feed_idx`, used title primary-key
probes and contained no `LIST SUBQUERY`.

### Task 3: Verify homepage, API and cache compatibility

**Files:**
- Inspect without speculative edits:
  `tests/Feature/CatalogHomePerformanceTest.php`
- Inspect without speculative edits:
  `tests/Feature/CatalogHomeContentAdditionTest.php`
- Inspect without speculative edits:
  `tests/Feature/CatalogHomeWebProjectionTest.php`
- Inspect without speculative edits:
  `tests/Feature/CatalogPageTest.php`
- Inspect without speculative edits:
  `tests/Feature/Api/V1/CatalogDiscoveryTest.php`

**Interfaces:**
- Consumes: unchanged snapshot data contract.
- Produces: evidence for HTML/API/order/cache invariants.

- [x] **Step 1: Run focused homepage matrix**

```bash
php artisan test \
  tests/Feature/CatalogHomePerformanceTest.php \
  tests/Feature/CatalogHomeContentAdditionTest.php \
  tests/Feature/CatalogHomeWebProjectionTest.php \
  tests/Feature/CatalogHomeCardCountQueryTest.php
```

- [x] **Step 2: Run snapshot/API/cache matrix**

```bash
php artisan test \
  --filter='CatalogHome|CatalogPage|CatalogDiscovery|ApiCatalog|Cache'
```

Record unrelated shared-tree failures separately; do not modify foreign
features.

- [x] **Step 3: Confirm protected query contracts**

Inspect emitted SQL and returned arrays for:

- exactly 12 `latest_media_ids`;
- unchanged `48/12/8/12` full API section counts;
- unchanged `12/0/8/0` web section counts;
- unchanged latest media order and no duplicate IDs;
- same snapshot resource/dimensions and no new statement.

Observed: the exact homepage matrix passed 20/20 with 137 assertions.
`CatalogPageTest` then passed 85/85 with 859 assertions, and the three
API/discovery files passed 20/20 with 800 assertions. An attempted broad
filter was stopped after the execution harness returned before its child
runner; only the exact Task 83 PID trees were terminated. The narrower
sequential matrices completed normally and preserved snapshot/API contracts.

### Task 4: Reprofile the production-shaped cold path

**Files:**
- Modify: `docs/performance.md`
- Delete before completion: `output/task80-*.php` diagnostics.

**Interfaces:**
- Consumes: current production-shaped SQLite snapshot through read-only
  scripts.
- Produces: exact parity, plan and diagnostic performance evidence.

- [x] **Step 1: Repeat the paired query-shape probe**

Run at least 15 alternating current/correlated samples over one snapshot.
Record ordered row count, deterministic SHA-256, median/range and both plans.

Observed: a second 15-pair run preserved the same 12 ordered IDs and SHA-256
`412fd422115fe129a5e25dea93d452af315d618a18088f0168b6388809ba8c64`.
Under concurrent foreign test load the former global title-list query measured
median `117.984 ms` (`79.859–227.787 ms`), while the correlated query measured
`0.347 ms` (`0.259–7.445 ms`). Both plans retained
`licensed_media_home_feed_idx`; only the correlated plan replaced the
`LIST SUBQUERY` with title primary-key probes.

- [x] **Step 2: Repeat cold snapshot builds**

Invoke private build only through a task diagnostic, without refreshing or
flushing shared cache. Run at least seven fresh processes and record:

- query count;
- snapshot counts;
- SQL and wall medians;
- latest-media statement median;
- remaining bottlenecks without claiming they are fixed.

Observed: seven fresh private `build()` processes retained 16 statements and
exact section counts `48/12/8/12`, with 12 year buckets. Diagnostic SQL median
changed from `544.56 ms` to `405.86 ms` (about `25.5%` lower). Wall median was
noisy under the simultaneously running foreign sequential PHPUnit process
(`842.075 ms` baseline versus `979.471 ms` repeat), so it is not presented as
an end-to-end improvement claim. The optimized latest-media selector was
sub-millisecond in the repeat sample (`0.42 ms` in a separately inspected
build); remaining medians were dominated by year buckets (`198.47 ms`) and
episode availability (`91.15 ms`).

- [x] **Step 3: Confirm no schema/cache mutation**

Inspect migrations/indexes/status/diff and prove no schema, DML, cache key,
generation, route, translation, configuration or dependency change.

Observed: the task diff changes only the snapshot query and its regression
test at application level. No Task 83 migration, index, DML, route,
translation, cache resource/dimension/version/TTL/invalidation, config,
environment or dependency file exists.

- [x] **Step 4: Delete all Task 83 diagnostics**

Delete every `output/task80-*.php` created by the investigation and confirm
no profiler/debug/raw URL/secret artifact remains.

Observed: all six task diagnostics were removed and `rg --files output`
contains no matching `output/task80-*.php` artifact.

### Task 5: Static, broad, build and browser verification

**Files:**
- Modify only if an in-scope regression is proven.

**Interfaces:**
- Produces: fresh verification evidence before documentation and commit.

- [x] **Step 1: Format and syntax-check exact PHP scope**

```bash
./vendor/bin/pint \
  app/Services/Catalog/CatalogHomeSnapshotCache.php \
  tests/Feature/CatalogHomePerformanceTest.php \
  --format agent

php -l app/Services/Catalog/CatalogHomeSnapshotCache.php
php -l tests/Feature/CatalogHomePerformanceTest.php
```

Observed: exact-file Pint passed without further changes and both PHP files
passed syntax checks.

- [x] **Step 2: Run static and modernization gates**

```bash
./vendor/bin/phpstan analyse --memory-limit=1G \
  app/Services/Catalog/CatalogHomeSnapshotCache.php

./vendor/bin/rector process --dry-run \
  app/Services/Catalog/CatalogHomeSnapshotCache.php \
  tests/Feature/CatalogHomePerformanceTest.php
```

Observed: scoped PHPStan passed with zero errors and scoped Rector reported
zero changed files/errors.

- [x] **Step 3: Run broad/full/build/docs/diff gates**

```bash
php artisan test
npm run build
php artisan project:docs-refresh --check
git diff --check
```

Use a temporary test-only PHPUnit memory override only if the documented
cumulative 256M runner limit recurs; delete it after the run. Record foreign
failures honestly.

Observed: Vite and task-scoped diff check passed. The first two full attempts
hit the repository's forced `256M` ceiling at
`PublicPageResponseCacheTest`; a temporary full-copy PHPUnit configuration
with only `memory_limit=1G` changed completed and was then deleted. It ran
1,969 tests: 1,956 passed, 11 skipped, one failure in the unrelated
browser-session flash contract and one pre-existing error because
`SeasonvarImportDispatchBatcher` is absent. No homepage/snapshot/API test
failed. The initial managed docs check reported foreign
`docs/MAINTENANCE_LOG.md` drift; the final repeat after Task 82's parallel
commit passed. Repository-wide diff check still reports only three foreign
Markdown blank-line errors. That foreign scope was neither changed nor
hidden.

- [x] **Step 4: Run managed Chromium QA**

Check desktop `1440×1200` and mobile `390×844` for `/` and `/ru`, plus
`/api/v1/home`:

- HTTP 200 and primary H1;
- latest/recommendation/release counts;
- response cache state and timing observation;
- no horizontal overflow;
- no console/page/request/local-asset error;
- unchanged API `48/12/8/12`.

Do not clear cache, change maintenance state or mutate production data.

Observed: managed Chromium returned `200` for desktop `/` at `1440×1200`
and mobile `/ru` at `390×844`, exposed the expected H1 and homepage section
headings, had no horizontal overflow in the bounding-box snapshots and
reported zero console errors. Every local CSS/JS/font request returned
`200`. `/api/v1/home` returned exact array counts `48/12/8/12`; a separate
HTTPS observation measured `0.244 s` TTFB and `0.251 s` total without cache
flush or data mutation.

### Task 6: Documentation, final audit and exact delivery

**Files:**
- Modify: `docs/performance.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Produces: canonical evidence, visitor history, compliance and exact main
  delivery.

- [x] **Step 1: Update canonical and visitor documentation**

Record measured root cause, rejected alternatives, correlated query, exact
hash/plan, before/after diagnostic values, no-schema/cache rollback and
browser result. Add a visitor-facing README history item only after verified
improvement and a separate dated Russian CHANGELOG item.

Observed: the canonical performance owner now records root cause,
alternatives, exact hash/plan, paired/cold measurements, remaining
bottlenecks, verification and rollback. README visitor history and the
Russian dated CHANGELOG contain separate Task 83 entries.

- [x] **Step 2: Reread applicable requirements**

Reread root/index, code standards, architecture, development, multilingual,
security, performance, caching, UI/frontend, production operations,
maintenance and system-wide integration owners plus this plan.

Observed: the canonical registry and every applicable owner were reread after
implementation together with this plan. The implementation still calls the
canonical title/media/release scopes and does not change a higher-priority
security, data, compatibility or cache rule.

- [x] **Step 3: Run repository-wide final audit**

Search for duplicate latest-media snapshot selectors, old global title-list
shape, direct Blade queries, stale cache paths, debug/TODO/placeholders,
secrets/raw URLs and unintended schema/routes/config/dependency changes.

Observed: the snapshot owns the only `latest_media_ids` selector. Remaining
`visibleTo()` title-list queries belong to distinct unbounded count,
sitemap, stats and profile contracts and were inspected rather than
speculatively changed. The homepage Blade files contain no query/service
call. Task files contain no debug call, placeholder, secret or diagnostic
artifact; no Task 83 schema, route, config, dependency or cache-identity
diff exists.

- [x] **Step 4: Complete the Task 83 matrix**

Mark every requirement only from evidence using `completed`,
`already_compliant`, `not_applicable` or `unresolved`.

Observed: the current task matrix records verified, already-compliant and
not-applicable domains separately, marks the final managed-doc check green
and leaves the two foreign full-suite problems plus shared diff explicitly
unresolved.

- [x] **Step 5: Commit exact Task 83 scope on `main`**

Use an alternate Git index based on current `HEAD`; stage only Task 83
files/hunks. Verify branch, cached name list, cached diff and
`git diff --cached --check`. Preserve every foreign staged/unstaged change.

Observed: alternate index was rebuilt from the final concurrent `HEAD`,
contained exactly seven Task 83 files/hunks, passed cached diff/secret checks
and repository hooks, and produced commit `c1d1c4a` on existing `main`.

- [x] **Step 6: Push configured non-force remote**

Run `composer git:doctor -- --remote` when safe and `git push origin main`.
If credentials or another external condition rejects transfer, record exact
failure as `unresolved`; never claim successful push without evidence.

Observed: `composer git:doctor -- --remote` confirmed `main`, hooks and
`ahead=69, behind=0`, then failed because the HTTPS remote has no detected
credential helper. `git push origin main` requested a GitHub username and
was cancelled before transfer; configured push is
`unresolved_authentication`, with no force, remote rewrite or history
rewrite.
