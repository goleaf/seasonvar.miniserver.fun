# Calendar Default Recent View Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `/calendar` show real recent releases by default while preserving a truthful explicit `/calendar/upcoming` view and backward-compatible redirects.

**Architecture:** Keep the existing `ReleaseCalendarPage`, bounded query, visibility and presenter boundaries. Change only route identity and consumers: `calendar.index` owns the recent default, `calendar.upcoming` owns the future window, old recent URLs redirect, and SEO/sitemap use the same bounded public queries as the pages.

**Tech Stack:** PHP 8.5, Laravel 13.20, Livewire full-page components, Eloquent, SQLite, PHPUnit 12.5, Laravel Pint, Tailwind CSS 4.3, Vite 8, Playwright/managed Chromium.

## Global Constraints

- Work only on the existing `main` branch; do not create branches, worktrees or pull requests.
- Preserve the pre-existing unrelated changes in `docs/plans/current-task-plan.md`; never absorb, discard or relabel them without explicit user authorization.
- Do not bypass `.githooks`, use `--no-verify`, or hide the existing `CHANGELOG.md` policy failure. Commit only when the normal hooks pass with an exactly reviewed scope.
- Do not modify `.env`, production data, calendar rows, migrations, dependencies, cache contents, queues or importer state.
- Never derive a release date from `created_at`, `updated_at`, `indexed_at`, `seasons.release_status_text` or another ambiguous technical/provider field.
- Preserve public visibility, audience/availability, premium/region, translation, personal-calendar, authorization and notification boundaries.
- Keep every HTML endpoint as a full-page Livewire component; redirect handlers may remain thin route responders.
- Keep visible interface text in `lang/{ru,en}/calendar.php`; do not add inline CSS, inline business JavaScript or fake public content.
- Apply TDD: add each behavioral test, run it and observe the expected failure before changing production PHP.
- Run `./vendor/bin/pint --dirty --format agent` after PHP changes, focused tests before the full suite, and `npm run build` after route/Blade asset-assumption changes.

---

### Task 1: Lock the canonical and compatibility routes

**Files:**
- Create: `tests/Feature/ReleaseCalendarDefaultViewTest.php`
- Modify: `routes/web.php:110-145`
- Modify: `app/Livewire/ReleaseCalendar/ReleaseCalendarPage.php:225-267`

**Interfaces:**
- Consumes: existing `ReleaseCalendarView::Recent|Upcoming`, `ReleaseCalendarPeriod`, `ReleaseCalendarQuery::entries()` and `public.page:calendar` middleware.
- Produces: `calendar.index`, `calendar.upcoming`, `localized.calendar.index`, `localized.calendar.upcoming`; legacy `calendar.recent` and `localized.calendar.recent` redirect routes.

- [ ] **Step 1: Add the released-entry fixture and failing route tests**

