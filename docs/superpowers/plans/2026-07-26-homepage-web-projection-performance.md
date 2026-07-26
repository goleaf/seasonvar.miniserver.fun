# Homepage Web Projection Performance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Уменьшить HTML и DOM главной страницы, ограничив только web SSR двенадцатью последними обновлениями, при сохранении полного 48-row API/snapshot contract и неизменной логики рекомендаций.

**Architecture:** `CatalogHomePageBuilder` остаётся единым owner и получает явные public boundaries `data()` для полной API-проекции и `webData()` для ограниченной HTML-проекции. Обе boundary используют один private builder; presentation limit применяется до Eloquent hydration, а recommendation exclusions строятся из полного snapshot. Full-page Livewire использует `webData()`, Blade выводит существующую локализованную ссылку на canonical `recently_updated`, а homepage full-response cache получает новый `response_contract`.

**Tech Stack:** PHP 8.5.8, Laravel 13.22.0, Livewire 4.3.3, Blade, Tailwind CSS 4.3.2, SQLite, PHPUnit 12.5.32, Playwright Chromium.

## Global Constraints

- Работать только в существующей ветке `main`; не создавать branch, worktree или PR.
- Не добавлять dependency, migration, route, environment variable, translation key, queue job или production DML.
- `/api/v1/home` сохраняет полный `latest_titles` contract до 48 элементов и текущую Resource shape.
- Web SSR показывает максимум 12 элементов `latestTitles`; snapshot по-прежнему хранит до 48 factual updates.
- Recommendation exclusions используют все `latest_title_ids` полного snapshot, а не только web subset.
- Существующие publication, availability, audience, region, Premium, legal и authorization boundaries не меняются.
- Cache rollout выполняется новой homepage dimension `response_contract=2` без cache flush или generation bump.
- RU/EN используют существующий ключ `home.actions.view_all`; новый пользовательский текст не добавляется.
- Изменения Task 63/64/65/67 в общем рабочем дереве не редактировать, не форматировать, не индексировать и не коммитить.
- README и CHANGELOG изменяются точными Task 68 hunks; обычный текст остаётся русским.
- Rollback — возврат exact Task 68 commit, штатная пересборка compiled view/config cache и graceful PHP-FPM reload; schema/data/cache cleanup не нужны.

---

## File map

- Create: `tests/Feature/CatalogHomeWebProjectionTest.php` — regression contract для полного API и bounded web SSR.
- Modify: `tests/Unit/PublicPageCachePolicyTest.php` — cache identity contract homepage HTML v2.
- Modify: `app/Services/Catalog/CatalogHomePageBuilder.php` — единая full/web projection boundary.
- Modify: `app/Livewire/CatalogHomePage.php` — перевод только HTML-render path на `webData()`.
- Modify: `resources/views/livewire/catalog-home-page.blade.php` — card marker и canonical «Показать все».
- Modify: `docs/performance.md` — измеренная причина, budgets и выбранная web-проекция.
- Modify: `README.md` — результат для посетителя и датированная история.
- Modify: `CHANGELOG.md` — отдельная техническая запись Task 68.
- Modify: `docs/plans/current-task-plan.md` — execution/compliance evidence.
- Modify: `docs/superpowers/plans/2026-07-26-homepage-web-projection-performance.md` — отмеченные execution steps.
- Existing design: `docs/superpowers/specs/2026-07-26-homepage-web-projection-performance-design.md`.

## Protected public contracts

- Named/localized routes `/`, `/ru`, `/en`, `discover.index` и `/api/v1/home`.
- Full-page Livewire ownership, canonical/SEO metadata и indexable no-JavaScript navigation.
- `CatalogHomeSnapshotCache` schema/key/TTL/invalidation и 48-row factual ordering.
- `CatalogHomeResource` keys, safe-field filtering и API query-count behavior.
- Recommendation type, candidates, exclusions, ranking, shown-state and cache behavior.
- Existing title-card component markup outside the new scoped marker.
- Search, sitemap, importer, media delivery, authentication, administration, translations and database schema.

### Task 1: RED contracts for full API and bounded web projection

**Files:**
- Create: `tests/Feature/CatalogHomeWebProjectionTest.php`

