# Кеширование и Redis/Memcached

Обновлено: 15.07.2026

Production rollout 15.07.2026 подтвердил, что исторические `cache-warm` envelopes имеют истёкший `retryUntil`: Laravel отклоняет их до application `handle()`, поэтому no-op compatibility не может безопасно drain-ить эту очередь. Pending/failed legacy payload не удаляются и не retry-ятся автоматически. Новый coalesced intent, heartbeat и единственный worker используют `cache-warm-v2`; job не публикует absolute retry deadline и ограничивает реальные ошибки тремя attempts. Redis/Memcached transports остаются раздельными, а отсутствие evictions не является доказательством корректной инвалидации.

## Неподвижные границы

- База данных остаётся единственным источником истины. Redis и Memcached содержат только производные или операционные данные.
- Redis и Memcached решают разные задачи: Redis domain хранит shared snapshots/stale-копии/метрики, critical locks workload хранит locks и version registry, остальные изолированные connections — sessions и queue payloads; Memcached хранит короткоживущую disposable hot-копию небольших публичных DTO.
- Queue work выполняется через Redis. Memcached не используется для очередей, sessions, idempotency или критических locks.
- `Cache::flush()` запрещён в application lifecycle и deployment. Инвалидация выполняется bump версии домена/объекта; старые ключи истекают по TTL.
- Каждый private cache обязан включать identity, tenant/profile, permission/subscription version, locale и audience. В текущей реализации shared tier используется только для гостевых публичных данных; private watchlist, history, notifications, permissions, admin state и signed playback URL не кэшируются.
- Raw tokens, credentials, CSRF state, исходный HTML, raw media URL и Eloquent object graphs не сохраняются. Google access token существует только в памяти текущего вызова.
- Sanitize-нутый guest HTML хранится gzip-сжатым с отдельными compressed/uncompressed границами; decode ограничен `PUBLIC_PAGE_CACHE_MAX_UNCOMPRESSED_PAYLOAD_BYTES`, а legacy plain entries остаются читаемыми до TTL.
- Memcached miss или eviction — штатное состояние. Cold database path остаётся рабочим, а Redis lock не допускает stampede.

## Слои

1. Browser/CDN и full-response: public API/documents получают HTTP validators, а явно отмеченные гостевые HTML routes используют server-side `TieredCache`. Authenticated, `Authorization`, `X-Livewire`, free search и session-specific state не разделяются между пользователями.
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
| `recomputable-failover` | `redis-domain` → `file` | только явно выбранные recomputable данные; не sessions, queues, locks или authorization |
| Redis `sessions` | отдельный connection | session payloads |
| Redis `queues` | отдельный connection | queue payloads и reservations |
| Redis `broadcasting` | отдельный reserved connection | только при появлении реального broadcasting use case |

На одном standalone Redis используются отдельные DB/prefixes для cache, queues, sessions, locks и зарезервированного broadcasting. Это не HA и не переносится буквально в Redis Cluster, где database-number separation недоступна. На production scale предпочтительны отдельные managed deployments/endpoints как минимум для disposable cache, queues, sessions и critical locks. Horizon не установлен: отдельная Horizon-compatible non-cluster queue topology и авторизованный operational UI пока не согласованы. Connection с зарезервированным Horizon именем не создаётся.

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
| catalog pages | 300 s | 1800 s | 120 s | 30 s | 90 s | 500 ms | 10% |
| catalog facets | 300 s | 1800 s | 120 s | 30 s | 90 s | 500 ms | 10% |
| catalog stats | 1800 s | 86400 s | 300 s | 30 s | 180 s | 250 ms | 15% |
| title detail policy | 300 s | 1800 s | 120 s | 30 s | 60 s | 250 ms | 10% |
| recommendations policy | 1800 s | 21600 s | 600 s | 60 s | 120 s | 500 ms | 15% |
| search suggestions policy | 60 s | 300 s | 30 s | 20 s | 30 s | 200 ms | 15% |
| sitemap policy | 1800 s | 21600 s | 300 s | 30 s | 120 s | 500 ms | 15% |
| API policy | 60 s | 300 s | 30 s | 20 s | 30 s | 250 ms | 10% |
| operational | 10 s | 60 s | 5 s | 5 s | 15 s | 100 ms | 10% |

