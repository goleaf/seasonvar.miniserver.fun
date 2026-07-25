# Import-safe response warm главной страницы — TDD execution plan

> Approved design:
> [`../specs/2026-07-26-homepage-import-safe-response-warm-design.md`](../specs/2026-07-26-homepage-import-safe-response-warm-design.md).

Дата: 26.07.2026.

Статус: `implementation_verified_delivery_pending`.

План без искусственного лимита: после minimal implementation он обязательно
продолжается worker activation, live measurement, requirement reread,
repository-wide legacy audit, documentation и exact delivery. Новая
архитектура добавляется только при непройденном acceptance gate.

## Task 1 — preparation и protected contracts

**Files:**

- Modify: `docs/plans/current-task-plan.md`
- Create: approved design и этот execution plan

- [x] Перечитать root `AGENTS.md`, requirement index и применимые canonical
  requirements.
- [x] Перечитать `docs/performance.md`, `docs/caching.md`,
  `docs/importer.md`, `docs/queues.md`, `docs/DATA_RELATIONS.md` и related
  Task 53/56 plans.
- [x] Подтвердить PHP/Laravel/package versions через Boost.
- [x] Проверить Laravel 13 official docs для long-lived worker deployment,
  graceful `queue:restart`, unique jobs и HTTP client testing.
- [x] Проследить existing cache middleware/policy/key dimensions,
  warm request store, warm job, public warmer, import activity и worker
  lifecycle.
- [x] Зафиксировать expected/protected files, cross-feature impact,
  production recovery и rollback в design/current plan.
- [x] Перечитать approved design до первого test edit.

## Task 2 — RED/GREEN: exact homepage-only target set

**Files:**

- Modify: `tests/Feature/PublicPageCacheWarmerTest.php`
- Modify: `app/Services/Catalog/PublicPageCacheWarmer.php`
- Modify: `config/cache-architecture.php`

### Step 1 — RED exact target test

Добавить test:

`test_homepage_warmer_requests_only_canonical_home_routes`

Setup:

- `app.url` и `warm_base_url` равны `https://seasonvar.test`;
- configured locales содержат RU/EN;
- `Http::preventStrayRequests()` и fake `200`;
- manifest содержит `/stats`, `/titles?page=2`;
- создать изменённый title, чтобы доказать отсутствие title query/URL
  fan-out.

Assertions:

- `warmHomepages()` существует и attempted/succeeded равно числу unique home
  routes;
- HTTP URLs точно равны `/`, `/ru`, `/en` в deterministic порядке;
- `/stats`, `/titles`, discovery/directory/title URLs отсутствуют;
- каждый request содержит существующий warm header и user-agent.

Run:

```bash
php artisan test tests/Feature/PublicPageCacheWarmerTest.php \
  --filter=homepage_warmer_requests_only
```

Expected RED: method `warmHomepages()` отсутствует.

### Step 2 — RED budget/config contract

Добавить test:

`test_homepage_warmer_stops_before_next_locale_after_import_safe_budget`

Настроить budget `1`, убрать inter-request delay, fake first response с
`1,05 s` latency. Проверить `attempted=1`, remaining targets `skipped`,
`limited=true`, HTTP count `1`.

Expected RED: отсутствует отдельный import-safe budget/API.

### Step 3 — minimal GREEN

В `PublicPageCacheWarmer`:

- extract private `homepageUrls(): list<string>`;
- `criticalUrls()` переиспользует `homepageUrls()` без изменения порядка и
  общего состава;
- добавить public `warmHomepages(): array`;
- использовать existing enabled gate, `baseUrl()`, `PublicCacheWarmTarget`,
  `executeTargets()` и safe HTTP contract;
- передать `budgetSeconds` из
  `cache-architecture.page_cache.import_safe_homepage_warm_budget_seconds`.

В config добавить integer default `30`, без `.env` и dependency.

### Step 4 — GREEN + regressions

```bash
php artisan test tests/Feature/PublicPageCacheWarmerTest.php
php artisan test tests/Unit/PublicPageCacheBatchWarmerTest.php
```

## Task 3 — RED/GREEN: active-import orchestration boundary

**Files:**

- Modify: `tests/Feature/CacheWarmJobTest.php`
- Modify: `app/Services/Catalog/CatalogCacheWarmer.php`
- Modify: `app/Jobs/WarmCatalogCaches.php`

### Step 1 — strengthen existing active-import test to RED

В
`test_job_keeps_pending_intent_and_dispatches_one_delayed_tail_during_import`:

- configure same-origin warmer and RU/EN locales;
- fake HTTP and prevent stray requests;
- active run + pending refresh/title intent остаются как production-shaped
  setup;
