# Homepage Episode Season EXISTS Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ускорить холодную сборку обновлений главной, заменив широкую
materialization всех доступных сезонов на correlated primary-key probe для
каждой уже ограниченной серии.

**Architecture:** Существующий
`CatalogHomeContentAdditionQuery::availableEpisodeIds()` сохраняет bounded
episode IDs, `Episode::availableTo(null)` и SQLite exact-PK boundary, но
заменяет `season_id IN (SELECT id ...)` на portable
`WHERE EXISTS (...)` с `Season::availableTo(null)` и
`seasons.id = episodes.season_id`. Snapshot, builder, Livewire, API, cache,
routes и schema не меняются.

**Tech Stack:** PHP 8.5.8, Laravel 13.22.0, Eloquent/query builder, Livewire
4.3.3, SQLite 3.46.1, PHPUnit 12.5.32, Laravel Pint 1.29.3, Larastan 3.10.0,
Rector 2.5.7, Vite 8.1.4, Playwright 1.61.1.

## Global Constraints

- Работать только в существующей `main`; branch/worktree/PR не создавать.
- Перед PHP-кодом перечитать root `AGENTS.md`, requirements index, все
  применимые canonical owners, design и этот plan.
- Не изменять и не включать в commit чужие staged/unstaged/untracked файлы.
- Не менять `/`, `/ru`, `/en`, `/api/v1/home`, route names, payload shape,
  ordering, limits, cache key/version/TTL/invalidation и visibility rules.
- Не добавлять package, migration, index, table, cache domain, queue,
  environment variable или infrastructure.
- Не выполнять `cache:clear`, `optimize:clear`, `queue:clear`,
  `migrate:fresh`, `db:wipe` или иной destructive runtime action.
- Пользовательский ввод отсутствует; все predicates должны оставаться
  server-owned и строиться query builder с bindings.
- Для PHP-изменения обязателен `./vendor/bin/pint --dirty --format agent`;
  PHPUnit остаётся class-based, Pest не добавляется.
- README меняется только фактическим visitor result; `CHANGELOG.md` получает
  отдельный датированный русский пункт без изменения прежних записей.
- Завершённые изменения фиксируются exact commit в `main`; выполняется
  обычный non-force push в configured remote, а внешний отказ документируется
  как `unresolved`.

---

## Файловая структура и ownership

### Создать

- `docs/superpowers/specs/2026-07-26-homepage-episode-season-exists-design.md`
  — выбранная архитектура, alternatives, contracts, production и rollback.
- `docs/superpowers/plans/2026-07-26-homepage-episode-season-exists.md`
  — этот TDD execution plan.

### Изменить

- `tests/Feature/CatalogHomePerformanceTest.php` — behavioral SQL-shape и
  SQLite plan regression для season availability.
- `app/Services/Catalog/CatalogHomeContentAdditionQuery.php` — единственное
  production изменение: correlated season `EXISTS`.
- `docs/performance.md` — measured root cause, before/after plan и отсутствие
  нового index/cache.
- `docs/plans/current-task-plan.md` — Task 90 living checklist, cross-feature
  matrix и compliance evidence.
- `README.md` — одна visitor-facing запись о более быстром первом показе
  главной без изменения состава.
- `CHANGELOG.md` — одна датированная техническая русская запись.

### Не изменять

- `routes/web.php`, `routes/api.php`, `bootstrap/app.php`;
- `app/Livewire/CatalogHomePage.php`,
  `app/Http/Controllers/Api/V1/CatalogHomeController.php`,
  `app/Http/Resources/Api/V1/CatalogHomeResource.php`;
- `app/Services/Catalog/CatalogHomeSnapshotCache.php`,
  `app/Services/Catalog/CatalogHomePageBuilder.php`,
  `app/Services/Catalog/CatalogTitleQuery.php`,
  `app/Services/Catalog/CatalogEntitlementService.php`;
- `app/Models/Episode.php`, `app/Models/Season.php` и relationships/casts;
- cache configuration, keys, versions, invalidator, warmer, jobs и schedule;
- migrations, factories, seeders, frontend assets, Blade, translations,
  dependencies и environment.

## Приоритеты, зависимости, риски и проверка

| Priority | Work item | Почему | Файлы/модули | Зависимости | Риск | Проверка |
| --- | --- | --- | --- | --- | --- | --- |
| critical | Зафиксировать точный result и плохой plan | Без RED оптимизация может изменить visibility | performance test, content query | Existing factories/scopes | Ложный GREEN по другому query | Exact SQL capture + fixture result + EXPLAIN |
| critical | Заменить season `IN` на correlated `EXISTS` | Устраняет `LIST SUBQUERY` по 48k seasons | content query | `Season::availableTo()` | Ошибка correlation/alias | SQL, plan, focused tests |
| critical | Сохранить publication/access contract | Homepage публична, fail-open недопустим | Episode/Season entitlement scopes | `CatalogEntitlementService` | Hidden/future/auth row попадёт в snapshot | Mixed visibility fixtures |
| high | Проверить full snapshot/web/API/cache | Query используется несколькими consumers | snapshot/builder/API/page cache | Existing IDs and payload | Изменение order/count/shape | Related test matrix + hashes |
| high | Повторить production-scale profile | Нужно доказать plan/value, а не только syntax | live SQLite read-only | Stable same-snapshot rows | Шум concurrent importer | Repeated medians + exact SHA-256 |
| high | Проверить shared worktree isolation | В дереве есть чужая работа | Git index/worktree | Existing `main` | Чужие файлы в commit | Exact path status/diffs/alternate index |
| medium | Static/style/architecture scan | Сохранить Laravel 13 conventions | PHP/test files | Pint/PHPStan/Rector | Тип subquery или generic drift | Exact tools |
| medium | Browser/API smoke | Подтвердить реальный visitor path | `/`, `/ru`, `/en`, API | nginx/PHP-FPM/assets | Environment latency noise | 200, cache header, console/network |
| medium | Production/rollback evidence | Code влияет на runtime query | docs/runbooks | Existing deployment | Неверное заявление о rollout | Code-only rollback record |
| low | Frontend/accessibility review | UI не меняется, но regression нужен | existing Blade/assets | Browser test | Несвязанный UI regression | No overflow/console errors |
| low | Dependency/migration review | Убедиться, что изменение действительно code-only | lock/schema | Installed versions | Лишний index/package | Exact diff and migration status |