Строки с пометкой `policy` подготовлены, но отдельный data snapshot для домена ещё не включён; title detail при этом уже использует общий безопасный HTML envelope. Permanent entries не используются. Version/modified registry живёт один год и продлевается при обращении; schema/format prefix делает rolling deployment совместимым.

HTTP API policy: browser `max-age=60`, shared `s-maxage=300`, SWR 60 s, stale-if-error 600 s. Public documents: 300/1800/300/3600 s. Anonymous public v1 GET/HEAD получает validators и может ответить `304`; любой `Authorization` header до и после optional Sanctum resolution принудительно даёт `private, no-store` без `ETag`/`Last-Modified`. Ошибки, redirects, private и cookie-bearing responses middleware не кэширует.

## Инвентарь доменов

| Данные | Реализация | Invalidation/failure | Security |
| --- | --- | --- | --- |
| Homepage HTML и content index | Tiered sanitized HTML плюс ID/scalar cold snapshot | homepage version; stale + Redis lock; queued HTTP warm | guest public+locale only |
| Homepage metrics | Tiered compact counts | homepage version; warm | public aggregate |
| Catalog/directory HTML | Tiered sanitized HTML | catalog-pages version; recent bounded manifest | guest only; `q`/`title` bypass |
| Genre/country and default catalog facets | Tiered compact rows | catalog-facets version; warm top facets | bypass for authenticated, searches and non-default criteria |
| `/stats` snapshot | Tiered sanitized array | catalog-stats version; long measured TTL; poll reads cache; import invalidates and warms | no source/media URLs or private event context |
| Public API response | CDN/browser validators | API version; database/API Resource remains cold path | only anonymous 200 GET/HEAD; Bearer always private/no-store |
| Sitemap/feed/OpenSearch/LLM docs | CDN/browser validators | sitemap version and deployment schema | public streamed documents |
| Recommendations | database relation; policy reserved | invalidated and rebuilt by importer | no raw signals in public cache |
| Title detail/seasons/episodes/media HTML | Tiered sanitized HTML; database cold path | title-scoped version плюс global generation | CSRF и signed playback URL восстанавливаются на каждый response |
| Search candidate IDs/suggestions | database/FTS; no arbitrary shared cache | bounded validated input; facets only | avoids key explosion/query leakage |
| Ratings/comments/reviews | database | catalog mutation bumps public domains | user mutations never shared |
| Watchlist/history/preferences/notifications | database or session where already designed | immediate authoritative read | private; not globally cached |
| Import progress/admin counts | operational DB snapshot and queue heartbeat | bounded polling/health | no public cache |
| Navigation/settings | static configuration/layout data | deployment/config cache | no database query in Blade |

## Invalidation и warming

`public.page` кэширует только успешный гостевой `GET` с HTML content type и ограниченным payload/query. Публичная session policy проверяется до и после рендера, поэтому добавленные контроллером flash/private state дают `BYPASS`. В envelope сохраняются body и allowlisted `Content-Type`; cookie/security headers добавляются внешними middleware. Для guest Livewire HTML допускаются только framework cookies `XSRF-TOKEN` и настроенная session cookie; их значения не попадают в envelope, а любой другой response cookie даёт `BYPASS`. Текущий CSRF и валидная локальная `playback.source` signature заменяются постоянными markers, а на HIT/STALE создаются заново для текущей session. Заголовок `X-Seasonvar-Page-Cache` сообщает `HIT`, `STALE`, `MISS` или `BYPASS`, не раскрывая ключи. Манифест хранит только bounded LRU относительных URL уже созданных shared entries; private routes, absolute URLs, `q` и `title` туда не попадают.

