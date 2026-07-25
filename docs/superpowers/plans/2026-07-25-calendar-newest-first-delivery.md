# Calendar Newest-First Delivery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Надёжно закрепить в Git и production clean-URL поведение `/calendar`, при котором новые даты выводятся раньше старых, не ломая явный `sort=earliest` и остальные представления календаря.

**Architecture:** `ReleaseCalendarPage` разрешает пустое Livewire URL-state в route-specific enum default (`Recent => Latest`, остальные views => `Earliest`), а существующий `ReleaseCalendarQuery` сортирует записи до именованной пагинации. Blade получает только effective sort и подготовленные группы; Task 47 переиспользует реализацию и RED/GREEN provenance Task 38, добавляет недостающий regression/пагинационный browser gate и выполняет отдельную Git/production delivery boundary.

**Tech Stack:** PHP 8.5, Laravel 13.22.0, Livewire 4.3.3, SQLite, PHPUnit 12.5.32, Blade, Tailwind CSS 4.3.2, Vite 8, Playwright.

**Execution status:** Tasks 1–3 and Task 4 steps 1–6 are implemented and
verified. The managed-docs freshness gate and canonical Git delivery remain
`unresolved_shared_worktree`: refresh would inventory an unrelated untracked
Task 48 migration, while the required hook rejects the numerous foreign
tracked/untracked changes. Hooks are not bypassed.

## Global Constraints

- Работать только в существующей ветке `main`; не создавать branch, worktree или PR.
- Не сбрасывать, не stash-ить, не stage-ить и не поглощать foreign dirty changes.
- Сохранять `ReleaseCalendarQuery` единственной query boundary и сортировать до `paginate()`.
- Чистый `/calendar` использует `Latest`; явный `earliest|latest|title` остаётся allowlisted.
- Upcoming/day/week/month/personal по умолчанию сохраняют `Earliest`.
- Не добавлять migrations, DML, routes, permissions, translation keys, cache keys, dependencies, workers, queues, environment или service-worker behavior.
- В Blade запрещены `reverse()`, `@php`, database/service calls, inline CSS и business JavaScript.
- Видимый текст остаётся русским; существующая RU/EN key parity не меняется.
- `README.md` изменяется только при новом фактическом visitor/product state; уже существующую запись newest-first не дублировать.
- Кодовый commit обязан включать осмысленный русский `CHANGELOG.md`, пройти hooks и быть отправлен обычным fast-forward push из `main`.
- Если canonical clean-tree hook нельзя пройти без foreign files, delivery фиксируется `unresolved_shared_worktree`, а hook не обходится.

---

### Task 1: Freeze Task 38 Ownership and Add the Missing Server Regression

**Files:**

- Modify: `tests/Feature/ReleaseCalendarDefaultViewTest.php`
- Inspect only: `app/Services/ReleaseCalendar/ReleaseCalendarQuery.php`
- Inspect only: `app/Enums/ReleaseCalendarSort.php`
- Inspect only: `routes/web.php`

**Interfaces:**

- Consumes: `ReleaseCalendarSort::{Earliest,Latest,Title}`,
  `ReleaseCalendarPage::render()` and route binding
  `calendar.index|calendar.upcoming`.
- Produces: server regressions proving invalid Recent sort falls back to
  `Latest` while Upcoming keeps `Earliest`.
- Preserves: public visibility, exact date formatting, route names,
  `calendarPage`, SEO and existing `createReleasedEntry()` helper.

- [x] **Step 1: Verify branch, exact ownership and the clean-HEAD drift**

Run:

```bash
git status --short --branch
git diff -- app/Livewire/ReleaseCalendar/ReleaseCalendarPage.php \
  resources/views/livewire/release-calendar/release-calendar-page.blade.php \
  tests/Feature/ReleaseCalendarDefaultViewTest.php \
  tests/browser/prepare-fixtures.php \
  tests/browser/release-calendar.spec.js
git show HEAD:app/Livewire/ReleaseCalendar/ReleaseCalendarPage.php \
  | rg -n "public string \\$sort|defaultSort|resolvedSort|changeSort"
```

Expected:

- active branch is `main`;
- Task 38 paths show only the already reviewed calendar diff plus any changes
  explicitly listed by this plan;
- `HEAD` still exposes the previous static `earliest` default and has no
  dynamic helper;
- any overlapping unknown hunk stops Task 47 as
  `unresolved_shared_worktree`; do not reset or stage it.

- [x] **Step 2: Add a focused regression for invalid Recent input and Upcoming default**

