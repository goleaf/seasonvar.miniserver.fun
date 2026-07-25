# Collection Directory Grouped Summary Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: use
> `superpowers:executing-plans`. Work only in the existing `main`; the
> repository rule explicitly forbids worktrees/feature branches.

**Goal:** Заменить bounded, но всё ещё correlated membership counts второй
фазы public collection directory одним grouped aggregate по exact current-page
IDs, сохранив все публичные contracts.

**Architecture:** `CatalogCollectionQuery` продолжает владеть eligibility,
search, category filters, ordering, pagination и summary relations. Новый
`CatalogCollectionSummaryLoader` присоединяет к prepared summary builder один
bounded grouped counts subquery, использующий canonical guest-visible title
query. Остальные summary consumers не меняются.

**Tech Stack:** PHP `8.5.8`, Laravel `13.22.0`, Laravel Boost `2.4.13`,
Livewire `4.3.3`, SQLite, PHPUnit `12.5.32`, Pint `1.29.3`, Tailwind CSS
`4.3.2`, Vite `8.1.4`.

**Plan status:** `implementation_verified_delivery_pending`.

---

## Task 1: Зафиксировать RED SQL/correctness contract

**Files:**

- Create: `tests/Feature/CatalogCollectionPublicDirectoryQueryTest.php`
- Reference: `tests/Feature/CatalogCollectionDirectoryCategoryTest.php`
- Reference: `app/Services/Collections/CatalogCollectionQuery.php`

1. Создать public/approved collections больше одного page.
2. Добавить visible, unpublished и empty membership cases.
3. Через `DB::listen()` найти directory summary statement.
4. Assert desired contract:
   - query не содержит correlated
     `select count(*) from catalog_collection_items`;
   - query содержит grouped alias `directory_counts`;
   - hydrated counts сохраняют исходную correctness.
5. Запустить:

```bash
php artisan test tests/Feature/CatalogCollectionPublicDirectoryQueryTest.php
```

Expected: exact SQL-shape assertion fails; fixture/count assertions pass.

## Task 2: Реализовать minimal grouped loader

**Files:**

- Create: `app/Services/Collections/CatalogCollectionSummaryLoader.php`
- Modify: `app/Services/Collections/CatalogCollectionQuery.php`

1. Создать final service с constructor-injected `CatalogTitleQuery`.
2. Нормализовать page IDs до unique positive integers и отклонять внутренний
   overflow свыше 36.
3. Создать guest-visible title ID subquery через `visibleTo(null)`.
4. Создать one grouped membership subquery:

```php
COUNT(*) AS total_items_count
SUM(CASE WHEN visible_titles.id IS NULL THEN 0 ELSE 1 END)
    AS visible_items_count
```

5. Ограничить aggregate exact page IDs.
6. `leftJoinSub()` aggregate к prepared collection builder и добавить
   `COALESCE` scalar attributes.
7. Добавить `summaryQuery(bool $withCounts = true)`; прежний default оставить
   всем существующим consumers.
8. В `publicDirectory()` использовать loader + `summaryQuery(false)`.
9. Сохранить exact phase-one order и исходный paginator instance.
10. Запустить:

```bash
php artisan test tests/Feature/CatalogCollectionPublicDirectoryQueryTest.php
./vendor/bin/pint --dirty --format agent
```

Expected: GREEN; форматирование GREEN.

## Task 3: Закрыть regression matrix

**Files:**

- Modify if required:
  `tests/Feature/CatalogCollectionDirectoryCategoryTest.php`
- Modify if required: `tests/Feature/UnifiedDiscoveryCollectionsTest.php`

1. Assert aggregate SQL ограничен текущими 12/6 IDs и не читает IDs соседней
   страницы.
2. Assert statement count не растёт относительно current directory ceiling.
3. Assert exact order и paginator total/current/last page.
4. Запустить:

```bash
php artisan test \
  tests/Feature/CatalogCollectionPublicDirectoryQueryTest.php \
  tests/Feature/CatalogCollectionDirectoryCategoryTest.php \
  tests/Feature/CatalogCollectionExplorerCategoryTest.php \
  tests/Feature/UnifiedDiscoveryCollectionsTest.php
```

## Task 4: Production-size read-only evidence

**Files:**

- Modify: `docs/plans/current-task-plan.md`
- Modify: this plan

1. На неизменном SQLite snapshot выполнить минимум пять read-only warm samples.
2. Зафиксировать elapsed, DB time, statement count и summary statement time.
3. Выполнить `EXPLAIN QUERY PLAN` grouped statement.
4. Сверить total/visible sums прежнего и нового query.
5. Не называть локальный результат SLA.