- выполнить job.

Новые assertions:

- sent URLs точно `/`, `/ru`, `/en`;
- no stats/title/directory/discovery calls;
- existing pending work остаётся claimable с теми же refresh/title IDs;
- `CacheWarmingState` остаётся `null`;
- ровно один delayed `WarmCatalogCaches` с existing pause.

Expected RED: current active-import branch делает zero HTTP requests.

### Step 2 — unit boundary through public behavior

Добавить test:

`test_homepage_response_warm_does_not_build_or_publish_full_warm_state`

Вызвать `CatalogCacheWarmer::warmHomepageResponses()` с fake HTTP:

- result показывает только page attempts;
- `CacheWarmingState::read()` остаётся `null`;
- cache data builders не получают новую full warm state.

Expected RED: orchestration method отсутствует.

### Step 3 — minimal GREEN

В `CatalogCacheWarmer`:

- добавить typed `warmHomepageResponses(): array`;
- делегировать только `PublicPageCacheWarmer::warmHomepages()`;
- записывать Operational telemetry для duration/failure/skipped, не вызывая
  `CacheWarmingState::started/succeeded/failed`;
- не менять `warmCritical()`.

В `WarmCatalogCaches`:

- внутри existing active import branch вызвать
  `$warmer->warmHomepageResponses()`;
- только после этого dispatch existing delayed unique tail;
- return до request claim;
- не добавлять payload fields, новый job, queue или lock.

### Step 4 — GREEN

```bash
php artisan test tests/Feature/CacheWarmJobTest.php \
  --filter='homepage_response_warm|keeps_pending_intent'
```

## Task 4 — failure и compatibility regressions

**Files:**

- Modify only tests/code discovered by an exact regression

- [x] Проверить disabled page warming: active import сохраняет intent и
  delayed tail, HTTP count zero.
- [x] Проверить one-locale/duplicate locale config: URLs deterministic и
  unique.
- [x] Проверить non-success/connection handling через existing safe result;
  raw URL/body не попадают в returned/logged error shape.
- [x] Проверить invalid cross-origin base URL fail closed.
- [x] Проверить inactive import: full `warmCritical()` и request completion
  остаются прежними.
- [x] Проверить `titleCapacity()` и total critical URL count не изменились.

Focused command:

```bash
php artisan test \
  tests/Feature/PublicPageCacheWarmerTest.php \
  tests/Feature/CacheWarmJobTest.php \
  tests/Unit/PublicPageCacheBatchWarmerTest.php
```

## Task 4b — RED/GREEN: coalesced release-calendar invalidation

Fresh live acceptance после первой GREEN реализации показал одинаковый
`lastModified` у `Homepage`, `ReleaseCalendar` и `Sitemap`.
`ReleaseCalendarCacheInvalidator::scheduleChanged()` вызывался import
observers на каждой release observation/episode/media записи и обходил
общую invalidation telemetry.

**Files:**

- Modify:
  `app/Services/ReleaseCalendar/ReleaseCalendarCacheInvalidator.php`
- Modify: `app/Jobs/FinalizeSeasonvarImportTitleGroup.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportPipeline.php`
- Modify: `tests/Feature/SeasonvarReleaseObservationSynchronizerTest.php`
- Modify: `tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php`

### Step 1 — RED scoped invalidator contract

- внутри новой scoped boundary выполнить реальную synchronization release
  observation;
- убедиться, что schedule entry сохранён;
- versions `ReleaseCalendar`, `Homepage`, `Sitemap` и scoped title остаются
  прежними;
- повторить изменение вне scope и подтвердить существующий immediate bump;
- проверить восстановление Context после exception.

Expected RED: scoped method отсутствует, текущий invalidator всегда bump-ит
versions.

### Step 2 — RED production-shaped queue behavior

Расширить full-import finalizer test release status данными:

- non-visitor global title group создаёт release schedule entry;
- per-group apply не меняет `Homepage`, `ReleaseCalendar`, `Sitemap`;
- terminal global finalizer остаётся единственным владельцем coherent bump.

Targeted visitor group с теми же данными должен сохранять immediate
invalidation.

Expected RED: full-import observation прямо bump-ит три public domains.

### Step 3 — minimal GREEN

- `ReleaseCalendarCacheInvalidator::deferPublicInvalidation()` использует
  Laravel hidden `Context::scope()` и автоматически восстанавливает state;
- `scheduleChanged()` возвращается без version bump только внутри этого
  scope;
- `FinalizeSeasonvarImportTitleGroup` оборачивает каждый prepared apply
  только для non-visitor/full runs;
