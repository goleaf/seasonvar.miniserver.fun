# Homepage Episode Primary-key Hydration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Устранить два широких SQLite index scan в холодном пути блока
«Новые серии», сохранив точные данные, видимость и публичные контракты
главной страницы.

**Architecture:** Существующий private helper
`CatalogHomeContentAdditionQuery::withoutSecondaryIndexes()` применяется
только к двум наружным episode hydration queries после bounded ID selection.
Первая загрузка остаётся обычным query builder path. Для второй загрузки
сначала получаются bounded media rows, затем один явный `Episode` query
загружает их уникальные `episode_id` и присоединяет модели через
`setRelation()`. Это заменяет один relation-proxy eager query, не добавляя
round trip. Inner ranking/visibility queries продолжают использовать текущие
индексы; на всех non-SQLite drivers helper остаётся no-op.

**Tech Stack:** PHP 8.5, Laravel 13.22.0, Eloquent, SQLite, PHPUnit 12.5,
Laravel Pint 1.29.3, Larastan 3.10.

## Global Constraints

- Работать только в существующей ветке `main`; branch/worktree не создавать.
- Не сбрасывать, не stash/unstage и не включать foreign Task 61 scope.
- Не менять routes, schema, data, translations, cache keys/TTL/versions,
  queues, dependencies, config или `.env`.
- Сохранить title/season/episode/media publication, audience, window,
  soft-delete и guest visibility predicates.
- Сохранить `RELEASE_ITEMS_PER_TITLE`, ordering, relation projections,
  `has_more` и HTML/payload shape.
- Production DDL/DML, `cache:clear`, `queue:clear`, broad flush и destructive
  команды запрещены.
- Каждое PHP behavior change выполняется только после observed RED.
- После PHP edits выполнить focused tests, Pint и PHPStan.
- Visitor-visible performance result требует `README.md`; technical result
  требует отдельную русскую запись `CHANGELOG.md`.
- Rollback — code/docs revert; data/cache restore не требуется.

---

### Task 1: RED query-plan regression

**Files:**

- Modify: `tests/Feature/CatalogHomePerformanceTest.php`
- Reference: `tests/Feature/CatalogHomeContentAdditionTest.php`
- Reference: `app/Services/Catalog/CatalogHomeContentAdditionQuery.php`

**Interfaces:**

- Consumes:
  `CatalogHomeContentAdditionQuery::latestTitleUpdates(int $limit = 48): array`
  and
  `CatalogHomeContentAdditionQuery::latestReleaseGroups(Collection $titles, array $updates, int $limit = 12): Collection`.
- Produces: one regression proving two outer episode hydration statements use
  `episodes NOT INDEXED` while returning the same episode/media identities.

- [x] **Step 1: Add one focused failing test**

Add imports:

```php
use App\Services\Catalog\CatalogHomeContentAdditionQuery;
```

Add to `CatalogHomePerformanceTest`:

