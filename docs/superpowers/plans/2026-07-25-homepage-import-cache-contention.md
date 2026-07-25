# Homepage Import Cache Contention — TDD Execution Plan

> Утверждённый design:
> [`../specs/2026-07-25-homepage-import-cache-contention-design.md`](../specs/2026-07-25-homepage-import-cache-contention-design.md).

Дата: 25.07.2026.

Статус: `implementation_verified_committed_push_unresolved`.

Этот план не ограничивается косметическим первым diff. После основной
реализации он продолжается измерением, regression-аудитом и только при
непройденном performance gate — отдельным design-review materialized read
model. Изменения без измеренной причины не добавляются.

## Task 1 — Preparation, contracts and RED matrix

**Ожидаемые изменяемые файлы:**

- `docs/plans/current-task-plan.md`
- этот plan и связанный design spec
- focused test files, перечисленные в следующих задачах

**Сохраняемые public contracts:**

- `routes/web.php`, `routes/api.php`, route names и response shapes;
- одна public import command `php artisan seasonvar:import`;
- queue names, job payload serialization, retry/backoff/timeout;
- cache domain/key/version names;
- database schema и persisted importer states;
- auth/privacy/premium/region/legal/player/media boundaries.

- [x] **Step 1: перечитать root instructions, requirement index и применимые owners**

Прочитаны `AGENTS.md`, canonical code/architecture/development,
multilingual/security/performance/caching/production/maintenance/system-wide
requirements, а также importer, queues, administration, authorization,
data-relations и предыдущий homepage plan.

- [x] **Step 2: подтвердить версии и официальный Laravel contract**

Подтверждены PHP 8.5, Laravel 13.22, Livewire 4.3, PHPUnit 12.5 и текущие
package versions. Через Laravel Boost проверены unique jobs,
`ShouldBeUniqueUntilProcessing`, after-commit dispatch, locks и flexible
cache behavior.

- [x] **Step 3: собрать baseline**

Зафиксированы live MISS 1,32 секунды, HIT 0,07–0,08 секунды,
`CatalogStats` average rebuild 54,16 секунды, homepage average rebuild
4,14 секунды, активный importer backlog и отсутствие hot Memcached.

- [x] **Step 4: обновить canonical current-task plan**

Task 56 и compliance matrix добавлены отдельным хвостовым блоком без
изменения или staging shared Task 52/55 scope.

## Task 2 — RED/GREEN: suppress wasted mass-import title warm

**Files:**

- Modify: `app/Services/Catalog/CatalogCacheInvalidator.php`
- Modify: `app/Jobs/FinalizeSeasonvarImportTitleGroup.php`
- Test: `tests/Feature/ImportedTitleCacheInvalidationTest.php`
- Test: `tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php`

- [x] **Step 1: write RED tests**

Добавить два контракта:

1. `importedTitleChanged($id, warm: false, invalidateCollections: false)`
   bump-ит scoped `TitleDetail`, откладывает dependent collection scopes и
   не dispatch-ит `WarmCatalogCaches`.
2. Full import title group использует `warm: false`, visitor run сохраняет
   `warm: true`.

- [x] **Step 2: run RED**

```bash
php artisan test --filter='ImportedTitleCacheInvalidationTest|SeasonvarImportTitleGroupFinalizerTest'
```

Ожидается failure из-за отсутствующего optional warm contract и текущего
безусловного dispatch.

- [x] **Step 3: minimal GREEN implementation**

Добавить backward-compatible `bool $warm = true` в invalidator и вычислить
visitor boundary до вызова. Не менять global finalizer.

- [x] **Step 4: run GREEN and related invalidator tests**

```bash
php artisan test --filter='CatalogCacheInvalidatorTest|ImportedTitleCacheInvalidationTest|SeasonvarImportTitleGroupFinalizerTest'
```

## Task 3 — RED/GREEN: pause and coalesce critical warm during import

**Files:**

- Modify: `app/Jobs/WarmCatalogCaches.php`
- Test: `tests/Feature/CacheWarmJobTest.php`

- [x] **Step 1: write RED active-import test**

Создать pending request и active `SeasonvarImportRun`; выполнить job с
mocked/spied warmer и проверить:

- `CatalogCacheWarmer::warmCritical()` не вызван;
- request остаётся claimable;
- dispatch-нут ровно один delayed `WarmCatalogCaches`;
- delay соответствует существующему bounded import pause.

- [x] **Step 2: write RED inactive-import regression**