Add these methods to `ReleaseCalendarDefaultViewTest`:

```php
public function test_invalid_recent_sort_falls_back_to_latest(): void
{
    $this->createReleasedEntry(
        'Старый релиз при неверной сортировке',
        CarbonImmutable::parse('2026-05-28 12:00:00 UTC'),
    );
    $this->createReleasedEntry(
        'Новый релиз при неверной сортировке',
        CarbonImmutable::parse('2026-05-29 12:00:00 UTC'),
    );

    $this->get('/calendar?sort=invalid')
        ->assertOk()
        ->assertSeeInOrder([
            'Новый релиз при неверной сортировке',
            'Старый релиз при неверной сортировке',
        ]);
}

public function test_upcoming_calendar_keeps_the_earliest_default(): void
{
    $this->createScheduledEntry(
        'Ближайший подтверждённый релиз',
        CarbonImmutable::parse('2026-07-20 12:00:00 UTC'),
    );
    $this->createScheduledEntry(
        'Поздний подтверждённый релиз',
        CarbonImmutable::parse('2026-07-21 12:00:00 UTC'),
    );

    $this->get('/calendar/upcoming')
        ->assertOk()
        ->assertSeeInOrder([
            'Ближайший подтверждённый релиз',
            'Поздний подтверждённый релиз',
        ]);
}
```

Add the explicit helper below `createReleasedEntry()`:

```php
private function createScheduledEntry(
    string $titleText,
    CarbonImmutable $startsAt,
): ReleaseScheduleEntry {
    $title = CatalogTitle::factory()->create([
        'title' => $titleText,
        'slug' => 'calendar-scheduled-'.str()->uuid(),
    ]);
    $season = Season::factory()->for($title)->create(['number' => 1]);
    $episode = Episode::factory()->for($season)->create(['number' => 1]);

    return ReleaseScheduleEntry::query()->create([
        'logical_key' => 'episode-release-test-'.$episode->id,
        'entry_type' => ReleaseScheduleEntryType::EpisodeRelease,
        'status' => ReleaseScheduleStatus::Confirmed,
        'precision' => ReleaseDatePrecision::ExactDateTime,
        'source' => ReleaseScheduleSource::Official,
        'catalog_title_id' => $title->id,
        'season_id' => $season->id,
        'episode_id' => $episode->id,
        'season_number' => 1,
        'episode_number' => 1,
        'starts_at' => $startsAt,
        'original_timezone' => 'UTC',
        'is_public' => true,
        'notifications_enabled' => false,
    ]);
}
```

- [x] **Step 3: Run the server regression**

Run:

```bash
php artisan test --filter=ReleaseCalendarDefaultViewTest
```

Expected:

- on the approved Task 38 working tree: PASS;
- the invalid-sort assertion is RED against the clean `HEAD` implementation,
  because clean `HEAD` resolves invalid Recent sort to `Earliest`;
- do not destructively revert the shared worktree merely to reproduce that
  already documented RED.

- [x] **Step 4: Confirm the query boundary needs no modification**

Run:

```bash
rg -n "private function sort|COALESCE\\(starts_at, date_value\\)|orderBy\\('id'" \
  app/Services/ReleaseCalendar/ReleaseCalendarQuery.php
rg -n "reverse\\(|array_reverse|sortByDesc|orderByRaw" \
  resources/views/livewire/release-calendar \
  app/Livewire/ReleaseCalendar
```

Expected:

- `ReleaseCalendarQuery::sort()` already owns `asc|desc` and deterministic
  `id`;
- no presentation reversal exists;
- do not modify the query service without a new failing assertion proving a
  separate defect.

- [x] **Step 5: Format the changed PHP test**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=ReleaseCalendarDefaultViewTest
```

Expected: Pint succeeds and the complete focused class passes.

---

### Task 2: Reconcile the Dynamic Livewire Default and Effective Select

**Files:**

- Modify: `app/Livewire/ReleaseCalendar/ReleaseCalendarPage.php`
- Modify: `resources/views/livewire/release-calendar/release-calendar-page.blade.php`
- Test: `tests/Feature/ReleaseCalendarDefaultViewTest.php`

**Interfaces:**

- Consumes: `ReleaseCalendarView`, `ReleaseCalendarSort`,
  `ReleaseCalendarQuery::entries(...)` and named paginator `calendarPage`.
- Produces: `defaultSort(): ReleaseCalendarSort`,
  `resolvedSort(): ReleaseCalendarSort`,
  `changeSort(string $sort): void` and Blade variable
  `effectiveSort: string`.
- Preserves: `type`, `status`, `title`, locale, period, subscriptions,
  `ReleaseCalendarSeoPresenter`, error states and all non-Recent defaults.

- [x] **Step 1: Run the existing chronology contract before touching implementation**

Run:

```bash
php artisan test \
  --filter=test_calendar_index_shows_the_newest_recent_date_first_but_honors_an_explicit_earliest_sort