Во время rolling migration тегов cold catalog path определяет готовность canonical schema по таблице `tag_translations`, которая создаётся только после добавления canonical-колонок. Результат мемоизируется только в attributes текущего HTTP request: повторные scopes не делают schema queries, но следующий request сразу видит завершённую migration. CLI и long-lived queue jobs не получают долгоживущий статический schema cache.

`CatalogCacheInvalidator` остаётся единственной catalog cache mutation boundary. После commit он нормализует не более 1000 title IDs, bump-ит homepage, catalog-pages, facets, stats, API, sitemap и recommendations. Известные IDs bump-ят scopes `title:{id}`; неизвестный набор bump-ит global title generation. Затем invalidator атомарно объединяет bounded warm intent и dispatch-ит одну job. Queue outage фиксируется low-card metric/log и не превращает уже committed authoritative write в ложный rollback/500. Bulk importer paths вызывают invalidator явно, потому что query-builder upserts не создают Eloquent events.

`WarmCatalogCaches`:

- использует только Redis queue `cache-warm-v2`, `ShouldBeUniqueUntilProcessing`, versioned недельный unique lock и `WithoutOverlapping`;
- routes every scheduler/on-one-server mutex through `redis-locks`, never through disposable domain cache;
- has bounded timeout, retry window and exponential backoff;
- claim-ит bounded pending generation/title IDs и подтверждает их только после успешного прогрева; новая generation переживает завершение старой пачки;
- прогревает stats, homepage data snapshots, главную, `/stats`, `/titles`, directory indexes, изменённые title URLs и bounded recent manifest через exact-origin self HTTP с короткими timeout/retry;
- scheduled/deployment `--refresh` rebuilds the active homepage, facets and stats namespaces under their Redis locks while the previous fresh/stale envelopes remain readable; importer/admin invalidation dispatches a normal job because authoritative changes have already selected new versions;
- stores a sanitized warming state and emits duration/failure counters;
- никогда не перечисляет произвольные search/filter combinations; legacy jobs без pending intent завершаются no-op.

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

Deployment increments `CACHE_SCHEMA_VERSION` when key meaning changes and `CACHE_FORMAT_VERSION` when serialization/envelope changes, then records and dispatches queued `--refresh` work. It does not scan or delete old namespaces/queue payloads. Scheduler каждые десять минут использует тот же режим, чтобы резервный прогрев укладывался в минимальное 15-минутное stale-окно главной страницы. Worker можно включать только после deployment кода с no-op legacy handling и read-only проверки backlog/health; точный rollout принадлежит `deployment.md`.

## Failure recovery

- Memcached unavailable: hot reads/writes become misses, Redis fresh/stale remains available, Redis rebuild lock prevents database stampede.
- Redis domain unavailable: existing Memcached hot values can serve; otherwise authoritative read rebuilds without treating Redis as source of truth. Version bumps остаются на отдельном critical locks workload, поэтому mutation меняет Memcached namespace даже при отказе domain Redis; это проверено real-service regression. Failures are logged by class/domain/operation without raw keys.
- Redis locks unavailable: tiered read may perform an uncached safe rebuild, but no destructive workflow uses this path. Import/warming critical locks fail their own operation rather than silently substituting Memcached.
- Critical cache version registry unavailable: tiered reads fail closed, ignore every old Memcached/Redis namespace and rebuild from the database without cache writes; public HTTP response switches to `no-store`. Invalidation reports failure instead of pretending that the version changed.
- Redis sessions unavailable: no unrelated failover is configured; readiness fails and traffic must not receive stale identity state.
- Redis queues unavailable: dispatch throws/fails visibly; jobs are not reported as accepted. DB remains authoritative and jobs remain idempotent.
- Rebuild lock contention: safe stale is served; without stale, wait is bounded and raises `CacheRebuildTimeout` instead of issuing the same expensive query.
- Self HTTP warm недоступен или возвращает non-2xx: pending work не подтверждается, job повторяется по queue policy, а старый namespace остаётся читаемым до TTL.

