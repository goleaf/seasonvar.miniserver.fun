# Editorial Collection Readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> `superpowers:executing-plans`. Work only in the existing `main`; project
> rules forbid feature branches/worktrees, and sub-agents are not authorized.
> Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Закрыть единым readiness contract административное feature и
публичное чтение editorial collections.

**Architecture:** Новый neutral watchable-title query переиспользуется
recommendation visibility и collection readiness. Readiness service даёт
bounded batch metrics и eligible featured-ID subquery; moderation, homepage,
discovery и admin presentation используют одну server-owned truth.

**Tech Stack:** PHP `8.5`, Laravel `13.22.0`, Laravel Boost `2.4.13`,
Livewire `4.3.3`, SQLite, PHPUnit `12.5.32`, Pint `1.29.3`, Tailwind CSS
`4.3.2`, Vite `8.1.4`.

## Global Constraints

- Только existing `main`; без branch/worktree/PR и без broad staging.
- TDD RED обязателен до PHP/Blade behavior.
- No migration, production DML, dependency, env, queue, scheduler или cache
  key.
- No collection images/fallback/storage; cards remain text-only.
- Guest watchability остаётся canonical title/media entitlement.
- Feature mutation остаётся policy + row lock + audit + targeted invalidation.
- Shared Task 57/58/60 files не reset/stash/unstage/overwrite/commit.

---

### Task 1: RED readiness и moderation contracts

**Files:**

- Create: `tests/Feature/CatalogCollectionPublicationReadinessTest.php`

**Interfaces:**

- Consumes: existing `CatalogCollectionModerationService::feature()`.
- Produces: fixtures/assertions for exact Task 15 semantics.

- [x] Создать local 12/12, local 11/11, source 4/4, source 3/3 и unavailable
  fixtures через реальные `CatalogTitle`, `LicensedMedia`,
  `CatalogCollectionItem`, `CatalogCollectionSource`.
- [x] Assert desired `evaluate()` array and stable reason codes.
- [x] Assert thin `feature(true)` throws localized validation and preserves
  `is_featured`, `content_version` and audit count.
- [x] Assert ready feature succeeds and exact retry is no-op.
- [x] Run:

```bash
php artisan test tests/Feature/CatalogCollectionPublicationReadinessTest.php
```

Expected: FAIL because readiness enum/service do not exist and current
moderation accepts thin collection.

### Task 2: GREEN watchable/readiness boundaries

**Files:**

- Create: `app/Enums/CatalogCollectionReadinessReason.php`
- Create: `app/Services/Catalog/CatalogWatchableTitleQuery.php`
- Create: `app/Services/Collections/CatalogCollectionPublicationReadiness.php`
- Modify: `app/Services/Catalog/CatalogRecommendationVisibilityService.php`
- Modify: `app/Services/Collections/CatalogCollectionModerationService.php`

**Interfaces:**

- `CatalogWatchableTitleQuery::visibleTo(?User): Builder<CatalogTitle>`.
- `CatalogWatchableTitleQuery::mediaForTitle(?User): Builder<LicensedMedia>`.
- `CatalogCollectionPublicationReadiness::evaluate()` and `evaluateMany()`.
- `CatalogCollectionPublicationReadiness::eligibleFeaturedCollectionIds()`.

- [x] Реализовать enum из восьми allowlisted reasons с localized label.
- [x] Вынести canonical visible+playable media query и делегировать ему
  существующий recommendation watchable path без изменения filters.
- [x] Реализовать one/batch grouped readiness metrics и thresholds `12|4`.
- [x] Добавить feature guard только для перехода `false → true`; unfeature и
  exact retry сохранить.
- [x] Запустить RED test до GREEN, затем:

```bash
php artisan test tests/Feature/CatalogCollectionPublicationReadinessTest.php
./vendor/bin/pint --dirty --format agent
```

### Task 3: RED/GREEN public read paths

**Files:**

- Create: `tests/Feature/CatalogEditorialDiscoveryTest.php`
- Modify: `app/Services/Collections/CatalogCollectionQuery.php`
- Modify: `app/Services/Catalog/CatalogPublicDiscoveryQuery.php`
- Modify: `tests/Feature/UnifiedDiscoveryCollectionsTest.php`

**Interfaces:**

- Consumes:
  `CatalogCollectionPublicationReadiness::eligibleFeaturedCollectionIds()`.
- Produces: readiness-filtered homepage collection rows and editorial title
  candidates.

- [x] RED: thin featured collection absent; ready source/local present;
  unavailable membership removes entire collection.
- [x] RED: multiple ready collections preserve publication/manual item order
  and unique candidate IDs.
- [x] GREEN: add the same eligible-ID subquery to both read boundaries.
- [x] Run:

```bash
php artisan test \
  tests/Feature/CatalogEditorialDiscoveryTest.php \
  tests/Feature/UnifiedDiscoveryCollectionsTest.php \
  tests/Feature/CatalogCollectionPublicationReadinessTest.php
```