- `SeasonvarImportPipeline::runSitemapCycle()` оборачивает только global
  sitemap `parsePages()`;
- targeted URL cycle, visitor refresh и admin/editor paths остаются вне
  scope.

### Step 4 — focused regressions

```bash
php artisan test \
  tests/Feature/SeasonvarReleaseObservationSynchronizerTest.php \
  tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php
```

Дополнительно проверить terminal `CatalogCacheInvalidator::catalogChanged()`
в queue и sync completion/failure paths. Новый intent/cache key/schema не
добавлять.

## Task 4c — RED/GREEN: coalesced tag/catalog invalidation

Второй fresh-process live interval показал синхронный рост
`Homepage`/`ReleaseCalendar`/`Sitemap`/`Collections` и общей invalidation
telemetry. Trace подтвердил full-import chain:
`CatalogRelationSyncer::afterTagChanges()` →
`TagCacheInvalidator::publicChanged()` →
`CatalogCacheInvalidator::catalogChanged()`.

**Files:**

- Modify: `app/Services/Catalog/CatalogCacheInvalidator.php`
- Modify: `app/Jobs/FinalizeSeasonvarImportTitleGroup.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportPipeline.php`
- Modify: `tests/Feature/CatalogCacheInvalidatorTest.php`
- Modify: `tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php`

### Step 1 — RED

- scoped `catalogChanged()` не меняет public versions, telemetry и warm
  intent;
- `importedTitleChanged()` остаётся рабочей scoped title boundary;
- full-import finalizer с реальным imported tag сохраняет tag/pivot, но не
  меняет global public versions;
- visitor/admin path вне scope сохраняет immediate global invalidation.

Expected RED: scoped API отсутствует, tag sync повышает global versions.

### Step 2 — minimal GREEN

- добавить hidden Context scope только для
  `CatalogCacheInvalidator::catalogChanged()`;
- nest его с release-calendar scope в non-visitor queue finalizer и sync
  sitemap chunks;
- не подавлять `importedTitleChanged()`,
  `titlePlaybackMetadataChanged()` или terminal global invalidation.

### Step 3 — regressions

```bash
php artisan test \
  tests/Feature/CatalogCacheInvalidatorTest.php \
  tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php
```

Live acceptance требует fresh-process correlation: importer parsed counter
растёт, а untracked record-level versions/telemetry — нет.

## Task 5 — format, adjacent и static verification

- [x] `./vendor/bin/pint --dirty --format agent`
- [x] Re-run exact focused files after Pint: финальный прогон
  `59 tests / 317 assertions`.
- [x] Adjacent cache/import/job suite: финальный прогон
  `29 tests / 174 assertions`.

```bash
php artisan test \
  tests/Feature/CacheWarmScheduleTest.php \
  tests/Feature/CacheWarmQueueFallbackTest.php \
  tests/Feature/FullPublicCacheWarmJobTest.php \
  tests/Feature/ImportedTitleCacheInvalidationTest.php \
  tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php \
  tests/Feature/PublicPageResponseCacheTest.php \
  tests/Feature/CatalogHomePerformanceTest.php
```

- [x] `./vendor/bin/phpstan analyse --memory-limit=1G`: `0` ошибок.
- [x] `php artisan project:docs-refresh --check --no-interaction`
- [x] `git diff --check`
- [x] `npm run build` — `not_applicable`: frontend/Blade/Vite source не
  изменялся.
- [x] Full `php artisan test` после focused/adjacent GREEN; independent
  failures классифицировать, не исправлять несвязанный shared scope.
  Полный прогон останавливается на независимом GD memory test; прогон без
  него дал `1811` tests, `1798` passed, `11` skipped и два известных
  несвязанных blocker (`WebAccountManagementTest` session flash и
  отсутствующий `SeasonvarImportDispatchBatcherTest` class).

## Task 6 — graceful worker activation

Production-affecting, но recoverable boundary:

1. Перед signal записать current worker PID/start time, import status,
   reserved/pending counts и homepage/cache versions.
2. Выполнить:

```bash
php artisan queue:restart
```

3. Не выполнять `queue:clear`, `cache:clear`, manual claim delete или run
   status mutation.
4. Poll не дольше 60 секунд за один tool call:
   - current jobs завершаются;
   - process manager создаёт replacement PID;
   - queue/import heartbeat продолжает обновляться;
   - reserved count не демонстрирует потерю jobs.
5. Если rotation дольше текущего 900-second timeout, продолжать bounded
   наблюдение и не заявлять activation до нового PID.
6. При отсутствии process-manager replacement зафиксировать production
   blocker; не запускать ad-hoc duplicate worker без отдельного review.

