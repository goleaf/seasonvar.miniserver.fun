# Homepage Year Buckets Facet Snapshot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking. Project policy requires inline
> execution in the existing `main`; do not create a branch, worktree or
> subagent.

**Goal:** Убрать повторную агрегацию годов из Homepage-only rebuild, сохранив
точный публичный `year_buckets` contract и authoritative SQL fallback.

**Architecture:** `CatalogHomeSnapshotCache` переиспользует существующий
`CatalogFacetSnapshotCache` для отдельного scalar resource. Внутри miss/error
fallback выполняется прежний `CatalogTitleQuery::visibleTo(null)` aggregate;
outer Homepage snapshot, API shape и invalidation contracts не меняются.

**Tech Stack:** PHP 8.5, Laravel 13.22, Eloquent Query Builder, SQLite,
TieredCache, PHPUnit 12.5.

## Global Constraints

- Работать только в существующей ветке `main`; branch/worktree/PR запрещены.
- Не добавлять migration, index, table, DML, dependency, queue или config.
- Сохранить `year_buckets` как
  `list<array{year:int,titles_count:int}>`, порядок `year DESC` и limit 12.
- Сохранить `CatalogTitleQuery::visibleTo(null)` без ослабления publication,
  audience, availability-window и soft-delete правил.
- Использовать только существующие `CatalogFacetSnapshotCache`,
  `CacheDomain::CatalogFacets` и `CatalogCacheInvalidator`.
- Не менять routes, translations, Blade, Livewire, JavaScript или CSS.
- Любая performance-цифра является локальным diagnostic evidence, не SLA.
- Учитывать чужие Task 76–85 changes и коммитить только exact Task 86 hunks.

---

### Task 1: Cache-lifecycle regression RED

**Files:**

- Modify:
  `tests/Feature/CatalogHomePerformanceTest.php`

**Interfaces:**

- Consumes:
  `CatalogHomeSnapshotCache::refresh(): array`,
  `CacheVersionRegistry::bump(CacheDomain::CatalogFacets): int`.
- Produces:
  regression contract
  `test_home_snapshot_reuses_year_buckets_until_the_facet_version_changes`.

- [x] **Step 1: Добавить fixture точных годов и SQL listener**

Добавить тест после
`test_home_snapshot_latest_media_uses_correlated_title_visibility()`:

```php
public function test_home_snapshot_reuses_year_buckets_until_the_facet_version_changes(): void
{
    $this->travelTo(now()->setDate(2026, 7, 26)->setTime(12, 0));
    CatalogTitle::factory()->create(['year' => 2026]);
    CatalogTitle::factory()->create(['year' => 2025]);
    CatalogTitle::factory()->create([
        'year' => 2027,
        'is_published' => false,
    ]);
    $versions = app(CacheVersionRegistry::class);
    $versions->bump(CacheDomain::CatalogFacets);
    $yearQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$yearQueries): void {
        $sql = str($query->sql)
            ->replace(['`', '"'], '')
            ->lower()
            ->squish()
            ->toString();

        if (str_contains($sql, 'select year, count(*) as titles_count')
            && str_contains($sql, 'group by year')) {
            $yearQueries[] = $sql;
        }
    });
    $snapshot = app(CatalogHomeSnapshotCache::class);

    $first = $snapshot->refresh();
    $second = $snapshot->refresh();

    $this->assertSame([
        ['year' => 2026, 'titles_count' => 1],
        ['year' => 2025, 'titles_count' => 1],
    ], $first['year_buckets']);
    $this->assertSame($first['year_buckets'], $second['year_buckets']);
    $this->assertCount(1, $yearQueries);

    CatalogTitle::factory()->create(['year' => 2026]);
    $stillCached = $snapshot->refresh();

    $this->assertSame($first['year_buckets'], $stillCached['year_buckets']);
    $this->assertCount(1, $yearQueries);

    $versions->bump(CacheDomain::CatalogFacets);
    $rebuilt = $snapshot->refresh();

    $this->assertSame([
        ['year' => 2026, 'titles_count' => 2],
        ['year' => 2025, 'titles_count' => 1],
    ], $rebuilt['year_buckets']);
    $this->assertCount(2, $yearQueries);
}
```

- [x] **Step 2: Запустить RED**

Run:

```bash
php artisan test --filter=test_home_snapshot_reuses_year_buckets_until_the_facet_version_changes
```

Expected: FAIL только на повторном `GROUP BY year`, stale-before-bump или
query-count assertions; fixture и application boot не должны завершаться
ошибкой.

- [x] **Step 3: Зафиксировать RED evidence в current task plan**

В Task 86 записать exact test count/assertions и причину отказа. Production
code до этого шага не менять.

---

### Task 2: Minimal CatalogFacets resource GREEN

**Files:**

- Modify:
  `app/Services/Catalog/CatalogHomeSnapshotCache.php`
- Test:
  `tests/Feature/CatalogHomePerformanceTest.php`

**Interfaces:**

- Consumes:
  `CatalogFacetSnapshotCache::remember(string, array, Closure, bool): array`.
- Produces:
  private
  `CatalogHomeSnapshotCache::yearBuckets(): array<int,array{year:int,titles_count:int}>`.

- [x] **Step 1: Добавить existing cache dependency**

Расширить constructor:

```php
public function __construct(
    private readonly CatalogTitleQuery $titles,
    private readonly CatalogHomeContentAdditionQuery $contentAdditions,
    private readonly CatalogFacetSnapshotCache $facetSnapshots,
    private readonly TieredCache $cache,
    private readonly CacheTtlPolicy $ttl,
) {}
```

- [x] **Step 2: Заменить inline aggregate одним private method call**

В `build()`:

```php
$yearBuckets = $this->yearBuckets();
```

Добавить перед `emptySnapshot()`:

```php
/**
 * @return list<array{year: int, titles_count: int}>
 */