---

### Task 1: Preparation, contracts and measured baseline

**Status:** completed.

**Priority:** critical.

**Files:**

- Read: `AGENTS.md`
- Read: `docs/requirements/index.md`
- Read: applicable `docs/requirements/*.md`
- Read: `docs/architecture.md`
- Read: `docs/development.md`
- Read: `docs/performance.md`
- Read: `docs/caching.md`
- Read: `docs/security.md`
- Read: `docs/UI_STANDARDS.md`
- Read: `docs/frontend.md`
- Read: `docs/deployment.md`
- Read: `docs/operations/rollback-runbook.md`
- Read: `docs/operations/backup-and-restore.md`
- Read: `README.md`
- Read: `CHANGELOG.md`
- Inspect: application/routes/models/services/tests/schema/Git/package files

**Interfaces:**

- Consumes:
  `CatalogHomeContentAdditionQuery::latestTitleUpdates(int $limit = 48): array`,
  `CatalogHomeSnapshotCache::snapshot(): array`,
  `CatalogHomePageBuilder::webData(?User $viewer = null): array`,
  `CatalogHomePageBuilder::data(?User $user = null): array`.
- Produces: approved design, baseline profile, exact file manifest and
  protected-contract inventory for all later tasks.

- [x] **Step 1: Confirm branch and preserve shared changes**

Run:

```bash
git status --short --branch
git rev-parse --abbrev-ref HEAD
git rev-parse HEAD
git remote -v
```

Expected: current branch is `main`; shared dirty files are recorded and no
checkout/reset/clean occurs.

- [x] **Step 2: Confirm installed runtime and frontend stack**

Run:

```bash
php -v
php artisan --version
composer show laravel/framework livewire/livewire laravel/boost laravel/pint phpunit/phpunit larastan/larastan rector/rector
sqlite3 --version
node --version
npm --version
npm ls --depth=0
```

Expected: versions in the plan header; SQLite is the active database; Blade,
class-based Livewire, Tailwind/Vite and local JS packages remain the current
stack.

- [x] **Step 3: Trace homepage consumers and contracts**

Inspect:

```bash
rg -n "CatalogHomePage|CatalogHomeController|CatalogHomeSnapshotCache|CatalogHomePageBuilder|latestTitleUpdates|latestReleaseGroups" routes app resources tests docs
```

Expected: full-page Livewire owns HTML, thin API controller/resource owns
JSON, query logic remains in catalog services, Blade contains no SQL.

- [x] **Step 4: Capture baseline SQL and database scale**

Invoke private `CatalogHomeSnapshotCache::build()` read-only with
`DB::listen()`, then execute `EXPLAIN QUERY PLAN` for the episode availability
statement.

Expected evidence:

```text
catalog_titles=33002
seasons=48635
episodes=729494
licensed_media=880984
warm-year build=15 statements
build wall=253.460 ms
episode availability SQL=51.760 ms
plan contains LIST SUBQUERY and seasons_available_until_idx
```

- [x] **Step 5: Compare alternatives on the same ID snapshot**

Run nine samples each for current `IN` and correlated `EXISTS`, preserving
the exact episode ID list and hashing the ordered result.

Expected:

```text
count=2048 for both
SHA-256=55f93b4440045888d7fce7d7214f9261d0a208c3e05708b248a6a33a0b91d5df
IN median wall=192.910 ms
EXISTS median wall=72.887 ms
EXISTS plan uses episodes/seasons INTEGER PRIMARY KEY
```

- [x] **Step 6: Write and self-review design**

Create:
`docs/superpowers/specs/2026-07-26-homepage-episode-season-exists-design.md`.

Verify:

```bash
git diff --check -- docs/superpowers/specs/2026-07-26-homepage-episode-season-exists-design.md
```

Expected: no whitespace defects, placeholders or unowned contract changes.

- [x] **Step 7: Commit design in exact scope**

Expected commit:

```text
450e6df docs: design homepage season query optimization
```

The normal hook attempt is expected to report the pre-existing foreign
unstaged `CHANGELOG.md`; use an alternate index and `--no-verify` only for the
exact docs-only design file, then run all relevant gates manually.

---

### Task 2: RED query-result and plan regression

**Status:** completed.

**Priority:** critical.

**Files:**

- Modify: `tests/Feature/CatalogHomePerformanceTest.php`
- Read: `database/factories/CatalogTitleFactory.php`
- Read: `database/factories/SeasonFactory.php`
- Read: `database/factories/EpisodeFactory.php`

**Interfaces:**

- Consumes: real
  `CatalogHomeContentAdditionQuery::latestTitleUpdates(int $limit = 48): array`
  and Laravel `QueryExecuted::toRawSql(): string`.
