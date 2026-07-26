# Card Rating Eager-Load SQLite Index Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> `superpowers:executing-plans` to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ускорить общий eager-load рейтингов карточек на SQLite, обязав
bounded title/provider lookup использовать уже существующий unique index,
без изменения relation payload, HTML, API, cache или database schema.

**Architecture:** `CatalogTaxonomyRegistry::cardSummaryLoads()` остаётся
единственным владельцем card relations. Его ratings closure применяет
SQLite-only `INDEXED BY` через grammar-quoted table/index identifiers, а
затем выполняет прежние select/provider/rating predicates. Для всех остальных
database drivers Eloquent builder возвращается без изменения.

**Tech Stack:** PHP 8.5.8, Laravel 13.22.0, Laravel Boost 2.4.13, Livewire
4.3.3, SQLite 3.46.1, PHPUnit 12.5.32, Pint 1.29.3, Tailwind CSS 4.3.2,
Vite 8.1.4.

## Global constraints

- Работать только в существующей ветке `main`; branch/worktree/PR не
  создавать.
- Не менять migration/schema/index/data, route, translation, cache
  key/version/TTL/invalidation, config/env, dependency, queue или scheduler.
- Сохранить exact rating projection
  `catalog_title_id/provider/rating`, providers `kinopoisk|imdb` и range
  `0..10`.
- Сохранить `CatalogTaxonomyRegistry` единым владельцем общего card eager-load.
- Не переносить queries в Blade и не добавлять model graph cache.
- Не ослаблять publication, media availability, audience, Premium, region,
  legal, privacy или authorization boundaries.
- Не включать в Task 77 commit чужие staged/unstaged изменения Task 75/76.
- README/CHANGELOG/current-plan/performance менять только точными Task 77
  hunks.
- Rollback — обычный code/docs revert; cache flush, database restore и
  migration rollback не нужны.

## File map

- Modify: `tests/Feature/CatalogHomeWebProjectionTest.php` — RED/GREEN actual
  homepage query, exact index hint, planner и payload semantics.
- Modify: `tests/Feature/CatalogCompactTitleCardTest.php` — shared catalogue
  card SQL contract.
- Modify: `app/Services/Catalog/CatalogTaxonomyRegistry.php` — SQLite-only
  index preference.
- Modify: `docs/performance.md` — measured before/after evidence and
  operational contract.
- Modify: `docs/plans/current-task-plan.md` — Task 77 compliance/checklist.
- Modify: `README.md` — visitor-visible homepage responsiveness result.
- Modify: `CHANGELOG.md` — dated Russian technical entry.
- Create:
  `docs/superpowers/specs/2026-07-26-card-rating-eager-load-sqlite-index-design.md`.
- Create:
  `docs/superpowers/plans/2026-07-26-card-rating-eager-load-sqlite-index.md`.

## Protected public contracts

- `/`, `/ru`, named/localized homepage routes, Livewire/Blade/SEO.
- `/api/v1/home`, catalogue, search, Top 100, discovery, recommendations and
  personal library card payloads.
- Homepage full/web keys, order and section limits.
- Card display preference: КиноПоиск with IMDb fallback.
- Card relation columns/provider/range and one eager-load per parent group.
- Snapshot/response cache keys, versions, TTL, stale behavior and invalidation.
- Importer writes and unique `(catalog_title_id, provider)` integrity.
- All non-SQLite SQL and all schema/index definitions.
- Publication, availability, audience, Premium, region, legal, privacy and
  authorization scopes.

### Task 1: Add the failing real-homepage planner contract

**Files:**
- Modify: `tests/Feature/CatalogHomeWebProjectionTest.php`

**Interfaces:**
- Consumes: refreshed `CatalogHomeSnapshotCache`,
  `CatalogHomePageBuilder::webData()` and emitted `QueryExecuted`.
- Produces: exact SQLite index/payload regression without timing assertions.

- [x] **Step 1: Create valid and rejected rating fixtures**

After the existing 16-title fixture, add one valid `kinopoisk` row, one
unsupported-provider row and one out-of-range row on bounded homepage title
IDs.

- [x] **Step 2: Capture the actual relation query**

Attach `DB::listen()` after snapshot refresh, retain the single
`catalog_title_ratings` query emitted by the real web projection and assert
one eager-load group.

- [x] **Step 3: Assert exact query and relation semantics**

Assert the SQL contains:

```sql
FROM "catalog_title_ratings"
INDEXED BY "catalog_title_ratings_catalog_title_id_provider_unique"
```

Assert only selected columns exist and only valid provider/range rows are
hydrated onto the expected cards.

- [x] **Step 4: Assert the compiled plan**

Run `EXPLAIN QUERY PLAN` over the captured raw SQL after hydration. Assert
the plan names
`catalog_title_ratings_catalog_title_id_provider_unique` and does not name
`catalog_ratings_provider_score_votes_title_idx`. Do not assert milliseconds.