## Task 5: Cross-feature/static/browser verification

1. Запустить:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=CatalogCollection
php artisan test --filter=UnifiedDiscoveryCollectionsTest
./vendor/bin/phpstan analyse app/Services/Collections/CatalogCollectionSummaryLoader.php app/Services/Collections/CatalogCollectionQuery.php --memory-limit=1G
php artisan project:docs-refresh --check
npm run build
```

2. Выполнить browser smoke `/discover/popular` в desktop/mobile:
   text-only cards, category filters, search/sort/pagination, no console/network
   errors, no overflow.
3. Проверить repository-wide отсутствие collection cover/poster fallback,
   duplicate directory loaders, Blade queries и stale correlated directory
   counts.

## Task 6: Документация и delivery

**Files:**

- Modify:
  `docs/superpowers/plans/2026-07-24-discovery-sections-end-to-end-improvement-master-plan.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

1. Обновить master Task 7: убрать stale cover step и отметить grouped second
   phase implementation/evidence.
2. Проверить `docs/performance.md`; не дублировать уже канонический grouped
   contract, добавить только фактическое измерение, если нужно.
3. Обновить README visitor history только после подтверждённого результата.
4. Добавить отдельную русскую CHANGELOG entry.
5. Перечитать требования, spec, plan и diff; обновить compliance matrix.
6. Проверить `git status --short --branch`, branch `main`, exact owned paths.
7. Commit только Task 59 scope через isolated index, не меняя foreign
   staged/unstaged files.
8. Push configured remote; auth/network rejection записать `unresolved`.

## Protected contracts

- Все route names/URLs, localized variants и 404 decisions.
- `CatalogCollectionQuery` как sole collection read boundary.
- Guest title visibility/entitlement и public/approved/deleted/source rules.
- Exact search/sort/category/subcategory/page state and paginator metadata.
- Text-only cards; no image/fallback/upload/storage.
- RU/EN, SEO/sitemap/API, importer, cache/invalidation and user-private state.
- SQLite compatibility, no migration/data/cache/queue/dependency/env change.

## Rollback

Revert Task 59 PHP/docs/tests commit. `publicDirectory()` снова использует
existing bounded `withCount()` summary. No persistent or operational rollback
is required.

## Verification evidence 26.07.2026

- Exact RED: новый focused test остановился на отсутствии
  `left join (...) as directory_counts`; SQL показал два прежних correlated
  membership `select count(*)`.
- GREEN focused: `1 test / 20 assertions`.
- Category/directory/discovery matrix: `16 / 111`.
- Все collection-named tests: `71 / 457`.
- Adjacent discovery interaction/unified directory: `21 / 96`.
- `./vendor/bin/pint --dirty --format agent`: GREEN.
- `PHPStan` нового `CatalogCollectionSummaryLoader`: GREEN, 0 errors.
  Отдельный анализ всего legacy `CatalogCollectionQuery` по-прежнему показывает
  пять прежних ошибок типов в source-sync summary, не затронутых Task 59.
- `php artisan project:docs-refresh --check`: GREEN.
- `npm run build`: GREEN.
- `discovery-collections.spec.js`: Desktop Chromium и Mobile Chromium
  `2/2`, без local HTTP/console/page errors или horizontal overflow;
  collection explorer не содержит `<img>`.
- Штатный полный `php artisan test` после длительного единого процесса
  остановился на прежнем независимом GD memory exhaustion при `256 MB` в
  `UserProfileMediaProcessingTest`; exact test отдельным чистым процессом
  прошёл `1 / 12`. Task 59 profile media не изменяет.
- Production-size SQLite read-only:
  baseline `211,38 ms` total / `180,78 ms` summary; GREEN first read
  `138,83 / 111,48 ms`; шесть warm reads `114,80–142,34 ms`, median около
  `117 ms`, 6 statements, counts `35 375 / 35 375`.
- `EXPLAIN QUERY PLAN`: materialized bounded `directory_counts`,
  `catalog_collection_items_collection_title_unique`, title PK lookup,
  collection PK lookup и automatic covering join index. Correlated membership
  node отсутствует; отдельный indexed source-record existence check сохранён.
- Финальный reread/scan подтвердил: public cards остаются text-only; active
  cover runtime отсутствует, а `cover_url=null`, exact purge service и
  schema-removal tests являются намеренной совместимостью/cleanup evidence;
  Blade queries, duplicate public directory loader, debug/TODO и stale
  correlated public-directory count отсутствуют.