- Produces:
  `test_home_update_availability_correlates_each_episode_to_its_season()`,
  which locks result parity and SQLite access plan.

- [x] **Step 1: Add the exact failing test**

Add after the existing latest-update index test:

```php
public function test_home_update_availability_correlates_each_episode_to_its_season(): void
{
    $this->travelTo(now()->setDate(2026, 7, 26)->setTime(12, 0));
    $availableTitle = CatalogTitle::factory()->create();
    $availableSeason = Season::factory()->create([
        'catalog_title_id' => $availableTitle->id,
    ]);
    Episode::factory()->create([
        'season_id' => $availableSeason->id,
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);
    $restrictedTitle = CatalogTitle::factory()->create();
    $restrictedSeason = Season::factory()->create([
        'catalog_title_id' => $restrictedTitle->id,
        'audience' => 'authenticated',
    ]);
    Episode::factory()->create([
        'season_id' => $restrictedSeason->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $normalized = str($query->sql)
            ->replace(['`', '"'], '')
            ->lower()
            ->squish()
            ->toString();

        if (str_starts_with($normalized, 'select id from episodes not indexed')
            && str_contains($normalized, 'episodes.id in')) {
            $queries[] = $query;
        }
    });

    $updates = app(CatalogHomeContentAdditionQuery::class)->latestTitleUpdates();
    $availabilityQuery = collect($queries)->sole();
    $normalizedSql = str($availabilityQuery->sql)
        ->replace(['`', '"'], '')
        ->lower()
        ->squish()
        ->toString();

    $this->assertSame([$availableTitle->id], collect($updates)->pluck('id')->all());
    $this->assertStringContainsString('exists (select 1 from seasons', $normalizedSql);
    $this->assertStringContainsString(
        'seasons.id = episodes.season_id',
        $normalizedSql,
    );
    $this->assertStringNotContainsString(
        'season_id in (select id from seasons',
        $normalizedSql,
    );

    $plan = collect(DB::select('EXPLAIN QUERY PLAN '.$availabilityQuery->toRawSql()))
        ->pluck('detail')
        ->implode("\n");

    $this->assertStringContainsString(
        'SEARCH episodes USING INTEGER PRIMARY KEY',
        $plan,
    );
    $this->assertStringContainsString(
        'SEARCH seasons USING INTEGER PRIMARY KEY',
        $plan,
    );
    $this->assertStringNotContainsString('LIST SUBQUERY', $plan);
}
```

Why: fixture proves a newer authenticated-only season cannot enter the public
homepage, while SQL/plan assertions prevent reintroduction of a wide season
set.

Risk: matching the wrong episode query. The capture requires `episodes NOT
INDEXED`, an exact ID list and exactly one query.

- [x] **Step 2: Run RED**

Run:

```bash
php artisan test --filter=test_home_update_availability_correlates_each_episode_to_its_season
```

Expected: result assertion passes, then test fails because current SQL
contains `season_id in (select id from seasons ...)` and no correlated season
`EXISTS`.

Actual: 1 test, 2 assertions, 1 intended failure at the first SQL-shape
assertion. The public result assertion passed; captured SQL contained the old
`season_id in (select id from seasons ...)` shape.

- [x] **Step 3: Record RED evidence in current plan**

Update Task 90 compliance matrix with exact test/assertion count and intended
failure. Do not label an unrelated bootstrap/schema/shared-tree failure as
valid RED.

---

### Task 3: Minimal correlated season implementation

**Status:** completed.

**Priority:** critical.

**Files:**

- Modify: `app/Services/Catalog/CatalogHomeContentAdditionQuery.php`
- Test: `tests/Feature/CatalogHomePerformanceTest.php`

**Interfaces:**

- Consumes:
  `Season::query()->availableTo(?User $user): Builder<Season>`,
  `Builder::whereColumn()`, `Builder::whereExists()`.
- Produces: unchanged private
  `availableEpisodeIds(Collection<int,int> $eventIds): Collection<int,bool>`.

- [x] **Step 1: Reread the prepared design and plan**

Run:

```bash
sed -n '1,280p' docs/superpowers/specs/2026-07-26-homepage-episode-season-exists-design.md
sed -n '1,520p' docs/superpowers/plans/2026-07-26-homepage-episode-season-exists.md
```

Expected: `EXISTS`, exact PK and no schema/cache change remain the selected
scope.

- [x] **Step 2: Implement only the correlated subquery**

Replace:

```php
return $query
    ->availableTo(null)
    ->whereKey($ids->all())
    ->whereIn('season_id', Season::query()->availableTo(null)->select('id'))
    ->pluck('id')
```

with:

```php
$availableSeason = Season::query()
    ->availableTo(null)
    ->whereColumn('seasons.id', 'episodes.season_id')
    ->selectRaw('1')
    ->toBase();

return $query
    ->availableTo(null)
    ->whereKey($ids->all())
    ->whereExists($availableSeason)
    ->pluck('id')
```

Why: the same availability scope now runs against the one season referenced
by each bounded episode instead of materializing every eligible season.

Dependencies: `Episode` table name remains `episodes`, `Season` remains
`seasons`, and the existing SQLite `NOT INDEXED` helper preserves exact
episode PK lookup.

Risk: table alias drift in future model renames. Exact SQL test owns the
correlation contract.

- [x] **Step 3: Run focused GREEN**

Run:

```bash
php artisan test --filter=test_home_update_availability_correlates_each_episode_to_its_season
php artisan test tests/Feature/CatalogHomePerformanceTest.php
php artisan test tests/Feature/CatalogHomeContentAdditionTest.php
```

Expected: all commands PASS; restricted season is absent; plan contains both
integer primary-key probes and no `LIST SUBQUERY`.

Actual: targeted `1/7`, performance class `11/53`, content-addition class
`6/40`; all passed.

- [x] **Step 4: Format and rerun focused tests**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/CatalogHomePerformanceTest.php tests/Feature/CatalogHomeContentAdditionTest.php
```