## Task 7 — live acceptance

Без destructive invalidation:

- [x] sample Homepage/CatalogStats generation и modified timestamps не менее
  пяти раз во время active import;
- [x] одновременно sample `ReleaseCalendar`/`Sitemap`; одинаковый
  per-record churn после replacement PID отсутствует;
- [x] подтвердить отсутствие per-title Homepage churn после replacement PID;
- [x] вызвать/дождаться normal warm intent и проверить homepage-only HTTP
  result через headers/telemetry, не читая raw Redis job payload;
- [x] измерить `/`, `/en` safe natural MISS/STALE и два consecutive HIT;
- [x] hot gate `<=0,1 s`, normal cold gate `<1,5 s`;
- [x] `seasonvar:import --status` backlog/heartbeat продолжают меняться;
- [x] health остаётся ready или новый regression классифицирован;
- [x] optional Memcached/legacy failed-job warnings не выдавать за
  исправленные этой задачей.

Fresh-process evidence: после второй PID rotation run `#1255` обработал
минимум `233` страницы (`16443→16676`) за `4:43`, а версии
`Homepage=59247`, `ReleaseCalendar=21125`, `Sitemap=59245`,
`Collections=10875` и invalidation counter `549` не изменились. Response
cache `HIT` для `/`, `/ru`, `/en` занимал `0,067–0,188 s`, причем основной
контрольный диапазон после warm — `0,065–0,144 s`; safe Authorization bypass
занимал `0,543–0,684 s`. Это bounded operational evidence, не p95/SLA.

Если normal cold gate не пройден после worker activation и bounded warm:

1. остановить расширение кода;
2. собрать fresh SQL/cache/worker correlation;
3. вернуться к отдельному approved design для previous-generation fallback
   или materialized read model;
4. не менять cache correctness на основании единичного spike.

## Task 8 — documentation и final audit

**Files:**

- Modify: `docs/caching.md`
- Modify: `docs/performance.md`
- Modify: `docs/importer.md`
- Modify: `docs/queues.md`
- Modify: `docs/release-calendar.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/plans/current-task-plan.md`
- Update: design/этот plan evidence

- [x] Обновить canonical owners фактической, а не предполагаемой
  implementation/operations семантикой.
- [x] Проверить `README.md`; visitor history обновить, потому что исчезает
  первый медленный ответ после release/import overlap.
- [x] Добавить отдельную датированную русскую запись `CHANGELOG.md`, не
  переписывая историю.
- [x] Перечитать применимые canonical requirements, approved design,
  execution plan и Task 57.
- [x] Repository-wide scan:
  - duplicate homepage warm URL lists;
  - active-import full stats/facets warm;
  - unconditional per-title public invalidation;
  - прямые `ReleaseCalendarCacheInvalidator::scheduleChanged()` callers и
    full-import paths вне scoped boundary;
  - import tag/taxonomy callers `CatalogCacheInvalidator::catalogChanged()`
    вне scoped boundary;
  - forced scheduled `--refresh`;
  - stale cache keys/config names;
  - direct Blade queries, inline code и unfinished Task 57 markers.
- [x] Классифицировать каждое совпадение до удаления.

Scan classification: full/global per-page release и imported-tag calls
находятся внутри nested Context; queue/sync terminal `catalogChanged()`
остаются снаружи и выполняют единый bump; targeted URL, visitor, admin,
technical-issue и merge callers намеренно immediate. Scheduled warm не
содержит `--refresh`; второй homepage route list в
`PublicCatalogWarmTargetSource` принадлежит отдельному all-public registry,
а не active-import critical pass. Task-owned Blade/inline/unfinished и stale
config/key paths не найдены.

## Task 9 — exact delivery

- [ ] Проверить `git status --short --branch`; branch только `main`.
- [ ] Не reset/stash/unstage/overwrite foreign collection task.
- [ ] Stage только Task 57 files/hunks; README и CHANGELOG должны содержать
  осмысленные Task 57 изменения.
- [ ] Запустить pre-commit на exact staged scope.
- [ ] Commit завершённого разрешённого scope в `main`.
- [ ] Выполнить configured push; auth/clean-tree/remote отказ записать
  `unresolved`, не маскировать успешной отправкой.
- [ ] После commit проверить, что Task 57 files clean, а оставшийся dirty
  scope принадлежит только явно классифицированной параллельной работе.

## Task-specific compliance exit gate

Ни одна строка Task 57 matrix не получает `completed` без exact evidence.
Не применимые migrations/routes/translations/permissions/dependencies
остаются `not_applicable`; shared/external blockers — `unresolved`.