Create `tests/Feature/ReleaseCalendarDefaultViewTest.php` with the following route-focused test class. The helper creates one public playable episode and one truthful portal-publication fact without invoking the media observer:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReleaseDatePrecision;
use App\Enums\ReleaseScheduleEntryType;
use App\Enums\ReleaseScheduleSource;
use App\Enums\ReleaseScheduleStatus;
use App\Models\CatalogTitle;
use App\Models\Episode;
use App\Models\LicensedMedia;
use App\Models\ReleaseScheduleEntry;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReleaseCalendarDefaultViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-19 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_calendar_index_shows_recent_releases_by_default(): void
    {
        $this->createReleasedEntry('Недавний календарный сериал');

        $this->get('/calendar')
            ->assertOk()
            ->assertSeeText('Недавний календарный сериал')
            ->assertSee('<link rel="canonical" href="'.route('calendar.index').'">', false);
    }

    public function test_upcoming_calendar_does_not_mix_in_past_publications(): void
    {
        $this->createReleasedEntry('Только прошедший релиз');

        $this->get('/calendar/upcoming')
            ->assertOk()
            ->assertDontSeeText('Только прошедший релиз')
            ->assertSeeText('В этом периоде релизов нет');
    }

    public function test_old_recent_routes_redirect_permanently_to_the_new_index(): void
    {
        $this->get('/calendar/recent')
            ->assertStatus(301)
            ->assertRedirect(route('calendar.index'));

        $this->get('/en/calendar/recent')
            ->assertStatus(301)
            ->assertRedirect(route('localized.calendar.index', ['locale' => 'en']));
    }

    private function createReleasedEntry(string $titleText): ReleaseScheduleEntry
    {
        $title = CatalogTitle::factory()->create([
            'title' => $titleText,
            'slug' => 'calendar-'.str()->uuid(),
        ]);
        $season = Season::factory()->for($title)->create(['number' => 1]);
        $episode = Episode::factory()->for($season)->create(['number' => 1]);
        $publishedAt = CarbonImmutable::now()->subDay();
        $media = LicensedMedia::withoutEvents(fn (): LicensedMedia => LicensedMedia::factory()->create([
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'status' => 'published',
            'published_at' => $publishedAt,
            'path' => 'licensed/calendar-test.mp4',
        ]));

        return ReleaseScheduleEntry::query()->create([
            'logical_key' => 'portal-publication-test-'.$media->id,
            'entry_type' => ReleaseScheduleEntryType::PortalPublication,
            'status' => ReleaseScheduleStatus::Released,
            'precision' => ReleaseDatePrecision::ExactDateTime,
            'source' => ReleaseScheduleSource::Portal,
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $episode->id,
            'licensed_media_id' => $media->id,
            'season_number' => 1,
            'episode_number' => 1,
            'starts_at' => $publishedAt,
            'original_timezone' => 'UTC',
            'is_public' => true,
            'notifications_enabled' => true,
            'released_at' => $publishedAt,
        ]);
    }
}
```

- [ ] **Step 2: Run the route tests and verify RED**

Run:

```bash
php artisan test tests/Feature/ReleaseCalendarDefaultViewTest.php
```

Expected: FAIL because `calendar.index` and `/calendar/upcoming` do not exist, `/calendar` is still `upcoming`, and `/calendar/recent` is not a redirect.

- [ ] **Step 3: Change canonical, explicit-upcoming and legacy routes**

Replace the public calendar route block in `routes/web.php` with this mapping, retaining the existing day/week/month constraints and middleware:

```php
Route::get('/calendar', ReleaseCalendarPage::class)
    ->defaults('view', 'recent')
    ->middleware('public.page:calendar')
    ->name('calendar.index');
Route::get('/calendar/upcoming', ReleaseCalendarPage::class)
    ->defaults('view', 'upcoming')
    ->middleware('public.page:calendar')
    ->name('calendar.upcoming');
Route::get('/calendar/day/{period}', ReleaseCalendarPage::class)
    ->defaults('view', 'day')
    ->where('period', '\\d{4}-\\d{2}-\\d{2}')
    ->middleware('public.page:calendar')
    ->name('calendar.day');
Route::get('/calendar/week/{period}', ReleaseCalendarPage::class)
    ->defaults('view', 'week')
    ->where('period', '\\d{4}-W\\d{2}')
    ->middleware('public.page:calendar')
    ->name('calendar.week');
Route::get('/calendar/month/{period}', ReleaseCalendarPage::class)
    ->defaults('view', 'month')
    ->where('period', '\\d{4}-\\d{2}')
    ->middleware('public.page:calendar')
    ->name('calendar.month');