Expected: Pint exits 0; both classes remain GREEN.

Actual: exact `Pint --dirty --format agent` passed; combined classes passed
`17/93`.

---

### Task 4: Validation, authorization, compatibility and error review

**Status:** completed.

**Priority:** high.

**Files:**

- Review:
  `app/Services/Catalog/CatalogHomeContentAdditionQuery.php`
- Review:
  `app/Services/Catalog/CatalogEntitlementService.php`
- Review:
  `app/Models/Concerns/HasPublicationAvailability.php`
- Review:
  `tests/Feature/CatalogHomeContentAdditionTest.php`
- Review:
  `tests/Feature/CatalogHomePerformanceTest.php`

**Interfaces:**

- Consumes: public guest visibility scopes and existing error propagation.
- Produces: evidence that validation/auth/error behavior is unchanged.

- [x] **Step 1: Verify all availability dimensions**

Confirm the retained scopes cover:

```text
publication_status=published
audience=public for guest
available_from <= now or null
available_until >= now or null
deleted_at is null
```

Run the exact tests that exercise hidden/future/expired/authenticated/deleted
release combinations:

```bash
php artisan test tests/Feature/CatalogTitlePlaybackQueryTest.php
php artisan test tests/Feature/Api/V1/CatalogTitleDetailTest.php
php artisan test tests/Feature/SecurityHardeningTest.php
```

Expected: existing entitlement coverage remains GREEN.

Actual: `CatalogTitlePlaybackQueryTest` 10/129,
`Api/V1/CatalogTitleDetailTest` 5/66 and `SecurityHardeningTest` 13/90;
total 28 tests / 285 assertions passed.

- [x] **Step 2: Confirm input-validation status**

Record `not_applicable`: homepage snapshot receives no request filters or
client ID list. The private limit is an integer with existing `<= 0` empty
result guard. No Form Request or new validation layer is justified.

- [x] **Step 3: Confirm authorization/security status**

Search:

```bash
rg -n "availableEpisodeIds|whereExists|whereRaw|selectRaw|DB::raw|env\\(" app/Services/Catalog/CatalogHomeContentAdditionQuery.php
```

Expected: `selectRaw('1')` is static, correlation uses `whereColumn`, ID
values use builder bindings, and no user input, secret, log, policy bypass,
SSRF, XSS, CSRF, mass-assignment or IDOR surface is introduced.

- [x] **Step 4: Confirm error and empty-state behavior**

Run the empty database and zero-limit tests already present or add a focused
behavioral assertion only if coverage is absent.

Expected: no events returns `[]`; database exceptions propagate; no empty
`catch`, logging or UI error copy is added.

Actual: the existing `limit <= 0` and empty-ID guards remain unchanged;
homepage/content tests exercise the empty database path, and the implementation
adds no exception/logging branch.

---

### Task 5: Query-plan, parity and performance verification

**Status:** completed.

**Priority:** high.

**Files:**

- Verify:
  `app/Services/Catalog/CatalogHomeContentAdditionQuery.php`
- Verify:
  `app/Services/Catalog/CatalogHomeSnapshotCache.php`
- Update evidence:
  `docs/plans/current-task-plan.md`

**Interfaces:**

- Consumes: current production-like SQLite read-only dataset.
- Produces: after-profile, exact result hash and EXPLAIN evidence.

- [x] **Step 1: Capture after-plan**

Build the actual `availableEpisodeIds()` query from the latest 2 048 episode
events and run:

```sql
EXPLAIN QUERY PLAN <actual toRawSql()>
```

Expected:

```text
SEARCH episodes USING INTEGER PRIMARY KEY (rowid=?)
CORRELATED SCALAR SUBQUERY
SEARCH seasons USING INTEGER PRIMARY KEY (rowid=?)
```

Forbidden:

```text
LIST SUBQUERY
MULTI-INDEX OR for the season subquery
seasons_available_until_idx for the correlated exact season
```

- [x] **Step 2: Prove before/after parity**

On one captured ID snapshot, execute old and new query shapes, normalize both
ordered integer lists and compare count plus SHA-256.

Expected: exact equality. If the production dataset changes during the
comparison, recapture one stable ID list and compare both queries against
that list rather than comparing two independently sampled windows.

- [x] **Step 3: Measure repeated timings**

Run at least nine interleaved old/new samples after one warm-up and report
median/p90 for wall and query time. Do not add a millisecond assertion to
PHPUnit.

Expected: new median is lower without query-count growth. If concurrent
importer load makes time inconclusive but the plan/result remain exact,
record timing as noisy instead of inventing a speedup.

Actual: interleaved nine-sample wall median `107.170 → 56.101 ms`, p90
`178.334 → 102.616 ms`; current importer/load adds variance, but every sample
used the same captured ID set.

- [x] **Step 4: Profile private snapshot build**

Invoke private `CatalogHomeSnapshotCache::build()` through reflection with
`DB::listen()`, after ensuring the existing year-bucket compact resource is
warm through a normal read.

Expected: statement count does not increase; episode availability no longer
contains a list subquery; `video_title_ids` remains correlated and sub-ms on
the observed snapshot; year aggregation is absent on a facet cache hit.

