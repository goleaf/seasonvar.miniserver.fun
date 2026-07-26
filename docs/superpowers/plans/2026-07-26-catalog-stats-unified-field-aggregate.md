# Catalog Stats Unified Field Aggregate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сократить exact cold rebuild `/stats`, объединив обычные presence
и URL metrics каждой общей таблицы в один authoritative aggregate.

**Architecture:** `CatalogStatsPageBuilder` остаётся единственным query owner.
Один private table loader заполняет существующие `presentCounts`,
`distinctPresentCounts` и `absoluteUrlCounts`; Task 75 adaptive distinct
fallback сохраняется без изменения.

**Tech Stack:** PHP 8.5.8, Laravel 13.22.0, PHPUnit 12.5.32, SQLite 3.46.1,
Laravel Query Builder.

## Global Constraints

- Работать только в существующей `main`, без branch/worktree.
- Не изменять schema, indexes, routes, translations, UI, cache identity/TTL,
  write paths, dependencies, config или `.env`.
- Сохранить current filled/distinct/absolute и Task 75 fallback semantics.
- Все identifiers брать только из application-owned maps и оборачивать
  connection grammar; values передавать bindings.
- Production code появляется только после наблюдаемого RED.
- Не включать foreign Task 76/77 или staged Task 75 hunks в task commit.

---

### Task 1: Зафиксировать combined table aggregate в RED

**Files:**

- Modify: `tests/Feature/CatalogStatsQueryConsolidationTest.php`
- Reference: `app/Services/Catalog/CatalogStatsPageBuilder.php`

**Interfaces:**

- Consumes: `CatalogStatsPageBuilder::data(): array<string,mixed>`.
- Produces: exact query-shape/value regression общей `licensed_media`
  boundary.

- [x] **Step 1: Расширить exact fixture обычными presence values**

Проверить summary rows `Связано с сериалом`, `Связано с сезоном`,
`Связано с серией`, `С качеством`, `С форматом`, `С постоянным ключом`,
`Проверено для просмотра` вместе с существующими URL values.

- [x] **Step 2: Зафиксировать один statement**

Query listener должен найти ровно один `licensed_media` statement, который
одновременно содержит `present_...`, `path_distinct`,
`source_url_distinct` и `playback_url_path_mismatches`.

- [x] **Step 3: Запретить второй presence-only scan**

Отдельного statement с обычными `licensed_media` presence selectors без URL
distinct/mismatch aliases быть не должно.

- [x] **Step 4: Запустить RED**

```bash
php artisan test tests/Feature/CatalogStatsQueryConsolidationTest.php
```

Expected: semantic assertions проходят, combined-query assertion падает,
потому что production builder выполняет отдельные URL и presence aggregates.

Observed: `4` tests, `3` passed, `1` failed after `34` assertions exactly on
the missing combined `licensed_media` statement; semantic value assertions
reached the query-shape gate successfully.

### Task 2: Реализовать единый private loader

**Files:**

- Modify: `app/Services/Catalog/CatalogStatsPageBuilder.php`
- Test: `tests/Feature/CatalogStatsQueryConsolidationTest.php`

**Interfaces:**

- Produces:
  `private function loadFieldCounts(string $table, ?string $requiredColumn = null): void`.
- Preserves existing three build-local count arrays.

- [x] **Step 1: Собрать table profile**

Получить unique presence columns из `PRESENT_COUNT_COLUMNS` плюс optional
required column и URL field records из `externalUrlFields()`.

- [x] **Step 2: Сформировать selector union**

Для каждого presence column добавить один conditional `SUM`. Для URL fields
добавить distinct/absolute selectors, переиспользовав presence alias вместо
его дублирования.

- [x] **Step 3: Перенести Task 75 proof/fallback без semantic diff**

Оставить mismatch selector в том же primary statement и exact fallback при
любом mismatch. Не добавлять `playback_url_distinct` на fast path.

- [x] **Step 4: Переключить consumers**

`presentCount()`, `distinctPresentCount()` и `absoluteUrlCount()` вызывают
один loader. Удалить прежние duplicate loaders только после repository
search.

- [x] **Step 5: Запустить focused GREEN**