`/health/ready` and `app:health` distinguish database, Redis cache/sessions/queues/locks, Memcached, queue heartbeat, Horizon state and last warming state. Redis cache status includes safe memory/eviction counters, while Memcached status aggregates hit/miss, eviction, item, byte and connection counters without server addresses. Queue health has four fixed low-cardinality entries: `default`, `cache_warm`, `seasonvar_import`, `seasonvar_title_refresh`; each reports connection/queue label, pending/delayed/reserved, oldest pending age and scoped heartbeat/last-processing timestamps. Worker loops refresh liveness even while idle, throttled to at most one cache write per queue every 5–30 seconds; processing refreshes immediately. `CACHE_DEFAULT_QUEUE_BUSY_THRESHOLD` and `CACHE_WARM_QUEUE_BUSY_THRESHOLD` control documented backlog degradation thresholds. Missing heartbeat plus work is `failed`, an empty unserved queue is `idle`, and a live over-threshold queue is `degraded`. Cache/Memcached outages and worker failures degrade aggregate application health; database/session/queue/lock transport failures make traffic readiness fail. CLI `app:health` exits nonzero for any state other than `ok`, so deployment/monitoring cannot accept `degraded`; HTTP `/health/ready` remains 200 while `ready=true`, so a usable web node is not ejected solely for a background-pool failure. A missing worker heartbeat does not masquerade as a transport outage, but it must never produce a false `ok`. Endpoint is private/no-store, does not start a session and never returns hostnames or credentials.

## Observability и alerts

Tiered cache records hits/misses per layer, writes, invalidations, stale responses, rebuild count/time/failures, lock timeout, rejected and accepted payload sizes and warming duration. Queue instrumentation records processed/failed jobs, worker heartbeat and server-derived wait duration. Laravel cache hit/miss/write/forget events are counted through direct low-card Redis counters; `CacheFailedOver` produces an explicit error log. The event reporter never records raw key, query, user ID or token and bypasses Laravel Cache to avoid recursive events.

Operational monitoring must alert on low warm hit ratio, rising Memcached evictions/bytes, Redis latency/memory pressure, `failure`/`lock-timeout`/`stale-served-on-error`, failover events, warming failure, queue backlog/wait and missing worker heartbeat. Memcached infrastructure dashboards additionally track `get_hits`, `get_misses`, `evictions`, `curr_items`, `total_items`, `bytes`, `limit_maxbytes`, connections, rejected connections and latency.

## Local/CI и safety

Local PHP requires `redis` and `memcached` extensions plus reachable standalone services. CI starts exactly one Redis and one Memcached service, assigns run-specific prefixes, uses Redis DB 1–5 and runs real integration tests when `RUN_CACHE_INFRASTRUCTURE_TESTS=true`. Normal PHPUnit cache remains `array`; integration tests touch only random exact keys/tags and never flush a shared store.

Use `redis-cli -n <db> PING`, `echo stats | nc 127.0.0.1 11211`, `app:health` and `cache:metrics`; never use Redis `KEYS`. Failure drills use isolated invalid endpoints/test prefixes rather than stopping production services.

## Cache lifecycle коллекций

`CacheDomain::Collections` версионирует только public-safe summaries. Create/rename/description/slug/visibility/moderation/feature/cover/item/order/sort/owner-public-name/title-merge mutations после commit bump-ят Collections, Homepage, Sitemap, TitleDetail, Recommendations и API, плюс targeted `collection:{id}` version. Flush store, wildcard key scan и user-specific membership cache не используются.

Directory/profile/home summaries могут использовать существующий versioned tier, но `/collections/{slug}`, management/editor, private/unlisted metadata, current-user membership, report draft, moderation notes и personal card state отвечают `private, no-store`. Cover delivery также всегда authorization-aware `private, no-store`, включая approved public cover: это намеренно предотвращает browser/shared stale exposure после public→private. Collection API и collection sitemap используют отдельные HTTP-cache profiles с `max-age=0`, `s-maxage=0` и без stale window; они немедленно revalidate и дополнительно зависят от API/Sitemap domain version. Public-to-private поэтому не ждёт старый общий documents/API TTL.