```php
public function test_home_release_hydration_uses_primary_key_lookups_for_bounded_episode_ids(): void
{
    $catalogTitle = CatalogTitle::factory()->create([
        'indexed_at' => now(),
    ]);
    $season = Season::factory()->create([
        'catalog_title_id' => $catalogTitle->id,
    ]);
    $episode = Episode::factory()->create([
        'season_id' => $season->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $media = LicensedMedia::factory()->create([
        'catalog_title_id' => $catalogTitle->id,
        'season_id' => $season->id,
        'episode_id' => $episode->id,
        'status' => 'published',
        'published_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $contentAdditions = app(CatalogHomeContentAdditionQuery::class);
    $updates = $contentAdditions->latestTitleUpdates();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = str($query->sql)
            ->replace(['`', '"'], '')
            ->lower()
            ->squish()
            ->toString();
    });

    $groups = $contentAdditions->latestReleaseGroups(
        collect([$catalogTitle]),
        $updates,
    );
    $group = $groups->sole();
    $primaryKeyHydrations = collect($queries)
        ->filter(fn (string $sql): bool => str_contains(
            $sql,
            'from episodes not indexed',
        ))
        ->values();

    $this->assertSame([$episode->id], $group['episodes']->pluck('id')->all());
    $this->assertSame([$media->id], $group['media']->pluck('id')->all());
    $this->assertSame($episode->id, $group['media']->sole()->episode?->id);
    $this->assertCount(2, $primaryKeyHydrations, implode("\n", $queries));
    $this->assertTrue(
        $primaryKeyHydrations->contains(
            fn (string $sql): bool => str_contains(
                $sql,
                'row_number() over (partition by seasons.catalog_title_id',
            ),
        ),
        implode("\n", $primaryKeyHydrations->all()),
    );
    $this->assertTrue(
        $primaryKeyHydrations->contains(
            fn (string $sql): bool => str_contains(
                $sql,
                'select id, season_id, number, kind, sort_order, title, released_at, created_at',
            ),
        ),
        implode("\n", $primaryKeyHydrations->all()),
    );
}
```

- [x] **Step 2: Run RED**

Run:

```bash
php artisan test --filter=test_home_release_hydration_uses_primary_key_lookups_for_bounded_episode_ids
```

Expected: `FAIL`; episode/media identity assertions pass, but
`assertCount(2, $primaryKeyHydrations)` receives `0`.

- [x] **Step 3: Record RED evidence**

Update the Task 62 compliance matrix:

```markdown
| TDD RED | `completed` | Exact regression returned 0 expected SQLite primary-key hydration statements while result identities remained correct |
```

- [x] **Step 4: Do not commit yet**

Keep the isolated test change unstaged until minimal GREEN and focused
verification are complete. Do not stage foreign paths.

---

### Task 2: Minimal GREEN primary-key hydration

**Files:**

- Modify: `app/Services/Catalog/CatalogHomeContentAdditionQuery.php`
- Test: `tests/Feature/CatalogHomePerformanceTest.php`

**Interfaces:**

- Consumes:
  `private function withoutSecondaryIndexes(Builder $query, string $table): Builder`.
- Produces: unchanged `latestReleaseGroups()` return shape with two
  SQLite-primary-key outer hydration statements.

- [x] **Step 1: Apply helper to `episodesFor()` outer query**

Replace:

```php
return Episode::query()
    ->select($episodeTable.'.*')
```

with:

```php
$episodes = $this->withoutSecondaryIndexes(
    Episode::query(),
    $episodeTable,
);

return $episodes
    ->select($episodeTable.'.*')
```

Keep every existing `availableTo`, `whereIn`, eager-load projection and
ordering clause unchanged.

- [x] **Step 2: Investigate media eager-loaded episode query**

After:

```php
$mediaTable = $media->getTable();
```

add:

```php
$episodeTable = (new Episode)->getTable();
```

Первоначальный вариант через eager-load callback отклонён после трёх
проверенных адаптаций:

- typed `Builder` получил runtime `BelongsTo` и завершился `TypeError`;
- `Relation::getQuery()` дал GREEN runtime, но потерял точный
  `Builder<Episode>` для Larastan;
- untyped fluent relation proxy возвращал decorated `BelongsTo`, а не
  builder, и снова нарушал helper contract.

Это не локальная type-annotation проблема: framework proxy намеренно
возвращает relation для fluent chaining.

- [x] **Step 3: Replace only the episode eager query with explicit bounded hydration**

Keep the media query and season eager load unchanged, remove only the
`episode` eager callback, collect unique non-zero `episode_id` values, then
issue one typed query:

```php
$episodes = $this->withoutSecondaryIndexes(Episode::query(), $episodeTable)
    ->availableTo(null)
    ->whereKey($episodeIds)
    ->select([
        'id',
        'season_id',
        'number',
        'kind',
        'sort_order',
        'title',
        'released_at',
        'created_at',
    ])
    ->get()
    ->keyBy('id');
```

Apply the resolved model or `null` to every media row through
`setRelation('episode', ...)`. Skip the episode query when the bounded media
set contains no episode IDs. This preserves the original three-query
media/season/episode sequence and relation-loaded semantics.

- [x] **Step 4: Run GREEN regression**

Run:

```bash
php artisan test --filter=test_home_release_hydration_uses_primary_key_lookups_for_bounded_episode_ids
```

Expected: `PASS`, one test and all assertions green.

- [x] **Step 5: Run nearest behavior tests**

Run:

```bash
php artisan test tests/Feature/CatalogHomePerformanceTest.php
php artisan test tests/Feature/CatalogHomeContentAdditionTest.php
php artisan test tests/Feature/CatalogHomeCardCountQueryTest.php
```

Expected: all existing identity, order, visibility, truncation, index and
cache-budget assertions pass.

- [x] **Step 6: Format**

Run:

```bash
./vendor/bin/pint --dirty --format agent
```

Expected: completed successfully; only Task 62 PHP/test formatting may change.

- [x] **Step 7: Re-run focused tests after formatting**

Run the same three test files again. Expected: all green.

---

### Task 3: Static, plan and database verification

**Files:**

- Modify: `docs/plans/current-task-plan.md`
- Modify:
  `docs/superpowers/plans/2026-07-26-homepage-episode-hydration-primary-key.md`

**Interfaces:**

- Consumes: GREEN PHP/test scope.
- Produces: measured evidence without production data or cache mutation.

- [x] **Step 1: Run exact PHPStan**

Run:

```bash
./vendor/bin/phpstan analyse \
  app/Services/Catalog/CatalogHomeContentAdditionQuery.php \
  tests/Feature/CatalogHomePerformanceTest.php \
  --memory-limit=1G \
  --no-progress
