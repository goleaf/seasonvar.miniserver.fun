# `TD-011` Catalog Count-Sort Aggregation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Заменить correlated aggregate трёх count-based сортировок `/titles` одним visibility-aware grouped aggregate join без изменения публичного порядка и пагинации.

**Architecture:** `CatalogTitleQuery` строит один grouped relation subquery только для выбранного `CatalogSort`, присоединяет его к result builder через `leftJoinSub()` и публикует прежний count alias через `COALESCE`. `CatalogTitlesPageBuilder` применяет эту boundary до существующих `sorted()`/`paginate()`, а `CatalogTitleCardCountLoader` после страницы продолжает загружать все presentation counts.

**Tech Stack:** PHP 8.5, Laravel 13.20, Livewire 4.3, Eloquent Query Builder, SQLite, PHPUnit 12.5.

## Global Constraints

- Работать только в существующей `main`; branch/worktree не создавать.
- Не менять schema, dependencies, lock files, cache keys/payloads, queue payloads, importer или runtime configuration.
- Не очищать cache/queue, не retry/rewrite jobs, не останавливать importer/workers и не маскировать latency timeout-ом.
- Сохранить `episodes_desc|seasons_desc|with_video`, guest/auth visibility, search/filter semantics, numbered pagination и tie-breakers `indexed_at DESC, id DESC`.
- Production code добавляется только после наблюдаемого RED; timings снимаются только в стабильном read-only окне.

---

### Task 1: Зафиксировать baseline и RED query-shape regression

**Files:**
- Modify: `tests/Feature/CatalogTitlesCardCountQueryTest.php`

**Interfaces:**
- Consumes: `CatalogTitlesPageBuilder::data(CatalogTitlesRequest $request, includeFacets: false): array`
- Produces: parameterized regression для порядка и отсутствия correlated aggregate у всех трёх count sorts.

- [x] **Step 1: Зафиксировать stable baseline**

После terminal importer/queue state вызвать builder для `sort=episodes_desc`, `sort=seasons_desc`, `sort=with_video` с `per_page=96`, записать elapsed/query time и нормализованный result SQL. Выполнить `EXPLAIN QUERY PLAN` только как read-only evidence.

Evidence 20.07.2026: terminal run ещё ожидал stale reservation, поэтому profile выполнен в доказанном idle окне до неизменившегося Redis retry score: workers/test/cache-warm idle, DB `9 ms` до и `9 ms` после, reserved retry-at оставался `1784520341`. Same-snapshot direct comparator вернул одинаковые 96 IDs: episodes `1 166,33→697,86 ms`, seasons `200,54→175,30 ms`, video `4 348,99→2 826,29 ms`; `EXPLAIN` заменил outer `CORRELATED SCALAR SUBQUERY` на materialized grouped `LEFT JOIN`.

- [x] **Step 2: Расширить regression data provider**

Добавить `countSortCases(): iterable` с exact cases:

```php
yield 'episodes' => ['episodes_desc', 'episodes_count'];
yield 'seasons' => ['seasons_desc', 'seasons_count'];
yield 'video' => ['with_video', 'published_media_count'];
```

Для каждого case создать low/high title, сформировать соответственно `1/2` доступных relations, собрать SQL через `DB::listen()`, проверить high→low order и точные count attributes.

- [x] **Step 3: Запретить correlated sort aggregate**

Нормализовать SQL и отфильтровать result query по прежним shapes:

```php
(select count(*) from seasons where catalog_titles.id = seasons.catalog_title_id
(select count(*) from episodes inner join seasons
(select count(*) from licensed_media where catalog_titles.id = licensed_media.catalog_title_id
```

Ожидать пустой список для каждого sort, не запрещая grouped presentation loader текущей страницы.

- [x] **Step 4: Запустить RED**

Run:

```bash
php artisan test --compact tests/Feature/CatalogTitlesCardCountQueryTest.php
```