При отсутствии active run job claim-ит, прогревает и подтверждает work по
существующему контракту.

- [x] **Step 3: run RED**

```bash
php artisan test --filter=CacheWarmJobTest
```

- [x] **Step 4: minimal GREEN implementation**

Инъецировать `SeasonvarImportActivity`; проверять activity до claim; для
active import dispatch-ить delayed unique tail и возвращаться.

- [x] **Step 5: run GREEN and queue-contract regressions**

```bash
php artisan test --filter='CacheWarmJobTest|CacheWarmQueueFallbackTest|FullPublicCacheWarmJobTest'
```

## Task 4 — RED/GREEN: regular warm and home metrics TTL

**Files:**

- Modify: `routes/console.php`
- Modify: `app/Services/Catalog/CatalogHomeMetricsCache.php`
- Test: `tests/Feature/CacheWarmScheduleTest.php`
- Test: existing or focused homepage cache test selected during discovery

- [x] **Step 1: write schedule RED**

Тест должен требовать `cache:warm-catalog --queue` и запрещать
`--refresh` в scheduled event.

- [x] **Step 2: write metrics TTL RED**

Подменить TTL policy и TieredCache contract либо проверить сформированную
policy через focused cache behavior: metrics используют
`CatalogStats` TTL, но `Homepage` domain и `metrics` scope.

- [x] **Step 3: run RED**

```bash
php artisan test --filter='CacheWarmScheduleTest|CatalogHomeMetrics'
```

- [x] **Step 4: minimal GREEN implementation**

Изменить только scheduled command и TTL policy argument. Key/domain/version
shape не менять.

- [x] **Step 5: run GREEN**

```bash
php artisan test --filter='CacheWarmScheduleTest|CatalogHomeMetrics|CatalogHomeSnapshot|CatalogCacheWarmer'
```

## Task 4b — RED/GREEN: defer collection-derived global invalidation

**Files:**

- Modify: `app/Services/Catalog/CatalogCacheInvalidator.php`
- Modify: `app/Jobs/FinalizeSeasonvarImportTitleGroup.php`
- Test: `tests/Feature/ImportedTitleCacheInvalidationTest.php`
- Test: `tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php`

- [x] **Step 1: write production-shaped RED**

Связать title с approved public collection и доказать, что full-run apply
повышает scoped `TitleDetail`, но не `Homepage`/`Collections` и не создаёт
warm intent. Targeted visitor test сохраняет прежние dependent invalidations.

- [x] **Step 2: run RED**

Ожидается failure: текущий `titleChanged()` bump-ит `Homepage` для каждого из
32 925 production titles, входящих в public collections.

- [x] **Step 3: minimal GREEN**

Добавить backward-compatible флаг dependent collection invalidation и
передавать `false` только из full/global title-group finalizer. Global
queued finalizer остаётся единственной terminal public-domain boundary.

- [x] **Step 4: rerun focused and adjacent cache/import suites**

## Task 5 — Cross-feature verification and measurement