### Task 4: RED/GREEN admin presentation

**Files:**

- Modify: `app/Livewire/Collections/CatalogCollectionAdministrationManager.php`
- Modify:
  `resources/views/livewire/collections/catalog-collection-administration-manager.blade.php`
- Modify: `lang/ru/collections.php`
- Modify: `lang/en/collections.php`
- Modify: `tests/Feature/CatalogCollectionPublicationReadinessTest.php`

**Interfaces:**

- Render consumes `evaluateMany()` for the current paginator only.
- Blade receives prepared scalar labels/counts/reasons; no queries.

- [x] RED HTTP/Livewire assertions for ready/not-ready status, counts,
  reasons and absent feature control on thin collection.
- [x] GREEN prepared presentation attributes and compact responsive status
  block; stale featured row retains unfeature control.
- [x] Assert render query ceiling does not grow per collection.
- [x] Run:

```bash
php artisan test tests/Feature/CatalogCollectionPublicationReadinessTest.php
npm run build
```

### Task 5: Query, compatibility and browser verification

**Files:**

- Modify: `docs/plans/current-task-plan.md`
- Modify: this plan.

- [x] Run `EXPLAIN QUERY PLAN` for eligible featured IDs and admin batch
  metrics on current SQLite; record indexes/temp sorts truthfully.
- [x] Run focused and adjacent suites:

```bash
php artisan test --filter=CatalogCollection
php artisan test --filter=UnifiedDiscoveryCollectionsTest
php artisan test --filter=CatalogRecommendationVisibility
./vendor/bin/phpstan analyse \
  app/Enums/CatalogCollectionReadinessReason.php \
  app/Services/Catalog/CatalogWatchableTitleQuery.php \
  app/Services/Collections/CatalogCollectionPublicationReadiness.php \
  --memory-limit=1G
./vendor/bin/pint --dirty --format agent
php artisan project:docs-refresh --check
npm run build
```

- [x] Playwright desktop/mobile/tablet:
  `/discover/editorial`, `/discover/popular`, admin collection section;
  no image, overflow, console/page/local HTTP error.
- [x] Search repository for duplicate readiness/playability logic, direct
  `is_featured` public trust, Blade queries, stale buttons and debug/TODO.

### Task 6: Documentation and delivery

**Files:**

- Modify:
  `docs/superpowers/plans/2026-07-24-editorial-collections-improvement-master-plan.md`
- Modify:
  `docs/superpowers/plans/2026-07-24-discovery-sections-end-to-end-improvement-master-plan.md`
- Modify: `docs/architecture.md`
- Modify: `docs/performance.md`
- Modify: `docs/catalog-search.md`
- Modify: `docs/frontend.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

- [x] Обновить canonical owners только фактическим behavior/evidence.
- [x] README visitor history описывает защиту качества без внутренних
  названий.
- [x] Добавить отдельную русскую CHANGELOG entry.
- [x] Перечитать требования/spec/plan и заполнить compliance matrix.
- [ ] Commit exact Task 61 scope в `main` через isolated index, сохранив
  foreign staged/unstaged state.
- [ ] Выполнить configured non-force push; auth/network failure записать
  `unresolved`.

## Verification evidence

- RED: readiness service отсутствовал, а прежняя moderation boundary принимала
  тонкую подборку; public reads возвращали readiness-failing featured rows;
  admin не имел status/count/reason contract.
- GREEN: readiness `7 tests / 52 assertions`; `CatalogCollection` broad
  filter `78 / 509`.
- Admin batch: ровно один grouped readiness query на 10 rows.
- SQLite: featured/source/membership/media indexes без temp sort; фактическая
  moderation queue — 20 rows/12 memberships, readiness около
  `3,17–5,18 ms`; eligible query warm `0,43–0,54 ms`.
- Static: scoped Task 61 PHPStan — `0 errors`; five прежних
  `CatalogCollectionQuery` source-sync errors в неизменённых строках остаются
  отдельно от scope.
- Frontend: Vite build прошёл; Chromium desktop/mobile/tablet прошёл без
  collection images, overflow, console/page/local-response errors.
- Independent read-only code review после двух RED/GREEN follow-up исправлений
  завершён verdict `Ready: Yes`, без оставшихся Critical/Important/Minor.
- Full repository snapshot без известного накопительного GD memory-case:
  `1832 tests`, `1819 passed`, `11 skipped`, один несвязанный account
  session-message failure и один уже находившийся в `HEAD` importer test без
  реализованного класса. Исключённый GD test отдельно прошёл `1 / 12`.
- Production data: no DML, feature activation or source/image storage.

## Rollback

Revert Task 61 commits. Read paths снова доверяют `is_featured`, а moderation
возвращается к structural guard. Schema, rows, files, cache, workers и
production content откатывать не требуется.
