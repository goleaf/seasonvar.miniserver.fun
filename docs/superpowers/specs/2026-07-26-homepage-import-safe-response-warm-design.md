# Import-safe response warm главной страницы — design

Дата: 26.07.2026.

Статус: `implemented_verified`.

## Контекст и измеренная причина

После Task 56 обычная гостевая главная уже соответствует performance gate:
горячий ответ занимает `0,062–0,096 s`, прямой uncached render после
прогрева data-cache — `0,508–0,560 s`, а первый process/data cold render —
`1,269–1,329 s`. `CatalogHomePageBuilder` для RU и EN выполняет одинаковые
57 запросов примерно за `0,37 s`, поэтому отдельного locale-specific query
дефекта нет.

Редкие задержки больше четырёх секунд первоначально имели две
подтверждённые причины:

1. Laravel queue workers являются долгоживущими. Четыре текущих
   `seasonvar-import` worker были запущены в `23:58`, а Task 56
   закоммичена в `00:10`; без graceful restart процессы продолжили выполнять
   старый invalidation fan-out. Последняя наблюдаемая смена Homepage
   generation произошла в `00:21`.
2. Vite manifest изменился в `00:01`. Asset fingerprint входит в ключ
   гостевого response-cache, но `WarmCatalogCaches` во время активного
   полного импорта откладывает весь прогрев. Поэтому новый response namespace
   впервые строит посетитель, хотя тяжёлый data-cache и public page response
   имеют разные operational costs.

Телеметрия за день подтверждает цену churn: 446 Homepage invalidations,
96 rebuild со средним `3 374,49 ms`; `CatalogStats` перестраивался в среднем
`54 159,67 ms`.

После первой реализации и graceful worker restart fresh live acceptance
выявил дополнительный путь, не отражённый в общей invalidation telemetry:
`Homepage`, `ReleaseCalendar` и `Sitemap` получали одинаковый
`lastModified`, тогда как `Collections` не менялся.
`ReleaseCalendarCacheInvalidator::scheduleChanged()` напрямую повышал все
три public versions для каждой новой import observation/episode/media
записи. Поэтому homepage generation всё ещё могла смениться между
последовательными samples, хотя Task 56 уже устранила основной per-title
fan-out.

Второй live interval после worker rotation показал синхронные изменения
всех public domains вместе с `Collections` и общей invalidation telemetry.
Repository trace локализовал caller:
`CatalogRelationSyncer::afterTagChanges()` →
`TagCacheInvalidator::publicChanged()` →
`CatalogCacheInvalidator::catalogChanged()`. Full import тем самым выполнял
глобальную invalidation на каждой странице с изменёнными тегами, хотя
terminal queue/sync paths уже владеют единым `catalogChanged()`.

## Рассмотренные варианты

### 1. Только graceful restart worker

Нужен как обязательная активация уже готового кода, но не защищает следующий
asset/deploy fingerprint во время многочасового импорта: первый посетитель
снова получит MISS.

### 2. Previous-generation stale fallback

Сделал бы любой первый ответ быстрым, но меняет глобальную семантику
versioned cache, усложняет совместимость asset/translation fingerprints и
может возвращать намеренно инвалидированный HTML. Для текущего измеренного
дефекта это слишком широкий риск.

### 3. Bounded homepage-only response warm во время импорта

Выбранный вариант. Во время активного импорта job выполняет только безопасные
query-free URL главной страницы, затем сохраняет существующий delayed tail
для полного прогрева. Stats, facets, discovery, directories и title pages
не строятся.

## Утверждённое решение

1. Выполнить штатный `php artisan queue:restart`. Команда записывает Laravel
   restart signal в настроенный cache store; worker завершают текущую job и
   перезапускаются под существующим process manager без потери queued jobs.
2. Выделить в `PublicPageCacheWarmer` точный homepage target set:
   `/` и все localized home routes из канонического списка поддерживаемых
   locale. URLs остаются relative, same-origin и дедуплицируются.
3. Добавить `warmHomepages()` с тем же безопасным HTTP client contract:
   заголовок warm, локальный origin, явные connect/request timeout, bounded
   retry, отсутствие redirects и hash-only error fingerprints.