- [x] **Step 1: run format and focused suites**

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter='CacheWarmJobTest|CacheWarmScheduleTest|CatalogCacheInvalidatorTest|ImportedTitleCacheInvalidationTest|SeasonvarImportTitleGroupFinalizerTest'
```

- [x] **Step 2: run affected importer/cache/home suites**

Использовать точный PHPUnit file list, затем broad suite. Независимый
untracked Task 54 missing class не исправлять и не маскировать.

- [x] **Step 3: static and managed checks**

```bash
./vendor/bin/phpstan analyse --memory-limit=1G
php artisan project:docs-refresh --check
git diff --check
```

`npm run build` не требуется без frontend/Blade/CSS/JS изменений.

- [x] **Step 4: live read-only performance evidence**

При работающем importer измерить:

- один безопасно полученный MISS/STALE path без broad cache clear;
- два последовательных HIT;
- cache metrics и import/queue health;
- отсутствие forced critical rebuild во время active import.

- [x] **Step 5: performance decision gate**

Если normal cold TTFB < 1,5 секунды и hot <= 0,1 секунды, materialized read
model отметить `not_applicable`. Иначе остановить code expansion, написать
отдельный design с migration/backfill/rollback и снова получить approval.

## Task 6 — Documentation, final audit and delivery

**Files:**

- Modify: `docs/caching.md`
- Modify: `docs/performance.md`
- Modify: `docs/importer.md`
- Modify: `docs/queues.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`
- Update: этот plan status/evidence

- [x] **Step 1: update canonical owners**

Описать import-aware critical warm, suppressed full-import fan-out,
scheduled non-refresh behavior, TTL и recovery.

- [x] **Step 2: README and CHANGELOG**

README visitor history обновить фактическим результатом ускорения; добавить
отдельную русскую датированную запись CHANGELOG без изменения прежних
записей.

- [x] **Step 3: final requirement reread and repository scan**

Перечитать все применимые owners. Искать оставшийся scheduled
`--refresh`, unconditional mass-import warm, duplicate warm services,
stale cache paths и неоконченный task code. Текстовое совпадение
классифицировать до удаления.

- [x] **Step 4: exact staging, commit and push**

Проверить `main`, shared status и exact task paths. Не stage/commit чужие
Task 52/54 изменения. Выполнить обычный commit с README и CHANGELOG,
затем push; внешний отказ записать `unresolved`.

## Task-specific requirement-compliance matrix

| Requirement / domain | Статус | Evidence / gate |
| --- | --- | --- |
| Root `AGENTS.md` + requirement index | `completed` | Fresh read before implementation |
| Applicable canonical requirements | `completed` | Architecture, code, security, i18n, performance, caching, production, maintenance, integration |
| Related importer/cache/queue docs | `completed` | Existing behavior and contracts traced |
| Installed versions | `completed` | Boost application info |
| Official Laravel 13 behavior | `completed` | Boost docs: unique jobs, after-commit, locks, flexible cache |
| Existing implementation | `completed` | Invalidation, warm store/job/warmer, import finalizers, scheduler and tests inspected |
| Alternatives and approval | `completed` | Stability-first option explicitly approved |
| Expected/protected files | `completed` | Manifest and compatibility section above |
| Migration/routes/translations/permissions/dependencies | `not_applicable` | No change planned |
| Rollback/data safety/production recovery | `completed` | Design contains no destructive state action |
| TDD RED before code | `completed` | Five focused tests failed for the intended old behavior before minimal GREEN |
| Cross-feature verification | `completed_with_independent_blockers` | Focused 45/230, adjacent 108/957, broad 1771 tests with 1758 pass/11 skip and only two pre-existing external results; GD test isolated GREEN 1/12 |
| Canonical docs/README/CHANGELOG | `completed` | Cache/performance/importer/queue owners, Russian visitor history and dated changelog updated |
| Final requirement reread/legacy scan | `completed` | Applicable requirements reread; scheduled refresh/direct dispatch/duplicate invalidator/stale mass fan-out scan classified |
| Commit/push in `main` | `implementation_committed_push_unresolved` | Path-limited commit `0b2bb88` contains exactly 18 Task 56 files; configured `git push origin main` failed because the HTTPS remote has no available credentials |

## Verification evidence

- Initial RED: five focused scenarios produced two intended assertion
  failures and three intended errors for missing warm/import/schedule/TTL
  contracts. Post-discovery RED separately reproduced the public-collection
  `Homepage 1→2` bump and missing `invalidateCollections` boundary.
- Final focused set: 45 tests / 230 assertions. Adjacent catalog,
  queue, public-warm and Seasonvar set: 108 tests / 957 assertions.
- Broad run without the cumulative GD file: 1 771 tests, 1 758 passed,
  11 skipped, 124 813 assertions. Remaining independent results:
  legacy web-session flash failure and absent parallel
  `SeasonvarImportDispatchBatcher`. The GD profile test passed separately:
  1 test / 12 assertions.
- Exact-path Pint, PHPStan, PHP syntax, managed docs and diff checks passed.
  No frontend asset or Blade change belongs to Task 56, so Vite build is
  `not_applicable`.
- During active run `#1255`, a normal queued critical request left
  `CatalogStats` unchanged at 18 rebuilds / 974 874 ms and remained a
  pending `refresh=true` intent while `cache-warm-v2` moved to delayed
  work. No queue/cache clear or failed-job rewrite was used.
- After worker natural `--max-time` rotation, ten samples over 30 seconds
  kept `Homepage` generation `57424` and invalidations `424` unchanged.
  HTTPS then measured `MISS 1,287 s`, `HIT 0,062 s`, `HIT 0,073 s`.
  The <1,5 s cold and <=0,1 s hot gates pass; materialized read model is
  `not_applicable`.
- The product, tests, canonical documentation, visitor README and Russian
  changelog were committed to `main` as `0b2bb88` without any collection
  task path. The configured push was attempted and remains `unresolved`
  because the HTTPS remote cannot obtain a username or credential in this
  environment.