```

Expected:

- PASS if the approved Task 38 working-tree implementation is intact;
- FAIL with `28 мая` before `29 мая` if the implementation is absent;
- never replace a passing equivalent implementation solely to match formatting
  in this plan.

- [x] **Step 2: Reconcile the URL property and sort actions**

Ensure the component contains exactly this contract:

```php
#[Url(history: true, except: '')]
public string $sort = '';

public function clearFilters(): void
{
    $this->reset('type', 'status', 'catalogTitle');
    $this->sort = '';
    $this->resetPage(pageName: 'calendarPage');
}

public function changeSort(string $sort): void
{
    $resolved = ReleaseCalendarSort::tryFrom($sort) ?? $this->defaultSort();
    $this->sort = $resolved === $this->defaultSort() ? '' : $resolved->value;
    $this->resetPage(pageName: 'calendarPage');
}
```

Do not add a second public property, session value, cookie or JavaScript
sorting state.

- [x] **Step 3: Reconcile normalization and effective sort**

Ensure `normalize()` and its helpers contain:

```php
$sort = ReleaseCalendarSort::tryFrom($this->sort);
$this->sort = $sort instanceof ReleaseCalendarSort && $sort !== $this->defaultSort()
    ? $sort->value
    : '';
```

```php
private function defaultSort(): ReleaseCalendarSort
{
    return ReleaseCalendarView::from($this->view) === ReleaseCalendarView::Recent
        ? ReleaseCalendarSort::Latest
        : ReleaseCalendarSort::Earliest;
}

private function resolvedSort(): ReleaseCalendarSort
{
    return ReleaseCalendarSort::tryFrom($this->sort) ?? $this->defaultSort();
}
```

In `render()`, resolve once and pass the same enum to the query and the same
value to Blade:

```php
$effectiveSort = $this->resolvedSort();
```

```php
$entries = $query->entries(
    $user,
    $calendarView,
    $period,
    ReleaseScheduleEntryType::tryFrom($this->type),
    ReleaseScheduleStatus::tryFrom($this->status),
    $effectiveSort,
    $locale,
    $timezone,
    $this->selectedCatalogTitleId(),
);
```

```php
'effectiveSort' => $effectiveSort->value,
```

The filtered SEO state compares `$this->sort !== ''`; it must not compare to
the old literal `earliest`.

- [x] **Step 4: Reconcile the Blade select without presentation sorting**

Use one Livewire action and one effective selected option:

```blade
<select
    wire:change="changeSort($event.target.value)"
    class="mt-2 min-h-11 w-full rounded-control border border-slate-300 bg-white px-3 py-2"
>
    @foreach ($sortOptions as $option)
        <option
            value="{{ $option['value'] }}"
            @selected($effectiveSort === $option['value'])
        >
            {{ $option['label'] }}
        </option>
    @endforeach
</select>
```

Use `$sort !== ''` for the clear-filter button and include `changeSort` in
the existing loading target. Do not add `reverse()`, `@php` or inline script.

- [x] **Step 5: Run focused GREEN and static UI contracts**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=ReleaseCalendarDefaultViewTest
php artisan test --filter=ReleaseCalendar
rg -n "wire:change=\"changeSort|effectiveSort|defaultSort|resolvedSort" \
  app/Livewire/ReleaseCalendar/ReleaseCalendarPage.php \
  resources/views/livewire/release-calendar/release-calendar-page.blade.php
```

Expected: all calendar tests pass; there is one dynamic default implementation
and one sort control.

---

### Task 3: Prove Livewire Sorting and Page Reset in Desktop and Mobile Browsers

**Files:**

- Modify: `tests/browser/prepare-fixtures.php`
- Modify: `tests/browser/release-calendar.spec.js`
- Test: `resources/views/livewire/release-calendar/release-calendar-page.blade.php`

**Interfaces:**

- Consumes: `calendarPage`, `changeSort()`, `data-pagination-region`,
  deterministic `ReleaseScheduleEntry` rows and compiled local assets.
