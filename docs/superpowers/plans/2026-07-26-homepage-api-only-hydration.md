# Homepage API-only Hydration Removal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Убрать из full-page Livewire главной Eloquent hydration двух секций, которые используются только `/api/v1/home`, сохранив API, HTML, snapshot и recommendation contracts.

**Architecture:** `CatalogHomePageBuilder` сохраняет public `data()` как полную projection boundary, а `webData()` включает existing 12-row limit и выключает только `featuredTitles`/`latestMedia` hydration. Shared private builder возвращает прежние array keys; recommendation exclusions получают scalar featured snapshot, а full API path остаётся прежним.

**Tech Stack:** PHP 8.5.8, Laravel 13.22.0, Livewire 4.3.3, Blade, SQLite, PHPUnit 12.5.32, Tailwind CSS 4.3.2, Vite 8, Playwright Chromium.

## Global Constraints

- Работать только в существующей ветке `main`; branch/worktree/PR не создавать.
- Не добавлять dependency, migration, route, translation, config/env, cache
  family/key/version/TTL, queue/scheduler или production DML.
- `/api/v1/home` и `CatalogHomePageBuilder::data()` сохраняют полные
  `featuredTitles` и `latestMedia`.
- `webData()` сохраняет прежние array keys, но не гидратирует неиспользуемые
  Blade секции.
- Recommendation exclusions не сокращаются вместе с web hydration.
- Publication, availability, audience, region, Premium, legal,
  authorization, SEO и localized route contracts не меняются.
- Homepage `response_contract=2` остаётся без bump: фактически используемый
  HTML не меняется.
- Shared foreign worktree/index changes не форматировать, не удалять, не
  перезаписывать и не включать в Task 70 commit.
- README/CHANGELOG/current-plan/performance изменять только точными Task 70
  hunks.
- Rollback не требует schema/data/cache cleanup.

---

## File map

- Modify: `tests/Feature/CatalogHomeWebProjectionTest.php` — RED/GREEN
  projection и SQL-shape contract.
- Modify: `app/Services/Catalog/CatalogHomePageBuilder.php` — full/web
  hydration switch.
- Modify: `docs/performance.md` — measured root cause/result.
- Modify: `docs/plans/current-task-plan.md` — Task 70 compliance/evidence.
- Modify: `README.md` — подтверждённый visitor performance result.
- Modify: `CHANGELOG.md` — датированная русская technical entry.
- Create:
  `docs/superpowers/specs/2026-07-26-homepage-api-only-hydration-design.md`.
- Create:
  `docs/superpowers/plans/2026-07-26-homepage-api-only-hydration.md`.

## Protected public contracts

- Named/localized home and `/api/v1/home` routes.
- `CatalogHomeResource` response keys and safe field filtering.
- Full 48-row factual snapshot and current content ordering.
- Existing 12-row web latest projection and full-list link.
- Recommendation candidates, exclusions, ranking and shown-state.
- Existing Blade markup, translations, SEO, mobile/no-JS behavior.
- Cache key/version/TTL/invalidation and warmer behavior.
- Database schema/indexes/data, dependencies, environment and queues.

### Task 1: Add the failing projection and query contracts

**Files:**
- Modify: `tests/Feature/CatalogHomeWebProjectionTest.php`

**Interfaces:**
- Consumes: `CatalogHomePageBuilder::data()`,
  `CatalogHomePageBuilder::webData()`, `CatalogHomeSnapshotCache::refresh()`.
- Produces: expected empty web `featuredTitles`/`latestMedia`, full API
  preservation and bounded query-shape assertions.

- [ ] **Step 1: Extend the existing full-versus-web behavior test**

After building `$full` and `$web`, add:

```php
$this->assertCount(12, $full['featuredTitles']);
$this->assertCount(12, $full['latestMedia']);
$this->assertEmpty($web['featuredTitles']);
$this->assertEmpty($web['latestMedia']);
```

Keep the existing full latest-title/API assertions unchanged.

- [ ] **Step 2: Add a query-shape test**

Build the same 16-title fixture through a private helper, refresh the
snapshot before attaching `DB::listen()`, then call only `webData()`.
Normalize SQL and assert:

```php
$this->assertCount(2, $cardTitleHydrations);
$this->assertEmpty($latestMediaHydrations);

foreach (['genres', 'countries', 'age_ratings', 'translations', 'tags'] as $table) {
    $this->assertCount(2, $taxonomyHydrations[$table] ?? collect());
}
```

The two card groups are latest and video. The fixture excludes all available
titles from recommendations, so a third taxonomy group proves the unused
featured branch still ran.

- [ ] **Step 3: Run RED**

Run:

```bash
php artisan test tests/Feature/CatalogHomeWebProjectionTest.php
```

Expected: FAIL because current `webData()` returns 12 featured and 12 latest
media rows. Query assertions should also expose three card taxonomy groups
and one API latest-media hydration.

### Task 2: Implement the minimal web hydration switch

**Files:**
- Modify: `app/Services/Catalog/CatalogHomePageBuilder.php`

**Interfaces:**
- Consumes: existing scalar homepage snapshot.
- Produces: unchanged `data()` full result and pruned `webData()` internal
  projection.

- [ ] **Step 1: Add an explicit private projection argument**

Use:

```php
public function webData(?User $user = null): array
{
    return $this->buildData(
        $user,
        self::WEB_LATEST_TITLE_LIMIT,
        includeApiOnlySections: false,
    );
}

private function buildData(
    ?User $user,
    ?int $latestTitleLimit = null,
    bool $includeApiOnlySections = true,
): array {
```