Route::permanentRedirect('/calendar/recent', '/calendar')->name('calendar.recent');
```

Inside the existing localized calendar group, use:

```php
Route::get('/calendar', ReleaseCalendarPage::class)->defaults('view', 'recent')->middleware('public.page:calendar')->name('index');
Route::get('/calendar/upcoming', ReleaseCalendarPage::class)->defaults('view', 'upcoming')->middleware('public.page:calendar')->name('upcoming');
Route::get('/calendar/day/{period}', ReleaseCalendarPage::class)->defaults('view', 'day')->where('period', '\\d{4}-\\d{2}-\\d{2}')->middleware('public.page:calendar')->name('day');
Route::get('/calendar/week/{period}', ReleaseCalendarPage::class)->defaults('view', 'week')->where('period', '\\d{4}-W\\d{2}')->middleware('public.page:calendar')->name('week');
Route::get('/calendar/month/{period}', ReleaseCalendarPage::class)->defaults('view', 'month')->where('period', '\\d{4}-\\d{2}')->middleware('public.page:calendar')->name('month');
Route::permanentRedirect('/calendar/recent', '/{locale}/calendar')->name('recent');
```

Verify from the installed Laravel 13 source or project documentation that `RedirectController` substitutes the `locale` route parameter in the redirect destination before relying on the localized test.

- [ ] **Step 4: Point the internal recent tab and canonical presenter at `calendar.index`**

In `ReleaseCalendarPage::calendarUrl()`, change only the `Recent` route-name mapping:

```php
ReleaseCalendarView::Recent => 'calendar.index',
```

In `ReleaseCalendarSeoPresenter::url()`, apply the same mapping:

```php
ReleaseCalendarView::Recent => 'calendar.index',
```

- [ ] **Step 5: Run the focused tests and verify GREEN**

```bash
php artisan test tests/Feature/ReleaseCalendarDefaultViewTest.php
php artisan route:list --path=calendar --except-vendor
```

Expected: all three tests PASS; route list contains canonical index/upcoming plus day/week/month/personal/admin and both recent compatibility redirects.

- [ ] **Step 6: Record the checkpoint without bypassing hooks**

```bash
git diff --check
git status --short --branch
```

Do not commit while unrelated `current-task-plan.md` work is present or the normal changelog hook fails. Preserve this verified diff for the final authorized commit.

---

### Task 2: Make default recent SEO and sitemap truthful

**Files:**
- Modify: `tests/Feature/ReleaseCalendarDefaultViewTest.php`
- Modify: `tests/Feature/SitemapAndRobotsTest.php`
- Modify: `app/Services/ReleaseCalendar/ReleaseCalendarSeoPresenter.php:16-79`
- Modify: `app/Services/ReleaseCalendar/ReleaseCalendarQuery.php:100-108`
- Modify: `app/Services/Catalog/CatalogSitemapResponder.php:132-143`

**Interfaces:**
- Consumes: `ReleaseCalendarPeriod::resolve()`, `ReleaseScheduleVisibility::constrain()` and bounded `Recent`/`Upcoming` windows.
- Produces: `ReleaseCalendarQuery::hasRecent(ReleaseCalendarPeriod $period, string $timezone): bool`; indexable non-empty canonical recent/upcoming pages and matching sitemap URLs.

- [ ] **Step 1: Add failing SEO assertions**

Extend `test_calendar_index_shows_recent_releases_by_default()` with:

```php
->assertSee('<meta name="robots" content="index, follow">', false)
->assertSee('"@type":"ItemList"', false)
->assertSee('<link rel="alternate" hreflang="ru" href="'.route('localized.calendar.index', ['locale' => 'ru']).'">', false)
```

Extend `test_upcoming_calendar_does_not_mix_in_past_publications()` with:

```php
->assertSee('<meta name="robots" content="noindex, follow">', false)
->assertSee('<link rel="canonical" href="'.route('calendar.upcoming').'">', false)
```

- [ ] **Step 2: Add the failing sitemap regression test**

Add this method to `SitemapAndRobotsTest` and move the shared released-entry fixture to a private helper in that class or a small test concern only if both test classes genuinely reuse it:

```php
public function test_static_sitemap_includes_non_empty_default_calendar_without_empty_upcoming(): void
{
    $this->createReleasedCalendarEntry('Календарь в карте сайта');

    $content = $this->get('/sitemap-static.xml')
        ->assertOk()
        ->assertStreamed()
        ->streamedContent();

    $this->assertStringContainsString('<loc>'.route('calendar.index').'</loc>', $content);
    $this->assertStringNotContainsString('<loc>'.route('calendar.upcoming').'</loc>', $content);
}
```

- [ ] **Step 3: Run the focused tests and verify RED**

```bash
php artisan test tests/Feature/ReleaseCalendarDefaultViewTest.php tests/Feature/SitemapAndRobotsTest.php
```

Expected: FAIL because `Recent` is currently always `noindex`, does not build `ItemList`, and sitemap only evaluates the empty upcoming window.

- [ ] **Step 4: Allow non-empty unfiltered recent and upcoming SEO surfaces**

In `ReleaseCalendarSeoPresenter::page()`, replace the eligibility assignment with:

```php
$eligible = in_array($view, [ReleaseCalendarView::Recent, ReleaseCalendarView::Upcoming], true)
    && ! $filtered;