```bash
php artisan test tests/Feature/CatalogStatsQueryConsolidationTest.php
```

Expected: все exact values, adaptive fast path/fallback и combined query
contract проходят.

Observed: `4/4` tests passed with `50` assertions; the fast path, mismatch
fallback, exact values and single combined `licensed_media` statement are
GREEN. The final query-shape matrix covers all five shared tables.

### Task 3: Проверить совместимость и measured effect

**Files:**

- No production file change expected.

- [x] **Step 1: Focused matrix**

```bash
php artisan test --filter=CatalogStats
php artisan test tests/Feature/CatalogPageTest.php --filter=stats
php artisan test tests/Unit/CatalogStatsSnapshotCacheTest.php
php artisan test tests/Unit/CatalogStatsSnapshotSanitizerTest.php
```

- [x] **Step 2: Style/static gates**

```bash
./vendor/bin/pint --dirty --format agent
composer analyse
composer rector:check
```

Repository-wide failures outside exact Task 78 files фиксируются отдельно и
не исправляются без scope.

- [x] **Step 3: Production-scale profile**

Минимум три отдельных builder process: query count, wall/SQL time, maximum
RSS, filesystem output и hashes 21 URL rows. Target `142 → 137` считается
доказанным только фактическим listener result.

- [x] **Step 4: Exact parity**

Сравнить canonical public stats fixture и production-scale URL/table/index
hashes; никакое значение не принимается приблизительно.

Observed:

- focused stats/cache/page matrix: `19` tests, `163` assertions;
- related full `CatalogPageTest` + `CacheWarmJobTest`: `100` tests,
  `928` assertions;
- scoped Pint/PHPStan/Rector: GREEN;
- repository `composer analyse`: GREEN;
- repository Rector: только два foreign collection `never` diffs;
- three full builders: exact `137` SQL, RSS `75 980–76 620 KiB`;
- same-transaction parity: `42/42` presence and `21/21` URL fields exact;
- wall samples записаны как concurrent-load diagnostics, не before/after SLA.

### Task 4: Документация, final audit и delivery

**Files:**

- Modify: `docs/performance.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md` only for factual visitor-visible performance result
- Modify: `CHANGELOG.md`
- Preserve: all unrelated shared-worktree files.

- [x] **Step 1: Обновить performance owner**

Записать только измеренные before/after, exact semantics, RSS/temp I/O,
отсутствие schema/cache changes и rejected alternatives.

- [x] **Step 2: Проверить README**

Если verified rebuild действительно ускорен, добавить краткую русскую
visitor-history запись. Если результат не material, README не менять и
зафиксировать проверку в compliance matrix.

- [x] **Step 3: Обновить русский CHANGELOG**

Добавить отдельный датированный пункт, не сокращая и не изменяя прежние
entries.

- [x] **Step 4: Финальный audit**

Перечитать requirements/plan, проверить exact diff, найти legacy duplicate
loaders, stale query expectations, `TODO|FIXME`, debug/secret markers,
cache/schema/route/translation drift.

- [x] **Step 5: Verification**

Выполнить применимые focused/broad/static/build/docs gates. Frontend build
нужен только как project completion gate; UI assets не меняются.

- [x] **Step 6: Commit/push delivery assessment**

Проверить `main`, сформировать exact Task 78 commit без foreign hunks и
выполнить configured non-force push. Hook/auth/shared-tree failure отметить
`unresolved`, не обходить и не называть успехом.

Observed before delivery:

- performance owner, factual README history and Russian changelog updated;
- final requirements/diff/legacy/debug/secret audit completed;
- README policy and task-specific diff check passed;
- repository changelog policy remains blocked after the Task 78 entry by a
  foreign calendar entry, and managed-doc check remains blocked by foreign
  `docs/MAINTENANCE_LOG.md`;
- independent read-only review found no code issue and requested only this
  plan-status reconciliation; follow-up verdict is `Ready: Yes`;
- exact hook-enabled commit remains `unresolved_shared_tree`: updating or
  committing foreign managed-doc/rating hunks and bypassing hooks are
  prohibited; push was not run without a Task 78 commit.
