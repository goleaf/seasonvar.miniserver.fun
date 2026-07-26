# Filtered Directory Candidate Aggregate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ускорить фильтрованные web/API справочники, ограничив pivot
aggregate точными taxonomy candidate IDs до visibility/grouping без изменения
результата или публичных контрактов.

**Architecture:** Один private taxonomy candidate builder владеет
name/slug, canonical tag, search и letter predicates. Filtered total и
filtered `count_desc` передают его ID subquery в существующий grouped pivot;
нефильтрованный `count_desc` остаётся глобальным, а years сохраняют отдельный
query path.

**Tech Stack:** PHP 8.5.8, Laravel 13.22.0, Eloquent/query builder, Livewire
4.3.3, SQLite 3.46.1, PHPUnit 12.5.32, Laravel Pint 1.29.3, Larastan 3.10.0,
Rector 2.5.7, Vite 8.1.4, Playwright 1.61.1.

## Global Constraints

- Работать только в существующей ветке `main`; branch/worktree/PR не
  создавать.
- Перед PHP-кодом перечитать root `AGENTS.md`, requirements index,
  применимые canonical owners, approved design и этот plan.
- Не изменять и не включать в commit чужие staged/unstaged/untracked файлы.
- Не менять web/API routes, route names, query keys, response shape,
  pagination, sorting, locale, SEO или guest-public visibility.
- Не менять `name_asc` bounded result, summary/alphabet/decades snapshots,
  cache keys/version/TTL/stale/lock/invalidation или warming.
- Не добавлять package, migration, index, table, DML, backfill, cache
  resource, queue, scheduler, environment variable или infrastructure.
- SQL identifiers поступают только из server-owned taxonomy registry;
  пользовательские `q`/`letter` остаются bound parameters.
- Не выполнять `cache:clear`, `optimize:clear`, `queue:clear`,
  `migrate:fresh`, `db:wipe` или иной destructive runtime action.
- После PHP-изменения выполнить
  `./vendor/bin/pint --dirty --format agent`; тесты остаются class-based
  PHPUnit, Pest не добавляется.
- README меняется только фактическим visitor result; `CHANGELOG.md` получает
  отдельную датированную русскую запись без изменения прежних записей.
- Завершённый scope фиксируется exact commit в `main`; выполняется обычный
  non-force push в configured remote, внешний отказ записывается как
  `unresolved`.

---

## Файловая структура и ownership

### Создать

- `docs/superpowers/specs/2026-07-26-filtered-directory-candidate-aggregate-design.md`
  — approved architecture, alternatives, contracts, rollout and rollback.
- `docs/superpowers/plans/2026-07-26-filtered-directory-candidate-aggregate.md`
  — этот execution plan.

### Изменить

- `tests/Feature/CatalogDirectoryQueryOptimizationTest.php` — exact
  filtered result/total и SQL-shape regressions.
- `app/Services/Catalog/CatalogDirectoryQuery.php` — shared taxonomy
  candidate builder и candidate-scoped grouped pivot.
- `docs/catalog-search.md` — canonical web/API directory query contract.
- `docs/performance.md` — measured root cause, alternatives, final plan and
  local before/after evidence.
- `docs/plans/current-task-plan.md` — Task 91 living checklist, manifests,
  risks and compliance.
- `README.md` — одна visitor-facing запись о более быстром поиске и
  алфавитной фильтрации справочников.
- `CHANGELOG.md` — отдельная датированная техническая русская запись.

### Не изменять

- `routes/web.php`, `routes/api.php`, `bootstrap/app.php`;
- directory full-page Livewire components, API controllers and Resources;
- `app/Services/Catalog/CatalogTitleQuery.php`;
- `app/Services/Catalog/CatalogDirectoryRegistry.php`;
- `app/Services/Catalog/CatalogTaxonomyRegistry.php`;
- `app/Services/Catalog/CatalogFacetSnapshotCache.php`;
- taxonomy models, relationships, casts and factories;
- cache config, invalidators, warmers, jobs and schedule;
- migrations, database indexes, seeders and production data;
- Blade, CSS, JavaScript, translations and frontend assets;
- authentication, authorization, Premium, player, importer, collections,
  homepage, recommendations and administration code.

