# Прогрев видимых страниц тайтлов и ускорение cold-path

Дата: 19.07.2026

Статус: implementation complete, final repository verification in progress.

## Наблюдаемая проблема

- Повторный guest page-cache `HIT` страницы тайтла быстрый, но uncached `/titles/ierrohierro` выполнял тяжёлые episode/recommendation/collection/user-summary SQL.
- `/titles` не передавал реально показанные карточки в background warming; общий `all-public` проход слишком велик для запуска при каждом просмотре каталога.
- Scheduler только внутри Livewire render недостаточен: полный catalog page-cache `HIT` не создаёт компонент и поэтому обязан читать bounded ID metadata из самого cached payload.

## Реализуемый контракт

1. `TieredCache::state()` читает только authoritative domain store и возвращает `fresh`, `stale`, `missing` или fail-safe `unavailable`, ничего не перестраивая.
2. `PublicPageCachePolicy::canonicalTitleContext()` строит точно те же canonical dimensions и version scope, что HTTP middleware для guest `/titles/{slug}`.
3. `CatalogSeries` фиксирует не более 96 уникальных положительных ID показанных тайтлов. На обычном GET middleware сохраняет их в guest cache payload; на Livewire update scheduler запускается после успешного ответа.
4. `CachePublicPage` передаёт ID scheduler и на `MISS`, и на `HIT`/`STALE`. `response_contract=2` делает прежние catalog payload без metadata недостижимыми без scan/flush.
5. На каждый ID создаётся отдельный `WarmCatalogTitlePage` в существующей `cache-warm-v2`. Job использует `ShouldBeUnique`, `WithoutOverlapping`, guest visibility, shared import detector и exact state check. Fresh/hidden ничего не делают; stale/missing выполняют один same-origin HTTP warm; import/cache outage дают bounded release.
6. Title playback применяет прямой `catalog_title_id` и bounded available-season subquery; recommendation count использует derived available-season projection только выбранных ID; collection membership использует индексируемый title lookup.
7. Additive reversible migration добавляет covering index `(catalog_title_id,in_watchlist,rating)`.

## Safety, cross-feature и rollback

- Authoritative данные остаются в SQLite; cache/queue outage никогда не меняет correctness или visibility.
- Authenticated, hidden, private, signed playback/download, source URL, premium/region/legal state не сериализуются и не прогреваются как guest page.
- Активные `queued|discovering|running|finalizing` импорты временно блокируют fan-out workload.
- Новая очередь, dependency, cron, route, controller или synchronous self-HTTP не добавляются.
- Быстрый rollback: `CACHE_VISIBLE_TITLE_WARM_ENABLED=false`, `config:cache`, graceful refresh PHP-FPM/worker. Queue/cache не очищаются. Индекс может остаться при application rollback.

## Verification checklist

- [x] `CacheEntryState` missing/fresh/stale/unavailable regression.
- [x] Canonical title context parity regression.
- [x] Import activity status regression и существующие consumer jobs.
- [x] Job contract: unique, overlap, fresh skip, stale/missing warm, hidden/import/outage skip/release.
- [x] Scheduler normalization, hard cap, disable flag, catalog `MISS` и cached `HIT` integration.
- [x] Playback/recommendation SQL shape, behavior и catalog HTTP query budget.
- [x] Collection title membership regression.
- [x] Migration applied only to disposable 26-ГБ reflink copy; covering query plan confirmed.
- [x] Single read-only internal cold observation before/after bounded SQLite revision.
- [ ] Full repository suite, docs refresh, build, final legacy scan, clean-tree commit and push.

## Compliance matrix

| Требование | Статус | Evidence |
| --- | --- | --- |
| Один background warm на реально видимый тайтл | completed | bounded metadata + scheduler + per-title job tests |
| Не прогревать fresh entry | completed | exact authoritative state API и HTTP assertion |
| Перепрогревать stale/missing | completed | state transition и one-request job regression |
| Не конкурировать с import | completed | shared activity detector и release regression |
| Работать при `/titles` page-cache HIT | completed | cached payload metadata + second-request integration test |
| Не раскрывать private/access state | already_compliant | guest visibility recheck, only integer public IDs, existing title policy |
| Отсутствие synchronous self-HTTP | completed | only queue job owns `PublicPageCacheWarmer`; scheduler dispatches after response |
| Bounded workload и existing infrastructure | completed | hard cap 96, existing Redis connection/locks/`cache-warm-v2` worker |
| SQLite cold-path и index | completed | SQL-shape tests, 1 970,2 ms observation, covering-index rehearsal |
| Production database safety | completed | no production migration; only reflink-copy rehearsal |
| Full repository delivery | unresolved | final suite/commit/push remain at time of this update |
