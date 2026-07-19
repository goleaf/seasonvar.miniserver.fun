# Прогрев показанных карточек и ускорение холодной страницы тайтла

Дата: 2026-07-19

## Цель и baseline

После успешной сборки `/titles` фоновой очередью проверять canonical guest cache каждого фактически показанного тайтла и прогревать только `missing/stale`. Одновременно устранить подтверждённые global-corpus SQL-запросы карточки, не меняя URL, entitlement, персонализацию, рекомендации, коллекции и плеер.

Read-only диагностика показала: canonical `HIT` `/titles/ierrohierro` даёт `0,06–0,20 s` TTFB, cold/bypass — `4,5–5,6 s`, cold `/titles` — около `7 s`. Cold title выполняет `70` SQL примерно за `2,49 s`: episode navigation около `1,02 s`, recommendation counts около `0,50 s` каждый, collections около `0,45 s`, season summaries около `0,30 s`, user-state aggregate около `0,18 s` с full scan таблицы из `1,65 млн` rows. Сам тайтл имеет только `2` сезона, `14` серий и `38` media rows.

Из первых восьми показанных карточек семь были `missing`, одна `stale`. `CatalogSeries` не инициирует title warm. Существующий general `WarmCatalogCaches` непригоден для каждого catalog render: измеренный job занял `136,424 s`, потому что вместе с title URLs перестраивает stats/home/facets и до `100` unrelated public URLs.

## Выбранная архитектура

`CatalogSeries` не читает cache stores внутри TTFB. Он фиксирует максимум `96` positive unique IDs текущего paginator в request metadata. `CachePublicPage` сохраняет этот публичный bounded список рядом с HTML и передаёт его Laravel deferred scheduler после успешного `MISS`, `HIT` или `STALE`; новый catalog `response_contract=2` отделяет старые payload без metadata. Livewire update, который не проходит через full-page middleware, вызывает тот же deferred scheduler после успешного response. Scheduler ставит по одному `ShouldBeUnique` job `WarmCatalogTitlePage` на ID в существующую `cache-warm-v2`.

Job повторно проверяет `CatalogTitle::availableTo(null)`, active import и exact versioned cache state. `fresh` завершается без HTTP; `unavailable` освобождается с backoff; `missing/stale` вызывает `PublicPageCacheWarmer::warmTargets()` ровно с одним queryless `GET /titles/{slug}`. Управляемые отсрочки новых payload ограничены абсолютным `retryUntil()`, а не числом попыток: `$tries=0`, deadline 24 часа и unique lock с пятиминутным запасом не теряют работу при долгом импорте или временном отказе cache/version store. Laravel queue metadata уже поставленных payload не переписывается: они завершаются по прежнему attempt-bound контракту, а demand-driven fan-out после истечения старого lock создаёт новое задание только для снова показанного тайтла; queue rewrite/clear не выполняется. `ShouldBeUnique`, `WithoutOverlapping` и существующий page-cache rebuild lock предотвращают duplicate authoritative work.

Scheduler работает для index, pagination, year/taxonomy, filters, search и Livewire updates, потому что они используют `CatalogSeries`. Search/filter query не переносится в title URL. Новый worker/queue не добавляется; queue dispatch failure не меняет response каталога.

## Exact cache state

Новый `CacheEntryState` содержит `fresh`, `stale`, `missing`, `unavailable`; только `stale/missing` требуют warm. `TieredCache::state()` использует те же `CacheVersionRegistry`, `CacheKeyFactory`, domain, resource, dimensions и version scope, что `remember()`. Отдельный marker не создаётся.

`PublicPageCachePolicy::canonicalTitleContext()` и request context используют один dimensions builder: audience public, canonical `APP_URL`, default `APP_LOCALE=ru`, route `titles.show`, canonical slug parameter, empty query hash, Vite manifest fingerprint, global title version и scope `title:{id}`. Self-HTTP без user session не прогревает session-selected locale или query variants `season/episode/media/variant/quality/format`.

## Cold SQL optimization

- `watchableEpisodes($title)` получает direct `seasons.catalog_title_id = ?`, scalar guest-visible title check и available-season subquery, уже ограниченный этим же title ID; global helper сохраняет correlated `EXISTS`.
- `seasonSummaries()` выбирает сезоны current title и получает episode/media counts title-bounded grouped queries.
- `CatalogRecommendationTitleLoader` заменяет global available-season list на derived projection доступных сезонов только выбранных recommendation ID.
- `CatalogCollectionQuery::publicForTitle()` сначала получает IDs через существующий `catalog_collection_items(catalog_title_id,catalog_collection_id)` index, затем применяет прежние public summary/order/limit.
- Additive reversible migration создаёт `catalog_user_state_title_summary_idx(catalog_title_id,in_watchlist,rating)` для existing watchlist/rating aggregate.

Regression tests сохраняют guest/authenticated publication/audience/time windows, hidden/deleted rows, playback location/health, cross-season navigation, counts, collection visibility/order и cache version invalidation.

## Ошибки, конфигурация и rollout

Active parent-run statuses `queued/running` откладывают job на `300 s`; стадии title-group `discovering/finalizing` проходят при родительском `running`. Cache unavailable не трактуется как missing. HTTP timeout/non-2xx использует bounded retries; failure одного title не отменяет другие. Logs не содержат slug, URL, source URL, cache key, cookie или token. Global cache/queue clear и video download запрещены.

`config/cache-architecture.php` получает `warming.visible_titles`: `enabled=true`, `max_titles=96`, `import_pause_seconds=300`, `unavailable_pause_seconds=60`, `retry_window_seconds=86400`, `unique_seconds=86700`. `.env.example` документирует `CACHE_VISIBLE_TITLE_WARM_*`; `.env` не меняется. `phpunit.xml` выключает feature по умолчанию, focused tests включают с `Queue::fake()`.

Перед SQLite index DDL обязательны backup, free-space check, disposable-copy build measurement, writer pause, `migrate --pretend`, `PRAGMA index_list`, EXPLAIN и health. Emergency rollback: `CACHE_VISIBLE_TITLE_WARM_ENABLED=false`, config cache rebuild и graceful PHP/worker reload; cache clear не нужен. Безопасный лишний index можно оставить до отдельного DDL window.

## Acceptance

- `N` shown unique titles создают не более `min(N,96)` unique jobs, без synchronous cache/self-HTTP в `/titles`.
- Fresh job делает `0` HTTP; missing/stale — один logical target с bounded retry; job никогда не прогревает stats/home/facets/manifest.
- После missing warm canonical request возвращает `HIT`; stale запускает bounded deferred rebuild.
- Median cold title TTFB `<=2,0 s`, SQL total `<=1,0 s`, max SQL `<=0,30 s`; если external load мешает абсолютному порогу, требуется минимум `50%` improvement baseline.
- Cached title остаётся `<=0,30 s`.
- PHPUnit focused/full, Pint, Vite, legacy scan, docs/compliance и read-only HTTPS smoke проходят до main-only commit/push.
