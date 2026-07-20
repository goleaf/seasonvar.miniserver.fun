# `TD-011` Homepage Card-Count Batching Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Устранить четыре подтверждённые correlated card-count query из cold homepage build, сохранив точные публичные counts и существующую cache architecture.

**Architecture:** `CatalogHomePageBuilder` гидратирует те же bounded модели без `withCount`, затем передаёт все homepage card instances в существующий `CatalogTitleCardCountLoader`. Loader выполняет один grouped batch на уникальные title IDs и выставляет counts каждому model instance.

**Tech Stack:** PHP 8.5, Laravel 13.20, Eloquent, SQLite, PHPUnit, application-owned `TieredCache`.

## Global Constraints

- Работать только в существующей `main`; не создавать branch/worktree.
- Не менять dependencies, lock files, schema, cache key/payload formats, Redis/Memcached, queue payloads или runtime requirements.
- Не очищать cache/queues и не останавливать importer ради benchmark.
- Сохранить guest/auth visibility, locale, card ordering, counts, translations, public routes и rollback.
- Production code только после наблюдаемого RED; verification evidence фиксируется честно.

---

### Task 1: Зафиксировать correlated-query regression

**Files:**
- Create: `tests/Feature/CatalogHomeCardCountQueryTest.php`

**Interfaces:**
- Consumes: `CatalogHomePageBuilder::data(?User $user = null): array`
- Produces: regression, запрещающий `select count(*) ... catalog_titles.id` в homepage title hydration при сохранении `seasons_count`, `episodes_count`, `published_media_count`.

- [x] **Step 1: Добавить failing test**

Создать published title, season, episode и licensed media, вызвать builder с `DB::listen()`, проверить три count attribute и подтвердить, что ни один title hydration SQL не содержит correlated `select count(*) from seasons|episodes|licensed_media`.

- [x] **Step 2: Запустить RED**

Run: `php artisan test --compact tests/Feature/CatalogHomeCardCountQueryTest.php`

Expected: semantic counts проходят, query-shape assertion падает на текущем `withCount` SQL.

Evidence 20.07.2026: ожидаемый RED — `1 failed`, 5 assertions; semantic counts прошли, assertion обнаружил четыре correlated homepage card-count query.

### Task 2: Переиспользовать canonical grouped loader

**Files:**
- Modify: `app/Services/Catalog/CatalogHomePageBuilder.php`
- Test: `tests/Feature/CatalogHomeCardCountQueryTest.php`

**Interfaces:**
- Consumes: `CatalogTitleCardCountLoader::load(Collection $titles, ?User $user): Collection`
- Produces: homepage models с теми же count attributes без correlated count subqueries.

- [x] **Step 1: Inject loader и убрать homepage `withCount`**

Добавить constructor dependency `CatalogTitleCardCountLoader $cardCounts`, убрать `withCount()` из `titleSummaryQuery()` и nested `latestMedia.catalogTitle` eager load.

- [x] **Step 2: Выполнить один batch после hydration**

Перед `latestReleaseGroups` вызвать loader для concatenated collection из `latestTitles`, `featuredTitles`, `videoTitles` и каждого non-null `latestMedia.catalogTitle`. Не применять `unique()` к model collection: loader сам ограничивает SQL уникальными IDs и должен выставить attributes каждому отдельному instance.

- [x] **Step 3: Запустить GREEN и соседние contracts**

Run:

```bash
php artisan test --compact tests/Feature/CatalogHomeCardCountQueryTest.php
php artisan test --compact tests/Feature/CatalogHomeContentAdditionTest.php tests/Feature/PublicPageResponseCacheTest.php tests/Feature/CatalogRecommendationTitleLoaderQueryTest.php
```

Expected: новый regression и все соседние homepage/cache/loader contracts проходят.

Evidence 20.07.2026: объединённый focused run — 16 tests, 134 assertions; nested latest-media title также получил все три count attributes, correlated count queries отсутствуют.

### Task 3: Verification, production evidence и delivery

**Files:**
- Modify: `docs/performance.md`
- Modify: `docs/caching.md`
- Modify: `docs/maintenance/technical-debt.md`
- Modify: `docs/maintenance/runtime-compatibility.md`
- Modify: `docs/operations/logging-and-health.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `CHANGELOG.md`
- Modify: `README.md` only when visitor-visible/product-state wording requires it

**Interfaces:**
- Consumes: RED/GREEN output, read-only builder profile, natural production cache state and project gates.
- Produces: dated evidence, honest TD-011 status, rollback, committed/pushed `main` snapshot.

- [x] **Step 1: Format и статический review**

Run:

```bash
./vendor/bin/pint --dirty --format agent
COMPOSER_ALLOW_SUPERUSER=1 composer analyse
php artisan project:docs-refresh --check --no-interaction
git diff --check
```

- [x] **Step 2: Повторить builder profile и full gates**

Повторить тот же read-only `CatalogHomePageBuilder` SQL/timing probe, затем запустить `php artisan test --compact` и `npm run build`. Значения назвать diagnostic observation, не SLA.

- [x] **Step 3: Production-safe activation**

Не очищать fresh `/en`. После natural invalidation/missing state разрешён read-only MISS→HIT probe; иначе подтвердить текущие HIT и worker/health state и оставить cold HTTP activation evidence честно `not_performed`. Schema/data/cache/queue rollback отсутствует.

- [ ] **Step 4: Документация, commit и push**

Обновить compliance matrix и русский `CHANGELOG.md`, проверить актуальность `README.md`, затем на чистой `main` выполнить configured hooks, commit, non-force push и сравнить local/origin/remote SHA.

Evidence 20.07.2026:

- Pint, Larastan (`0` errors), managed-doc check и `git diff --check` прошли.
- Same builder profile: `1 355,92 ms`, 57 queries/`1 119,23 ms` SQL, 0 correlated counts; full PHPUnit: 1 433 tests, 1 422 passed, 11 skipped, 122 992 assertions; Vite: 23 modules.
- PHP-FPM graceful reload прошёл, service остался active. Cache/queue не очищались. Isolated desktop/mobile routes дали шесть `200` и один cold `/` timeout под одновременными critical warm/importer; последующие `/` HIT подтвердился отдельным `200`. Остаточный catalogue/contention риск остаётся в `TD-011`, а не скрыт успешным builder profile.
