# Прогрев видимых страниц тайтлов и ускорение cold-path

Дата: 19.07.2026

Статус: implementation, production index rollout, rolling runtime activation и task-focused verification завершены. Post-delivery TDD follow-up исправляет обход `ShouldBeUniqueUntilProcessing` у общего `WarmCatalogCaches`, обнаруженный по production backlog во время run `#954`. Fresh full repository verification и Git delivery остаются `unresolved` до завершения параллельных изменений общего рабочего дерева.

## Наблюдаемая проблема

- Повторный guest page-cache `HIT` страницы тайтла быстрый, но uncached `/titles/ierrohierro` выполнял тяжёлые episode/recommendation/collection/user-summary SQL.
- `/titles` не передавал реально показанные карточки в background warming; общий `all-public` проход слишком велик для запуска при каждом просмотре каталога.
- Scheduler только внутри Livewire render недостаточен: полный catalog page-cache `HIT` не создаёт компонент и поэтому обязан читать bounded ID metadata из самого cached payload.

## Реализуемый контракт

1. `TieredCache::state()` читает только authoritative domain store и возвращает `fresh`, `stale`, `missing` или fail-safe `unavailable`, ничего не перестраивая.
2. `PublicPageCachePolicy::canonicalTitleContext()` строит canonical dimensions для queryless guest `/titles/{slug}` с default `APP_LOCALE`, не наследуя mutable locale долгоживущего worker, и использует тот же version scope, что HTTP middleware.
3. `CatalogSeries` фиксирует не более 96 уникальных положительных ID показанных тайтлов. На обычном GET middleware сохраняет их в guest cache payload; на Livewire update scheduler запускается после успешного ответа.
4. `CachePublicPage` передаёт ID scheduler и на `MISS`, и на `HIT`/`STALE`. `response_contract=2` делает прежние catalog payload без metadata недостижимыми без scan/flush.
5. На каждый ID создаётся отдельный `WarmCatalogTitlePage` в существующей `cache-warm-v2`. Job использует `ShouldBeUnique`, `WithoutOverlapping`, guest visibility, shared import detector и exact state check. Fresh/hidden ничего не делают; stale/missing выполняют один same-origin HTTP warm; import/cache/version-store outage дают bounded release внутри абсолютного 24-часового retry window, а unique lock живёт дольше deadline.
6. Title playback применяет прямой `catalog_title_id` и bounded available-season subquery; recommendation count использует derived available-season projection только выбранных ID; collection membership использует индексируемый title lookup.
7. Additive reversible migration добавляет covering index `(catalog_title_id,in_watchlist,rating)`.

## Safety, cross-feature и rollback

- Authoritative данные остаются в SQLite; cache/queue outage никогда не меняет correctness или visibility.
- Authenticated, hidden, private, signed playback/download, source URL, premium/region/legal state не сериализуются и не прогреваются как guest page.
- Активные parent-run состояния `queued|running` временно блокируют fan-out workload; стадии title-group `discovering|finalizing` уже выполняются внутри родительского `running`.
- Новая очередь, dependency, cron, route, controller или synchronous self-HTTP не добавляются.
- Быстрый rollback: `CACHE_VISIBLE_TITLE_WARM_ENABLED=false`, `config:cache`, graceful refresh PHP-FPM/worker. Queue/cache не очищаются. Индекс может остаться при application rollback.

## Verification checklist