```

Expected: `0 errors`.

- [x] **Step 2: Confirm exact SQLite plans**

Use a read-only application bootstrap with `DB::listen`, capture the two
episode hydration statements after `latestReleaseGroups()`, and run
`EXPLAIN QUERY PLAN` through PDO.

Expected outer nodes:

```text
SEARCH episodes USING INTEGER PRIMARY KEY (rowid=?)
```

Expected inner ranking nodes continue using current
`episodes_publication_lookup_idx`,
`seasons_publication_lookup_idx` and primary keys.

- [x] **Step 3: Repeat direct builder profile**

Run five isolated `CatalogHomePageBuilder::data()` samples with cached
snapshot/metrics. Record:

- wall time;
- query count;
- total SQL time;
- two episode hydration times;
- collection counts and unique IDs.

Acceptance:

- query count does not increase from 57;
- latest/featured/video/latest-media counts remain `48/12/8/12` on the same
  snapshot;
- two hydration operations use primary key;
- direct builder improves against `358,53 ms` diagnostic baseline.

Do not create a hard runtime assertion in PHPUnit.

- [x] **Step 4: Update discovery status immediately**

Set Task 62:

```markdown
Статус: `implementation_green_verification`.
```

Mark TDD/minimal GREEN/static/query plan evidence with exact measured values.

---

### Task 4: Adjacent, broad and live verification

**Files:**

- No production-code changes expected.
- Modify plan evidence only after commands finish.

**Interfaces:**

- Consumes: formatted GREEN implementation.
- Produces: cross-feature and production-safe evidence.

- [x] **Step 1: Run adjacent homepage/cache/catalog tests**

Run focused filters covering:

```bash
php artisan test --filter='CatalogHome|CatalogPageTest|CacheWarmJobTest|PublicPageCacheWarmerTest'
```

Expected: all Task 62-adjacent tests green.

- [x] **Step 2: Run broader PHPUnit**

Run:

```bash
php artisan test
```

If the known independent GD memory profile or existing session/importer
blockers recur, record their exact class/message and run the widest safe
exclusion matrix. Never describe an incomplete run as passing.

- [x] **Step 3: Run project static/document checks**

Run:

```bash
./vendor/bin/pint --test --format agent \
  app/Services/Catalog/CatalogHomeContentAdditionQuery.php \
  tests/Feature/CatalogHomePerformanceTest.php
php artisan project:docs-refresh --check
bash scripts/ci-check.sh docs
git diff --check
```

Expected: all applicable checks green.

Evidence: targeted Pint и `git diff --check` прошли. Managed-docs check и
docs CI честно остановились только на foreign незавершённом
`docs/MAINTENANCE_LOG.md` Task 61; Task 62 не изменяет managed blocks и не
перезаписывает чужой scope.

- [x] **Step 4: Safe live HTTP matrix**

Perform five anonymous `GET /` requests with bounded timeout.

Record:

- HTTP 200;
- first TTFB/total;
- subsequent TTFB/total;
- payload size;
- `X-Seasonvar-Page-Cache` behavior.

Do not bump versions, clear cache, mutate data or restart workers solely for
measurement.

- [x] **Step 5: Cross-feature review**

Confirm:

- same homepage headings, release group IDs and detail links;
- no route/API/schema/translation/config/dependency diff;
- no cache key/version/TTL/invalidation diff;
- no importer/queue/admin/auth/private-state diff;
- non-SQLite path remains unchanged by helper source inspection.

---

### Task 5: Documentation and visitor history

**Files:**

- Modify: `docs/performance.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify:
  `docs/superpowers/plans/2026-07-26-homepage-episode-hydration-primary-key.md`

