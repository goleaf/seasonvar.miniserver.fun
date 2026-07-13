# Кеширование и Redis/Memcached

Обновлено: 13.07.2026

## Неподвижные границы

- База данных остаётся единственным источником истины. Redis и Memcached содержат только производные или операционные данные.
- Redis и Memcached решают разные задачи: Redis domain хранит shared snapshots/stale-копии/метрики, critical locks workload хранит locks и version registry, остальные изолированные connections — sessions, limiter counters и queue payloads; Memcached хранит короткоживущую disposable hot-копию небольших публичных DTO.
- Queue work выполняется через Redis. Memcached не используется для очередей, sessions, idempotency или критических locks.
- `Cache::flush()` запрещён в application lifecycle и deployment. Инвалидация выполняется bump версии домена/объекта; старые ключи истекают по TTL.
- Каждый private cache обязан включать identity, tenant/profile, permission/subscription version, locale и audience. В текущей реализации shared tier используется только для гостевых публичных данных; private watchlist, history, notifications, permissions, admin state и signed playback URL не кэшируются.
- Raw tokens, credentials, CSRF state, исходный HTML, raw media URL и Eloquent object graphs не сохраняются. Google access token существует только в памяти текущего вызова.
- Memcached miss или eviction — штатное состояние. Cold database path остаётся рабочим, а Redis lock не допускает stampede.

## Слои

1. Browser/CDN: public API, sitemap, feeds, OpenSearch и `llms.txt` получают `Cache-Control`, `ETag`, `Last-Modified`, bounded `Vary`, `stale-while-revalidate` и `stale-if-error`. Livewire, signed playback, authenticated и session-bearing ответы не помечаются public.
2. Laravel compiled cache: deployment выполняет `php artisan optimize`; hashed Vite assets публикуются production build. `optimize:clear` не является обычной deployment-командой, потому что default cache может быть shared Redis.
3. Request memo: `Cache::memo()` устраняет повторный Redis/Memcached round trip для одного ключа внутри HTTP request или job. Static mutable request state не используется.
4. Memcached hot: короткий TTL для повторно запрашиваемых публичных compact arrays/ID lists.
5. Redis domain: fresh и bounded stale envelope, warming state и low-cardinality metrics; version registry использует отдельный critical `redis-locks` store, чтобы отказ disposable domain cache не мог отменить invalidation Memcached namespace.
6. SQLite/database: authoritative rebuild. Внешний Seasonvar и Google APIs не являются request-time источником обычных страниц каталога.

`TieredCache` выполняет hot lookup → Redis fresh lookup → Redis stale lookup → Redis single-flight lock → authoritative rebuild → Redis fresh/stale write → Memcached promotion. Envelope содержит только format marker, negative marker и безопасное значение. Payload больше `CACHE_MAX_PAYLOAD_BYTES` не записывается. TTL получает bounded jitter.

## Named stores и connections

| Имя | Backend | Назначение |
| --- | --- | --- |
| `redis-domain` | Redis connection `cache` | shared domain cache, stale snapshots, metrics и default application cache |
| `memcached-hot` | один или несколько Memcached servers | disposable short-lived hot DTO/ID snapshots |
| `redis-locks` | Redis connection `locks` | rebuild/import/warming locks, unique-job locks и authoritative cache version registry |
| `redis-limiter` | Redis connection `limiter` | atomic HTTP/Livewire/action limiter counters |
| `recomputable-failover` | `redis-domain` → `file` | только явно выбранные recomputable данные; не sessions, queues, locks или authorization |
| Redis `sessions` | отдельный connection | session payloads |
| Redis `queues` | отдельный connection | queue payloads и reservations |
| Redis `broadcasting` | отдельный reserved connection | только при появлении реального broadcasting use case |

На одном standalone Redis используются DB 1–6 и разные prefixes. Это не HA и не переносится буквально в Redis Cluster, где database-number separation недоступна. На production scale предпочтительны отдельные managed deployments/endpoints как минимум для disposable cache, queues, sessions и critical locks/limiters. Horizon не установлен: отдельная Horizon-compatible non-cluster queue topology и авторизованный operational UI пока не согласованы. Connection с зарезервированным Horizon именем не создаётся.

PhpRedis является default client. Поддерживаются workload-specific URL/TLS, username/password, timeout/read timeout, retry interval, max retries, decorrelated-jitter backoff, client name, persistent ID и TCP keepalive через environment. Serializer/compression не переключались: локально доступны PHP/JSON serializers, но igbinary/MessagePack/LZ4/Zstandard отсутствуют, а rolling compatibility и CPU/payload benchmark не выполнены. Любая будущая смена serializer/compression требует `CACHE_FORMAT_VERSION` bump.

## Key и tag policy

`CacheKeyFactory` формирует ключ как:

```text
{application}:{environment}:s{schema}:f{format}:{domain}:v{content-version}:{resource}:{sha256(canonical-dimensions)}
```

Ассоциативные dimensions рекурсивно сортируются; строки normalise/squish/lower, ограничиваются по длине и никогда не попадают в ключ напрямую. Число dimensions, длина строки, resource и scope ограничены. Search/filter input проходит request validation, после чего canonical array хэшируется. Locale, public audience, year/filter/page/sort входят только там, где меняют результат.

Primary cross-store invalidation использует versioned namespace, потому что tag semantics разных stores и failover не обязаны совпадать. Redis tags поддерживаются для точных односторонних групп и проверены integration-тестом; production code не использует `KEYS`, wildcard deletion или обычный full-store flush. Для title cache используется scope `title:{id}`. Metric keys содержат только дату, domain и allowlisted low-card metric name.

## TTL matrix

| Домен | Fresh Redis | Stale Redis | Hot Memcached | Negative | Lock | Wait | Jitter |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| homepage | 120 s | 900 s | 60 s | 15 s | 60 s | 500 ms | 10% |
| catalog facets | 300 s | 1800 s | 120 s | 30 s | 90 s | 500 ms | 10% |
| catalog stats | 1800 s | 86400 s | 300 s | 30 s | 180 s | 250 ms | 15% |
| title detail policy | 300 s | 1800 s | 120 s | 30 s | 60 s | 250 ms | 10% |
| recommendations policy | 1800 s | 21600 s | 600 s | 60 s | 120 s | 500 ms | 15% |
| search suggestions policy | 60 s | 300 s | 30 s | 20 s | 30 s | 200 ms | 15% |
| sitemap policy | 1800 s | 21600 s | 300 s | 30 s | 120 s | 500 ms | 15% |
| API policy | 60 s | 300 s | 30 s | 20 s | 30 s | 250 ms | 10% |
| operational | 10 s | 60 s | 5 s | 5 s | 15 s | 100 ms | 10% |

Строки с пометкой `policy` подготовлены, но shared application snapshot для домена ещё не включён: наличие TTL не считается доказательством кэширования. Permanent entries не используются. Version/modified registry живёт один год и продлевается при обращении; schema/format prefix делает rolling deployment совместимым.

HTTP API policy: browser `max-age=60`, shared `s-maxage=300`, SWR 60 s, stale-if-error 600 s. Public documents: 300/1800/300/3600 s. Ошибки, redirects, private и cookie-bearing responses middleware не кэширует.

## Инвентарь доменов

| Данные | Реализация | Invalidation/failure | Security |
| --- | --- | --- | --- |
| Homepage content index | Tiered ID/scalar snapshot | homepage version; stale + Redis lock; queued warm | public+locale only |
| Homepage metrics | Tiered compact counts | homepage version; warm | public aggregate |
| Genre/country and default catalog facets | Tiered compact rows | catalog-facets version; warm top facets | bypass for authenticated, searches and non-default criteria |
| `/stats` snapshot | Tiered sanitized array | catalog-stats version; long measured TTL; poll reads cache; import invalidates and warms | no source/media URLs or private event context |
| Public API response | CDN/browser validators | API version; database/API Resource remains cold path | only anonymous 200 GET/HEAD |
| Sitemap/feed/OpenSearch/LLM docs | CDN/browser validators | sitemap version and deployment schema | public streamed documents |
| Recommendations | database relation; policy reserved | invalidated and rebuilt by importer | no raw signals in public cache |
| Title detail/seasons/episodes/media | database with bounded eager loads; policy reserved | title-scoped version exists | signed/raw playback data deliberately excluded |
| Search candidate IDs/suggestions | database/FTS; no arbitrary shared cache | bounded validated input; facets only | avoids key explosion/query leakage |
| Ratings/comments/reviews | database | catalog mutation bumps public domains | user mutations never shared |
| Watchlist/history/preferences/notifications | database or session where already designed | immediate authoritative read | private; not globally cached |
| Import progress/admin counts | operational DB snapshot and queue heartbeat | bounded polling/health | no public cache |
| Navigation/settings | static configuration/layout data | deployment/config cache | no database query in Blade |

## Invalidation и warming

`CatalogCacheInvalidator` is the only catalog cache mutation boundary. It waits for transaction commit, normalizes at most 1000 affected title IDs, bumps each public domain once, bumps scoped title versions and dispatches one deduplicated `WarmCatalogCaches` job. Необязательный warmer dispatch имеет отдельную safe failure boundary: queue outage фиксируется low-card metric/log и не превращает уже committed authoritative catalog write в ложный rollback/500. Bulk importer paths call invalidator explicitly because query-builder upserts do not emit Eloquent events. Admin writes, sync importer completion/error, queued zero-work completion and queued finalizer are connected to the same boundary.

`WarmCatalogCaches`:

- uses Redis queue `cache-warm`, `ShouldBeUnique`, Redis unique store and `WithoutOverlapping`;
- routes every scheduler/on-one-server mutex through `redis-locks`, never through disposable domain cache;
- has bounded timeout, retry window and exponential backoff;
- warms stats, homepage metrics/content index and top homepage genre/country lists only;
- scheduled/deployment `--refresh` rebuilds the active homepage, facets and stats namespaces under their Redis locks while the previous fresh/stale envelopes remain readable; importer/admin invalidation dispatches a normal job because authoritative changes have already selected new versions;
- stores a sanitized warming state and emits duration/failure counters;
- never enumerates arbitrary search/filter combinations.

Commands:

```bash
php artisan cache:warm-catalog
php artisan cache:warm-catalog --queue
php artisan cache:warm-catalog --queue --refresh
php artisan cache:metrics
php artisan cache:metrics --json
php artisan app:health
php artisan app:health --json
```

Deployment increments `CACHE_SCHEMA_VERSION` when key meaning changes and `CACHE_FORMAT_VERSION` when serialization/envelope changes, then runs the queued `--refresh` warmer. It does not scan or delete old namespaces. The hourly scheduler uses the same `--queue --refresh` mode, so the worker atomically replaces successful envelopes without creating a version gap or making the first public request perform planned work.

## Failure recovery

- Memcached unavailable: hot reads/writes become misses, Redis fresh/stale remains available, Redis rebuild lock prevents database stampede.
- Redis domain unavailable: existing Memcached hot values can serve; otherwise authoritative read rebuilds without treating Redis as source of truth. Version bumps остаются на отдельном critical locks workload, поэтому mutation меняет Memcached namespace даже при отказе domain Redis; это проверено real-service regression. Failures are logged by class/domain/operation without raw keys.
- Redis locks unavailable: tiered read may perform an uncached safe rebuild, but no destructive workflow uses this path. Import/warming critical locks fail their own operation rather than silently substituting Memcached.
- Critical cache version registry unavailable: tiered reads fail closed, ignore every old Memcached/Redis namespace and rebuild from the database without cache writes; public HTTP response switches to `no-store`. Invalidation reports failure instead of pretending that the version changed.
- Redis sessions unavailable: no unrelated failover is configured; readiness fails and traffic must not receive stale identity state.
- Redis queues unavailable: dispatch throws/fails visibly; jobs are not reported as accepted. DB remains authoritative and jobs remain idempotent.
- Rebuild lock contention: safe stale is served; without stale, wait is bounded and raises `CacheRebuildTimeout` instead of issuing the same expensive query.

`/health/ready` and `app:health` distinguish database, Redis cache/sessions/queues/locks/limiter, Memcached, queue heartbeat, Horizon state and last warming state. Redis cache status includes safe memory/eviction counters, while Memcached status aggregates hit/miss, eviction, item, byte and connection counters without server addresses. Cache/Memcached outages and an absent worker heartbeat make the aggregate status `degraded`; database/session/queue/lock/limiter failures make it `failed`. A missing heartbeat does not make the transport itself unready, but it must never produce a false `ok`. Endpoint is rate-limited, private/no-store, does not start a session and never returns hostnames or credentials.

## Observability и alerts

Tiered cache records hits/misses per layer, writes, invalidations, stale responses, rebuild count/time/failures, lock timeout, rejected and accepted payload sizes and warming duration. Queue instrumentation records processed/failed jobs, worker heartbeat and server-derived wait duration. Laravel cache hit/miss/write/forget events are counted through direct low-card Redis counters; `CacheFailedOver` produces an explicit error log. The event reporter never records raw key, query, user ID or token and bypasses Laravel Cache to avoid recursive events.

Operational monitoring must alert on low warm hit ratio, rising Memcached evictions/bytes, Redis latency/memory pressure, `failure`/`lock-timeout`/`stale-served-on-error`, failover events, warming failure, queue backlog/wait and missing worker heartbeat. Memcached infrastructure dashboards additionally track `get_hits`, `get_misses`, `evictions`, `curr_items`, `total_items`, `bytes`, `limit_maxbytes`, connections, rejected connections and latency.

## Local/CI и safety

Local PHP requires `redis` and `memcached` extensions plus reachable standalone services. CI starts exactly one Redis and one Memcached service, assigns run-specific prefixes, uses Redis DB 1–5 and runs real integration tests when `RUN_CACHE_INFRASTRUCTURE_TESTS=true`. Normal PHPUnit cache remains `array`; integration tests touch only random exact keys/tags and never flush a shared store.

Use `redis-cli -n <db> PING`, `echo stats | nc 127.0.0.1 11211`, `app:health` and `cache:metrics`; never use Redis `KEYS`. Failure drills use isolated invalid endpoints/test prefixes rather than stopping production services.