- Produces: browser evidence for clean Latest, explicit Earliest, default URL
  cleanup and paginator reset on Desktop/Mobile Chromium.
- Preserves: production database, importer fixtures, player fixtures and all
  external media boundaries.

- [x] **Step 1: Expand only the browser calendar fixture beyond one page**

Replace the two-row calendar `collect([...])->each(...)` block with:

```php
collect(range(1, 26))->each(
    function (int $offset) use (
        $title,
        $season,
        $episode,
        $media,
        $nextEpisode,
        $nextMedia,
    ): void {
        $useNext = $offset % 2 === 0;
        $entryEpisode = $useNext ? $nextEpisode : $episode;
        $entryMedia = $useNext ? $nextMedia : $media;
        $startsAt = now()->subDays($offset)->startOfHour();

        ReleaseScheduleEntry::query()->create([
            'logical_key' => 'browser-calendar-'.$offset,
            'entry_type' => ReleaseScheduleEntryType::PortalPublication,
            'status' => ReleaseScheduleStatus::Released,
            'precision' => ReleaseDatePrecision::ExactDateTime,
            'source' => ReleaseScheduleSource::Portal,
            'catalog_title_id' => $title->id,
            'season_id' => $season->id,
            'episode_id' => $entryEpisode->id,
            'licensed_media_id' => $entryMedia->id,
            'season_number' => $season->number,
            'episode_number' => $entryEpisode->number,
            'starts_at' => $startsAt,
            'released_at' => $startsAt,
            'original_timezone' => 'UTC',
            'is_public' => true,
            'notifications_enabled' => false,
        ]);
    },
);
```

This modifies only the dedicated Playwright database. Do not create production
rows or trigger importer observers.

- [x] **Step 2: Add the paginator-reset assertion to the browser spec**

After the initial clean `/calendar` Latest checks, add:

```javascript
const nextPage = page.locator(
    '[data-pagination-region="release-calendar-results"] a[rel="next"]',
);

await expect(nextPage).toBeVisible();
await nextPage.click();
await expect.poll(() => new URL(page.url()).searchParams.get('calendarPage')).toBe('2');
```

Immediately after `await sort.selectOption('earliest');`, add:

```javascript
await expect.poll(() => new URL(page.url()).searchParams.has('calendarPage')).toBe(false);
```

Keep the existing monotonic timestamp checks and final return to `latest`.
Add overflow and failed local asset evidence:

```javascript
expect(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)).toBeLessThanOrEqual(1);
expect(pageErrors).toEqual([]);
```

The project-wide Playwright reporter remains responsible for request failures;
do not download or inspect video bodies in this calendar test.

- [x] **Step 3: Run the browser RED/GREEN cycle**

Run:

```bash
npx playwright test tests/browser/release-calendar.spec.js \
  --project="Desktop Chromium" --project="Mobile Chromium"
```

Expected:

- RED before the fixture expansion or page-reset fix because a next page is
  unavailable or `calendarPage=2` survives the sort change;
- GREEN after the deterministic fixture and existing named reset are aligned;
- both projects show monotonic Latest/Earliest order, clean default URL, no
  page errors and no horizontal overflow.

- [x] **Step 4: Build frontend assets**

Run:

```bash
npm run build
```

Expected: Vite succeeds with no new calendar bundle or remote asset.

- [x] **Step 5: Perform a read-only production smoke without fixed page numbers**

Open:

```text
https://seasonvar.miniserver.fun/calendar
https://seasonvar.miniserver.fun/calendar?sort=earliest
```

For the clean route, assert:

```javascript
const groups = [...document.querySelectorAll('h2[id^="release-group-"]')]
    .map((element) => element.textContent.trim());
const timestamps = [...document.querySelectorAll(
    '[data-pagination-region="release-calendar-results"] time[datetime]',
)].map((element) => Date.parse(element.getAttribute('datetime')));

({
    groups,
    descending: timestamps.every(
        (time, index) => index === 0 || timestamps[index - 1] >= time,
    ),
});
```

Expected: `descending === true`. Navigate read-only through pagination until
two different adjacent groups are present and confirm the newer group appears
first; never hard-code a production page number.

---

### Task 4: Update Owners, Register Task 47, Verify and Deliver

**Files:**

- Modify: `docs/release-calendar.md`
- Modify: `docs/frontend.md`
- Modify: `docs/superpowers/specs/2026-07-25-calendar-newest-first-delivery-design.md`
- Modify: `docs/superpowers/plans/2026-07-25-calendar-newest-first-delivery.md`
- Modify: `docs/superpowers/plans/2026-07-24-system-maintenance-and-optimization-master-plan.md`
- Modify: `docs/plans/current-task-plan.md`
- Check: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**