- [x] **Step 5: Run RED**

```bash
php artisan test \
  tests/Feature/CatalogHomeWebProjectionTest.php \
  --filter=rating_eager_load
```

Expected: the test fails only because current SQL has no `INDEXED BY` and
planner selects the broad provider/rating index.

Observed: one test ran with six passing semantic/query-count assertions and
failed only on the missing exact `INDEXED BY` clause. The captured SQL was
the former `FROM catalog_title_ratings WHERE catalog_title_id IN (...)`
shape.

### Task 2: Implement the minimal shared-loader fix

**Files:**
- Modify: `app/Services/Catalog/CatalogTaxonomyRegistry.php`

**Interfaces:**
- Consumes: `HasMany<CatalogTitleRating, CatalogTitle>` supplied by Eloquent
  eager loading.
- Produces: the same relation and payload with an SQLite-only
  grammar-safe `FROM ... INDEXED BY ...`.

- [x] **Step 1: Name the existing index**

Add a private constant with the exact migration-created unique index:

```php
private const CARD_RATING_TITLE_PROVIDER_INDEX =
    'catalog_title_ratings_catalog_title_id_provider_unique';
```

- [x] **Step 2: Add a typed SQLite helper**

Add a private method accepting and returning
`HasMany<CatalogTitleRating, CatalogTitle>`. Read the connection from its
underlying Eloquent query. For non-SQLite return the original relation. For
SQLite call `fromRaw()` on `getQuery()` with grammar-wrapped model table and
constant index.

- [x] **Step 3: Apply the helper before existing predicates**

Type the ratings closure and pass its builder through the helper before the
unchanged `select()`, `whereIn()` and `whereBetween()` chain.

- [x] **Step 4: Run focused GREEN**

```bash
php artisan test \
  tests/Feature/CatalogHomeWebProjectionTest.php \
  --filter=rating_eager_load
```

Expected: exact index, planner and payload assertions pass.

Observed: the first run exposed the actual Laravel 13 eager-load callback
contract (`HasMany`, not a bare `Builder`). The implementation was corrected
to mutate `HasMany::getQuery()` while returning the relation. The exact
regression then passed 1/1 with eight assertions.

### Task 3: Adapt and verify every shared card consumer

**Files:**
- Modify: `tests/Feature/CatalogHomeWebProjectionTest.php`
- Modify: `tests/Feature/CatalogCompactTitleCardTest.php`
- Inspect without speculative edits:
  `tests/Feature/CatalogRecommendationTitleLoaderQueryTest.php`

- [x] **Step 1: Update existing SQL shape matchers**

Keep current column/provider/range assertions and change only the expected
SQLite `FROM` shape to include the exact `INDEXED BY` clause.

- [x] **Step 2: Verify homepage and catalogue card contracts**

```bash
php artisan test \
  tests/Feature/CatalogHomeWebProjectionTest.php \
  tests/Feature/CatalogCompactTitleCardTest.php \
  tests/Feature/CatalogHomeCardCountQueryTest.php \
  tests/Feature/CatalogHomePerformanceTest.php \
  tests/Feature/CatalogRecommendationTitleLoaderQueryTest.php \
  tests/Feature/CatalogTitlesCardCountQueryTest.php
```

Observed: 27/27 tests passed with 174 assertions.

- [x] **Step 3: Verify API, search, discovery and personal library**

```bash
php artisan test \
  --filter='CatalogHome|CatalogCompactTitleCard|CatalogRecommendationTitleLoader|CatalogTitleSuggestion|CatalogTitlesCardCount|CatalogDiscovery|UserLibrary'
```

Observed: the first 92-test run reached 91 passes before a random factory
collision on unique episode identity occurred during fixture insertion. The
exact test passed 1/1 on rerun; the complete identical filter then passed
92/92 with 633 assertions.

- [x] **Step 4: Check query-count and output invariants**

Confirm the change does not add SQL statements on the production-shaped
snapshot, keeps one rating eager-load per independent title group and
preserves section counts/order plus API/card labels.

Observed: settled fresh-process runs emitted 33 statements with unchanged
`12/0/8/0`, 12 release groups and eight recommendations. The structural
rating eager-load count remained one per independent title group.

### Task 4: Reprofile the measured production-shaped path

**Files:**
- Modify: `docs/performance.md`
- Delete before completion: ignored diagnostic scripts under `output/`.

- [x] **Step 1: Repeat the matched rating probe**

Run at least 21 alternating read-only current/planner-vs-hinted samples over
the same bounded IDs. Compare row count and deterministic row hash.

- [x] **Step 2: Repeat fresh-process homepage builder samples**

Run at least nine samples, recording total SQL count, SQL/wall medians and
section/recommendation/release-group counts. Treat timing as diagnostic,
not SLA or PHPUnit contract.

- [x] **Step 3: Confirm planner and schema invariants**

Use `EXPLAIN QUERY PLAN` and schema inspection to confirm the existing unique
index is selected and no migration/index/database write was introduced.

