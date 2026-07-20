# `TD-011` Catalog Card-Count Batching Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Устранить три correlated card-count subquery из обычной hydration `/titles`, сохранив count-based sorting и точные presentation counts.

**Architecture:** `CatalogTitlesPageBuilder` пагинирует прежний result query, но загружает counts текущих карточек существующим `CatalogTitleCardCountLoader`. Только сортировки по episode/season/media count сохраняют один соответствующий aggregate в result query.

**Tech Stack:** PHP 8.5, Laravel 13.20, Livewire 4.3, Eloquent, SQLite, PHPUnit, application-owned public page cache.

## Global Constraints

- Работать только в существующей `main`; не создавать branch/worktree.
- Не менять schema, dependencies, lock files, cache key/payload formats, queue payloads или runtime configuration.
- Не очищать cache/queues, не повторять jobs вручную и не останавливать importer/workers ради benchmark.
- Сохранить guest/auth visibility, locale, paginator URLs, sort semantics, Livewire state и rollback.
- Production code добавляется только после наблюдаемого RED.

---

### Task 1: Зафиксировать catalog hydration regression

**Files:**
- Create: `tests/Feature/CatalogTitlesCardCountQueryTest.php`

**Interfaces:**
- Consumes: `CatalogTitlesPageBuilder::data(CatalogTitlesRequest $request, includeFacets: false): array`
- Produces: regression для точных card counts и query shape обычной выдачи.

- [x] **Step 1: Создать semantic fixture и listener**

Создать published `CatalogTitle`, `Season`, `Episode`, `LicensedMedia`; собрать валидированный guest `CatalogTitlesRequest` с `per_page=96`; сохранить нормализованный SQL через `DB::listen()`.

- [x] **Step 2: Проверить counts и отсутствие correlated hydration**

Проверить `seasons_count=1`, `episodes_count=1`, `published_media_count=1`. Отфильтровать title hydration SQL с `(select count(*) from seasons`, `(select count(*) from episodes` или `(select count(*) from licensed_media` и ожидать пустой список при default sort.

- [x] **Step 3: Запустить RED**

Run: `php artisan test --compact tests/Feature/CatalogTitlesCardCountQueryTest.php`

Expected: semantic assertions проходят, query-shape assertion падает на существующем `withCount($cardCounts)`.

Evidence 20.07.2026: RED завершился `1 failed`, 5 assertions; counts прошли, query-shape нашёл один hydration SQL с тремя correlated subquery.

---

### Task 2: Переиспользовать grouped card-count loader

**Files:**
- Modify: `app/Services/Catalog/CatalogTitlesPageBuilder.php`
- Test: `tests/Feature/CatalogTitlesCardCountQueryTest.php`

**Interfaces:**
- Consumes: `CatalogTitleCardCountLoader::load(Collection $titles, ?User $user): Collection`
- Produces: paginator collection с тремя прежними count attributes без correlated hydration.

- [x] **Step 1: Inject loader и вычислить sort-only count map**

Добавить constructor dependency `CatalogTitleCardCountLoader $cardCounts`. Перенести `sortCountKeys` перед ветвление и построить `$sortCardCounts = array_intersect_key($this->query->publicCardCounts($request->user()), array_flip($sortCountKeys))`.

- [x] **Step 2: Удалить общую correlated hydration**

В ranked-search hydration удалить `->withCount($cardCounts)`. В ordinary query удалить общую `->withCount($cardCounts)` и применять `withCount($sortCardCounts)` только когда map не пуст.

- [x] **Step 3: Batch-load bounded page collection**

До `CatalogUserCardStateLoader` выполнить:

```php
$catalogTitles->setCollection(
    $this->cardCounts->load($catalogTitles->getCollection(), $request->user()),
);
```

- [x] **Step 4: Сохранить count-sort contract**

Добавить test с двумя тайтлами и разным episode count для `sort=episodes_desc`; проверить порядок и наличие всех трёх attributes. SQL может содержать только необходимый episodes aggregate для сортировки.

- [x] **Step 5: Запустить GREEN и соседние tests**

Run:

```bash
php artisan test --compact tests/Feature/CatalogTitlesCardCountQueryTest.php
php artisan test --compact tests/Feature/CatalogAdvancedFilterTest.php tests/Feature/CatalogPageTest.php tests/Feature/PublicPageResponseCacheTest.php tests/Feature/CatalogRecommendationTitleLoaderQueryTest.php
```

Expected: regression и существующие sorting/filter/cache contracts проходят.

Evidence 20.07.2026: GREEN/characterization — 2 tests/9 assertions; финальный affected suite — 113 tests/1 045 assertions. Facet query-count baseline намеренно изменился с 12 до 14, Livewire initial/update — с 8 до 10, deferred — с 12 до 14 из-за двух grouped queries в fixture без сезонов; все budgets остались bounded, а option count не меняет число запросов.

---

### Task 3: Документация, verification и delivery

**Files:**
- Modify: `docs/performance.md`
- Modify: `docs/caching.md`
- Modify: `docs/maintenance/technical-debt.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `CHANGELOG.md`
- Modify: `README.md` only if visitor-visible history requires the confirmed result

**Interfaces:**
- Consumes: RED/GREEN output, read-only before/after profile, natural HTTP MISS/HIT evidence.
- Produces: updated compliance, rollback/evidence, committed and pushed `main` snapshot.

- [x] **Step 1: Повторить direct profile**

Повторить `per_page=96`, `includeFacets=false` в stable idle window. Зафиксировать elapsed/query time и отсутствие correlated card hydration как diagnostic observation, не SLA.

- [x] **Step 2: Выполнить repository gates**

Run:

```bash
./vendor/bin/pint --dirty --format agent
COMPOSER_ALLOW_SUPERUSER=1 composer analyse
php artisan project:docs-refresh --check --no-interaction
git diff --check
php artisan test --compact
npm run build
```

Evidence 20.07.2026: scoped Pint, Larastan 0 errors, Rector 0 diffs, Composer/npm audits 0 advisories/vulnerabilities, managed docs/diff и Vite build 23 modules прошли; full PHPUnit — 1 444 tests/1 433 passed/11 expected skipped/123 046 assertions. Managed Chromium fallback проверил desktop/mobile catalog page/search: 4×`200`, корректные H1, no overflow/console/page/request/first-party failures; natural page-2 `MISS` DOMContentLoaded `3,59 s`, следующий `HIT` `1,67 s`.

- [x] **Step 3: Обновить owners и compliance**

Зафиксировать before/after, remaining count-sort cost, cross-feature impact, rollback и честный статус `TD-011`. Проверить `README.md`; не добавлять фиктивную visitor запись без фактического улучшения.

- [ ] **Step 4: Commit и push только своего scope**

Проверить `main`, staged diff и отсутствие чужих файлов в index. Commit/push выполнять без force только после завершения параллельных изменений, чтобы не включить importer scope.
