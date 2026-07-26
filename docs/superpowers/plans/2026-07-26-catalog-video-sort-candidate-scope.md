# Candidate-scoped `with_video` Sort Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: use
> `superpowers:executing-plans` while implementing this plan.

**Goal:** Не считать доступные media всех тайтлов при фильтрованной или
поисковой сортировке `/titles?sort=with_video`, сохранив точные visibility,
порядок и pagination contracts.

**Architecture:** `CatalogTitleQuery` клонирует уже ограниченный result
builder в ID-only candidate subquery до media join. Существующий
visibility-aware media aggregate добавляет `whereIn` по этим ID и сохраняет
grouped `LEFT JOIN`/`COALESCE`.

**Tech Stack:** PHP 8.5.8, Laravel 13.22.0, Laravel Boost 2.4.13, Eloquent,
SQLite, PHPUnit 12.5.32.

## Global constraints

- Работать только в существующей `main`; branch/worktree не создавать.
- Не изменять production data/cache/queue/processes и не выполнять
  destructive Artisan/SQLite operations.
- Не добавлять migration/index/dependency/config/env/cache domain.
- Не изменять `with_video`, visibility, FTS/filter semantics, paginator
  total, zero-count titles и tie-breakers.
- Сохранять чужие изменения shared worktree; commit строить через точный
  alternate index только из Task 69 patch.

## Task 1 — Preparation and RED

**Files:**

- Modify: `tests/Feature/CatalogTitlesCardCountQueryTest.php`
- Modify: `docs/plans/current-task-plan.md`

**Contracts:**

- Consume:
  `CatalogTitlesPageBuilder::data(CatalogTitlesRequest $request,
  includeFacets: false): array`
- Preserve: exact `LengthAwarePaginator`, card counts, ordinary/FTS sort.
- Produce: candidate-scope SQL regression.

- [x] Добавить fixture с двумя тайтлами выбранного года, двумя тайтлами
  другого года и разными media counts.
- [x] Собрать normalized result SQL через `DB::listen()`.
- [x] Проверить total/order/count только выбранного year scope.
- [x] Потребовать
  `licensed_media.catalog_title_id in (select catalog_titles.id ...)` и
  повторение year predicate внутри candidate subquery.
- [x] Запустить точный test method и получить наблюдаемый RED только на
  отсутствующем candidate boundary.

Evidence: exact RED — `1` test, `5` assertions, semantic
total/order/count прошли; единственный failure на строке 179 показывает,
что result SQL не содержит candidate `whereIn`.

## Task 2 — Minimal candidate-scoped aggregate

**Files:**

- Modify: `app/Services/Catalog/CatalogTitleQuery.php`
- Test: `tests/Feature/CatalogTitlesCardCountQueryTest.php`

**Contracts:**

- Preserve:
  `withCardCountSortAggregate(Builder, CatalogSort, ?User): Builder`
- Internal change:
  `mediaCountSortAggregate(?User, Builder $candidateTitleIds): Builder`
- Add private ID-only clone boundary.

- [x] В ветке `VideoDesc` получить клон result builder до join.
- [x] Удалить у клона eager loads/order и заменить select на
  `catalog_titles.id`.
- [x] Передать candidate builder в media aggregate.
- [x] Добавить qualified `whereIn` после canonical media/release scopes.
- [x] Не изменять season/episode sorts и presentation loader.
- [x] Запустить RED method, затем весь
  `CatalogTitlesCardCountQueryTest` до GREEN.
- [x] Добавить real FTS assertion, если parameter-ranked case не доказывает
  preservation candidate SQL.

Evidence: exact filtered GREEN — `1/6`; весь focused файл до FTS addition —
`9/38`; отдельный real FTS contract — `1/6`. Production change ограничен
одним query service.

## Task 3 — Profile and adjacent verification

**Files:**