4. Добавить в `CatalogCacheWarmer` отдельную orchestration boundary
   `warmHomepageResponses()`. Она не вызывает `CatalogStats`,
   `CatalogHomeMetricsCache`, snapshot или facets и не помечает полный
   `CacheWarmingState` завершённым.
5. В active-import ветке `WarmCatalogCaches` сначала выполнить только эту
   boundary, затем поставить существующий unique delayed tail и вернуть
   управление до claim. Durable warm intent остаётся нетронутым.
6. Полный inactive-import path, scheduler, queue names, unique key,
   `WithoutOverlapping`, retry/backoff/timeout и request-store generation
   не менять.
7. Добавить в `ReleaseCalendarCacheInvalidator` scoped boundary на Laravel
   hidden `Context`. Внутри неё `scheduleChanged()` сохраняет изменения БД,
   но не повышает public/scoped cache versions на каждой записи.
8. Применять boundary только к full/global import apply:
   `FinalizeSeasonvarImportTitleGroup` для non-visitor run и
   `SeasonvarImportPipeline::runSitemapCycle()` для synchronous sitemap
   import. Targeted URL/visitor refresh и admin/editor paths не входят в
   scope и сохраняют немедленную invalidation.
9. Terminal global import path уже вызывает
   `CatalogCacheInvalidator::catalogChanged()`, который один раз повышает
   `ReleaseCalendar`, `Homepage`, `Sitemap` и связанные public domains.
   Дополнительный deferred-intent store не нужен.
10. Добавить аналогичный hidden `Context` guard в
    `CatalogCacheInvalidator::catalogChanged()` и включать его в том же
    full/global import apply scope. Это coalesces tag/taxonomy global
    invalidation до terminal handoff, не затрагивая
    `importedTitleChanged()`, playback metadata, targeted import или
    admin/editor writes.

## Data flow

```text
scheduled/deploy warm intent
          |
          v
WarmCatalogCaches
          |
          +-- active import --> homepage-only HTTP warm
          |                         |
          |                         +--> /, /ru, /en response-cache
          |                    delayed unique tail; intent не claim-ится
          |
          +-- inactive ------> claim intent
                                full data + public-page warm
                                complete/next tail

full/global import apply
          |
          v
hidden Context scope
          |
          +--> release observations / episode / media writes
          |       scheduleChanged() не churn-ит public versions
          +--> tag/taxonomy changes
          |       catalogChanged() не churn-ит public versions
          |
          +--> terminal catalogChanged()
                  один coherent public generation bump

targeted visitor/admin change
          |
          +--> scheduleChanged() сразу bump-ит calendar/home/sitemap
```

## Error handling и bounded behavior

- Invalid cross-origin base URL продолжает fail closed через существующий
  `LogicException`.
- Connection/HTTP failures записываются существующей безопасной структурой
  результата; body и URL не логируются.
- Homepage-only pass использует отдельный общий бюджет 30 секунд и
  существующие per-request timeout/retry. Проверка бюджета выполняется перед
  каждым следующим target; текущий request всегда остаётся ограничен
  HTTP timeout.
- Failed homepage target не подтверждает и не удаляет durable full-warm
  intent. Следующий scheduled/delayed pass может восстановиться.
- Полный warm state не выдаёт homepage-only pass за завершённый полный
  прогрев.
- `Context::scope()` восстанавливает прежнее состояние после return или
  exception; hidden flag не попадает в logging context.
- Если full import аварийно завершится, terminal failure path всё равно
  выполняет существующую глобальную invalidation. Targeted/admin changes
  никогда не зависят от этого terminal handoff.

## Expected changed files

- `app/Services/Catalog/PublicPageCacheWarmer.php`
- `app/Services/Catalog/CatalogCacheWarmer.php`
- `app/Services/Catalog/CatalogCacheInvalidator.php`
- `app/Jobs/WarmCatalogCaches.php`
- `app/Jobs/FinalizeSeasonvarImportTitleGroup.php`
- `config/cache-architecture.php`
- `app/Services/ReleaseCalendar/ReleaseCalendarCacheInvalidator.php`
- `app/Services/Seasonvar/SeasonvarImportPipeline.php`
- `tests/Feature/PublicPageCacheWarmerTest.php`
- `tests/Feature/CacheWarmJobTest.php`
- `tests/Feature/CatalogCacheInvalidatorTest.php`
- `tests/Feature/SeasonvarReleaseObservationSynchronizerTest.php`
- `tests/Feature/SeasonvarImportTitleGroupFinalizerTest.php`
- `docs/caching.md`
- `docs/performance.md`
- `docs/importer.md`
- `docs/queues.md`
- `docs/release-calendar.md`
- `docs/plans/current-task-plan.md`
- связанный execution plan
- `README.md`
- `CHANGELOG.md`

