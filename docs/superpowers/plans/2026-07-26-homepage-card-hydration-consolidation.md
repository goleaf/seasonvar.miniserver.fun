# Homepage Card Hydration Consolidation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Свести `latest`/`featured`/`video` homepage card hydration к одному
bounded Eloquent query group, сохранив HTML, API, ordering, relation-loading
и mutable model-state contracts.

**Architecture:** `CatalogHomePageBuilder` строит ordered ID groups из
существующего scalar snapshot, одним visibility-aware query загружает union
моделей и card taxonomy relations, затем проецирует отдельные cloned
`Eloquent\Collection` для каждой секции. `latestSeason` остаётся отдельным
eager-load только latest collection.

**Tech Stack:** PHP 8.5.8, Laravel 13.22.0, Laravel Boost 2.4.13, Livewire
4.3.3, SQLite, PHPUnit 12.5.32, Pint 1.29.3, Tailwind CSS 4.3.2, Vite 8.1.4.

## Global Constraints

- Работать только в существующей ветке `main`; branch/worktree/PR не
  создавать.
- Не менять route, translation, config/env, cache key/version/TTL,
  migration/schema/index, dependency, queue/scheduler или production data.
- Сохранять `CatalogHomePageBuilder::data()` и `webData()` public return
  keys, section order и limits.
- Сохранять отдельные Eloquent model instances между пересекающимися
  sections.
- Не загружать `latestSeason` на featured/video и не менять card HTML.
- Не объединять recommendation loader с snapshot card hydration.
- Не ослаблять publication, availability, audience, Premium, region, legal
  или authorization boundaries.
- Shared foreign worktree/index changes не форматировать, не удалять, не
  перезаписывать и не включать в Task 74 commit.
- README/CHANGELOG/current-plan/performance менять только точными Task 74
  hunks.
- Rollback не требует schema/data/cache cleanup.

## File map

- Modify: `tests/Feature/CatalogHomeWebProjectionTest.php` — RED/GREEN query
  groups, overlap isolation и full/web projection contract.
- Modify: `app/Services/Catalog/CatalogHomePageBuilder.php` — union hydration
  и ordered cloned projections.
- Modify: `docs/performance.md` — before/after evidence.
- Modify: `docs/plans/current-task-plan.md` — Task 74 matrix/checklist.
- Modify: `README.md` — visitor-visible homepage responsiveness result.
- Modify: `CHANGELOG.md` — dated Russian technical entry.
- Create:
  `docs/superpowers/specs/2026-07-26-homepage-card-hydration-consolidation-design.md`.
- Create:
  `docs/superpowers/plans/2026-07-26-homepage-card-hydration-consolidation.md`.

## Protected public contracts

- Named/localized homepage routes and full-page Livewire boundary.
- `/api/v1/home`, `CatalogHomeResource` and 48/12/8/12 full section counts.
- Existing 12-row web latest and 8-row video projection.
- Snapshot schema/order/key/TTL/stale/invalidation and response cache v2.
- Card taxonomy projection, tag localization and no-query Blade.
- Recommendation candidates/exclusions/ranking/shown-state.
- Card counts, personal state, release groups and SEO.
- Visibility, audience, Premium, region, legal and authorization scopes.
- Schema/indexes/data, dependencies, environment, importer and queues.

### Task 1: Add failing query and model-isolation contracts

**Files:**
- Modify: `tests/Feature/CatalogHomeWebProjectionTest.php`

**Interfaces:**
- Consumes: `CatalogHomePageBuilder::data()`, `webData()` and refreshed
  scalar snapshot.
- Produces: exact one-group SQL expectation and preserved section isolation.

- [ ] **Step 1: Preserve overlap semantics**

In the existing full-versus-web test, find a title shared by latest and video
and assert:

```php
$this->assertNotSame($latestTitle, $videoTitle);
$this->assertTrue($latestTitle->hasAttribute('content_added_at'));
$this->assertFalse($videoTitle->hasAttribute('content_added_at'));
$this->assertTrue($latestTitle->relationLoaded('latestSeason'));
$this->assertFalse($videoTitle->relationLoaded('latestSeason'));
```

Keep current full/web counts, ordering, link and API assertions.

- [ ] **Step 2: Tighten web query group expectation**

Keep the existing SQL normalization and exact root matcher, but change web
expectations from two card roots/taxonomy groups to one.

- [ ] **Step 3: Add full query group expectation**

Refresh the same fixture snapshot before attaching `DB::listen()`, call
`data()` and assert one card root plus one query for every canonical card
taxonomy. The fixture excludes every available title from recommendations,
so no independent recommendation taxonomy group is present.

- [ ] **Step 4: Run RED**

```bash
php artisan test tests/Feature/CatalogHomeWebProjectionTest.php
```

Expected: semantic overlap assertions pass against current separate
instances; web/full SQL assertions fail only because current builder emits
2/3 roots and 2/3 taxonomy groups.

### Task 2: Implement one bounded hydration group

**Files:**
- Modify: `app/Services/Catalog/CatalogHomePageBuilder.php`

**Interfaces:**
- Consumes: existing ordered snapshot ID lists and
  `Builder<CatalogTitle>`.
- Produces: named `Eloquent\Collection<int, CatalogTitle>` groups.