- Consumes: canonical requirement owners, approved spec, Task 38 evidence and
  append-only master protocol.
- Produces: completed Task 47 evidence, Russian changelog entry, honest Git
  delivery status and rolling Task 48+ pointer.
- Preserves: managed `project-docs` blocks, README final H2 order and all
  historical master/changelog entries.

- [x] **Step 1: Update owner documentation without duplicating contracts**

Ensure `docs/release-calendar.md` and `docs/frontend.md` state:

```text
Пустое состояние сортировки `/calendar` разрешается как `latest`; явный
`earliest|latest|title` сохраняет приоритет, остальные calendar views
по умолчанию остаются `earliest`, а SQL-сортировка выполняется до пагинации.
```

Do not manually edit any `project-docs` managed block.

- [x] **Step 2: Finalize spec, current plan and append-only master evidence**

Set the spec status to `implemented_verified_delivery_pending` only after all
Task 1–3 gates pass. Update Task 47 in the master/current plan with exact test,
build, browser and production observations. Leave Task 48+ as the next
evidence-backed rolling pointer; never renumber Tasks 1–47.

- [x] **Step 3: Check README and add one Russian changelog item**

Run:

```bash
rg -n "Календарь релизов|Стартовый календарь показывает недавние даты" README.md
```

Expected: README already describes the visitor-visible newest-first result.
Do not add a duplicate visitor-history item unless implementation produces a
new result beyond that existing statement.

Add one separate 25.07.2026 `CHANGELOG.md` item describing:

```text
Стартовый `/calendar` закреплён в порядке от новых дат к старым; явный
`sort=earliest`, остальные calendar views, маршруты, данные, кеш,
разрешения и переводы сохранены. Указать фактические PHPUnit/Playwright/build
результаты и честный commit/push status.
```

Do not merge, shorten, translate identifiers or delete earlier entries.

- [x] **Step 4: Reread requirements and scan for duplicate/legacy sorting**

Run:

```bash
rg -n "ReleaseCalendarSort::Earliest|ReleaseCalendarSort::Latest|defaultSort|resolvedSort|changeSort|reverse\\(|array_reverse" \
  app resources routes tests docs
rg -n "TODO|FIXME|HACK" \
  app/Livewire/ReleaseCalendar \
  app/Services/ReleaseCalendar \
  resources/views/livewire/release-calendar \
  tests/Feature/ReleaseCalendarDefaultViewTest.php \
  tests/browser/release-calendar.spec.js
```

Expected: one query sort owner, one route-default owner, one Livewire change
action, no Blade reversal and no unfinished Task 47 marker.

- [x] **Step 5: Run final verification**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=ReleaseCalendarDefaultViewTest
php artisan test --filter=ReleaseCalendar
npm run build
npx playwright test tests/browser/release-calendar.spec.js \
  --project="Desktop Chromium" --project="Mobile Chromium"
php artisan project:docs-refresh --check
bash scripts/ci-check.sh docs
git diff --check
```

Expected: all task-scoped gates pass. Any unrelated full-suite or shared-doc
failure is recorded with its exact command and remains `unresolved`; it is not
renamed successful.

Result: task-scoped Pint, both PHPUnit filters, Vite, Desktop/Mobile
Playwright, route inventory and `git diff --check` passed. Both
`php artisan project:docs-refresh --check` and `bash scripts/ci-check.sh docs`
correctly report `docs/MAINTENANCE_LOG.md` stale because the shared worktree
contains an unrelated untracked Task 48 migration that the generated migration
inventory would add. Task 47 does not absorb that foreign migration or its
generated documentation.

- [x] **Step 6: Review the exact delivery set**

Run:

```bash
git status --short --branch
git diff --stat -- \
  app/Livewire/ReleaseCalendar/ReleaseCalendarPage.php \
  resources/views/livewire/release-calendar/release-calendar-page.blade.php \
  tests/Feature/ReleaseCalendarDefaultViewTest.php \
  tests/browser/prepare-fixtures.php \
  tests/browser/release-calendar.spec.js \
  docs/release-calendar.md \
  docs/frontend.md \
  docs/superpowers/specs/2026-07-25-calendar-newest-first-delivery-design.md \
  docs/superpowers/plans/2026-07-25-calendar-newest-first-delivery.md \
  docs/superpowers/plans/2026-07-24-system-maintenance-and-optimization-master-plan.md \
  docs/plans/current-task-plan.md \
  README.md \
  CHANGELOG.md