```

Keep the existing `ItemList` de-duplication, 20-item limit, personal `noarchive` and empty-page `noindex` behavior unchanged.

- [ ] **Step 5: Add a bounded recent existence query**

Add this method beside `hasUpcoming()` in `ReleaseCalendarQuery`:

```php
public function hasRecent(ReleaseCalendarPeriod $period, string $timezone): bool
{
    $query = ReleaseScheduleEntry::query();
    $this->visibility->constrain($query, null);
    $this->constrainWindow($query, ReleaseCalendarView::Recent, $period, $timezone);

    return $query->where('status', ReleaseScheduleStatus::Released->value)->exists();
}
```

This method must use the existing indexed bounded range and public visibility query; do not load models or count the entire table.

- [ ] **Step 6: Publish canonical recent and explicit upcoming sitemap URLs independently**

Replace the calendar block in `CatalogSitemapResponder` with:

```php
$calendarTimezone = $this->releaseCalendarTimezone->public();
$recentPeriod = ReleaseCalendarPeriod::resolve(ReleaseCalendarView::Recent, null, $calendarTimezone);
$upcomingPeriod = ReleaseCalendarPeriod::resolve(ReleaseCalendarView::Upcoming, null, $calendarTimezone);

if ($this->releaseCalendarSchema->ready()
    && $this->releaseCalendarQuery->hasRecent($recentPeriod, $calendarTimezone)) {
    $this->writeSitemapUrl(route('calendar.index'), now(), 'daily', '0.7');

    foreach (config('release-calendar.supported_locales', ['ru']) as $locale) {
        $this->writeSitemapUrl(route('localized.calendar.index', ['locale' => $locale]), now(), 'daily', '0.7');
    }
}

