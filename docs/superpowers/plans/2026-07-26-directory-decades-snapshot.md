# План реализации быстрого snapshot десятилетий справочника годов

> Обязательный workflow: TDD RED → minimal GREEN → refactor → measured
> verification. Работать только в существующей `main`, не затрагивая foreign
> staged/unstaged scope общего дерева.

**Goal:** заменить дорогой вычисляемый `GROUP BY decade` на индексируемый
`DISTINCT year` rebuild и повторно использовать результат через существующий
`CatalogFacetSnapshotCache` для web `/years` и API V1.

**Architecture:** `CatalogDirectoryQuery` остаётся единственным владельцем
семантики годов/десятилетий. `decades()` читает compact scalar resource
`directory-decades-v1`; miss или cache failure вызывает private
`buildDecades()`, сохраняющий прежний `CatalogTitleQuery::visibleTo(null)` и
year bounds. Новый cache domain, service, migration или route не создаётся.

**Stack:** PHP 8.5, Laravel 13.22, Eloquent/Query Builder, existing
`TieredCache`/`CatalogFacetSnapshotCache`, SQLite, PHPUnit 12.5.

Design:
[`2026-07-26-directory-decades-snapshot-design.md`](../specs/2026-07-26-directory-decades-snapshot-design.md).

## Scope и safety

### Expected changed files

- `app/Services/Catalog/CatalogDirectoryQuery.php`;
- `tests/Feature/CatalogDirectoryQueryOptimizationTest.php`;
- `tests/Feature/CatalogFacetCacheTest.php`;
- linked design и этот detailed plan;
- exact Task 89 sections в `docs/caching.md`, `docs/performance.md`,
  `docs/catalog-search.md` и `docs/plans/current-task-plan.md`;
- exact Task 89 entries в `README.md` и `CHANGELOG.md`.

### Protected files и public contracts

- `routes/web.php`, `routes/api.php`, full-page
  `App\Livewire\CatalogDirectoryBrowser`;
- `CatalogDirectoryPageBuilder::data()` return shape;
- `GET /api/v1/catalog/directories/{directory}` Resources/pagination и
  `meta.decades`;
- `CatalogDirectoryQuery::decades(): Collection<int, int>`;
- exact guest-public visibility, year bounds, descending order and uniqueness;
- search, letter, sort, decade filters, page size, SEO/canonical and redirects;
- Homepage `year_buckets` resource и незавершённый Task 86 scope;
- `CacheDomain::CatalogFacets`, version, TTL, stale, lock, telemetry,
  failure fallback and after-commit invalidation;
- imports, administration, auth/authorization, Premium, payments,
  advertisements, regional/legal access, privacy, sitemap, recommendations,
  notifications and personal state;
- весь foreign shared-tree scope.

### Risks и решения

| Риск | Решение / verification |
| --- | --- |
| Visibility drift | Fixtures для draft/future/expired/authenticated/deleted |
| Неверный порядок/дубли | Exact descending integer assertion |
| Stale snapshot | CatalogFacets version-bump lifecycle test |
| Calendar/config rollover | `minimum_year` и resolved `maximum_year` в dimensions |
| Cache outage | Existing TieredCache authoritative rebuild matrix |
| SQLite-only rewrite | Portable `select()->distinct()->orderByDesc()->pluck()` |
| Unjustified schema | Existing index and EXPLAIN; no migration/index |
| Web/API regression | Exact route/API tests and related directory matrix |
| Shared Git contamination | Alternate index and exact hunk/path review |

## Task 1 — cache lifecycle RED

**Modify:** `tests/Feature/CatalogFacetCacheTest.php`

1. В начале test повысить `CacheDomain::CatalogFacets`.
2. Создать visible titles в двух десятилетиях и получить первый result.
3. Создать visible title в новом десятилетии.
4. Доказать, что repeat до bump возвращает прежний snapshot.
5. Повысить `CatalogFacets`; доказать exact rebuild с новым десятилетием.

Run:

```bash
php artisan test --filter=test_directory_decades_snapshot_is_reused_until_the_facet_version_changes
```

Expected RED: текущий uncached `decades()` немедленно видит новый год.

## Task 2 — cold query shape RED

**Modify:** `tests/Feature/CatalogDirectoryQueryOptimizationTest.php`

1. Создать representative visible titles, дубли одного десятилетия и
   недоступные draft/future/expired/deleted/authenticated-only rows.
2. Повысить facet version перед capture.
3. Через существующий `captureQueries()` вызвать `decades()`.
4. Проверить exact result.
5. Найти единственный `catalog_titles` decades statement.
6. Требовать `select distinct year`, отсутствие `cast(year / 10`,
   `group by decade` и temporary aggregate form.

Run:

```bash
php artisan test --filter=test_decades_rebuild_selects_distinct_years_without_database_decade_grouping
```

Expected RED: текущий SQL содержит вычисляемый `GROUP BY`.

## Task 3 — minimal GREEN

**Modify:** `app/Services/Catalog/CatalogDirectoryQuery.php`

Сохранить публичную сигнатуру:

```php
/** @return Collection<int, int> */
public function decades(): Collection
```

Внутри использовать:

```php
$this->snapshots->remember(
    'directory-decades-v1',
    [
        'minimum_year' => $this->minimumYear(),
        'maximum_year' => $this->maximumYear(),
    ],
    fn (): array => $this->buildDecades()
        ->map(fn (int $decade): array => ['decade' => $decade])
        ->all(),
)
```