```

Expected: only reviewed Task 38/47 hunks are eligible. If shared files contain
inseparable foreign hunks or the clean-tree hook would reject remaining work,
do not commit and mark delivery `unresolved_shared_worktree`.

Result: branch is `main`, but it is ahead of `origin/main` by 34 commits and
contains numerous unrelated tracked/untracked files. Shared `README.md`,
`CHANGELOG.md`, current/master plans and browser fixtures also contain
inseparable foreign hunks. The canonical pre-commit hook calls
`seasonvar_git_guard_require_no_unstaged_changes` and
`seasonvar_git_guard_require_no_untracked_files`, so no Task 47 commit is
attempted.

- [ ] **Step 7: Commit and push only when canonical hooks can run**

When the worktree ownership gate is clean, run:

```bash
git add -- \
  app/Livewire/ReleaseCalendar/ReleaseCalendarPage.php \
  resources/views/livewire/release-calendar/release-calendar-page.blade.php \
  tests/Feature/ReleaseCalendarDefaultViewTest.php \
  tests/browser/prepare-fixtures.php \
  tests/browser/release-calendar.spec.js \
  docs/release-calendar.md \
  docs/frontend.md \
  docs/superpowers/specs/2026-07-25-calendar-newest-first-delivery-design.md \
  docs/superpowers/plans/2026-07-25-calendar-newest-first-delivery.md \
  docs/superpowers/plans/2026-07-24-system-maintenance-and-optimization-master-plan.md \
  docs/plans/current-task-plan.md \
  README.md \
  CHANGELOG.md
git diff --cached --check
git status --short --branch
git commit -m "fix: закрепить новые даты календаря первыми"
git push --porcelain origin main
```

Expected: commit is created only on `main`; push is an ordinary fast-forward.
Never use `--no-verify`, force push, history rewrite or broad staging.

- [ ] **Step 8: Run post-delivery production acceptance**

Verify:

```text
/calendar                 -> Latest, clean sort URL, newer group first
/calendar?sort=earliest   -> Earliest, explicit query retained
/calendar/upcoming        -> Earliest default
/ru/calendar              -> same Recent semantics
/en/calendar              -> same identity with localized labels
```

Record HTTP status, selected sort, first distinct groups, monotonic timestamps,
console/page/local-asset errors and overflow for `1440×1200` and `390×844`.
If production does not serve the committed result, leave Task 47
`unresolved_production_activation`; do not claim delivery from local evidence.

---

## Completion Contract

Task 47 is complete only when:

- clean `/calendar` renders `29 мая` before `28 мая` for matching data;
- explicit `sort=earliest` renders `28 мая` before `29 мая`;
- other views keep `Earliest`;
- sorting happens before pagination and sort change resets `calendarPage`;
- server, Desktop/Mobile browser, build and documentation gates pass;
- README is checked without a duplicate visitor entry;
- Russian `CHANGELOG.md`, current plan, spec and master evidence are current;
- the reviewed commit exists on `main`, push is confirmed, and production
  acceptance observes that commit.

If Git ownership, push or activation remains unavailable, implementation
evidence can be `completed`, but Task 47 delivery remains explicitly
`unresolved`; Task 48+ may be appended only from new measured evidence.

## Execution Evidence — 25.07.2026

- `./vendor/bin/pint tests/Feature/ReleaseCalendarDefaultViewTest.php
  tests/browser/prepare-fixtures.php --format agent`: passed.
- `php artisan test --filter=ReleaseCalendarDefaultViewTest`: 8 tests,
  33 assertions, passed.
- `php artisan test --filter=ReleaseCalendar`: 8 tests, 33 assertions, passed.
- `npm run build`: Vite 8.1.4 built 24 modules.
- Desktop/Mobile Chromium Playwright: 2 scenarios passed, including
  `calendarPage=2` reset and chronology checks.
- `git diff --check`: passed.
- `php artisan route:list --name=calendar`: 17 preserved canonical,
  localized, administrative and legacy routes.
- `php artisan project:docs-refresh --check` and
  `bash scripts/ci-check.sh docs`: `unresolved_shared_worktree` because
  `docs/MAINTENANCE_LOG.md` must inventory an unrelated untracked Task 48
  migration.
- Commit/push/post-delivery acceptance: `unresolved_shared_worktree`; no
  staging, hook bypass or mixed-scope commit was performed.
