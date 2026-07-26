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

- [x] **Step 1: Extend the existing full-versus-web behavior test**

After building `$full` and `$web`, add:

```php
$this->assertCount(12, $full['featuredTitles']);
$this->assertCount(12, $full['latestMedia']);
$this->assertEmpty($web['featuredTitles']);
$this->assertEmpty($web['latestMedia']);
```

Keep the existing full latest-title/API assertions unchanged.

- [x] **Step 2: Add a query-shape test**

Build the same 16-title fixture through a private helper, refresh the
snapshot before attaching `DB::listen()`, then call only `webData()`.
Normalize SQL and assert:

```php
$this->assertCount(2, $cardTitleHydrations);
$this->assertEmpty($latestMediaHydrations);

foreach ([
    'genres' => 'catalog_title_genre',
    'countries' => 'catalog_title_country',
    'age_ratings' => 'age_rating_catalog_title',
    'translations' => 'catalog_title_translation',
    'tags' => 'catalog_title_tag',
] as $table => $pivotTable) {
    $this->assertCount(2, $taxonomyHydrations[$table] ?? collect());
}
```

The two card groups are latest and video. The fixture excludes all available
titles from recommendations, so a third taxonomy group proves the unused
featured branch still ran.

- [x] **Step 3: Run RED**

Run:

```bash
php artisan test tests/Feature/CatalogHomeWebProjectionTest.php
```

Expected: FAIL because current `webData()` returns 12 featured and 12 latest
media rows. Query assertions should also expose three card taxonomy groups
and one API latest-media hydration.

Observed RED: 2 tests failed after 7 assertions; both stopped exactly on the
expected non-empty `web['featuredTitles']` boundary before implementation.
During GREEN, the first query-shape matcher was corrected to use the exact
existing `age_rating_catalog_title` pivot name; this changed only diagnostic
matching, not the expected application behavior.

### Task 2: Implement the minimal web hydration switch

**Files:**
- Modify: `app/Services/Catalog/CatalogHomePageBuilder.php`

**Interfaces:**
- Consumes: existing scalar homepage snapshot.
- Produces: unchanged `data()` full result and pruned `webData()` internal
  projection.

- [x] **Step 1: Add an explicit private projection argument**

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

- [x] **Step 2: Skip only the unused model hydration**

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

- [x] **Step 3: Preserve recommendation exclusions**

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

- [x] **Step 4: Run GREEN**

Run:

```bash
php artisan test tests/Feature/CatalogHomeWebProjectionTest.php
```

Expected: both tests PASS; full API assertions remain unchanged.

Observed GREEN: 2 tests passed with 27 assertions.

### Task 3: Verify related contracts and measure the result

**Files:**
- Modify only if a regression is discovered:
  `tests/Feature/CatalogHomeCardCountQueryTest.php`
- Modify only if a regression is discovered:
  `tests/Feature/CatalogHomePerformanceTest.php`

**Interfaces:**
- Consumes: existing homepage/API/cache/recommendation tests.
- Produces: measured before/after evidence without wall-time assertions.

- [x] **Step 1: Run focused tests**

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

Observed: corrected API owner path
`tests/Feature/Api/V1/CatalogDiscoveryTest.php`; focused matrix passed
37 tests with 297 assertions.

- [x] **Step 2: Repeat the five-process builder profile**

Bootstrap Laravel in a fresh PHP process per sample, attach `DB::listen()`,
call `webData()` and record wall time, query count, SQL time and section
counts. Expected semantic counts: 12 latest, 0 featured, 8 video, 0 latest
media, 8 recommendations. Do not encode wall time in PHPUnit.

Observed stable samples: every web run used 47 queries versus the previous
57. A six-pair same-state comparison gave median web builder `165,91 ms`
versus full `206,64 ms`; median SQL `33,73 ms` versus `65,67 ms`. This is
diagnostic evidence under current shared load, not an SLA.

- [x] **Step 3: Verify full projection parity**

Call `data()` and confirm its section counts remain 48/12/8/12 on the same
snapshot. Compare `/api/v1/home` keys/counts with the pre-change contract.