## Сохраняемые interfaces

- `CatalogDirectoryQuery::paginate(CatalogDirectoryDefinition $directory,
  string $search, string $letter, string $sort, ?int $decade, int $total,
  int $perPage): LengthAwarePaginator`;
- `CatalogDirectoryQuery::summary()`, `letters()`, `decades()` and
  `detailExists()` behavior;
- private `taxonomyQuery()` return type `Builder<Model>`;
- `CatalogTitleQuery::visibleTo(?User $viewer): Builder<CatalogTitle>`;
- `CatalogTaxonomyRegistry::modelClass()` and `pivot()` contracts;
- all directory web/API payloads, counts and deterministic ordering.

## Приоритеты, зависимости и риски

| Priority | Work item | Зависимость | Риск | Gate |
| --- | --- | --- | --- | --- |
| critical | Exact RED для total/result SQL | Current shared query service | Ложный GREEN по значениям при прежнем global aggregate | SQL capture + exact behavior |
| critical | Общий taxonomy candidate builder | Existing model/tag/search/letter predicates | Расхождение tag/localized semantics | Existing tag + new actor tests |
| critical | Candidate-scoped filtered total | Existing reverse pivot and title visibility | Неверный total или hidden title leak | Mixed visibility fixtures |
| critical | Candidate-scoped filtered `count_desc` | Same candidate builder | Неверная глобальная сортировка | Filtered exact order + unfiltered regression |
| high | Web/API/cache/SEO regressions | Shared `CatalogDirectoryQuery` consumers | Contract drift вне service | Focused matrix |
| high | Same-snapshot profile and EXPLAIN | Live SQLite read-only | Шум importer/lock contention | Exact hash + repeated medians + plan |
| medium | Static/style verification | Pint/PHPStan/Rector | Builder generic/type drift | Exact commands |
| medium | Production/rollback review | Existing deployment runbook | Неверное заявление об operations | Code-only rollback evidence |
| low | Frontend/build smoke | UI intentionally unchanged | Несвязанный asset regression | Vite + safe GET |

---

### Task 1: Preparation, design and baseline

**Status:** completed.

**Files:**

- Read: `AGENTS.md`
- Read: `docs/requirements/index.md`
- Read: all applicable canonical requirement owners
- Read: `docs/catalog-search.md`
- Read: `docs/api.md`
- Read: `docs/testing.md`
- Read: Laravel best-practice database/query/testing rules
- Inspect: directory services, consumers, tests, schema, indexes and Git
- Create:
  `docs/superpowers/specs/2026-07-26-filtered-directory-candidate-aggregate-design.md`

**Interfaces:**

- Consumes: current `CatalogDirectoryQuery`, actor pivot indexes and
  guest-visible title scope.
- Produces: approved design `d2f249b`, baseline timings and exact protected
  contracts for Tasks 2–5.

- [x] **Step 1: Confirm branch, versions and shared changes**

Run:

```bash
git status --short --branch
git rev-parse --abbrev-ref HEAD
php -v
php artisan --version
composer show --direct --format=json
sqlite3 --version
node --version
npm --version
npm ls --depth=0 --json
```

Expected: branch `main`; PHP 8.5.8, Laravel 13.22.0, SQLite 3.46.1 and plan
header versions; foreign dirty files recorded without reset/stash/clean.

- [x] **Step 2: Trace directory consumers and SQL ownership**

Run:

```bash
rg -n "CatalogDirectoryQuery|catalog/directories|count_desc|filteredValueCount" app routes tests docs
```

Expected: one shared query owner serves web/API; Blade performs no query.

- [x] **Step 3: Inspect existing schema and query plan**