private function yearBuckets(): array
{
    $currentYear = (int) now()->format('Y');

    return $this->facetSnapshots->remember(
        'homepage-year-buckets-v1',
        [
            'audience' => 'public',
            'limit' => 12,
            'year' => $currentYear,
        ],
        fn (): array => $this->titles->visibleTo(null)
            ->select('year')
            ->selectRaw('count(*) as titles_count')
            ->whereNotNull('year')
            ->where('year', '>=', 1900)
            ->where('year', '<=', $currentYear + 1)
            ->groupBy('year')
            ->orderByDesc('year')
            ->limit(12)
            ->get()
            ->map(fn ($bucket): array => [
                'year' => (int) $bucket->year,
                'titles_count' => (int) $bucket->getAttribute('titles_count'),
            ])
            ->all(),
    );
}
```

- [x] **Step 3: Запустить targeted GREEN**

Run:

```bash
php artisan test --filter=test_home_snapshot_reuses_year_buckets_until_the_facet_version_changes
```

Expected: PASS; до bump один year query, после bump ровно второй.

- [x] **Step 4: Запустить весь `CatalogHomePerformanceTest`**

Run:

```bash
php artisan test tests/Feature/CatalogHomePerformanceTest.php
```

Expected: все tests PASS, включая latest media index/EXPLAIN contracts.

- [x] **Step 5: Отформатировать PHP**

Run:

```bash
./vendor/bin/pint --dirty --format agent
```

Expected: exit 0; просмотреть exact Task 86 diff.

---

### Task 3: Cache correctness, parity and measured profile

**Files:**

- Modify:
  `tests/Feature/CatalogHomePerformanceTest.php` только если GREEN выявит
  недостающий assertion существующего public contract
- Modify:
  `docs/plans/current-task-plan.md`

**Interfaces:**

- Consumes:
  Homepage refresh, CatalogFacets generation, SQL listener.
- Produces:
  exact first/repeat/rebuild evidence и production-like parity hash.

- [x] **Step 1: Проверить cache invalidation owners**

Run:

```bash
php artisan test --filter='CatalogCacheInvalidatorTest|CatalogFacetCacheTest|CatalogHomePerformanceTest'
```

Expected: Homepage/CatalogFacets version coupling и Task 86 test PASS.

- [x] **Step 2: Проверить shared consumers**

Run:

```bash
php artisan test --filter='CatalogHome|CatalogPage|CatalogDiscovery|PublicPageResponseCache|CacheWarm'
```

Expected: no Task 86 regression in HTML, API, discovery, snapshot or warmer.

- [x] **Step 3: Выполнить fresh-process профиль**

Через read-only bootstrap script вызвать private `build()` сначала после
новой CatalogFacets generation, затем повторно в той же generation. Записать:

- wall time;
- SQL time;
- query count;
- counts `48/12/8/12/12`;
- число year aggregate statements;
- SHA-256 exact `year_buckets`.

Expected: первый build имеет один year aggregate; повторный — ноль. Не
заявлять end-to-end improvement, если parallel SQLite load делает wall time
шумным.

- [x] **Step 4: Проверить static contracts**

Run:

```bash
php -l app/Services/Catalog/CatalogHomeSnapshotCache.php tests/Feature/CatalogHomePerformanceTest.php
./vendor/bin/phpstan analyse --memory-limit=1G app/Services/Catalog/CatalogHomeSnapshotCache.php tests/Feature/CatalogHomePerformanceTest.php
./vendor/bin/rector process --dry-run app/Services/Catalog/CatalogHomeSnapshotCache.php tests/Feature/CatalogHomePerformanceTest.php
```

Expected: exit 0/no proposed Task 86 diff.

---

### Task 4: Broad verification and browser acceptance

**Files:**

- No application changes unless verification reveals a Task 86 regression.
- Store browser artifacts only under ignored `output/playwright/`.

**Interfaces:**

- Consumes: public `/`, `/ru`, `/api/v1/home`.
- Produces: desktop/mobile HTTP, H1, overflow, console/network and API counts
  evidence.

- [x] **Step 1: Запустить полный доступный backend suite**

Run:

```bash
php artisan test
```

Если project 256M limit исчерпан накопительно, повторить с отдельным
temporary test-only PHPUnit configuration at 1G, удалить его после запуска и
зафиксировать exact passed/skipped/failure counts. Foreign failures не
исправлять и не называть Task 86 regression без evidence.

- [x] **Step 2: Запустить frontend build**

Run:

```bash
npm run build
```

Expected: exit 0. Assets не меняются; build является compatibility gate.

- [x] **Step 3: Выполнить managed Chromium QA**

Проверить desktop `1440×1200` `/`, mobile `390×844` `/ru` и
`/api/v1/home`:

- HTTP 200;
- H1 «Сериалы онлайн»;
- 12 latest cards;
- отсутствие horizontal overflow;
- no console/page/first-party request errors;
- API counts `48/12/8/12` и 12 year buckets.

- [x] **Step 4: Проверить документацию без записи**

Run:

```bash
php artisan project:docs-refresh --check
```

Expected: exit 0 либо exact foreign drift, отдельно от Task 86 files.

---

### Task 5: Documentation, final compliance and delivery

**Files:**

- Modify: `docs/caching.md`
- Modify: `docs/performance.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify:
  `docs/superpowers/plans/2026-07-26-homepage-year-buckets-facet-snapshot.md`