`data()` keeps calling `buildData($user)` and therefore preserves the full
projection.

- [ ] **Step 2: Skip only the unused model hydration**

Wrap the existing featured/media builders:

```php
$featuredTitles = $includeApiOnlySections
    ? $this->orderedTitles(/* existing query and IDs */)
    : collect();

$latestMedia = $includeApiOnlySections
    ? $this->orderedMedia(/* existing query */, $snapshot['latest_media_ids'])
    : collect();
```

Do not modify title/media scopes, selected fields or eager loads in full
mode.

- [ ] **Step 3: Preserve recommendation exclusions**

Use full scalar featured IDs only when their models were intentionally
skipped:

```php
$featuredRecommendationIds = $includeApiOnlySections
    ? $featuredTitles->pluck('id')
    : collect($snapshot['featured_title_ids']);

$excludedRecommendationIds = collect($allLatestTitleIds)
    ->concat($featuredRecommendationIds)
    ->concat($videoTitles->pluck('id'))
    ->map(fn (mixed $id): int => (int) $id)
    ->unique()
    ->values()
    ->all();
```

This retains exact full-mode behavior and prevents web presentation pruning
from changing recommendation membership.

- [ ] **Step 4: Run GREEN**

Run:

```bash
php artisan test tests/Feature/CatalogHomeWebProjectionTest.php
```

Expected: both tests PASS; full API assertions remain unchanged.

### Task 3: Verify related contracts and measure the result

**Files:**
- Modify only if a regression is discovered:
  `tests/Feature/CatalogHomeCardCountQueryTest.php`
- Modify only if a regression is discovered:
  `tests/Feature/CatalogHomePerformanceTest.php`

**Interfaces:**
- Consumes: existing homepage/API/cache/recommendation tests.
- Produces: measured before/after evidence without wall-time assertions.

- [ ] **Step 1: Run focused tests**

```bash
php artisan test \
  tests/Feature/CatalogHomeWebProjectionTest.php \
  tests/Feature/CatalogHomeCardCountQueryTest.php \
  tests/Feature/CatalogHomePerformanceTest.php \
  tests/Feature/CatalogHomeContentAdditionTest.php \
  tests/Feature/Api/V1/CatalogHomeApiTest.php \
  tests/Feature/PublicPageResponseCacheTest.php \
  tests/Unit/PublicPageCachePolicyTest.php
```

Expected: PASS.

- [ ] **Step 2: Repeat the five-process builder profile**

Bootstrap Laravel in a fresh PHP process per sample, attach `DB::listen()`,
call `webData()` and record wall time, query count, SQL time and section
counts. Expected semantic counts: 12 latest, 0 featured, 8 video, 0 latest
media, 8 recommendations. Do not encode wall time in PHPUnit.

- [ ] **Step 3: Verify full projection parity**

Call `data()` and confirm its section counts remain 48/12/8/12 on the same
snapshot. Compare `/api/v1/home` keys/counts with the pre-change contract.

- [ ] **Step 4: Run broad related tests**

```bash
php artisan test --filter='CatalogHome|CatalogRecommendation|CatalogDiscovery|PublicPageCache|EagerLoadProjection'
```

Expected: PASS or exact unrelated blocker documented.

### Task 4: Static, browser and documentation gates

**Files:**
- Modify: `docs/performance.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Produces: measured documentation, completed compliance matrix and delivery
  evidence.

- [ ] **Step 1: Format and analyze exact PHP scope**

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

- [ ] **Step 2: Run build/docs/diff checks**

```bash
npm run build
php artisan project:docs-refresh --check
git diff --check
```

Build remains required because the public HTML route is affected even though
no frontend asset is edited. Managed docs foreign drift must not be silently
rewritten.

- [ ] **Step 3: Browser and live HTTP verification**

Check desktop `1440×1200`, mobile `390×844` and throttled mobile `/`.
Capture HTTP status, page-cache state, H1, update/recommendation cards,
HTML bytes, DOM nodes, images, LCP/FCP/CLS, overflow, console/page/request
failures. No cache flush, generation bump or production DML.

- [ ] **Step 4: Update documentation**

Append measured evidence to `docs/performance.md`; add one visitor-facing
README history bullet and one Russian technical CHANGELOG bullet. Complete
Task 70 matrix with `completed`, `already_compliant`, `not_applicable` and
honest `unresolved`.

- [ ] **Step 5: Final requirement and legacy scan**

Re-read applicable canonical requirements and Task 70. Search the repository
for all `webData`, `featuredTitles`, `latestMedia`, homepage builder/API
consumers, old cache dimensions, duplicate services and unfinished markers.
Do not remove text matches without dependency review.

### Task 5: Commit and push exact Task 70 scope

**Files:**
- All files listed in the file map, exact Task 70 hunks only.

- [ ] **Step 1: Verify branch and exact staged manifest**

```bash
git status --short --branch
git diff --cached --check
git diff --cached --name-status
```

Branch must be `main`; foreign shared-tree changes must remain outside the
commit.

- [ ] **Step 2: Commit**

```bash
git commit -m "perf: исключить API-only данные из web главной"
```

- [ ] **Step 3: Push**

```bash
git push origin main
```

If configured remote rejects authentication, record exact failure as
`unresolved`; do not claim successful delivery or bypass hooks.