- Test: catalog/search/API/cache suites.
- Modify only if evidence requires:
  `app/Services/Catalog/CatalogTitleQuery.php`,
  `tests/Feature/CatalogTitlesCardCountQueryTest.php`.

- [x] Повторить full/2024/2000/1980 builder profile без cache clear.
- [x] Снять `EXPLAIN QUERY PLAN` result query и подтвердить indexed
  `status + catalog_title_id` lookup/list subquery.
- [x] Сравнить exact result IDs/order before/after на одном snapshot.
- [x] Запустить focused count-sort test.
- [x] Запустить adjacent advanced filter, search query-plan, catalog page,
  API discovery и public page cache tests.
- [x] Запустить scoped Pint без форматирования foreign dirty PHP.
- [x] Запустить scoped Larastan/Rector, если команды проекта доступны.
- [x] Запустить полный `php artisan test --compact`; cumulative memory или
  foreign failure записать отдельно и проверить exact failed test.
- [x] `npm run build` не запускать только ради PHP-only change; выполнить,
  если Task 69 фактически затронет frontend assumptions.

Profile evidence: candidate/legacy hashes совпали для full/2024/2000/1980.
Paired full-catalog samples были в одном шумовом диапазоне; filtered direct
result query изменился `2 198,19→129,05 ms` для 2024,
`2 405,53→44,93 ms` для 2000 и `2 432,46→6,87 ms` для 1980.
`EXPLAIN` для 2024 выбрал
`licensed_media_publication_lookup_idx (catalog_title_id=? AND status=? AND
audience=? AND deleted_at=?)`, `LIST SUBQUERY` и
`catalog_titles_published_year_idx`.

Verification evidence: adjacent matrix `138/138`, `1 234` assertions;
final focused `10/10`, `44` assertions; scoped Pint и Rector clean, Larastan
`0` errors. Generic aggregate helper получил корректный method template
`TAggregate of Model` вместо инвариантного `Builder<Model>`; runtime contract
не менялся. Full process достиг известного cumulative `256M` exhaustion при
создании application перед `TieredCacheTest`; exact interrupted test прошёл
`1/5`, весь файл `10/35`, focused каталог повторно `10/44`. Task 69 не
затронула frontend, поэтому Vite build не является применимой проверкой.

## Task 4 — Documentation and delivery

**Files:**

- Modify: `docs/performance.md`
- Modify: `docs/maintenance/technical-debt.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Preserve foreign shared-tree documentation hunks.

- [x] Зафиксировать diagnostic before/after без p95/SLA заявления.
- [x] Обновить `TD-011`: candidate-scoped media cost завершён частично,
  общий SQLite contention остаётся open.
- [x] Добавить visitor history в README только после подтверждённого
  ускорения публичного фильтрованного каталога.
- [x] Добавить отдельный русский пункт CHANGELOG без изменения истории.
- [x] Перечитать применимые canonical requirements и Task 69 plan/matrix.
- [x] Выполнить repository-wide scan старых/duplicate media-sort paths,
  stale docs/cache/schema references и `git diff --check`.
- [x] Проверить `git status --short --branch`; branch обязана быть `main`.
- [x] Собрать alternate index от текущего `HEAD`, применив только Task 69
  patch, и проверить staged manifest/diff.
- [ ] Commit в `main`; повторить verification при hook changes.
- [ ] Выполнить configured non-force push. Ошибку аутентификации или внешнего
  remote записать как `unresolved`, не как успешную отправку.

Final preparation evidence: canonical owners and Task 69 re-read; legacy
scan found one runtime media-sort owner and unrelated card/API/recommendation
consumers; PHP syntax and exact diff-check green. Alternate index contains
exactly nine Task 69 files from `HEAD`, plus the required Russian-policy
cleanup of the already committed Task 68 CHANGELOG paragraph. Staged
README/CHANGELOG policies and whitespace check pass. Managed-doc check on
the shared worktree remains unresolved because foreign Task 67 changes make
five generated blocks stale; broad refresh was not run.