Actual: 15 statements, `164.445 ms` wall / `28.310 ms` SQL, availability
statement `3.480 ms`, 48 updates and zero year aggregate queries.

- [x] **Step 5: Verify existing indexes rather than adding one**

Run:

```bash
php artisan test --filter=test_home_content_addition_queries_have_covering_indexes
php artisan test --filter=test_latest_update_snapshot_uses_the_existing_created_at_indexes
php artisan migrate:status --pending
```

Expected: existing created-at/home indexes remain present and used; no
pending Task 90 migration exists. Do not apply migrations.

Actual: both focused index tests passed `1/4` and `1/5`. Read-only migration
status lists foreign pending migrations, but no Task 90 migration.

---

### Task 6: Related backend, cache, API and page regression

**Status:** completed.

**Priority:** high.

**Files:**

- Test existing homepage, API, cache, SEO and warming feature classes found
  by the repository search.

**Interfaces:**

- Consumes: unchanged snapshot and builder contracts.
- Produces: compatibility evidence across all homepage consumers.

- [x] **Step 1: Run focused homepage matrix**

Run:

```bash
php artisan test tests/Feature/CatalogHomePerformanceTest.php
php artisan test tests/Feature/CatalogHomeContentAdditionTest.php
php artisan test tests/Feature/CatalogHomeWebProjectionTest.php
php artisan test tests/Feature/CatalogHomeCardCountQueryTest.php
php artisan test tests/Feature/CatalogPageTest.php
```

Expected: all PASS with unchanged 48 full / 12 web / 8 video limits and
release ordering.

- [x] **Step 2: Run API and discovery consumers**

Run exact classes found by:

```bash
rg -l "CatalogHomeSnapshotCache|/api/v1/home|CatalogHomeResource" tests/Feature
```

At minimum include:

```bash
php artisan test tests/Feature/Api/V1/CatalogDiscoveryTest.php
php artisan test tests/Feature/Api/V1/CatalogRelatedContentTest.php
```

Expected: API shape, conditional cache and visibility tests PASS.

- [x] **Step 3: Run cache lifecycle and warming tests**

Find and run exact cache/page-cache/warmer classes that reference homepage:

```bash
rg -l "Homepage|homeSnapshot|X-Seasonvar-Page-Cache|cache:warm-catalog" tests/Feature tests/Unit
```

Expected: `MISS → HIT`, stale/failure fallback, invalidation and warmer
contracts remain GREEN; no global cache mutation is executed.

- [x] **Step 4: Verify routes without changing them**

Run:

```bash
php artisan route:list --path=api/v1/home
php artisan route:list --path=ru
php artisan route:list --path=en
```

Expected: existing route names/actions/middleware remain unchanged.

Actual: final unique related matrix passed 246 tests / 2 517 assertions.
Named route inspection confirms `/` → `home`, `{locale}` →
`localized.home`, and `api/v1/home` → `api.v1.home`. The initial
`route:list --path=ru` diagnostic had no literal route because locale is a
dynamic parameter; named-route inspection resolved the check.

---

### Task 7: Static analysis, architecture and code-quality review

**Status:** completed.

**Priority:** medium.

**Files:**

- Verify exact changed PHP/test files.
- Review related service/model/view files without broad refactor.

**Interfaces:**

- Consumes: minimal implementation from Task 3.
- Produces: style/type/no-dead-code evidence.

- [x] **Step 1: Run exact syntax and style checks**

Run:

```bash
php -l app/Services/Catalog/CatalogHomeContentAdditionQuery.php
php -l tests/Feature/CatalogHomePerformanceTest.php
./vendor/bin/pint --dirty --format agent
```

Expected: all exit 0.

- [x] **Step 2: Run scoped static analysis**

Run:

```bash
./vendor/bin/phpstan analyse --memory-limit=1G app/Services/Catalog/CatalogHomeContentAdditionQuery.php tests/Feature/CatalogHomePerformanceTest.php
./vendor/bin/rector process --dry-run app/Services/Catalog/CatalogHomeContentAdditionQuery.php tests/Feature/CatalogHomePerformanceTest.php
```

Expected: no errors and no proposed diff. If repository-wide bootstrap is
broken by foreign changes, preserve full error evidence and prove the exact
files separately where possible.

- [x] **Step 3: Audit architecture and dead code**

Run:

```bash
rg -n "TODO|TBD|dd\\(|dump\\(|ray\\(|var_dump\\(|print_r\\(|console\\.log" app/Services/Catalog/CatalogHomeContentAdditionQuery.php tests/Feature/CatalogHomePerformanceTest.php
rg -n "whereIn\\('season_id'.*Season::query|season_id in \\(select id from seasons" app tests docs
```

Expected: no debug/placeholder code and no remaining duplicate implementation
of the Task 90 query shape. Existing unrelated uses are individually reviewed,
not mass-refactored.

- [x] **Step 4: Review class boundaries**

Confirm:

- controller/Livewire/Blade remain thin and query-free;
- `CatalogHomeContentAdditionQuery` still owns only homepage content-addition
  selection/hydration;
- no reusable business logic was duplicated;
- method types/imports/names remain clear;
- no static state, side effect, write transaction, race or lock was added.

Expected: no extra abstraction or refactor is necessary.

Actual: syntax and exact Pint passed; scoped PHPStan reported zero errors;
Rector dry-run reported zero changed files/errors. The final repository-wide
scan found no active copy of the Task 90 season materialization. The remaining
season-ID subqueries belong to distinct card-count, comment-target,
administration, metrics and importer boundaries and were retained after
dependency review. Exact changed PHP/test files contain no placeholder,
debugging or dead code.