Cache key dimensions public summary включают domain version, collection identity, locale, page, normalized filters/sort и content/moderation versions там, где payload формируется. User ID, private notes, likes/follows/membership draft и signed upload paths никогда не входят в shared payload. Ошибка disposable cache не меняет policy/query truth: cold path повторно проверяет visibility/moderation/deleted state.

## Cache lifecycle обсуждений

- Второй comment cache не создаётся. Guest SSR первой public page может входить только в существующий versioned target HTML cache; authenticated requests, Livewire updates, direct-comment redirects, profile/inbox/admin и ответы с cookie/auth/error обходят shared cache.
- Guest DTO содержит только published public body/excerpt, author public name, derived public reply/reaction totals и stable anchors. Current-user reaction, own pending/hidden/rejected/spam rows/replies, edit/delete/report permission, block/mute sets, restriction, notification state и moderator controls/notes всегда вычисляются private request overlay и глобально не кешируются.
- Create/reply/edit/delete/restore/moderation/reaction/spoiler-state mutation вызывает `CommentCacheInvalidator` after commit. Title/season/episode bump-ят existing `TitleDetail` version по root title; collection bump-ит `Collections`. Target merge bump-ит canonical title after commit. При author rename до 1 000 title identities invalidated scoped; больший fan-out безопасно bump-ит весь `TitleDetail` domain вместо stale embedded names.
- Comment count и reaction totals derived из authoritative DB и обновляются вместе с version bump. Notification read/preference/block/mute/restriction changes не flush-ят public pages: эти состояния туда не входят. Target visibility/collection lifecycle используют собственные existing invalidators; permanent collection delete дополнительно privacy-retires rows.
- Account deletion до удаления reaction rows собирает union authored и reacted-to target identities. После commit он invalidates bounded title/season/episode roots и collection domain; более 1 000 title identities дают один global `TitleDetail` generation bump, а не неполный fan-out или полный cache flush.
- Comment URLs с `discussion_scope`, `discussion_sort`, `comments_page`, `thread` или `comment` не входят в allowlisted public-page dimensions и обходят shared full-response cache. Direct redirect имеет `private, no-store` и `X-Robots-Tag: noindex`; cache failure report-ится, но не отменяет committed comment mutation.

## Cache lifecycle отзывов

- Отдельный review cache не создаётся. Только guest canonical title HTML может содержать published public review DTO/count/totals в existing `TitleDetail` version. Authenticated title, Livewire updates, `/profile/reviews`, `/admin/reviews`, notifications and direct-review redirect bypass shared full-response cache.
- Public payload содержит published non-deleted provider/user text (кроме unrevealed spoilers), public author label, canonical public rating, verified snapshot and derived helpful totals. Current-user vote, permissions, own pending/deleted visibility, block/mute sets, restriction, report eligibility/data, notification state and moderator notes/controls are request overlays and never shared.
- Create/edit/delete/restore/rating/spoiler/moderation/title-merge mutations register `ReviewCacheInvalidator` after commit and bump affected `TitleDetail`; public count changes may also bump Recommendations, while provider moderation/title merge bump existing API version. Vote changes bump only affected title. Preference/read/block/mute/restriction changes do not invalidate public pages because no public payload depends on them. Author/account fan-out above 1 000 title IDs uses one existing global `TitleDetail` version bump instead of thousands of cache writes; this is namespace invalidation, not store flush.
- Review sort/filter/page/highlight query URLs are not allowlisted full-page cache identities and canonicalize/noindex to the title. Direct `/reviews/{id}` is `private, no-store`. Derived DB count/average/totals are recomputed after version bump; no denormalized cache counter or full-store flush exists. Cache failure cannot undo a committed mutation and falls back to authoritative visibility query.