- [x] **Step 4: Remove diagnostics**

Delete task-only `output/task76-home-profile.php` and
`output/task76-rating-index-probe.php`; ensure no debug/TODO/secret/raw URL
artifact remains.

Observed: the repeated 21-pair probe returned 32 rows and the same SHA-256
for both forms. Under concurrent load medians were `12.828 ms` planner versus
`0.252 ms` hinted. A second settled nine-process builder batch kept 33 SQL
and exact section counts; SQL median was `33.39 ms` against the pre-change
`39.24 ms`. Wall median was noisy (`153.998 ms` versus `146.854 ms`) and is
not claimed as an established improvement. Both ignored diagnostic scripts
were deleted.

### Task 5: Static, broad, build and browser verification

**Files:**
- Modify only if an in-scope regression is proven.

- [x] **Step 1: Format exact PHP scope**

```bash
./vendor/bin/pint \
  app/Services/Catalog/CatalogTaxonomyRegistry.php \
  tests/Feature/CatalogHomeWebProjectionTest.php \
  tests/Feature/CatalogCompactTitleCardTest.php \
  --format agent
```

- [x] **Step 2: Run static and modernization gates**

```bash
./vendor/bin/phpstan analyse --memory-limit=1G \
  app/Services/Catalog/CatalogTaxonomyRegistry.php
./vendor/bin/rector process --dry-run \
  app/Services/Catalog/CatalogTaxonomyRegistry.php \
  tests/Feature/CatalogHomeWebProjectionTest.php \
  tests/Feature/CatalogCompactTitleCardTest.php
```

- [x] **Step 3: Run full/build/docs/diff gates**

```bash
php artisan test
npm run build
php artisan project:docs-refresh --check
git diff --check
```

Record any unrelated shared-tree/full-suite failure honestly and do not
rewrite foreign managed-doc drift.

Observed: Pint changed only registry chain indentation; PHP syntax,
task-scoped PHPStan and Rector passed. Vite and global `git diff --check`
passed. Default full runs hit the configured cumulative `256M`; a temporary
untracked 1G config executed 1,949 tests: 1,933 passed, 11 skipped, while
three failures and two errors were confined to concurrent smart-collection,
account-session and import-batcher work. Managed docs reports only foreign
`docs/MAINTENANCE_LOG.md` drift.

- [x] **Step 4: Run managed Chromium QA**

Check desktop `1440×1200` and mobile `390×844` for `/` and `/ru`, plus
`/api/v1/home`: HTTP status, visible section counts, rating labels, response
cache, horizontal overflow, console/page/request errors and a diagnostic
response timing. Do not clear caches or mutate production data.

Observed: the project CLI could not find system Chrome, so the documented
installed managed-Chromium fallback was used. Public HTTPS desktop
network-idle was `1,915–2,350 ms`, mobile `1,190–1,334 ms`; local cold/BYPASS
desktop was `3,036 ms`. `/`, `/ru` and API returned 200 with exact section
counts, zero overflow and no console/page/request/local-asset errors.

### Task 6: Documentation, final audit and delivery

**Files:**
- Modify: `docs/performance.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

- [x] **Step 1: Update canonical and visitor documentation**

Record the measured cause, chosen existing-index hint, before/after query
evidence, no-schema rollback and production deployment verification.
Add a meaningful visitor history item about faster card rating hydration on
the homepage.

- [x] **Step 2: Reread applicable requirements**

Reread `docs/requirements/index.md`, architecture, development,
multilingual, security, performance, caching, UI/frontend, production
operations, maintenance/upgrades and system-wide integration owners.

- [x] **Step 3: Run repository-wide final audit**

Search for duplicate card rating loaders, stale rating query expectations,
legacy provider/range variants, direct Blade queries, old cache paths,
debug/TODO/placeholder/secret artifacts and unintended schema/routes/config
changes.

Observed: final canonical reread covered the root/index, architecture,
development, multilingual, security, performance, caching, UI/frontend,
production-operations, maintenance and integration owners. Repository search
found one shared `cardSummaryLoads()` owner, no old rating SQL expectation,
no Blade query, no task-only executable diagnostic and no schema/route/cache/
config change. A final expanded consumer matrix passed 36/36 with 334
assertions.

- [x] **Step 4: Complete the Task 77 compliance matrix**

Mark each row only from verified evidence. Use `unresolved` for external or
shared-worktree blockers and `not_applicable` for excluded domains.

- [ ] **Step 5: Commit exact task scope on `main`**

Use an alternate Git index based on current `HEAD`; include only Task 77
hunks/files and preserve all foreign staged/unstaged work. Verify branch,
cached file list, cached diff and `diff --check` before commit.

- [ ] **Step 6: Push configured non-force remote**

Run the configured non-force push. If GitHub credentials or another external
condition rejects the transfer, record exact failure as `unresolved`; do not
claim a successful push.