if ($this->releaseCalendarSchema->ready()
    && $this->releaseCalendarQuery->hasUpcoming($upcomingPeriod, $calendarTimezone)) {
    $this->writeSitemapUrl(route('calendar.upcoming'), now(), 'daily', '0.7');

    foreach (config('release-calendar.supported_locales', ['ru']) as $locale) {
        $this->writeSitemapUrl(route('localized.calendar.upcoming', ['locale' => $locale]), now(), 'daily', '0.7');
    }
}
```

- [ ] **Step 7: Verify GREEN and query scope**

```bash
php artisan test tests/Feature/ReleaseCalendarDefaultViewTest.php tests/Feature/SitemapAndRobotsTest.php
```

Expected: focused tests PASS. Inspect captured SQL or `EXPLAIN QUERY PLAN` against a disposable/test SQLite if the new `hasRecent()` query does not select the existing public-time index; do not add speculative DDL.

---

### Task 3: Update navigation, notifications and cache route contracts

**Files:**
- Modify: `tests/Feature/ReleaseCalendarDefaultViewTest.php`
- Modify: `tests/Feature/PublicPageResponseCacheTest.php`
- Modify: `app/View/ViewData/AppLayoutData.php:95-103,318-326`
- Modify: `app/Services/ReleaseCalendar/ReleaseCalendarNotificationQuery.php:87-99`

**Interfaces:**
- Consumes: `calendar.index` and existing header/footer link builders, database notifications and public-page cache middleware.
- Produces: global calendar navigation and historical notification URLs that land on useful recent content; both canonical calendar pages remain assigned to `public.page:calendar`.

- [ ] **Step 1: Add failing navigation and notification assertions**

In `ReleaseCalendarDefaultViewTest`, add:

```php
public function test_global_navigation_uses_the_calendar_index(): void
{
    $this->get('/calendar/upcoming')
        ->assertOk()
        ->assertSee('href="'.route('calendar.index').'"', false);
}
```

Add a notification-query test that creates a user, calls `createReleasedEntry()`, stores `ReleaseCalendarActivityNotification` for that entry through `$user->notify(...)`, resolves `ReleaseCalendarNotificationQuery`, and asserts:

```php
$this->assertSame(route('calendar.index'), $notifications->first()?->url);
```

Use `ReleaseCalendarNotificationType::Released`, the entry public UUID/type/status/revision, and the real database notification channel; do not mock the query boundary.

- [ ] **Step 2: Add calendar cache-profile expectations**

Extend the `$expected` route map in `PublicPageResponseCacheTest::test_cacheable_public_routes_have_the_expected_profiles()` with:

```php
'calendar.index' => 'public.page:calendar',
'calendar.upcoming' => 'public.page:calendar',
```

- [ ] **Step 3: Run focused tests and verify RED**

```bash
php artisan test tests/Feature/ReleaseCalendarDefaultViewTest.php tests/Feature/PublicPageResponseCacheTest.php
```

Expected: notification/navigation assertions FAIL because they still use `calendar.upcoming`; cache assertions should expose any missing middleware regression.

- [ ] **Step 4: Point generic navigation and notification destinations to the index**

In both header and footer branches of `AppLayoutData`, replace the route existence/name pair with:

```php
if ($this->router->has('calendar.index')) {
    $layoutHeaderNavigation[] = $this->headerLink(
        'calendar.index',
        'fa-regular fa-calendar-days',
        __('calendar.title'),
        $this->request->routeIs('calendar.*', 'localized.calendar.*'),
    );
}
```

Use the equivalent existing `$this->footerLink(...)` call in the footer block. In `ReleaseCalendarNotificationQuery`, set:

```php
url: route('calendar.index'),
```

Keep `CatalogHomePageBuilder::upcomingUrl` and `CatalogTitleDetail::$releaseCalendarUrl` on `calendar.upcoming`, because those consumers explicitly promise future events.

- [ ] **Step 5: Verify GREEN and search for stale generic links**

```bash
php artisan test tests/Feature/ReleaseCalendarDefaultViewTest.php tests/Feature/PublicPageResponseCacheTest.php
rg -n "calendar\.upcoming|calendar\.index|/calendar/recent" app routes resources tests
```

Expected: focused tests PASS. Every remaining `calendar.upcoming` is semantically future-specific; `calendar.recent` appears only as compatibility route/test/documentation.

---

### Task 4: Update canonical requirements and visitor documentation

**Files:**
- Modify: `docs/release-calendar.md`
- Modify as required by stale statements: `docs/architecture.md`, `docs/frontend.md`, `docs/caching.md`, `docs/performance.md`, `docs/MAINTENANCE_LOG.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`
- Existing task artifacts: `docs/superpowers/specs/2026-07-19-calendar-default-recent-design.md`, `docs/superpowers/plans/2026-07-19-calendar-default-recent.md`

**Interfaces:**
- Consumes: verified route/test/browser evidence from Tasks 1-3.
- Produces: one consistent Russian canonical contract, visitor history and final compliance evidence without changing managed `project-docs` blocks manually.

- [ ] **Step 1: Update the canonical route and SEO rules**

In `docs/release-calendar.md`, replace the old route statements with the verified contract:

```markdown
- `/calendar` — каноническая стартовая страница недавних фактических релизов;
- `/calendar/upcoming` — отдельная страница ближайших подтверждённых событий;
- `/calendar/recent` — постоянное совместимое перенаправление на `/calendar`;
```

Replace the old “only upcoming” SEO rule with the exact non-empty/unfiltered recent and upcoming eligibility implemented in Task 2. Preserve the prohibition on ambiguous dates and the known limitation of zero confirmed `episodes.released_at`.

- [ ] **Step 2: Re-run owner-map searches and update only stale dependent prose**

```bash
rg -n "`?/calendar`?.*ближай|основн.*upcoming|только.*upcoming|calendar\.upcoming|/calendar/recent" README.md docs routes app resources tests
```

Update only statements that now contradict the canonical route/SEO behavior. Do not duplicate the whole release-calendar domain into `AGENTS.md` or `docs/README.md`.

- [ ] **Step 3: Update README visitor-facing text**

Add one concise Russian visitor-history bullet under `## 2026-07-19` explaining that календарь now opens real recent releases and retains a separate nearest-events tab. Keep `История обновлений для посетителей` as the final second-level section and do not edit the managed `project-docs` block manually.

- [ ] **Step 4: Add a separate Russian CHANGELOG entry and resolve the hook honestly**

Add a new bullet under `## 2026-07-19` covering route compatibility, SEO/sitemap and tests. Run:

```bash
bash scripts/check-changelog-policy.sh CHANGELOG.md
```

The current baseline reports an unrelated unquoted English phrase `production-style` at line 10. Do not bypass the hook or silently rewrite historical entries as part of the calendar fix. If the same blocker remains after the new Russian entry, record it as `unresolved` in the compliance matrix and request explicit authorization before a separate historical-policy cleanup.

- [ ] **Step 5: Complete the task plan and documentation checks**

Mark each calendar requirement in `docs/plans/current-task-plan.md` only from actual evidence, preserving the unrelated saved plan content already present in that file. Run:

```bash
php artisan project:docs-refresh --check
bash scripts/check-readme-policy.sh README.md
git diff --check
```