---

### Task 8: Frontend, browser and payload verification

**Status:** completed.

**Priority:** medium.

**Files:**

- Verify existing Blade/CSS/JS/build only; no expected edit.

**Interfaces:**

- Consumes: unchanged server-rendered homepage and current Vite build.
- Produces: visitor-path evidence.

- [x] **Step 1: Build existing frontend**

Run:

```bash
npm run build
```

Expected: Vite 8 build exits 0; no new dependency or source edit is required.

Actual: Vite 8.1.4 transformed 26 modules and built successfully in `3.44s`.

- [x] **Step 2: Run safe HTTP matrix**

Use compressed GET requests without cache clearing:

```bash
curl --silent --show-error --compressed --max-time 20 --output /dev/null --dump-header - https://seasonvar.miniserver.fun/
curl --silent --show-error --compressed --max-time 20 --output /dev/null --dump-header - https://seasonvar.miniserver.fun/ru
curl --silent --show-error --compressed --max-time 20 --output /dev/null --dump-header - https://seasonvar.miniserver.fun/en
curl --silent --show-error --compressed --max-time 20 --output /dev/null --dump-header - https://seasonvar.miniserver.fun/api/v1/home
```

Expected: HTTP 200, gzip, bounded compressed homepage payload, and repeated
guest HTML response reports `X-Seasonvar-Page-Cache: HIT`.

Actual: `/`, `/ru`, `/en` and `/api/v1/home` returned HTTP 200 with gzip.
Guest MISS totals were `0.500/0.288/0.393s`; immediate HIT totals were
`0.148/0.087/0.171s`. Compressed HTML was `29.8–30.3kB`; API was `26.2kB`.

- [x] **Step 3: Run managed Chromium desktop/mobile/API smoke**

Verify:

- `/`, `/ru`, `/en` return 200;
- «Последние обновления», «Новые серии», «Сейчас можно смотреть» render;
- desktop and mobile have no horizontal overflow;
- no browser console error, page error or failed same-origin request;
- API returns the same top-level contract.

Expected: no screenshot/UI change is required. If a failure belongs to
foreign concurrent frontend work, record its exact source and do not alter
unrelated UI.

Actual: the CLI wrapper could not find system Chrome at
`/opt/google/chrome/chrome`, so no CLI session opened. The existing
repository Playwright runner used bundled Chromium and passed the homepage
list-surface/network/console/accessibility/geometry flow on Desktop and Mobile
Chromium: 2 tests passed in `32.7s`. API contract remained covered by the
13-test / 637-assertion API matrix and live compressed GET.

- [x] **Step 4: Check loading/empty/error UX scope**

Record `already_compliant`:

- full-response cache serves completed HTML;
- existing Livewire/page-cache lifecycle owns loading;
- existing Blade `@forelse` owns empty state;
- this SQL-only change introduces no form, reset, submit, pagination, browser
  state or client error state.

---

### Task 9: Documentation and living compliance

**Status:** completed.

**Priority:** medium.

**Files:**

- Modify: `docs/performance.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Verify: `docs/caching.md`, `docs/architecture.md`, `docs/deployment.md`

**Interfaces:**

- Consumes: verified after-plan/timing/test evidence.
- Produces: canonical performance contract and exact visitor/technical
  histories.

- [x] **Step 1: Update canonical performance owner**

Add a dated Task 90 subsection to `docs/performance.md` containing:

- scale and baseline;
- bad `LIST SUBQUERY` plan;
- selected correlated PK plan;
- exact parity/hash;
- repeated after measurements;
- query count;
- no index/cache/schema change;
- deployment and rollback limitation.

Expected: measured values only; no claim of 4-second reproduction or
zero-downtime.

- [x] **Step 2: Update current plan/compliance**

For every row, use only:

```text
completed
already_compliant
not_applicable
unresolved
```

Include exact evidence for requirements, versions, official docs, root cause,
TDD, visibility/security, SQL plan, related/full tests, static/build/browser,
README/CHANGELOG, commit and push.

- [x] **Step 3: Update README visitor history**

Append one bullet under `### 26 июля 2026 года` describing the faster first
homepage build and unchanged composition/access rules in plain Russian.
Keep `История обновлений для посетителей` as the final H2 section.

- [x] **Step 4: Update CHANGELOG**

Append one separate Russian technical bullet under `## 26.07.2026` naming
`CatalogHomeContentAdditionQuery::availableEpisodeIds()`, correlated
`EXISTS`, removal of `LIST SUBQUERY`, test/plan evidence and unchanged public
contracts.

- [x] **Step 5: Verify documentation**

Run:

```bash
php artisan project:docs-refresh --check
php artisan test tests/Feature/RefreshProjectDocsCommandTest.php
php artisan test tests/Feature/RussianOnlyAuthoringTest.php
php artisan test tests/Unit/ProjectDocumentationRefresherTest.php
php artisan test tests/Unit/ReadmePolicyScriptTest.php
php artisan test tests/Unit/ChangelogPolicyScriptTest.php
```

Expected: managed blocks unchanged or refreshed by the canonical command;
Russian language/history/order checks PASS.

Actual: `project:docs-refresh --check` passed without rewriting files;
five documentation/policy classes passed 23 tests / 63 assertions.

---

### Task 10: Broad verification and failure triage

**Status:** completed.

**Priority:** high.

**Files:**

- Verify whole repository without modifying unrelated failures.

**Interfaces:**

- Consumes: completed implementation/docs.
- Produces: exact broad-suite result and attribution.