Expected: semantic order/count assertions проходят, три datasets падают ровно из-за одного correlated sort aggregate в result query.

Evidence 20.07.2026: RED завершился `1 passed / 3 failed`, 14 assertions; каждый dataset сохранил ожидаемый high→low order и нашёл ровно один correlated result query для своего aggregate.

---

### Task 2: Применить grouped count-sort aggregate

**Files:**
- Modify: `app/Services/Catalog/CatalogTitleQuery.php`
- Modify: `app/Services/Catalog/CatalogTitlesPageBuilder.php`
- Test: `tests/Feature/CatalogTitlesCardCountQueryTest.php`

**Interfaces:**
- Produces: `CatalogTitleQuery::withCardCountSortAggregate(Builder $query, CatalogSort $sort, ?User $user): Builder`
- Preserves: `CatalogTitleQuery::sorted(Builder $query, CatalogSort $sort): Builder`

- [x] **Step 1: Добавить grouped aggregate boundary**

В `CatalogTitleQuery` добавить метод, который для sort строит один из трёх query:

```php
public function withCardCountSortAggregate(
    Builder $query,
    CatalogSort $sort,
    ?User $user,
): Builder
```

Season query группирует `availableTo($user)` по `catalog_title_id`; episode query суммирует доступные episode counts только через доступные seasons; media query группирует `availableTo($user)->forAvailableReleases($user)`. Каждый branch вызывает private helper с constant alias/column, `leftJoinSub()` и `COALESCE(alias.aggregate_count, 0) AS column`.

- [x] **Step 2: Заменить sort-only `withCount()` в builder**

Удалить `$cardCountQueries`, `$sortCountKeys`, `$sortCardCountQueries`. В обеих ветках — ranked ID query и ordinary result query — вызвать:

```php
$this->query->withCardCountSortAggregate($query, $sortOption, $request->user());
```

до `sorted()`. Не менять post-pagination `CatalogTitleCardCountLoader`.

- [x] **Step 3: Запустить GREEN**

Run:

```bash
php artisan test --compact tests/Feature/CatalogTitlesCardCountQueryTest.php
```

Expected: все datasets проходят; generated result SQL содержит grouped `LEFT JOIN`, correlated count отсутствует.

Evidence 20.07.2026: первый GREEN дважды завершился `4/4`, 14 assertions после детерминизации fixture numbers. Ranked/default expansion и второй RED на повтор aggregate в paginator total завершились `5 passed / 3 failed`, 32 assertions; финальный GREEN — `8/8`, 32 assertions.

- [x] **Step 4: Запустить соседние catalog contracts**

Run:

```bash
php artisan test --compact tests/Feature/CatalogAdvancedFilterTest.php tests/Feature/CatalogPageTest.php tests/Feature/PublicPageResponseCacheTest.php tests/Feature/Api/V1/CatalogDiscoveryTest.php
```

Expected: filters, sort allowlist, ranked/default pagination, response cache и mobile API contracts проходят без изменения totals/order.

Evidence 20.07.2026: после обоих GREEN соседняя matrix завершилась `119/119`, 1 069 assertions; до paginator correction она давала `111/111`, 1 037 assertions, а безопасный refactor общего episode aggregate отдельно прошёл count+advanced filters `17/17`, 95 assertions.

- [x] **Step 5: Отформатировать PHP и повторить focused tests**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --compact tests/Feature/CatalogTitlesCardCountQueryTest.php
```

Expected: Pint изменяет только scoped PHP files; regression остаётся GREEN.

Evidence 20.07.2026: scoped Pint прошёл; Larastan — `0 errors`, Rector — `0 diffs`.

---

### Task 3: Профиль, документация и delivery

**Files:**
- Modify: `docs/performance.md`
- Modify: `docs/caching.md`
- Modify: `docs/catalog-search.md`
- Modify: `docs/maintenance/technical-debt.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `CHANGELOG.md`
- Modify: `README.md` only for a confirmed visitor-visible performance result