Добавить private builder:

```php
/** @return Collection<int, int> */
private function buildDecades(): Collection
```

Он обязан:

1. начать с `validYearTitles()`;
2. выбрать только `year`;
3. применить `distinct()` и `orderByDesc('year')`;
4. получить scalar collection через `pluck('year')`;
5. преобразовать год через `intdiv((int) $year, 10) * 10`;
6. применить `unique()->values()`.

Не добавлять DB-specific raw SQL, query в Blade, новый cache owner или
двойную visibility-логику.

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter='test_directory_decades_snapshot_is_reused_until_the_facet_version_changes|test_decades_rebuild_selects_distinct_years_without_database_decade_grouping'
```

Expected GREEN.

## Task 4 — parity, query и cache verification

1. Запустить полные классы:

```bash
php artisan test tests/Feature/CatalogFacetCacheTest.php
php artisan test tests/Feature/CatalogDirectoryQueryOptimizationTest.php
```

2. Запустить связанные directory/web/API/cache tests:

```bash
php artisan test --filter='CatalogDirectory|DirectoryApi|CatalogFacetCache|TieredCache|CatalogCacheInvalidator|CacheWarm'
```

3. На production-scale SQLite:

- сравнить old/new exact value hash;
- измерить 9 cold rebuild samples в array cache generation;
- подтвердить repeat `0` catalog SQL;
- выполнить `EXPLAIN QUERY PLAN` для final `DISTINCT year`;
- записать median/p90, query count и index evidence.

4. Запустить:

```bash
./vendor/bin/phpstan analyse app/Services/Catalog/CatalogDirectoryQuery.php tests/Feature/CatalogFacetCacheTest.php tests/Feature/CatalogDirectoryQueryOptimizationTest.php
./vendor/bin/rector process app/Services/Catalog/CatalogDirectoryQuery.php tests/Feature/CatalogFacetCacheTest.php tests/Feature/CatalogDirectoryQueryOptimizationTest.php --dry-run
php artisan test
```

Если общий runner конфликтует с foreign shared work, отделить task failure от
foreign failure и не маскировать unresolved результат.

## Task 5 — documentation и final compliance

**Modify exact Task 89 hunks only:**

- `docs/caching.md`: resource, dimensions, invalidation/failure;
- `docs/performance.md`: measured before/after and EXPLAIN;
- `docs/catalog-search.md`: unchanged web/API contract and compact decades;
- `docs/plans/current-task-plan.md`: final statuses/evidence;
- `README.md`: visitor-facing faster years directory;
- `CHANGELOG.md`: отдельная датированная русская техническая запись.

Перечитать applicable canonical requirements и выполнить:

```bash
git diff --check -- <exact-task-paths>
bash scripts/ci-check.sh docs
bash scripts/check-readme-policy.sh README.md
bash scripts/check-changelog-policy.sh CHANGELOG.md
```

Искать legacy scope:

```bash
rg -n "cast\\(year / 10|groupBy\\('decade'\\)|directory-decades" app tests docs
rg -n "dd\\(|dump\\(|var_dump|TODO|FIXME" <exact-task-paths>
```

## Task 6 — exact delivery

1. Проверить `git status --short --branch` и `git branch --show-current`.
2. Создать alternate index из финального concurrent `HEAD`.
3. Добавить только exact Task 89 paths/hunks.
4. Проверить cached name-status, diff, `diff --check`, secret/debug search и
   настоящий `.githooks/pre-commit`.
5. Создать task-specific commits только в `main`.
6. Восстановить real index representation только для Task 89 paths, не меняя
   foreign staged state.
7. Выполнить обычный non-force `git push origin main`; authentication или
   remote failure записать как `unresolved`.

## Наблюдаемое выполнение

- RED: 2 теста, 4 утверждения, 2 ожидаемых failure — uncached repeat увидел
  новый год, SQL сохранил вычисляемый `GROUP BY`.
- Minimal GREEN: 2 теста, 8 утверждений; итоговые focused classes:
  11 тестов, 49 утверждений.
- Web/API/cache/state/invalidation: 107 тестов, 990 утверждений.
- Warming matrix: 41 тест, 175 утверждений.
- Exact Pint, PHP syntax, PHPStan с process memory `1G` и Rector dry-run:
  успешно, Rector не предложил изменений.
- Production-scale parity: legacy/final SHA-256
  `2ec82a03a7372dd25ec09cc9aada0fe0b9dbcc2564b79a13b700dd9fe409bb72`;
  девять rebuild — median `5,14 ms`, p90 `7,04 ms`, median SQL `0,38 ms`;
  hot — `0,35 ms`, `0` catalog SQL.
- Final EXPLAIN: `SEARCH catalog_titles USING INDEX
  catalog_titles_published_year_idx`; temporary group отсутствует.
- Monolithic PHPUnit: повторные direct runs завершились process error `2`,
  потому что XML-enforced `memory_limit=256M` исчерпан в существующем
  `PublicPageResponseCacheTest::test_large_compressible_public_html_is_served_from_shared_cache`.
  Task 89 focused/related tests до и после этого остаются GREEN; full result
  честно остаётся unresolved.
- README и docs checks прошли. Whole-worktree CHANGELOG policy останавливается
  на foreign Task 87 строке со словом `source-health` до новой русской записи;
  exact staged Task 89 snapshot остаётся delivery gate.