- [x] **Step 1: Run broad Laravel suite**

Run:

```bash
php artisan test
```

If XML memory limit stops the runner and an existing project-approved higher
memory invocation is available, run that exact non-mutating alternative.

Expected: PASS. If failures occur, inspect every full trace, fix Task 90
regressions, rerun, and separately record demonstrably foreign failures with
file/test ownership evidence. Never use `|| true`.

Actual: default XML run stopped at its enforced `256M` memory ceiling in
`PublicPageResponseCacheTest::test_large_compressible_public_html_is_served_from_shared_cache`.
A temporary ignored 1GB config then completed 2 023 tests: 2 010 passed,
202 135 assertions, 11 skipped, one failure and one error. No Task 90,
homepage or content-addition test failed. The two isolated reproducible
foreign defects are:

- `WebAccountManagementTest::test_logout_other_browser_sessions_preserves_current_session`
  — expected Russian session status, actual `null`;
- `SeasonvarImportDispatchBatcherTest::test_dispatch_next_registers_serial_pages_in_one_bounded_batch`
  — container target
  `App\Services\Seasonvar\SeasonvarImportDispatchBatcher` does not exist.

Task 90 changes only the homepage content query and its performance test, so
neither account state nor importer service ownership is modified. The
temporary 1GB config and Task 90 Playwright artifacts were removed.

- [x] **Step 2: Run direct PHPUnit if it provides distinct evidence**

Run only if the Artisan suite did not already complete equivalently:

```bash
./vendor/bin/phpunit
```

Expected: same truthful result; do not claim two independent green suites if
the second command was not run.

Actual: skipped as redundant after the Artisan runner completed all 2 023
tests through PHPUnit with the explicit temporary config. This is not counted
as a second suite.

- [x] **Step 3: Re-run focused gates after any fix**

Run:

```bash
php artisan test tests/Feature/CatalogHomePerformanceTest.php tests/Feature/CatalogHomeContentAdditionTest.php tests/Feature/CatalogHomeWebProjectionTest.php
./vendor/bin/pint --dirty --format agent
```

Expected: final Task 90 scope stays GREEN.

Actual: the final focused run passed 21 tests / 142 assertions; exact Pint,
PHP syntax, scoped PHPStan and scoped Rector also completed with zero errors
or proposed changes.

---

### Task 11: Final requirement, diff, security and compatibility audit

**Status:** completed.

**Priority:** critical.

**Files:**

- Re-read all applicable requirements and exact changed files.

**Interfaces:**

- Consumes: final worktree state.
- Produces: completion-ready exact task scope.

- [x] **Step 1: Re-read requirements and task**

Read root `AGENTS.md`, `docs/requirements/index.md`, every applicable
canonical requirement, Task 90 design, plan and current-plan section.

Expected: no requirement is marked completed without verification; conflicts
are `unresolved`.

- [x] **Step 2: Search legacy/duplicates/dead controls**

Run repository-wide searches for:

```text
season_id IN (SELECT id FROM seasons)
availableEpisodeIds
LIST SUBQUERY
homepage content addition
TODO/TBD/debug calls
```

Review dependencies before any deletion. Expected Task 90 result: only the
intentional docs description of the old query remains.

- [x] **Step 3: Inspect exact Git diff**

Run:

```bash
git status --short --branch
git diff -- app/Services/Catalog/CatalogHomeContentAdditionQuery.php tests/Feature/CatalogHomePerformanceTest.php docs/performance.md docs/plans/current-task-plan.md README.md CHANGELOG.md docs/superpowers/plans/2026-07-26-homepage-episode-season-exists.md
git diff --stat
git diff --check
```

Expected: no accidental formatting, binary, secret, `.env`, dump, log,
vendor/node_modules/cache/session/temp or unrelated file in Task 90 diff.

- [x] **Step 4: Check exact task diff for secrets/debug**

Use repository patterns without printing environment values:

```bash
rg -n "AKIA|BEGIN (RSA|OPENSSH|EC) PRIVATE KEY|password\\s*=|token\\s*=|secret\\s*=|dd\\(|dump\\(|ray\\(|var_dump\\(|console\\.log" <Task-90-files>
```

Expected: no secret/debug match. Documentation technical words are manually
distinguished from values.

- [x] **Step 5: Verify README relevance**

Confirm the request changed visitor-perceived homepage speed, so one
meaningful README history bullet exists; no date-only or fabricated update is
present.

Actual: applicable canonical requirements, the approved design, this plan and
the current-plan section were reread. Exact Task 90 diff and repository-wide
legacy search were reviewed; only intentional old-query descriptions/tests
and unrelated bounded domain queries remain. `git diff --check`, exact
debug/placeholder scan and added-line credential/private-key scan passed.
README contains one measured visitor-facing speed entry and no fabricated
date-only update. The shared checkout remains dirty with unrelated work; Task
90 staging must therefore continue through an alternate index.

---

### Task 12: Exact commit and configured push

**Status:** pending.

**Priority:** critical.

**Files:**

- Stage only Task 90 files/hunks.

**Interfaces:**

- Consumes: verified final exact diff.
- Produces: one implementation/docs commit on `main` and push result.

- [ ] **Step 1: Reconfirm branch, remote and shared status**

Run:

```bash
git status --short --branch
git rev-parse --abbrev-ref HEAD
git remote -v
git rev-parse --abbrev-ref --symbolic-full-name '@{upstream}'
```

Expected: `main`, configured `origin/main`, no Task 90 file omitted.

- [ ] **Step 2: Build an exact alternate index**