**Interfaces:**
- Consumes: RED/GREEN evidence, stable before/after profiles and verification output.
- Produces: owner docs, compliance matrix, rollback evidence and published `main` snapshot.

- [x] **Step 1: Снять after profile**

В том же stable load profile повторить три direct builder calls и `EXPLAIN QUERY PLAN`. Зафиксировать before/after как diagnostic observation, не p95/SLA; не выполнять cache clear или повтор queue jobs.

Evidence 20.07.2026: первый grouped builder profile показал повтор materialization во внутреннем paginator count. После второго RED/GREEN total передаётся из исходной filtered query; query count сохранился `17/11/11`, total — `32 940`, а full builder episodes изменился `1 807,01→1 230,60 ms`, video `5 906,74→3 586,10 ms`; seasons `735,87→780,03 ms` находится в шуме, при direct result comparator он изменился `200,54→175,30 ms`. Значения diagnostic, не SLA; остаточный `with_video` cost остаётся открытым.

- [x] **Step 2: Обновить owners и compliance**

Зафиксировать выбранный grouped join, exact semantic parity, cross-feature matrix, SQLite/production impact, rollback и остаточный contention risk. Перечитать `README.md`; добавить visitor history только если подтверждено реальное ускорение доступной посетителю сортировки.

- [x] **Step 3: Найти остаточные реализации**

Run:

```bash
rg -n "EpisodesDesc|SeasonsDesc|VideoDesc|episodes_count|seasons_count|published_media_count|withCount\(" app routes resources tests docs
git diff --check
```

Проверить каждый найденный path; другие consumers `publicCardCounts()` не удалять без dependency evidence.

Evidence 20.07.2026: repository-wide поиск не нашёл прежний `$sortCardCountQueries` в runtime; оставшееся упоминание находится только в историческом completed plan. Другие `withCount()` принадлежат API, administration, recommendations и importer contracts и не являются duplicate sort implementation.

- [x] **Step 4: Выполнить repository gates**

Run:

```bash
COMPOSER_ALLOW_SUPERUSER=1 composer analyse
php artisan project:docs-refresh --check --no-interaction
php artisan test --compact
npm run build
```

Expected: Larastan/Rector/audits/docs checks проходят; полный PHPUnit и Vite build завершаются без новых failures.

Evidence 20.07.2026: `Pint`, Rector dry-run, Larastan, managed-doc check, `git diff --check`, Composer/npm audit и Vite build (`23` modules) прошли. Повторный полный PHPUnit завершился результатом `1 453` tests, `1 442` passed, `11` expected skipped и `123 088` assertions; финальный focused count-sort regression повторно прошёл `8/8`, `32` assertions.

- [x] **Step 5: Проверить public behavior**

В managed Chromium проверить desktop/mobile для одного count sort и page 2: `200`, сохранённый `sort`, стабильный порядок/pagination, no overflow/console/page/request failures. Не заявлять non-Chromium/provider evidence.

Evidence 20.07.2026: documented wrapper не смог запустить отсутствующий system Chrome, поэтому использован разрешённый fallback с repository Playwright Chromium `1228`. На production HTTPS все шесть read-only сценариев (`episodes_desc`, `seasons_desc`, `with_video`; desktop `1440×1200` и mobile `390×844`; `page=2`) вернули `200`, один `h1`, один `main`, `24` карточки, сохранили точные `sort`/`page`, не создали horizontal overflow, console/page errors или failed same-origin requests. Снимки сохранены в ignored `output/playwright/task26-catalog-count-sort/`; non-Chromium и provider media не проверялись.

- [ ] **Step 6: Commit и push только разрешённого scope**

Проверить `git status --short --branch`, ветку `main`, staged diff и отсутствие чужих файлов. Commit/push выполняются только после завершения владельца параллельных изменений и чистого совместимого snapshot; force push запрещён. Проверить равенство local/origin SHA.