Observed direct full projection and live API counts `48/12/8/12`; API keys
remain `stats/latest_titles/featured_titles/titles_with_video/latest_releases/
year_buckets/genres/countries/subtitle_tag`.

- [x] **Step 4: Run broad related tests**

```bash
php artisan test --filter='CatalogHome|CatalogRecommendation|CatalogDiscovery|PublicPageCache|EagerLoadProjection'
```

Expected: PASS or exact unrelated blocker documented.

Observed: 173 tests passed with 991 assertions.

### Task 4: Static, browser and documentation gates

**Files:**
- Modify: `docs/performance.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Produces: measured documentation, completed compliance matrix and delivery
  evidence.

- [x] **Step 1: Format and analyze exact PHP scope**

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

Observed: exact Pint and Rector reported no changes; PHPStan completed with
zero errors, and the exact projection test remained GREEN at 2/27.

- [x] **Step 2: Run build/docs/diff checks**

```bash
npm run build
php artisan project:docs-refresh --check
git diff --check
```

Build remains required because the public HTML route is affected even though
no frontend asset is edited. Managed docs foreign drift must not be silently
rewritten.

Observed: Vite built 25 modules in `2,13 s`;
`project:docs-refresh --check` and task-scoped `git diff --check` passed.

- [x] **Step 3: Browser and live HTTP verification**

Check desktop `1440×1200`, mobile `390×844` and throttled mobile `/`.
Capture HTTP status, page-cache state, H1, update/recommendation cards,
HTML bytes, DOM nodes, images, LCP/FCP/CLS, overflow, console/page/request
failures. No cache flush, generation bump or production DML.

Observed: Chromium returned `200`; cold first-byte `395 ms`, HIT first-byte
`49–75 ms`; throttled mobile load `2 999 ms` with FCP/LCP `1 164 ms`.
Counts were 12 latest, 8 recommendations and 12 release groups; no
horizontal overflow or browser/network errors. Mobile CLS was zero; cold
desktop reported CLS `0,782`, recorded as a neighboring unresolved UI
observation because Task 70 does not change rendered HTML.

- [x] **Step 4: Update documentation**

Append measured evidence to `docs/performance.md`; add one visitor-facing
README history bullet and one Russian technical CHANGELOG bullet. Complete
Task 70 matrix with `completed`, `already_compliant`, `not_applicable` and
honest `unresolved`.

Observed: performance owner, visitor history, Russian changelog and current
compliance evidence were updated with exact Task 70 hunks.

- [x] **Step 5: Final requirement and legacy scan**

Re-read applicable canonical requirements and Task 70. Search the repository
for all `webData`, `featuredTitles`, `latestMedia`, homepage builder/API
consumers, old cache dimensions, duplicate services and unfinished markers.
Do not remove text matches without dependency review.

Observed: final canonical reread completed. Repository scan confirms
`webData()` is consumed only by `CatalogHomePage`, neither API-only
collection is read by Blade, `CatalogHomeResource` remains the full
consumer, `response_contract=2` remains unchanged, and no Task 70
TODO/FIXME, duplicate builder or stale cache path remains.

### Task 5: Commit and push exact Task 70 scope

**Files:**
- All files listed in the file map, exact Task 70 hunks only.

- [x] **Step 1: Verify branch and exact staged manifest**

```bash
git status --short --branch
git diff --cached --check
git diff --cached --name-status
```

Branch must be `main`; foreign shared-tree changes must remain outside the
commit.

Observed: normal repository on `main`; alternate index contained exactly
seven Task 70 files, passed cached diff check and excluded the foreign shared
worktree/index state.

- [x] **Step 2: Commit**

```bash
git commit -m "perf: исключить API-only данные из web главной"
```

Observed: exact implementation/documentation commit `4b5a86c` created on
`main`; `git diff-tree` confirms only the seven planned files.

- [x] **Step 3: Push**

```bash
git push origin main
```

If configured remote rejects authentication, record exact failure as
`unresolved`; do not claim successful delivery or bypass hooks.

Observed: ordinary non-force `git push origin main` exited 128 before data
transfer because GitHub HTTPS credentials are unavailable:
`could not read Username for 'https://github.com'`.