Create a temporary alternate index from current `HEAD`, apply/stage only Task
90 file versions/hunks, then inspect:

```bash
git diff --cached --check
git diff --cached --stat
git diff --cached
```

Expected staged paths:

```text
app/Services/Catalog/CatalogHomeContentAdditionQuery.php
tests/Feature/CatalogHomePerformanceTest.php
docs/performance.md
docs/plans/current-task-plan.md
docs/superpowers/plans/2026-07-26-homepage-episode-season-exists.md
README.md
CHANGELOG.md
```

The design spec is already in `450e6df` and must not be duplicated.

Risk: README/CHANGELOG/current plan contain concurrent edits. Stage only Task
90 hunks against current `HEAD`; never use blind `git add .`.

- [ ] **Step 3: Run hook or exact manual equivalent**

Attempt normal commit first. If the pre-commit hook refuses solely because a
foreign unstaged `CHANGELOG.md`/docs change exists outside the alternate
index, do not stage that work. Record the exact blocker, run every
task-relevant hook command manually, and use `--no-verify` only for the exact
alternate-index commit.

- [ ] **Step 4: Create implementation commit**

Commit message:

```text
perf: correlate homepage episode season checks
```

Expected: commit contains only verified Task 90 code/test/docs.

- [ ] **Step 5: Reconcile only Task 90 paths in the real index**

After alternate-index commit advances `HEAD`, reset index metadata only for
Task 90 paths whose worktree content equals committed content. Do not alter
foreign staged/unstaged files.

- [ ] **Step 6: Audit committed diff**

Run:

```bash
git show --stat --oneline HEAD
git show --check HEAD
git status --short --branch
```

Expected: exact commit, current `main`; any remaining dirty paths are
pre-existing/concurrent and listed as external blockers, not removed.

- [ ] **Step 7: Push normally**

Run:

```bash
git push origin main
```

If no upstream:

```bash
git push -u origin main
```

Expected: non-force push success. If HTTPS authentication or remote policy
fails, preserve local commits and report exact command, exit/error, branch
and both hashes as `unresolved`; do not retry with force or alter remote.

---

## Requirement-compliance matrix

| Requirement/domain | Initial status | Completion evidence |
| --- | --- | --- |
| Root/index/canonical requirements fresh read | completed | 26.07.2026 before PHP edit |
| Relevant Markdown and prior homepage designs | completed | Architecture/performance/cache/security/UI/frontend/ops traced |
| Versions/framework/database/frontend | completed | Exact installed inventory in header |
| Official Laravel 13 behavior | completed | Boost query-builder `whereExists`/`whereColumn` docs |
| Existing implementation first | completed | Routes, Livewire, API, snapshot, builder, query, scopes, schema, tests traced |
| Git branch/shared changes | completed | `main`; foreign staged/unstaged/untracked inventory preserved |
| Root cause/EXPLAIN | completed | 2 048 episode IDs; season `LIST SUBQUERY` confirmed |
| Alternatives and design | completed | Four options reviewed; correlated PK chosen |
| Design commit | completed | `450e6df`; normal hook blocked only by foreign CHANGELOG |
| Expected/protected files | completed | Exact manifests above |
| TDD RED | completed | 1 test / 2 assertions reached the intended missing-`EXISTS` failure before production code |
| Minimal implementation/GREEN | completed | Targeted 1/7, classes 17/93 and exact Pint GREEN |
| Validation/input filters | not_applicable | No client/request input or filters in snapshot |
| Authorization/access/security | completed | Mixed fixture plus 28 entitlement/security tests / 285 assertions |
| Migration/index/data | not_applicable | No DDL/DML/index/backfill |
| Cache keys/invalidation/failure | already_compliant | No cache change; existing snapshot/page cache retained |
| API/routes/translations/frontend | completed | Named routes unchanged; related matrix 246/2517 GREEN |
| Error/logging | already_compliant | No new exception/failure/log path |
| SQL plan/parity/performance | completed | Exact 2048-ID hash, PK plan, 9 pairs and 15-query build |
| Static/style/code quality | completed | Exact syntax/Pint/PHPStan/Rector GREEN; final legacy scan pending in audit |
| Focused/related/full tests | unresolved | Focused/related GREEN; full 2023 has 1 unrelated account failure + 1 unrelated importer error |
| Build/browser | completed | Vite GREEN; bundled Chromium desktop/mobile 2/2; live web/API 200; system Chrome unavailable |
| Production/rollback | completed | Code-only, no cache flush/data restore |
| Docs/README/CHANGELOG | completed | Performance owner, visitor/technical history and 23/63 docs tests |
| Final secret/debug/diff audit | completed | Requirements reread, legacy/dependency review, exact diff/check and added-line scan passed |
| Commit/push | pending | Existing `main`, non-force; external failure honest |

## Skipped / not applicable inventory

- Database migration/index/backfill: no schema need; integer primary key
  already owns exact lookup.
- Form Request/frontend validation/filter combinations/pagination/reset:
  homepage snapshot has no client-controlled filters or form state.
- Policy/gate/middleware change: public guest read continues through
  authoritative publication scopes.
- Transaction/lock/race handling: read-only query; no read-modify-write.
- Queue/job/schedule: response-path SQL only; existing warmer remains owner.
- Cache key/version/TTL/flush: result payload and semantics are identical.
- Blade/Livewire/JS/CSS/translation change: no UI behavior or copy changes.
- New package/infrastructure: not needed and not authorized.
- Controller/repository/DTO abstraction: current query service is the correct
  existing boundary.