## Protected contracts

- `routes/web.php`, `routes/api.php`, route names, response bodies и status
  codes;
- одна public import command `php artisan seasonvar:import`;
- queue connection/name, serialized job payload, unique ID, retry/backoff и
  timeout;
- cache domains, keys, version scopes, fresh/stale TTL и invalidation;
- database schema/data, migrations, import states и media/source boundaries;
- authentication, authorization, privacy, premium, regional/legal, player,
  search, SEO, sitemap, API/mobile и translations;
- no external dependency, `.env`, secret, broad cache clear или queue clear.

## Cross-feature и production impact

| Domain | Impact | Boundary |
| --- | --- | --- |
| Guest homepage response cache | `affected` | Новый namespace прогревается в background до первого visitor MISS |
| Homepage data cache | `unchanged` | Active-import pass не вызывает stats/metrics/snapshot/facets |
| Import throughput | `bounded_affected` | Не более home URL set; cache HIT дешёвый, MISS имеет общий и per-request budget |
| Full cache warm | `compatible` | Durable intent и delayed recovery сохраняются |
| Assets/deploy | `affected` | Новый Vite fingerprint получает proactive homepage response |
| Release calendar/import | `affected` | Full import coalesces release и tag/taxonomy public bumps до существующей terminal invalidation |
| Targeted/admin writes | `compatible` | Вне hidden Context сохраняется immediate invalidation |
| Auth/privacy | `unchanged` | Только public query-free warm requests, без cookies/Authorization |
| SEO/localization | `compatible` | Канонические home routes для всех configured locales |
| Schema/data/dependencies | `not_applicable` | DDL/DML/package changes отсутствуют |

## Rollout, recovery и rollback

Rollout:

1. RED/GREEN focused tests.
2. Focused и adjacent cache/import suites, Pint и static checks.
3. Graceful `queue:restart`; подтвердить replacement PID после завершения
   текущих jobs и уменьшение backlog.
4. Проверить стабильность Homepage generation и bounded homepage warm при
   активном import.
5. Измерить fresh namespace/MISS и последующие HIT без `cache:clear`.

Partial failure:

- если restart signal не подхвачен, process manager и worker PID проверяются,
  но jobs/claims вручную не удаляются;
- если self-warm недоступен, intent остаётся pending, job retry/scheduler
  выполняет восстановление;
- если asset build прерван, прежние assets и response namespace не очищаются
  этой задачей.

Rollback — revert только application/config/docs changes и повторный graceful
worker restart. Schema/data/cache restore не нужен; существующие cache entries
истекают по прежнему TTL.

## Acceptance

- active import вызывает только `/` и localized homepage self-warm;
- stats, titles, directories, discovery и title detail во время этого pass не
  запрашиваются;
- warm request-store intent не claim-ится и не теряется;
- full cache state не получает ложный `ok`;
- inactive path сохраняет прежнее поведение;
- после graceful restart Homepage generation не churn-ит от full-import
  title groups;
- full import продолжает создавать release schedule entries, но
  `Homepage`/`ReleaseCalendar`/`Sitemap` меняются один раз на terminal
  global invalidation, а не на каждую запись;
- full import продолжает сохранять импортированные теги, но tag changes не
  вызывают per-page `catalogChanged()`; terminal handoff публикует их один
  раз;
- visitor refresh и изменения вне import scope по-прежнему немедленно
  инвалидируют calendar/home/sitemap;
- hot homepage остаётся не медленнее `0,1 s`, normal cold — ниже `1,5 s`;
- отсутствие cache payload, route, schema, translation и permission
  regressions подтверждено тестами и scan.