**Interfaces:**

- Produces: canonical cache/performance contract, visitor history, Russian
  technical history, final compliance evidence and exact commits.

- [x] **Step 1: Обновить canonical owners**

`docs/caching.md` должен описать resource/dimensions/invalidation/failure.
`docs/performance.md` — measured first/repeat profile, exact parity и
limitations. Не копировать длинный design.

- [x] **Step 2: Проверить и обновить README**

Добавить visitor-facing строку только если код реально снижает повторный
rebuild. Сохранить русский текст и последний раздел
`История обновлений для посетителей`.

- [x] **Step 3: Добавить отдельную русскую запись CHANGELOG**

Записать фактический code result, TDD/broad/browser evidence, измерения,
неизменённые contracts и ограничения. Не переписывать прежние записи.

- [x] **Step 4: Перечитать требования и провести repository audit**

Повторно прочитать применимые canonical owners и Task 86. Выполнить:

```bash
rg -n \"homepage-year-buckets|year_buckets|groupBy\\('year'\\)|group by year\" app tests docs
rg -n \"TODO|TBD|dd\\(|dump\\(|var_dump\\(|print_r\\(\" app/Services/Catalog/CatalogHomeSnapshotCache.php tests/Feature/CatalogHomePerformanceTest.php
git diff --check -- app/Services/Catalog/CatalogHomeSnapshotCache.php tests/Feature/CatalogHomePerformanceTest.php docs/superpowers/specs/2026-07-26-homepage-year-buckets-facet-snapshot-design.md docs/superpowers/plans/2026-07-26-homepage-year-buckets-facet-snapshot.md
```

Просмотреть все matches; текстовый поиск сам по себе не разрешает удаление.

- [x] **Step 5: Запустить fresh final verification**

Повторить targeted test, Task 86 focused matrix, Pint, PHP syntax, scoped
PHPStan/Rector, Vite, browser/API smoke и managed docs check. Обновить
compliance matrix только по свежему output.

- [ ] **Step 6: Создать exact implementation/docs commit**

Проверить `git status --short --branch`, подтвердить `main`, собрать
alternate index из текущего `HEAD` и только Task 86 hunks. Commit message:

```text
perf: reuse homepage year bucket snapshot
```

Hook должен пройти; чужие staged/unstaged/untracked files не включать.

- [ ] **Step 7: Выполнить configured push**

Run:

```bash
composer git:doctor -- --remote
GIT_TERMINAL_PROMPT=0 git push origin main
```

Expected: fast-forward push. Если HTTPS credentials отсутствуют, записать
exact exit/error как `unresolved` до передачи данных; не менять remote, не
force-push и не переписывать history.