- [ ] **Step 1: Build named ID groups**

Inside `buildData()` create latest, conditional featured and video ID lists
without changing snapshot values or web projection limits.

- [ ] **Step 2: Add ordered group helper**

Add a typed private helper that:

1. flattens and normalizes group IDs;
2. executes the supplied builder once for unique IDs;
3. indexes models by integer primary key;
4. rebuilds every group in original order;
5. clones every projected model;
6. returns an empty Eloquent collection for empty/missing groups.

- [ ] **Step 3: Use one card taxonomy eager-load**

Pass a single `titleSummaryQuery($user)->with(
$this->taxonomies->cardSummaryLoads())` to the helper and assign its
`latest`, `featured` and `video` results.

- [ ] **Step 4: Load latest season only for latest**

Call `load()` on the latest Eloquent collection with the exact current
`latestSeason` projection. Empty latest groups must not emit a query.

- [ ] **Step 5: Keep downstream code unchanged**

Do not change content timestamps, count/state loaders, release groups,
recommendations, SEO or return shape.

- [ ] **Step 6: Run GREEN**

```bash
php artisan test tests/Feature/CatalogHomeWebProjectionTest.php
```

Expected: all semantic and SQL-shape assertions pass.

### Task 3: Verify contracts and measure result

**Files:**
- Modify only if a regression is found:
  `tests/Feature/CatalogHomeCardCountQueryTest.php`
- Modify only if a regression is found:
  `tests/Feature/CatalogHomePerformanceTest.php`

- [ ] **Step 1: Run focused homepage/API matrix**

```bash
php artisan test \
  tests/Feature/CatalogHomeWebProjectionTest.php \
  tests/Feature/CatalogHomeCardCountQueryTest.php \
  tests/Feature/CatalogHomePerformanceTest.php \
  tests/Feature/CatalogHomeContentAdditionTest.php \
  tests/Feature/Api/V1/CatalogDiscoveryTest.php \
  tests/Feature/PublicPageResponseCacheTest.php \
  tests/Unit/PublicPageCachePolicyTest.php
```

- [ ] **Step 2: Run broad related filter**

```bash
php artisan test \
  --filter='CatalogHome|CatalogRecommendation|CatalogDiscovery|PublicPageCache|EagerLoadProjection'
```

- [ ] **Step 3: Repeat matched builder profile**

On the same snapshot, record seven web/full wall samples and one SQL trace.
Expected structural result:

- web root groups `2 → 1`, section taxonomy `10 → 5`, total SQL `42 → 36`;
- full root groups `3 → 1`, section taxonomy `15 → 5`, total SQL `45 → 33`.

Wall time is diagnostic and must not become a PHPUnit assertion.

- [ ] **Step 4: Verify exact output parity**

Compare pre/post section IDs/order/counts and serialized API keys/counts.
Confirm latest and video overlap still consists of different model
instances with section-local mutable attributes/relations.

### Task 4: Static, full, browser and documentation gates

**Files:**
- Modify: `docs/performance.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Format and statically analyze exact PHP scope**

```bash
./vendor/bin/pint \
  app/Services/Catalog/CatalogHomePageBuilder.php \
  tests/Feature/CatalogHomeWebProjectionTest.php \
  --format agent
./vendor/bin/phpstan analyse --memory-limit=1G \
  app/Services/Catalog/CatalogHomePageBuilder.php
./vendor/bin/rector process --dry-run \
  app/Services/Catalog/CatalogHomePageBuilder.php \
  tests/Feature/CatalogHomeWebProjectionTest.php
```

- [ ] **Step 2: Run full/build/docs/diff gates**

```bash
php artisan test
npm run build
php artisan project:docs-refresh --check
git diff --check
```

Document exact unrelated shared-tree failures or known full-process memory
ceiling honestly; do not rewrite foreign managed-doc drift.

- [ ] **Step 3: Browser and live HTTP verification**

Check desktop `1440×1200`, mobile `390×844`, `/`, localized homepage and
`/api/v1/home`: status, response-cache state, section counts/order, DOM,
overflow, console/page/request errors and response timing. Do not clear
cache or modify production data.

- [ ] **Step 4: Update documentation**

Record measured result in `docs/performance.md`, one visitor-facing README
history bullet and one Russian CHANGELOG bullet. Complete Task 74 compliance
statuses with evidence or honest `unresolved`/`not_applicable`.

- [ ] **Step 5: Final requirement and legacy scan**

Re-read applicable canonical requirements and Task 74. Search all
`orderedTitles`, homepage section consumers, duplicate hydration services,
stale cache paths, TODO/FIXME/debug output and unintended relation loading.

### Task 5: Commit and push exact Task 74 scope

- [ ] **Step 1: Verify branch and exact manifest**

Confirm current branch is `main`. Use an alternate index or another
path-limited safe method so foreign staged/unstaged changes are untouched.
Inspect every staged Task 74 hunk.

- [ ] **Step 2: Commit**

Create one exact implementation commit in `main` after hooks pass. A prior
design-only commit may remain separate as required by the design workflow.

- [ ] **Step 3: Push**

Run configured non-force push to `origin main`. If credentials or remote
state reject the push before transfer, record it as `unresolved`; never
claim success.
