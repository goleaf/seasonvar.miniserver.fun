# Прогрев видимых страниц тайтлов и ускорение cold-path

Дата: 19.07.2026

Статус: implementation, production index rollout, rolling runtime activation и task-focused verification завершены. Fresh full repository verification и Git delivery остаются `unresolved` до завершения параллельных изменений общего рабочего дерева.

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
- [ ] Fresh full repository suite: прежние 7 admin `public_id` errors закрыты минимальным loaded-attribute fallback; соответствующие catalog/search группы прошли 85 tests / 812 assertions. Два следующих общих запуска пересеклись с параллельной записью новых admin/import/Livewire файлов: 1 380 tests / 1 365 passed / 11 skipped / 122 302 assertions и затем 1 385 tests / 1 369 passed / 11 skipped / 122 324 assertions. Их transient группы после стабилизации прошли 15 tests / 78 assertions и 43 tests / 229 assertions; восстановление legacy `seasonvar.admin_emails` для granular catalog permissions подтверждено отдельными 3 tests / 52 assertions и 12 tests / 60 assertions. Clean full snapshot всё ещё обязателен после прекращения параллельных записей.
- [x] Rolling runtime activation: `cache-warm-v2` worker автоматически обновился в 00:20; read-only Redis inspection показал 175 ready payload с `maxTries=0`/absolute `retryUntil` и 74 legacy payload с прежним attempt-bound контрактом. Ручной общий FPM restart, queue rewrite и clear не выполнялись.
- [ ] Clean-tree commit и configured push.

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
| Full repository delivery | unresolved | Свежий изолированный cache/runtime набор 33 tests / 239 assertions зелёный; прежние 7 admin projection errors исправлены и их 85-test группа зелёная, но два новых full run пересеклись с продолжающейся записью admin/import/Livewire файлов. Все извлечённые стабильные группы сейчас зелёные; единый clean snapshot и commit/push ещё невозможны |
| Production rollout | completed | Backup, target-only index и основной runtime rollout завершены; автоматический recycle cache-warm worker и live new-format payload подтвердили retry/version/locale activation без ручного общего restart |