Use Laravel Boost schema tools and read-only `EXPLAIN QUERY PLAN`.

Expected:

```text
catalog_title_actor primary=(catalog_title_id, actor_id)
catalog_title_actor reverse covering=(actor_id, catalog_title_id)
actors directory index=(name, id)
current filtered total joins all matching pivot rows before COUNT(DISTINCT)
current filtered count_desc groups all visible actor IDs before outer filter
```

- [x] **Step 4: Measure current and candidate query forms**

Run same-snapshot read-only actor probes for `letter=А` and
`q=Александр`, comparing exact totals and ordered hashes.

Expected design evidence:

```text
letter current total/result ≈518.51/290.47 ms
letter candidate total/result ≈120.59/171.88 ms
search current total/result ≈588.32/306.79 ms
search candidate total/result ≈137.28/176.02 ms
exact totals and ordered SHA-256 values match
```

- [x] **Step 5: Compare and approve alternatives**

Expected: candidate-scoped grouped pivot selected; correlated `EXISTS`
rejected after `≈11.99 s` letter probe; materialized counters/index rejected
because query order, not missing schema, is the isolated cause.

- [x] **Step 6: Save, review and commit design**

Expected exact commit:

```text
d2f249b docs: design filtered directory query optimization
```

---

### Task 2: RED filtered total and count-order regressions

**Status:** completed.

**Files:**

- Modify: `tests/Feature/CatalogDirectoryQueryOptimizationTest.php`
- Read: `database/factories/CatalogTitleFactory.php`
- Read: `app/Models/Actor.php`

**Interfaces:**

- Consumes:
  `CatalogDirectoryQuery::paginate(...)` and `QueryExecuted::$sql`.
- Produces:
  `test_filtered_total_groups_only_visible_candidate_pivot_values()` and
  `test_filtered_count_order_scopes_the_grouped_aggregate_to_candidates()`.

- [x] **Step 1: Add RED filtered-total test**

Add a test that creates:

```php
$alpha = Actor::query()->create([
    'name' => 'Alpha Actor',
    'slug' => 'alpha-filtered-actor',
]);
$alpine = Actor::query()->create([
    'name' => 'Alpine Actor',
    'slug' => 'alpine-filtered-actor',
]);
$beta = Actor::query()->create([
    'name' => 'Beta Actor',
    'slug' => 'beta-filtered-actor',
]);

$alpha->catalogTitles()->attach([
    CatalogTitle::factory()->create()->id,
    CatalogTitle::factory()->create()->id,
]);
$alpine->catalogTitles()->attach(
    CatalogTitle::factory()->create([
        'is_published' => false,
        'publication_status' => PublicationStatus::Draft,
    ]),
);
$beta->catalogTitles()->attach(CatalogTitle::factory()->create());
```

Capture a `search: 'Alpha'`, `sort: 'name_asc'` pagination. Assert total `1`,
only `$alpha`, count `2`, and total SQL containing:

```text
from (select actor_id from catalog_title_actor
where actor_id in (select actors.id from actors
group by actor_id) as filtered_directory_values
```

Also assert it does not contain:

```text
from actors inner join catalog_title_actor
count(distinct actors.id)
```

- [x] **Step 2: Add RED filtered count-order test**

Create two visible `A*` actors with counts `2` and `1`, a visible `B*` actor
with count `3`, and draft/future/expired/deleted `A*` relations. Capture
`letter: 'A'`, `sort: 'count_desc'`.

Assert:

```php
$this->assertSame(2, $paginator->total());
$this->assertSame([$alpha->id, $alpine->id], $items->pluck('id')->all());
$this->assertSame([2, 1], $items->pluck('published_titles_count')
    ->map(fn (mixed $count): int => (int) $count)
    ->all());
```

Require result SQL fragments:

```text
directory_value_counts
actor_id in (select actors.id from actors
group by actor_id
order by published_titles_count desc, actors.name asc, actors.id asc
```

