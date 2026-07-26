# Homepage Recently Added SQLite Order Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Устранить temporary B-tree из холодного anonymous
`recently_added` запроса главной страницы, сохранив canonical visibility,
результаты и все не-SQLite пути.

**Architecture:** Существующий `CatalogPublicDiscoveryQuery` остаётся
единственным владельцем публичного ranking query. Его метод
`recentlyAdded()` применяет database-driver-specific source только после
полного `eligibleQuery()`: для SQLite source компилируется через текущую
grammar как `catalog_titles INDEXED BY catalog_titles_created_at_idx`, для
остальных drivers builder не меняется. Новая schema, cache boundary или
service не вводятся.

**Tech Stack:** PHP 8.5.8, Laravel 13.22.0, Eloquent Query Builder,
SQLite, PHPUnit 12.5.32, Laravel Pint 1.29.3, Larastan 3.10.0.

---

### Task 1: Зафиксировать regression contract

**Files:**
- Modify: `tests/Feature/CatalogHomePerformanceTest.php`
- Test: `tests/Feature/CatalogHomePerformanceTest.php`

- [x] Добавить тест с несколькими опубликованными watchable titles,
  одинаковым `created_at`, excluded title и более старым title.
- [x] Через `DB::listen()` захватить только `recently_added` candidate SQL.
- [x] Проверить deterministic `created_at DESC, id DESC`, exclusions и
  отсутствие изменения candidate identity.
- [x] На SQLite проверить exact
  `catalog_titles indexed by catalog_titles_created_at_idx`.
- [x] Запустить новый test method и сохранить ожидаемый RED на текущем коде.

### Task 2: Реализовать минимальную SQLite-only границу

**Files:**
- Modify: `app/Services/Catalog/CatalogPublicDiscoveryQuery.php`
- Test: `tests/Feature/CatalogHomePerformanceTest.php`

- [x] После `eligibleQuery()` вызвать отдельный private method, который
  проверяет driver текущего connection.
- [x] Для SQLite сформировать source только из grammar-owned table/index
  identifiers и применить `fromRaw()`.
- [x] Для любого другого driver вернуть исходный builder без изменений.
- [x] Не менять predicates, joins, exclusions, order, candidate limit,
  cache keys или public DTO.
- [x] Запустить RED test до GREEN.

### Task 3: Проверить recommendation/homepage compatibility

**Files:**
- Verify: `tests/Feature/CatalogHomePerformanceTest.php`
- Verify: `tests/Feature/CatalogRecommendationListTest.php`
- Verify: `tests/Feature/CatalogPageTest.php`
- Verify: `tests/Feature/CatalogHomeContentAdditionTest.php`

- [x] Запустить весь `CatalogHomePerformanceTest`.
- [x] Запустить focused recently-added/recommendation tests.
- [x] Запустить homepage/content-addition test classes.
- [x] Подтвердить отсутствие N+1, route/API/translation/cache contract
  changes.

### Task 4: Статические и измеримые проверки

**Files:**
- Verify: `app/Services/Catalog/CatalogPublicDiscoveryQuery.php`
- Verify: `tests/Feature/CatalogHomePerformanceTest.php`

- [x] Запустить `./vendor/bin/pint --dirty --format agent`.
- [x] Запустить task-scoped Larastan с project config.
- [x] Запустить task-scoped Rector dry-run.
- [x] Повторить шесть current/indexed query comparisons и проверить exact
  ID parity.
- [x] Повторить пять `CatalogHomePageBuilder::data()` samples и
  `EXPLAIN QUERY PLAN`.
- [x] Удалить ignored profiling scripts через `apply_patch`.
- [x] Выполнить safe anonymous HTTPS series без generation bump/flush.

### Task 5: Широкая проверка и документация

**Files:**
- Modify: `docs/performance.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Verify: all applicable canonical requirement owners

- [x] Запустить максимально широкий релевантный test suite, отдельно
  зафиксировав foreign/shared-tree failures.
- [x] Обновить performance owner с before/after и plan evidence.
- [x] Обновить Task 66 compliance statuses и verification evidence.
- [x] Добавить visitor-facing README history и отдельный русский
  CHANGELOG item без внутренних секретов.
- [x] Перечитать применимые requirements и выполнить repository-wide scan
  legacy `recently_added`, duplicate query paths и stale index references.

### Task 6: Изолированная доставка

**Files:**
- Commit only Task 66 files/hunks in existing `main`

- [ ] Проверить `git status --short --branch` и текущую `main`.
- [ ] Создать exact alternate index от текущего HEAD; не stage/reset/stash
  foreign worktree changes.
- [ ] В shared docs собрать blobs только с Task 66 hunks.
- [ ] Выполнить project hooks или эквивалентную ручную matrix, если hooks
  блокируются чужими concurrent changes.
- [ ] Commit разрешённые изменения в `main`.
- [ ] Выполнить configured non-force push; auth/network rejection отметить
  `unresolved`, не выдавая за успех.