**Interfaces:**

- Consumes: verified GREEN measurements.
- Produces: canonical performance, visitor and technical evidence.

- [x] **Step 1: Update `docs/performance.md`**

Add a dated Task 62 follow-up under homepage cold-path evidence:

```markdown
Follow-up 26.07.2026 локализовал два наружных episode hydration query,
которые после bounded ID selection выбирали широкий SQLite publication index.
Existing SQLite-only `withoutSecondaryIndexes()` сохранил inner
ranking/visibility predicates и перевёл только наружный lookup на
`INTEGER PRIMARY KEY`.
```

Include exact before/after medians, builder/query evidence and explicit note
that results are diagnostic, not p95/SLA.

- [x] **Step 2: Update `README.md`**

In the final visitor history section add a Russian result:

```markdown
- Первый показ главной после обновления кеша стал быстрее: блок новых серий
  теперь сразу читает уже найденные эпизоды по их точным идентификаторам,
  не просматривая повторно большой список опубликованных выпусков.
```

Do not expose SQL implementation beyond this visitor-readable result.

- [x] **Step 3: Add separate implementation `CHANGELOG.md` item**

Add a new Russian bullet distinct from the design-only item. Include:

- two primary-key hydration boundaries;
- preserved visibility/results;
- focused/static/live evidence;
- no migration/routes/cache/dependency change.

- [x] **Step 4: Complete compliance matrix**

Use only:

- `completed`;
- `already_compliant`;
- `not_applicable`;
- `unresolved`.

Record exact verification and any independent blocker.

- [x] **Step 5: Re-read applicable requirements and design**

Re-read:

- `AGENTS.md`;
- `docs/requirements/index.md`;
- performance/cache/production owners;
- approved Task 62 design;
- this implementation plan;
- Task 62 current-plan section.

- [x] **Step 6: Legacy/duplicate scan**

Search repository-wide for:

```text
episodes NOT INDEXED
withoutSecondaryIndexes
latestReleaseGroups
episodes_recommendation_release_events_idx
TODO|FIXME
```

Classify every related caller. Do not delete an implementation based only on
text search.

---

### Task 6: Exact delivery in `main`

**Files:**

- Stage only verified Task 62 code, test, docs, plan, README and changelog
  hunks.
- Preserve all foreign Task 61 staged/unstaged/untracked content.

**Interfaces:**

- Consumes: completed verified scope.
- Produces: one or more exact commits in `main` and an honest push status.

- [x] **Step 1: Verify branch and shared state**

Run:

```bash
git status --short --branch
git branch --show-current
composer git:doctor
```

Expected branch: `main`. Record foreign dirty scope separately.

- [x] **Step 2: Prepare exact alternate index**

Initialize an alternate index from the current `HEAD` and stage only Task 62
files/hunks. For shared `README.md`, `CHANGELOG.md`,
`docs/performance.md` and `docs/plans/current-task-plan.md`, construct blobs
containing only Task 62 changes relative to current `HEAD`.

- [x] **Step 3: Verify staged scope**

Run with the alternate index:

```bash
git diff --cached --name-status
git diff --cached --check
.githooks/pre-commit
```

Expected: no foreign collection/readiness files or hunks.

Evidence: alternate index contains eight exact Task 62 paths and
`git diff --cached --check` passes. Staged README/CHANGELOG policies pass.
Pre-commit reaches managed docs but stops on the foreign uncommitted
`docs/MAINTENANCE_LOG.md`; the current-plan standalone policy also exposes a
pre-existing extra H1 in `HEAD`. Neither baseline is rewritten or staged by
Task 62.

- [ ] **Step 4: Commit**

Commit in `main`:

```bash
git commit -m "perf: use primary keys for homepage episodes"
```

Record the exact hash in Task 62 plan evidence.

- [ ] **Step 5: Attempt configured push**

Run:

```bash
git push origin main
```

If clean-tree pre-push or remote authentication rejects delivery, record the
exact non-secret reason as `unresolved`; do not use `--no-verify`.

- [ ] **Step 6: Final report**

Report:

- measured outcome;
- tests/static/live evidence;
- compatibility result;
- commit hash;
- push status;
- foreign dirty-tree blocker if still present;
- compliance status for every applicable requirement group.