- [x] **Step 3: Run the two new tests and verify RED**

Run:

```bash
php artisan test tests/Feature/CatalogDirectoryQueryOptimizationTest.php \
  --filter='filtered_(total|count_order)'
```

Expected: behavioral values remain correct, but both tests fail only on the
new candidate-scoped SQL assertions.

- [x] **Step 4: Confirm existing unfiltered contract remains GREEN**

Run:

```bash
php artisan test tests/Feature/CatalogDirectoryQueryOptimizationTest.php \
  --filter=test_count_order_keeps_the_global_grouped_aggregate
```

Expected: 1 passed; unfiltered `count_desc` still has the global aggregate.

---

### Task 3: GREEN candidate-scoped grouped pivot

**Status:** completed.

**Files:**

- Modify: `app/Services/Catalog/CatalogDirectoryQuery.php`
- Test: `tests/Feature/CatalogDirectoryQueryOptimizationTest.php`

**Interfaces:**

- Consumes: registry-provided model/pivot metadata and
  `CatalogTitleQuery::visibleTo(null)`.
- Produces:
  `taxonomyCandidates(CatalogDirectoryDefinition $directory, string
  $search, string $letter): Builder<Model>` used by `taxonomyQuery()` and
  `filteredValueCount()`.

- [x] **Step 1: Extract the canonical taxonomy candidate builder**

Add:

```php
/** @return Builder<Model> */
private function taxonomyCandidates(
    CatalogDirectoryDefinition $directory,
    string $search,
    string $letter,
): Builder {
    $filterType = $directory->filterType?->value;
    abort_if($filterType === null, 404);
    $modelClass = $this->taxonomies->modelClass($filterType);
    $model = new $modelClass;
    $table = $model->getTable();
    $query = $modelClass::query()
        ->select([
            $table.'.id',
            $table.'.name',
            $table.'.slug',
        ])
        ->whereNotNull($table.'.name')
        ->where($table.'.name', '<>', '')
        ->whereNotNull($table.'.slug')
        ->where($table.'.slug', '<>', '');

    $localizedTag = $model instanceof Tag && Tag::usesCanonicalSchema();

    if ($localizedTag) {
        $this->constrainCanonicalTags($query, $table);
    }

    $this->applyTaxonomySearch($query, $table, $search, $localizedTag);

    if ($letter !== '') {
        $this->applyLetter($query, $table, $letter, $localizedTag);
    }

    return $query;
}
```

- [x] **Step 2: Reuse candidates in result query**

Replace duplicated outer taxonomy filter construction in `taxonomyQuery()`
with:

```php
$query = $this->taxonomyCandidates($directory, $search, $letter);
```

Inside `sort === 'count_desc'`, before title visibility/grouping, add:

```php
if ($search !== '' || $letter !== '') {
    $counts->whereIn(
        $pivot['related_key'],
        (clone $query)->select($table.'.id'),
    );
}
```

Keep the condition explicit so the unfiltered path produces the same global
aggregate SQL as before.

- [x] **Step 3: Replace filtered distinct join count**

After the year branch in `filteredValueCount()`, build:

```php
$filterType = $directory->filterType?->value;
abort_if($filterType === null, 404);
$modelClass = $this->taxonomies->modelClass($filterType);
$table = (new $modelClass)->getTable();
$pivot = $this->taxonomies->pivot($filterType);
$candidateIds = $this->taxonomyCandidates($directory, $search, $letter)
    ->select($table.'.id');
$visibleCandidateValues = DB::table($pivot['table'])
    ->select($pivot['related_key'])
    ->whereIn($pivot['related_key'], $candidateIds)
    ->whereIn(
        $pivot['title_key'],
        $this->titles->visibleTo(null)->select('catalog_titles.id'),
    )
    ->groupBy($pivot['related_key']);

return DB::query()
    ->fromSub($visibleCandidateValues, 'filtered_directory_values')
    ->count();
```