- [x] `CacheEntryState` missing/fresh/stale/unavailable regression.
- [x] Canonical title context parity regression.
- [x] Import activity status regression и существующие consumer jobs.
- [x] Job contract: unique, overlap, fresh skip, stale/missing warm, hidden/import/outage skip/release.
- [x] Job retry contract: новые payload используют `$tries=0`, absolute `retryUntil()`, deadline-covering unique lock, domain/version-store outage release и default-locale canonical key; старые Laravel payload честно остаются на записанном attempt-bound контракте и заменяются только следующим demand-driven fan-out без queue rewrite/clear.
- [x] End-to-end regression: missing job выполняет canonical self-HTTP `MISS`, записывает full-response cache, а следующий queryless guest request получает `HIT`.
- [x] Scheduler normalization, hard cap, disable flag, catalog `MISS`, cached `HIT` и `STALE` integration.
- [x] Playback/recommendation SQL shape, behavior и catalog HTTP query budget.
- [x] Collection title membership regression.
- [x] Migration rehearsed on disposable 26-ГБ reflink copy, then applied alone to production after verified backup and writer pause; covering query plan confirmed.
- [x] Single read-only internal cold observation before/after bounded SQLite revision.
- [x] Пять повторных isolated read-only observations: HTTP median 1 394,4 ms, SQL median 1 075,0 ms, max SQL 526,9 ms; absolute SQL thresholds не достигнуты, fallback improvement от 2 490 ms baseline равен 56,8%.
- [x] Read-only HTTPS smoke: первоначально `/titles` `HIT` 67,9 ms, `/titles/ierrohierro` `MISS` 1 510,2 ms, затем `HIT` 76,8 ms. Повтор под длительным import/finalization backlog честно показал `/titles` `STALE` 2 720,1 ms, `/titles/ierrohierro` cold `MISS` 12 530,6 ms и следующие `HIT` 1 382,7/752,9 ms. Это подтверждает переход `MISS→HIT`, но не постоянный cold SLA; один dedicated worker сохранил fan-out и перевёл 290 title jobs в delayed без queue rewrite/clear до окончания импорта.
- [x] Расширенный task-focused cache/route/query snapshot: 115 tests / 1 040 assertions; после новых параллельных admin-изменений свежий изолированный cache/runtime набор: 33 tests / 239 assertions. End-to-end `MISS→HIT` regression входит в оба evidence-набора.
- [x] Pint, focused PHPStan, прежний Vite build, task docs refresh check и final legacy/duplicate scan.
- [x] Production backup/write-pause window, target-only migration, index/query-plan verification и возврат application/workers для основной реализации.
- [x] Fresh full repository suite после стабилизации admin/import snapshot и bounded queued-recommendation контракта: 1 407 tests, 11 expected skipped, 122 916 assertions. Дополнительно повторно прошли cache/runtime 33 tests / 239 assertions, importer 83 / 470 и recommendation 21 / 102.
- [x] Rolling runtime activation: `cache-warm-v2` worker автоматически обновился в 00:20; read-only Redis inspection показал 175 ready payload с `maxTries=0`/absolute `retryUntil` и 74 legacy payload с прежним attempt-bound контрактом. Ручной общий FPM restart, queue rewrite и clear не выполнялись.
- [x] Import recovery/live fan-out: run `#944` завершён с `last_recommendations.mode=deferred`, checkpoint удалён и dirty recommendations сохранены. Сразу после снятия pause Redis показал 373 отдельных ready `WarmCatalogTitlePage`; новый controlled sitemap-tail run `#954` снова включил ожидаемую паузу без queue rewrite/clear.
- [x] Все найденные producers затронутых unique jobs используют pending dispatch: RED воспроизвёл `2→1` `WarmCatalogCaches` и `3→1` recommendation jobs, минимальные типизированные `::dispatch()` дали GREEN; cache/import и collection-sync наборы прошли 49 tests / 319 assertions. Production census после rollout остаётся отдельным незавершённым evidence.
- [x] Актуальный общий PHPUnit после coalescing follow-up: 1 427 tests, 1 416 passed, 11 expected skipped, 122 945 assertions; Pint, PHP syntax, managed docs и diff gates прошли.
- [x] Clean-tree product commit и configured push: snapshot с cache-реализацией опубликован в `main` как `eb4e7f9`.
- [x] Отдельный live-evidence follow-up объединён с совместимыми admin/Livewire closure edits в общий documentation commit без перестройки или потери чужого staging.

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
| SQLite cold-path и index | completed | SQL-shape tests, covering-index rehearsal; повторный median HTTP 1 394,4 ms, SQL 1 075,0 ms и 56,8% improvement от 2 490 ms baseline выполняют только предусмотренный fallback >=50%, не абсолютные SQL ceilings |
| Production database safety | completed | verified owner-only backup с `quick_check` и `foreign_key_check`, maintenance/write pause, target-only batch 30 migration, exact index columns и covering `EXPLAIN` |
| Full repository delivery | completed | Актуальный snapshot прошёл 1 427 tests / 1 416 passed / 11 expected skipped / 122 945 assertions; реализация зафиксирована в `096c66f`, а содержащий её documentation HEAD `51ba313` подтверждён в `origin/main` без force push. |
| Production rollout | completed | Import workers естественно переработались в 02:58 без ручного restart. Два read-only census за 91 секунду при продвижении active run на 42 страницы показали недельный unique lock и неизменные 614 legacy общих jobs; новые общие дубли не появились, legacy backlog оставлен для естественного drain без queue clear/rewrite. |
