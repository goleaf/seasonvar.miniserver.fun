# Top List Episode Aggregate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> `superpowers:executing-plans` to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ускорить точную cold классификацию movies/series Top 100 и sitemap,
убрав duplicate season list и distinct temp B-tree.

**Architecture:** `CatalogTopListQuery` сохраняет один canonical eligible
query. Ranked rows добавляют прежние projections/order; `hasItems()` вызывает
`exists()` на той же boundary. Episode classification становится
season-owned aggregate с flattened public episode subquery.

**Tech Stack:** PHP 8.5.8, Laravel 13.22.0, Boost 2.4.13, PHPUnit 12.5.32,
SQLite 3.46.1, Laravel Eloquent/Query Builder.

## Global constraints

- Работать только в существующей `main`, без branch/worktree.
- Не изменять routes, API, DTO output, UI, translations, cache identity,
  schema, indexes, writes, dependencies, config или `.env`.
- Сохранить entitlement, category, filters, ranking, limit и stable
  tie-breaker.
- Production code появляется только после наблюдаемого RED.
- Не включать foreign Tasks 92–95 или чужие staged/unstaged hunks.

---

### Task 1: Зафиксировать SQL и semantic contracts в RED

**Files:**

- Modify: `tests/Feature/CatalogTopListPageTest.php`
- Reference: `app/Services/Catalog/CatalogTopListQuery.php`

**Interfaces:**

- Consumes:
  `CatalogTopListQuery::items(CatalogTopListCategory, ?User, ?CatalogTopListFilters)`.
- Consumes: `CatalogTopListQuery::hasItems(CatalogTopListCategory): bool`.
- Preserves: ordered `Collection<int, CatalogTopListItem>`.

- [x] **Step 1: Обновить query-shape regression**

Потребовать season-owned join с public episode subquery,
`GROUP BY seasons.catalog_title_id`, `COUNT(*)`, отсутствие
`COUNT(DISTINCT episodes.id)` и отдельного
`seasons.id IN (SELECT seasons.id ...)`.

- [x] **Step 2: Добавить existence-path regression**

Захватить SQL `hasItems()` и потребовать `SELECT EXISTS` без
`top_weighted_score` и weighted `ORDER BY`.

- [x] **Step 3: Добавить exact availability fixture**

Проверить one-vs-many classification через несколько seasons; private,
future, expired и soft-deleted seasons/episodes не должны менять public
category.

- [x] **Step 4: Запустить RED**

```bash
php artisan test tests/Feature/CatalogTopListPageTest.php
```

Expected: прежние semantic assertions проходят, новые SQL-shape assertions
падают на duplicate season list/distinct и ranked `hasItems()`.

Observed: exact filter запустил `3` tests и `9` assertions. Availability
fixture прошёл, SQL-shape test упал на отсутствии flattened episode
subquery, а sitemap probe — на отсутствии `SELECT EXISTS`. Это требуемый
двойной RED до production edit.

### Task 2: Реализовать canonical base query и flattened aggregate

**Files:**

- Modify: `app/Services/Catalog/CatalogTopListQuery.php`
- Test: `tests/Feature/CatalogTopListPageTest.php`

- [ ] **Step 1: Выделить base rankable query**

Один private builder создаёт public/watchable/filter/rating/category
constraints. Никакой execution, select projection или order внутри helper.

- [ ] **Step 2: Переключить `hasItems()` на `exists()`**

Использовать тот же base query с empty filters. Не вычислять score и не
сортировать sitemap availability probe.

- [ ] **Step 3: Переписать episode aggregate**

Начать с `Season::query()->availableTo(null)`, присоединить constrained
`Episode` projection `id, season_id` через `joinSub`, сгруппировать по
`seasons.catalog_title_id`, использовать точный `COUNT(*) = 1` или
`COUNT(*) >= 2`.

- [ ] **Step 4: Сохранить ranked projection**

`rankedRows()` добавляет прежние rating/vote/weighted columns, четыре
детерминированных order clauses и bounded limit.

- [ ] **Step 5: Запустить focused GREEN**

```bash
php artisan test tests/Feature/CatalogTopListPageTest.php
php artisan test tests/Unit/CatalogTopListFiltersTest.php
```

### Task 3: Доказать parity и measured effect

**Files:**

- No production file change expected.

- [ ] **Step 1: Ordered payload parity**

В production-scale read-only DB сравнить hashes ID/rank/provider/rating/
votes/weighted score всех четырёх категорий с design baseline.

- [ ] **Step 2: Query plan**

Для movies и series получить `EXPLAIN QUERY PLAN`. Подтвердить отсутствие
`USE TEMP B-TREE FOR count(DISTINCT)` и отдельного available-season
`LIST SUBQUERY`; сохранить indexed title/media/rating lookups.

- [ ] **Step 3: Paired profile**

Минимум пять direct calls для `hasItems()` и `items()` каждой категории.
Зафиксировать диапазон/медиану ranking SQL и wall. Не выдавать локальные
observations за p95/SLA.

- [ ] **Step 4: Related compatibility matrix**

```bash
php artisan test --filter=CatalogTopList
php artisan test --filter=SitemapAndRobots
php artisan test tests/Unit/PublicPageCachePolicyTest.php
```

- [ ] **Step 5: Style/static checks**

```bash
./vendor/bin/pint --dirty --format agent
composer analyse
composer rector:check
```

Repository-wide foreign failures фиксируются отдельно и не расширяют scope.

### Task 4: Документация, verification и delivery

**Files:**

- Modify exact task hunks: `docs/catalog-search.md`
- Modify exact task hunks: `docs/performance.md`
- Modify exact task hunk: `docs/plans/current-task-plan.md`
- Modify exact task hunk: `README.md`
- Modify exact task hunk: `CHANGELOG.md`
- Preserve all foreign shared-tree files/hunks.

- [ ] **Step 1: Обновить canonical owners**

Записать фактическую SQL-форму, exact semantics, before/after observations,
EXPLAIN и отсутствие schema/cache изменения.

- [ ] **Step 2: README и CHANGELOG**

Добавить осмысленную русскую visitor-history запись о более быстрой загрузке
Top 100 и отдельный датированный русский changelog пункт.

- [ ] **Step 3: Broad verification**

```bash
php artisan test
npm run build
php artisan project:docs-check
composer docs:check
```

Frontend не меняется, но build остаётся project completion gate.

- [ ] **Step 4: Final compliance and legacy audit**

Перечитать requirements/design/plan, проверить точный diff, найти legacy
episode aggregate, duplicate ranking query, stale docs/tests, `TODO|FIXME`,
debug/secret markers, route/cache/schema/translation drift.

- [ ] **Step 5: Exact commit and push**

Проверить `main`, сформировать изолированный Task 96 commit без foreign
hunks и выполнить configured non-force push. Hook/auth/shared-tree failure
фиксируется `unresolved`, а не маскируется как успех.