Delete the former taxonomy/pivot/title joins, duplicated canonical tag,
search and letter predicates, and `distinct()->count($table.'.id')`.

- [x] **Step 4: Run new tests and verify GREEN**

Run:

```bash
php artisan test tests/Feature/CatalogDirectoryQueryOptimizationTest.php \
  --filter='filtered_(total|count_order)'
```

Expected: both tests pass with exact result and SQL shape.

- [x] **Step 5: Run the whole optimization class**

Run:

```bash
php artisan test tests/Feature/CatalogDirectoryQueryOptimizationTest.php
```

Expected: all class tests pass, including global unfiltered aggregate,
alphabet, canonical tag locale and decades contracts.

- [x] **Step 6: Format and repeat focused tests**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/CatalogDirectoryQueryOptimizationTest.php
```

Expected: Pint success and all tests pass after formatting.

---

### Task 4: Cross-feature verification and measured proof

**Status:** completed_with_independent_full_suite_failures.

**Files:**

- Inspect: modified PHP/test files
- Inspect: related web/API/cache/SEO tests
- Modify: `docs/performance.md`

**Interfaces:**

- Consumes: GREEN service and test contract.
- Produces: exact behavior, query-plan, static-analysis and performance
  evidence for documentation and delivery.

- [x] **Step 1: Run focused web/API/cache/SEO matrix**

Discover exact related classes with:

```bash
rg -l "CatalogDirectoryQuery|catalog/directories|/actors|directory" tests/Feature tests/Unit
```

Run the bounded set containing directory page, directory API,
query/optimization, cache snapshot/invalidation, public page cache, SEO and
warmer tests.

Expected: all selected tests pass; no response, page-size, cache or SEO
contract changes.

- [x] **Step 2: Run syntax and static analysis**

Run:

```bash
php -l app/Services/Catalog/CatalogDirectoryQuery.php
php -l tests/Feature/CatalogDirectoryQueryOptimizationTest.php
./vendor/bin/phpstan analyse \
  app/Services/Catalog/CatalogDirectoryQuery.php \
  tests/Feature/CatalogDirectoryQueryOptimizationTest.php \
  --no-progress
./vendor/bin/rector process \
  app/Services/Catalog/CatalogDirectoryQuery.php \
  tests/Feature/CatalogDirectoryQueryOptimizationTest.php \
  --dry-run
```

Expected: syntax clean, PHPStan no errors and Rector no changes.

- [x] **Step 3: Repeat exact same-snapshot performance comparison**

Inside a read-only/frozen-time diagnostic, run at least seven alternating
samples for old-equivalent and final queries for:

```text
letter=А, sort=count_desc
q=Александр, sort=count_desc
```

Record median/p90 total and result SQL, exact totals and ordered SHA-256.
Expected: hashes/totals match and final candidate-scoped form is materially
faster than old-equivalent form. Do not convert local measurements into SLA.

- [x] **Step 4: Inspect final SQLite plan**

Run `EXPLAIN QUERY PLAN` for final filtered total and result SQL.

Expected:

```text
candidate taxonomy subquery appears before pivot group
reverse covering pivot index is available for candidate probes
visible title scope remains publication-index/PK bounded
no taxonomy-to-pivot outer COUNT(DISTINCT taxonomy.id)
```

- [x] **Step 5: Run safe runtime and build smoke**

Run non-mutating web/API GETs for a filtered actors directory when runtime is
available, then:

```bash
npm run build
```

Expected: HTTP 200, unchanged public shape, no secrets/raw SQL, Vite build
success. UI assets are unchanged.

- [x] **Step 6: Run broad PHPUnit**

Run:

```bash
php artisan test
```

Expected: all repository tests pass. Any failure caused by foreign dirty
work must be reproduced, attributed by exact file/test evidence and recorded
as `unresolved`; it must not be hidden as success.

Actual: штатный лимит `phpunit.xml` 256 MB дважды исчерпан в
`PublicPageResponseCacheTest::test_large_compressible_public_html_is_served_from_shared_cache`.
Временный удалённый после запуска test-only config с 1 ГБ выполнил 2 029
тестов: 2 013 passed, 11 skipped, 3 failed и 2 errors. Независимые failures:
два `CatalogMetadataProvenance*`, один `WebAccountManagementTest`;
независимые errors: отсутствующий
`SeasonvarImportDispatchBatcher` и не созданный importer title в
`SeasonvarParsePageCommandTest`. Directory tests в списке отказов
отсутствуют.

---

### Task 5: Documentation, compliance and exact delivery

**Status:** delivery_pending.

**Files:**

- Modify: `docs/catalog-search.md`
- Modify: `docs/performance.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Read: all applicable canonical requirements and approved design