**Interfaces:**
- Consumes: `CatalogHomeSnapshotCache::refresh(): array`, `CatalogHomePageBuilder::data(?User $user = null): array`, route names `home` and `discover.index`.
- Produces: expected `CatalogHomePageBuilder::webData(?User $user = null): array`, `hasMoreLatestTitles: bool`, `recentlyUpdatedUrl: string`, Blade markers `data-home-latest-update-card` and `data-home-latest-updates-all`.

- [x] **Step 1: Write the failing feature test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CatalogRecommendationType;
use App\Models\CatalogTitle;
use App\Models\LicensedMedia;
use App\Services\Catalog\CatalogHomePageBuilder;
use App\Services\Catalog\CatalogHomeSnapshotCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CatalogHomeWebProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_projection_is_bounded_while_api_keeps_the_full_snapshot(): void
    {
        $this->travelTo(now()->setDate(2026, 7, 26)->setTime(12, 0));

        foreach (range(1, 16) as $index) {
            $createdAt = now()->subMinutes($index);
            $title = CatalogTitle::factory()->create([
                'slug' => "homepage-web-projection-{$index}",
                'title' => "Главная web-проекция {$index}",
                'poster_url' => "https://media.example.com/homepage-web-projection-{$index}.jpg",
                'indexed_at' => $createdAt,
            ]);

            LicensedMedia::factory()->create([
                'catalog_title_id' => $title->id,
                'status' => 'published',
                'published_at' => $createdAt,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        $snapshot = app(CatalogHomeSnapshotCache::class)->refresh();
        $builder = app(CatalogHomePageBuilder::class);
        $full = $builder->data();
        $web = $builder->webData();

        $this->assertCount(16, $snapshot['latest_title_ids']);
        $this->assertCount(16, $full['latestTitles']);
        $this->assertCount(12, $web['latestTitles']);
        $this->assertSame(
            collect($full['latestTitles'])->take(12)->pluck('id')->all(),
            collect($web['latestTitles'])->pluck('id')->all(),
        );
        $this->assertTrue($web['hasMoreLatestTitles']);
        $this->assertSame(
            route('discover.index', ['type' => CatalogRecommendationType::RecentlyUpdated->value]),
            $web['recentlyUpdatedUrl'],
        );
        $this->assertEmpty($web['homeRecommendationItems']);

        $webResponse = $this->get(route('home'))->assertOk();
        $webHtml = $webResponse->getContent();

        $this->assertSame(12, substr_count($webHtml, 'data-home-latest-update-card'));
        $webResponse
            ->assertSee('data-home-latest-updates-all', false)
            ->assertSee($web['recentlyUpdatedUrl'], false)
            ->assertSeeText(__('home.actions.view_all'));

        $this->getJson('/api/v1/home')
            ->assertOk()
            ->assertJsonCount(16, 'data.latest_titles');
    }
}
```

- [x] **Step 2: Run RED and verify the missing boundary**

Run:

```bash
php artisan test tests/Feature/CatalogHomeWebProjectionTest.php
```

Expected: FAIL because `CatalogHomePageBuilder::webData()` does not exist. The fixture and full `data()`/snapshot assertions must reach that exact missing-method boundary without an unrelated setup error.

- [x] **Step 3: Record RED evidence in the current plan**

Add the exact failing test count/assertion and missing-method message under Task 68 in `docs/plans/current-task-plan.md`; do not mark implementation completed.

### Task 2: Implement one builder with separate full and web projections

**Files:**
- Modify: `app/Services/Catalog/CatalogHomePageBuilder.php`
- Modify: `app/Livewire/CatalogHomePage.php`

**Interfaces:**
- Consumes: full `CatalogHomeSnapshotCache::snapshot()` arrays and existing `CatalogRecommendationType::RecentlyUpdated`.
- Produces: `data(?User $user = null): array` with the unchanged full projection; `webData(?User $user = null): array` with at most 12 latest titles; shared array keys `hasMoreLatestTitles` and `recentlyUpdatedUrl`.

- [x] **Step 1: Add the explicit projection boundary**

In `CatalogHomePageBuilder`, add:

```php
private const WEB_LATEST_TITLE_LIMIT = 12;

/**
 * @return array<string, mixed>
 */
public function data(?User $user = null): array
{
    return $this->buildData($user);
}

/**
 * @return array<string, mixed>
 */
public function webData(?User $user = null): array
{
    return $this->buildData($user, self::WEB_LATEST_TITLE_LIMIT);
}

```

Rename the current `data()` implementation to
`private function buildData(?User $user, ?int $latestTitleLimit = null): array`
without deleting any part of its body. Keep the two public wrappers above
that method. Immediately after the existing `$snapshot =
$this->snapshot->snapshot();` line, derive the hydration IDs:

```php
$allLatestTitleIds = $snapshot['latest_title_ids'];
$latestTitleIds = $latestTitleLimit === null
    ? $allLatestTitleIds
    : array_slice($allLatestTitleIds, 0, $latestTitleLimit);
```

Pass `$latestTitleIds` to `orderedTitles()` and retain the full scalar snapshot for exclusions:

```php
$excludedRecommendationIds = collect($allLatestTitleIds)
    ->concat($featuredTitles->pluck('id'))
    ->concat($videoTitles->pluck('id'))
    ->map(fn (mixed $id): int => (int) $id)
    ->unique()
    ->values()
    ->all();
```

Add these returned values:

```php
'hasMoreLatestTitles' => count($allLatestTitleIds) > $latestTitles->count(),
'recentlyUpdatedUrl' => $this->discoveryUrl(CatalogRecommendationType::RecentlyUpdated),
```

Do not alter snapshot refresh, full `latest_title_updates`, content availability, recommendation type or API controller/resource.

- [x] **Step 2: Route only full-page Livewire through webData**

Change the render boundary in `app/Livewire/CatalogHomePage.php`:

```php
$data = $page->webData($viewer instanceof User ? $viewer : null);
```

Keep `CatalogHomeController` on `$home->data()` so `/api/v1/home` remains full.

- [x] **Step 3: Run the feature test to reach the next expected RED**

Run:

```bash
php artisan test tests/Feature/CatalogHomeWebProjectionTest.php
```

Expected: builder count/order/API assertions pass; test still FAILS because the Blade card/link markers are absent.

### Task 3: Render the bounded list with a canonical full-list link

**Files:**
- Modify: `resources/views/livewire/catalog-home-page.blade.php`

**Interfaces:**
- Consumes: `$latestByDate`, `$hasMoreLatestTitles`, `$recentlyUpdatedUrl`, existing `home.actions.view_all`.
- Produces: exactly one `data-home-latest-update-card` per rendered update card and one `data-home-latest-updates-all` link only when additional snapshot entries exist.

- [x] **Step 1: Mark only latest-update cards**

Inside the latest-updates loop, pass the scoped attribute through the existing component attribute bag:

```blade
<x-catalog.title-card
    :title="$catalogTitle"
    layout="list"
    :show-description="false"
    data-home-latest-update-card="{{ $catalogTitle->id }}"
/>
```

Do not add this marker to recommendations, latest media or watch-now cards.

- [x] **Step 2: Add the existing translated full-list action**

After `data-home-latest-updates-list`, but inside the same panel, add:

```blade
@if ($hasMoreLatestTitles)
    <div class="border-t border-slate-200 bg-slate-50 px-4 py-3 text-right">
        <a
            href="{{ $recentlyUpdatedUrl }}"
            data-home-latest-updates-all
            class="inline-flex min-h-11 items-center gap-2 rounded-control px-3 py-2 text-sm font-bold text-emerald-700 hover:bg-emerald-100"
        >
            <span>{{ __('home.actions.view_all') }}</span>
            <x-ui.icon name="fa-solid fa-arrow-right" />
        </a>
    </div>
@endif
```

- [x] **Step 3: Run GREEN for web/API projection**

Run:

```bash
php artisan test tests/Feature/CatalogHomeWebProjectionTest.php
```

Expected: PASS with 16 full API titles, 12 ordered web titles, 12 scoped markers and the canonical full-list link.

### Task 4: Version the homepage HTML response contract

**Files:**
- Modify: `tests/Unit/PublicPageCachePolicyTest.php`
- Modify: `app/Support/Cache/PublicPageCachePolicy.php`

**Interfaces:**
- Consumes: `PublicPageCachePolicy::context(Request $request, string $profile): ?PublicPageCacheContext`.
- Produces: homepage cache dimension `response_contract => 2`; other profile dimensions remain unchanged.

- [x] **Step 1: Add the RED assertion**

In `test_it_maps_profiles_to_bounded_canonical_contexts()` add:

```php
$this->assertSame(2, $homepage->dimensions['response_contract']);
```

- [x] **Step 2: Run the focused cache-policy RED**

Run:

```bash
php artisan test tests/Unit/PublicPageCachePolicyTest.php --filter=test_it_maps_profiles_to_bounded_canonical_contexts
```

Expected: FAIL because `response_contract` is absent for the homepage profile.

- [x] **Step 3: Add the homepage contract dimension**

Change the existing homepage branch to:

```php
if ($profile === 'homepage') {
    $dimensions['translations'] = $this->homepageTranslationFingerprint();
    $dimensions['response_contract'] = 2;
}
```

Do not change cache domain, version registry, TTL, canonical origin, query normalization or invalidation.

- [x] **Step 4: Run GREEN**

Run:

```bash
php artisan test tests/Unit/PublicPageCachePolicyTest.php
```

Expected: all `PublicPageCachePolicyTest` tests pass.

### Task 5: Focused regression, static checks and measured browser budgets

**Files:**
- Modify: `docs/plans/current-task-plan.md`
- Modify: `docs/superpowers/plans/2026-07-26-homepage-web-projection-performance.md`

**Interfaces:**
- Consumes: implemented web projection, existing homepage/API/cache test suites and deployed local HTTPS origin.
- Produces: exact GREEN counts plus server/browser performance evidence without cache flush or production DML.

- [x] **Step 1: Format only Task 68 PHP files**

Run:

```bash
./vendor/bin/pint app/Services/Catalog/CatalogHomePageBuilder.php app/Livewire/CatalogHomePage.php app/Support/Cache/PublicPageCachePolicy.php tests/Feature/CatalogHomeWebProjectionTest.php tests/Unit/PublicPageCachePolicyTest.php --format agent
```

Expected: PASS. Inspect exact diffs because `tests/Unit/PublicPageCachePolicyTest.php` is an existing shared file.

- [x] **Step 2: Run the focused matrix**

Run:

```bash
php artisan test \
  tests/Feature/CatalogHomeWebProjectionTest.php \
  tests/Feature/CatalogHomeContentAdditionTest.php \
  tests/Feature/CatalogHomeCardCountQueryTest.php \
  tests/Feature/CatalogVisualSystemTest.php \
  tests/Feature/Api/V1/CatalogDiscoveryTest.php \
  tests/Unit/PublicPageCachePolicyTest.php
```

Expected: all tests pass with no changed API shape, content grouping, card-count query or cache-policy regressions.

- [x] **Step 3: Run relevant broad tests**

Run:

```bash
php artisan test --filter='CatalogHome|CatalogDiscovery|PublicPageCache|Recommendation'
```

Expected: all relevant tests pass. If the accumulated full process reaches the documented 256M test-runner limit, rerun the exact failing class separately and record both results honestly.

- [x] **Step 4: Run static verification**

Run:

```bash
./vendor/bin/phpstan analyse --memory-limit=1G app/Services/Catalog/CatalogHomePageBuilder.php app/Livewire/CatalogHomePage.php app/Support/Cache/PublicPageCachePolicy.php
./vendor/bin/rector process --dry-run app/Services/Catalog/CatalogHomePageBuilder.php app/Livewire/CatalogHomePage.php app/Support/Cache/PublicPageCachePolicy.php
npm run build
git diff --check
```

Expected: Larastan 0 errors, Rector no changes, Vite build succeeds, diff check succeeds.

- [x] **Step 5: Measure live response without destructive cache operations**

Request the normal homepage and a unique bypass query only for read-only measurement. Record:

- HTTP status and `X-Cache`;
- compressed transfer bytes and uncompressed HTML bytes;
- exact `data-home-latest-update-card` count;
- DOM nodes, poster count and document height;
- desktop 1440×1200 and mobile 390×844 FCP/LCP/DOMContentLoaded;
- horizontal overflow, console/page/request failures.

Acceptance budgets:

```text
web latest-update cards = 12
uncompressed homepage HTML <= 500000 bytes
DOM nodes <= 3000
mobile throttled LCP <= 2500 ms
normal cache HIT total <= 500 ms
horizontal overflow = false
console/page/request failures = 0
```

Use the project Playwright QA workflow with managed Chromium if the project CLI wrapper still points to a missing browser. Do not run `cache:clear`, `optimize:clear`, generation bumps, queue mutations or DML.

### Task 6: Documentation, compliance, exact commit and push

**Files:**
- Modify: `docs/performance.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `docs/superpowers/plans/2026-07-26-homepage-web-projection-performance.md`
- Existing: `docs/superpowers/specs/2026-07-26-homepage-web-projection-performance-design.md`

**Interfaces:**
- Consumes: verified code/test/browser evidence.
- Produces: durable Russian documentation, completed Task 68 compliance matrix, one exact commit in `main`, configured push result.

- [x] **Step 1: Update documentation with measured facts**

Add to `docs/performance.md`:

- pre-change HTML/DOM/card evidence;
- full API versus 12-row web projection;
- full-snapshot recommendation exclusion;
- `response_contract=2`;
- post-change bytes/DOM/browser timings and budgets;
- code-only rollback and no-flush rollout.

Add a visitor-facing README note that the homepage now renders a compact current-update overview with a full-list link and avoids loading dozens of duplicate card trees. Keep `История обновлений для посетителей` as the last H2.

Add a dated Russian CHANGELOG bullet naming `CatalogHomePageBuilder::webData()`, full `/api/v1/home` compatibility and cache contract v2.

- [x] **Step 2: Re-read canonical requirements and scan repository**

Re-read:

```text
AGENTS.md
docs/requirements/index.md
docs/architecture.md
docs/performance.md
docs/caching.md
docs/frontend.md
docs/UI_STANDARDS.md
docs/requirements/maintenance-and-upgrades.md
docs/requirements/production-operations.md
docs/requirements/system-wide-integration.md
README.md
CHANGELOG.md
```

Search for duplicate/legacy homepage builders, calls to `CatalogHomePageBuilder::data()`, old latest-update markup, cache contract dimensions, routes, translations and unfinished markers. Classify dependencies before removing anything; no removal is planned.

- [x] **Step 3: Complete the compliance matrix**

For every applicable domain use only `completed`, `already_compliant`, `not_applicable` or `unresolved`, with exact test/file/browser evidence. Mark physical-device validation `unresolved_external` unless performed on real hardware.

- [x] **Step 4: Verify exact Task 68 diff and stage only owned hunks**

Run:

```bash
git status --short --branch
git diff -- app/Services/Catalog/CatalogHomePageBuilder.php app/Livewire/CatalogHomePage.php app/Support/Cache/PublicPageCachePolicy.php resources/views/livewire/catalog-home-page.blade.php tests/Feature/CatalogHomeWebProjectionTest.php tests/Unit/PublicPageCachePolicyTest.php docs/superpowers/specs/2026-07-26-homepage-web-projection-performance-design.md docs/superpowers/plans/2026-07-26-homepage-web-projection-performance.md docs/performance.md docs/plans/current-task-plan.md README.md CHANGELOG.md
git diff --cached --check
```

Use patch staging for shared dirty files. Confirm current branch is `main`; do not stage any Task 63/64/65/67 file or foreign hunk.

- [x] **Step 5: Commit and push**

Create one exact commit:

```bash
git commit -m "perf: сократить web-проекцию главной"
git push
```

Expected: commit succeeds on `main`. If configured HTTPS authentication rejects push, record `unresolved_push_authentication` with the exact non-secret failure and do not claim a successful push.

- [x] **Step 6: Final handoff**

Report the outcome first: before/after bytes, DOM, card count, HIT and throttled mobile LCP. Include commit hash, push result, test/static/build summary and a requirement-by-requirement compliance report with clickable evidence paths.

## Delivery evidence

- Exact product/docs commit: `c59fac2` (`perf: сократить web-проекцию
  главной`), 12 Task 68 files, 926 insertions and 7 deletions.
- Configured `git push origin main` was attempted without force and rejected
  before data transfer: `fatal: could not read Username for
  'https://github.com': No such device or address`.
- The shared worktree retains unrelated Task 67 changes; no reset, stash,
  checkout, broad formatting or foreign staging was used.