If `project:docs-refresh --check` still reports pre-existing managed-block drift outside files changed by the calendar contract, document it rather than rewriting unrelated managed blocks.

---

### Task 5: Full verification, browser QA and delivery

**Files:**
- Verify all files changed in Tasks 1-4.
- Store ignored QA artifacts under `output/playwright/calendar-default-recent/`.

**Interfaces:**
- Consumes: completed implementation and documentation.
- Produces: test/build/browser evidence, final legacy scan, an exactly scoped `main` commit and configured push attempt when hooks and repository state allow it.

- [ ] **Step 1: Format and run the focused suite**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/ReleaseCalendarDefaultViewTest.php tests/Feature/SitemapAndRobotsTest.php tests/Feature/PublicPageResponseCacheTest.php
```

Expected: Pint succeeds and all focused tests PASS.

- [ ] **Step 2: Run framework and frontend verification**

```bash
php artisan test
php artisan route:list --path=calendar --except-vendor
php artisan config:cache
php artisan route:cache
php artisan view:cache
npm run build
```

Expected: full tests, route/config/view compilation and Vite build succeed. These cache commands compile repository configuration/routes/views only; do not run `cache:clear`, `config:clear`, `route:clear`, `view:clear` or production-state mutation commands.

- [ ] **Step 3: Run local browser QA against a temporary server**

Start `php artisan serve` on an unused loopback port and use project Playwright with managed Chromium to verify:

```text
/calendar
/calendar/upcoming
/calendar/recent
/ru/calendar
/en/calendar
/en/calendar/upcoming
/en/calendar/recent
```

Assert final URLs after redirects, recent cards on index, truthful empty upcoming state, correct active tabs, canonical/robots tags, no console/page/network errors, and `scrollWidth === clientWidth` at 1440×1000 and 390×844. Save desktop/mobile screenshots and a compact JSON report under `output/playwright/calendar-default-recent/`.

- [ ] **Step 4: Re-read requirements and scan legacy implementations**

```bash
rg -n "calendar\.recent|calendar\.upcoming|calendar\.index|/calendar/recent|/calendar/upcoming" . --glob '!vendor/**' --glob '!node_modules/**' --glob '!storage/**' --glob '!output/**'
rg -n "created_at|updated_at|indexed_at|release_status_text" app/Services/ReleaseCalendar app/Observers app/Livewire/ReleaseCalendar
git diff --check
git status --short --branch
```

Classify every match; do not delete a dependency based only on text search. Confirm `main`, no secrets, no production-data diff, and no unrelated file staged.

- [ ] **Step 5: Commit and push only if the repository contract permits it**

```bash
git add \
    routes/web.php \
    app/Livewire/ReleaseCalendar/ReleaseCalendarPage.php \
    app/Services/ReleaseCalendar/ReleaseCalendarSeoPresenter.php \
    app/Services/ReleaseCalendar/ReleaseCalendarQuery.php \
    app/Services/ReleaseCalendar/ReleaseCalendarNotificationQuery.php \
    app/Services/Catalog/CatalogSitemapResponder.php \
    app/View/ViewData/AppLayoutData.php \
    tests/Feature/ReleaseCalendarDefaultViewTest.php \
    tests/Feature/SitemapAndRobotsTest.php \
    tests/Feature/PublicPageResponseCacheTest.php \
    docs/release-calendar.md \
    docs/architecture.md \
    docs/frontend.md \
    docs/caching.md \
    docs/performance.md \
    docs/MAINTENANCE_LOG.md \
    README.md \
    CHANGELOG.md \
    docs/superpowers/specs/2026-07-19-calendar-default-recent-design.md \
    docs/superpowers/plans/2026-07-19-calendar-default-recent.md
git diff --cached --check
git diff --cached --stat
git status --short --branch
git commit -m "Fix calendar default release view"
git push
```

Never stage the unrelated pre-existing portion of `docs/plans/current-task-plan.md` without explicit authorization. If that shared file or the baseline changelog policy prevents an exact clean commit, leave the implementation verified but report delivery as blocked; do not use stash tricks for final delivery, skip hooks, force push or claim success.

- [ ] **Step 6: Final evidence**

Report the root cause, exact route behavior, tests/build/browser results, README review, commit hash/push result or the precise unresolved delivery blocker. Do not claim that future schedules now exist: only the default destination changed to real recent facts.