**Interfaces:**

- Consumes: final verified code, tests, timings and plans.
- Produces: canonical product/performance record, completed Task 91 matrix,
  exact implementation commit and push result.

- [x] **Step 1: Update feature and performance owners**

Document in `docs/catalog-search.md` that filtered total and filtered
`count_desc` scope pivot grouping by canonical candidate IDs while
unfiltered `count_desc` remains global.

Document in `docs/performance.md`:

- original root cause;
- rejected correlated/materialized alternatives;
- exact local before/after medians and hashes;
- final `EXPLAIN` indexes/temporary structures;
- no schema/cache/route/translation/dependency change;
- rollback and local-observation disclaimer.

- [x] **Step 2: Update visitor and technical histories**

Add a Russian README visitor-history item describing quicker filtered actor,
director and other directory pages without changed results. Add a separate
dated Russian `CHANGELOG.md` item naming `CatalogDirectoryQuery`, filtered
total and `count_desc`.

- [x] **Step 3: Complete current-task compliance evidence**

Update Task 91 statuses to `completed`, `already_compliant`,
`not_applicable` or `unresolved` only after evidence. Include exact focused,
static, performance, broad, Git and push results.

- [x] **Step 4: Reread requirements and scan legacy/debug/secrets**

Reread:

```text
AGENTS.md
docs/requirements/index.md
applicable canonical owners
approved design
this plan
Task 91 current plan section
```

Search repository references to `filteredValueCount`, global
`directory_value_counts`, duplicate directory services/routes, direct Blade
queries, debug calls, conflict markers and secret-like additions. Inspect
dependencies before declaring any legacy removable; this task removes only
the duplicated predicates replaced inside the same service.

- [x] **Step 5: Run final exact verification**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test tests/Feature/CatalogDirectoryQueryOptimizationTest.php
php artisan test
npm run build
git diff --check -- <exact Task 91 paths>
git status --short --branch
git diff -- <exact Task 91 paths>
git diff --cached -- <exact Task 91 paths>
```

Expected: task files clean/formatted, known tests/build pass or independent
failures are exact and recorded, branch remains `main`, foreign changes
remain excluded.

Actual: exact Pint, PHP syntax, 8/47 focused PHPUnit, PHPStan with 1 ГБ,
Rector dry-run, Vite, managed-doc check, diff/temporary/debug/secret scans
passed. Earlier related matrix remained GREEN at 141/1 747. The full 1 ГБ
run remained classified as 2 013 passed, 11 skipped and five independent
foreign failures/errors out of 2 029; Task 91 had no failure.

- [ ] **Step 6: Commit exact implementation scope**

Commit only Task 91 code/test/docs paths or exact owned hunks:

```text
perf: scope filtered directory aggregates to candidates
```

Because shared files contain foreign edits, construct and review an exact
alternate index from current `HEAD`; do not reset, stash, checkout, clean or
unstage another task.

- [ ] **Step 7: Push configured main**

Run:

```bash
GIT_TERMINAL_PROMPT=0 git push origin main
```

Expected: configured non-force push succeeds. If HTTPS credentials are
unavailable, record the exact external authentication failure as
`unresolved`; never report it as successful.
