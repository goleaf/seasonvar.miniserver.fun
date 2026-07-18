# Кеширование и Redis/Memcached

Обновлено: 18.07.2026

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
| Homepage HTML и content index | Tiered sanitized HTML плюс ID/scalar cold snapshot | homepage version + public translation fingerprint; stale + Redis lock; queued HTTP warm | guest public+locale only |
| Homepage metrics | Tiered compact counts | homepage version; warm | public aggregate |
| Catalog/directory HTML | Tiered sanitized HTML | catalog-pages version; recent bounded manifest | guest only; `q`/`title` bypass |
| Genre/country and default catalog facets | Tiered compact rows | catalog-facets version; warm top facets | bypass for authenticated, searches and non-default criteria |
| `/stats` snapshot | Tiered sanitized array | catalog-stats version; long measured TTL; poll reads cache; import invalidates and warms | no source/media URLs or private event context |
| Public API response | CDN/browser validators | API version; database/API Resource remains cold path | only anonymous 200 GET/HEAD; Bearer always private/no-store |
| Sitemap/feed/OpenSearch/LLM docs | CDN/browser validators | sitemap version and deployment schema | public streamed documents |
| Recommendations | database relation; policy reserved | invalidated and rebuilt by importer | no raw signals in public cache |
| Title detail/seasons/episodes/media HTML | Tiered sanitized HTML; database cold path | title-scoped version плюс global generation | CSRF и signed playback URL восстанавливаются на каждый response |
| Search candidate IDs/suggestions | database/FTS; автодополнение шапки хранит compact arrays в `SearchSuggestions` | version bump; exact-short от 1 символа, partial от 2; SHA-256 query dimension | только public данные, raw query отсутствует в ключе |
| Ratings/comments/reviews | database | catalog mutation bumps public domains | user mutations never shared |
| Watchlist/history/preferences/notifications | database or session where already designed | immediate authoritative read | private; not globally cached |
| Import progress/admin counts | operational DB snapshot and queue heartbeat | bounded polling/health | no public cache |
| Navigation/settings | static configuration/layout data | deployment/config cache | no database query in Blade |

## Eloquent AutoCache для фильтров Top 100

`wddyousuf/eloquent-autocache` подключён строго в режиме `opt-in` и не заменяет `TieredCache`, version registry или полностраничный кеш. Trait проекта `CachesCatalogFilterOptions` используют только модели `Country` и `Genre`; `CatalogTitle`, `User`, импортёр, отзывы, комментарии, media/access и любые личные данные в эту границу не входят.

Единственный разрешённый cacheable query для каждой модели выбирает `id`, `name`, `slug`, сортирует по `name`, затем `id`, и ограничен 100 строками. Его вызывает `CatalogTopListFilterOptions` для публичных списков стран и жанров в Top 100. Обычный `Country::query()` или `Genre::query()` без явного `cache()` всегда остаётся cold Eloquent query.

Production использует named store `recomputable-failover` (`redis-domain` → `file`), PHPUnit — изолированный `array`. TTL равен 300 секундам с jitter 10%; model version отделяет Country от Genre. Tags, row cache, stale-while-revalidate, pivot listener и кеширование внутри database transaction отключены. Payload состоит только из массивов и scalar attributes, поэтому сохраняется `cache.serializable_classes=false`.

Записи через Eloquent `Country`/`Genre` автоматически повышают model version после create, update и delete. Прямой будущий `DB::table()`/raw SQL обход этой модели обязан явно вызвать `Country::flushCache()` или `Genre::flushCache()` в том же доменном процессе; массовый store flush для этого запрещён. При rollback query внутри transaction всегда обходит cache, а следующий committed read строит свежий payload.

Операторские команды:

```bash
php artisan autocache:warm --all
php artisan autocache:flush "App\Models\Country"
php artisan autocache:clear
php artisan autocache:stats
```

`warm --all` выполняет только два зарегистрированных bounded query. `flush` сбрасывает версию одной модели; `clear` инвалидирует зарегистрированные AutoCache-модели и не является `Cache::flush()` всего store. Counters выключены по умолчанию и появляются только при `AUTOCACHE_STATS=true`.

Аварийное отключение: задать `AUTOCACHE_ENABLED=false`, выполнить `php artisan config:cache` и graceful reload PHP-FPM/workers. Удалять package до этого не нужно. Даже при ошибке инвалидации старый entry ограничен конечным TTL; wildcard scan, Redis `KEYS`, store-wide flush и превращение `AUTOCACHE_MODE=auto` в rollout-переключатель запрещены.

## Invalidation и warming

`cache:warm-catalog` сохраняет быстрый `critical`-режим по умолчанию и отдельно поддерживает полный безопасный проход по конечному публичному реестру. Перед запуском оператор получает точную агрегатную оценку без HTTP и queue writes; справочники считаются групповыми запросами, а не загрузкой сотен тысяч моделей по одной:

```bash
php artisan cache:warm-catalog --scope=all-public --dry-run
php artisan cache:warm-catalog --scope=all-public --queue
php artisan cache:warm-catalog --scope=all-public --queue --resume
```

`all-public` разрешён только через `cache-warm-v2`; синхронный запуск отклоняется. Потоковый реестр последовательно выдаёт главные и индексные страницы, всю существующую пагинацию каталога и справочников, каждый доступный гостю тайтл, используемые годы/таксономии, индексируемые discovery-варианты, все категории публичных топ-листов, публичные заявки, публичные документы и безопасные parameter-free API snapshots. Keyset cursor и один bounded batch не материализуют весь каталог в PHP или Redis. Ошибка одного same-origin запроса сохраняется только как SHA-256 fingerprint, HTTP status и class исключения, после чего проход продолжается. Внешние redirect не выполняются. Активный импорт временно откладывает пакет; критический прогрев использует ту же очередь и может выполняться между пакетами. Автоматическое десятиминутное расписание остаётся только у `critical`, полный проход запускается оператором после выпуска или крупного импорта.

Реестр является deny-by-default. В него никогда не входят административные и account routes, auth/reset/verification, Livewire transport, health, signed playback, выдача и скачивание видео, персональные рекомендации, случайная выдача, поиск и произвольные фильтры, профили, пользовательские коллекции профиля, приватные/доступные по ссылке данные, ответы с `Authorization`, активным пользователем или transient session state. Явный route contract запрещает `CachePublicPage` на чувствительных маршрутах; новый shared route требует добавления в проверяемый allowlist. Это ограничение важнее полноты прогрева.

Состояние поколения хранится в lock store с bounded retention. `app:health --json` и `/health/ready` показывают отдельный `full_cache_warming` с состоянием `idle`, `running`, `ok`, `degraded` или `failed`, агрегатами `estimated/attempted/warmed/failed` и временными метками, но без URL, cursor и диагностических payload. Свежий `running` не ухудшает readiness; `completed_with_failures`, исключение или устаревший heartbeat после `CACHE_WARM_FULL_STALE_SECONDS` дают degraded/failed operational signal. `--resume` продолжает поколение, остановившееся до исчерпания реестра; завершённый проход с отдельными HTTP-ошибками повторяется новым обычным запуском.

Автодополнение глобального поиска использует `HeaderSearchSuggestionCache` поверх существующего `TieredCache` и домена `SearchSuggestions`. Ресурс один — `header-autocomplete`; dimensions содержат версию формата, allowlisted scope `header_titles|header_portal`, текущую интерфейсную локаль, фиксированную аудиторию `public` и SHA-256 уже нормализованного запроса. `header_titles` хранит не более 5 подготовленных карточек с URL/poster/year/public season/episode counts; `header_portal` — не более 30 публичных людей, справочников, годов, подборок, заявок, профилей и зарегистрированных разделов. Eloquent-модели, описания, private/user-specific data, raw query и FTS internals не сериализуются. Односимвольный запрос может создать только `header_titles` entry для bounded exact-short lookup; portal scope начинается с двух символов. Вкладка браузера дополнительно держит bounded FIFO-cache не более 120 ответов, разделённых по endpoint/scope/locale/query; это не shared source of truth. Отказ Memcached/Redis/locks/version registry обслуживается штатным cold rebuild. Catalog/title/alias/import/admin и public tag изменения bump-ят существующую version boundary; public collection/content-request/profile invalidators также повышают `SearchSuggestions` after commit, потому что эти identities участвуют в portal scope. Private/unlisted mutations не превращают пользовательские данные в shared payload; полный flush, wildcard scan и user ID dimension отсутствуют.

`/search` и `/titles?q=...` не используют shared full-response cache: первая страница содержит произвольный пользовательский query, вторая — Livewire/filter state. Кешируются только компактные public suggestions; authenticated audience в полном/mobile search выполняется напрямую через canonical entitlement query. Изменение локали всегда создаёт другой cache identity, а сбой cache возвращает bounded database/FTS результат, не ошибку интерфейса.

`public.page` кэширует только успешный гостевой `GET` с HTML content type и ограниченным payload/query. Публичная session policy проверяется до и после рендера, поэтому добавленные контроллером flash/private state дают `BYPASS`. В envelope сохраняются body и allowlisted `Content-Type`; cookie/security headers добавляются внешними middleware. Для guest Livewire HTML допускаются только framework cookies `XSRF-TOKEN` и настроенная session cookie; их значения не попадают в envelope, а любой другой response cookie даёт `BYPASS`. Текущий CSRF и валидная локальная `playback.source` signature заменяются постоянными markers, а на HIT/STALE создаются заново для текущей session. Заголовок `X-Seasonvar-Page-Cache` сообщает `HIT`, `STALE`, `MISS` или `BYPASS`, не раскрывая ключи. Манифест хранит только bounded LRU относительных URL уже созданных shared entries; private routes, absolute URLs, `q` и `title` туда не попадают.

Во время rolling migration тегов cold catalog path определяет готовность canonical schema по таблице `tag_translations`, которая создаётся только после добавления canonical-колонок. Результат мемоизируется только в attributes текущего HTTP request: повторные scopes не делают schema queries, но следующий request сразу видит завершённую migration. CLI и long-lived queue jobs не получают долгоживущий статический schema cache.

`CatalogCacheInvalidator` остаётся единственной catalog cache mutation boundary. После commit он нормализует не более 1000 title IDs, bump-ит homepage, catalog-pages, facets, stats, API, sitemap и recommendations. Известные IDs bump-ят scopes `title:{id}`; неизвестный набор bump-ит global title generation. Затем invalidator атомарно объединяет bounded warm intent и dispatch-ит одну job. Queue outage фиксируется low-card metric/log и не превращает уже committed authoritative write в ложный rollback/500. Bulk importer paths вызывают invalidator явно, потому что query-builder upserts не создают Eloquent events.

File-size metadata использует focused метод той же boundary: после material conditional write `titlePlaybackMetadataChanged()` bump-ит только `TitleDetail` scope `title:{id}` и пишет low-cardinality telemetry. Размер не входит в collection/home/sitemap/recommendation/API presentation, поэтому этот путь не выполняет collection membership query, не меняет их generations и не создаёт general warming intent. Guest title variants уже включают scoped version, а authenticated page bypass-ит shared response cache; следующий запрос затронутой карточки читает актуальный размер без global flush.

Homepage snapshot/facets/full-response keys включают validated interface locale вместе с public audience, route, parameters/query и domain version; payload `ru` никогда не обслуживает `en`. Full-response dimension дополнительно содержит SHA-256 fingerprint active/fallback PHP groups, которые реально формируют guest home/layout (`auth`, `catalog`, `collections`, `home`, `recommendations`, `requests`, `tags`). Поэтому code translation edit автоматически выбирает новый exact namespace без store flush; изменение одного locale не требует user ID или arbitrary key. Raw catalogue totals имеют одинаковую семантику, но presentation formatting выполняется после чтения. `CatalogCacheWarmer` последовательно прогревает `ru` и `en` snapshot/facets/metrics, восстанавливает исходный application locale и включает `/ru`/`/en` плюс indexable localized discovery URLs в bounded self-HTTP set. Catalog/domain mutation bump-ит общую Homepage generation и тем самым инвалидирует все locale variants; изменение DB translation проходит тот же domain invalidator. User-specific Continue Watching и account preference никогда не входят в shared homepage payload.

`CACHE_WARMING_ENABLED=false` является общей границей автоматического прогрева: `CatalogCacheInvalidator` не dispatch-ит job после mutations, а десятиминутное scheduler event не становится исполняемым. Ручной `cache:warm-catalog` намеренно остаётся доступен оператору для controlled recovery. Переключение флага не удаляет snapshots, queue rows, failed jobs или cache keys; после изменения environment production config cache и long-lived процессы обновляются штатным deployment workflow.

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

`CacheDomain::Collections` версионирует только public-safe summaries. Create/rename/description/slug/visibility/moderation/feature/cover/item/order/sort/owner-public-name/title-merge mutations после commit bump-ят Collections, Homepage, Sitemap, TitleDetail и API, плюс targeted `collection:{id}` version. Recommendations bump добавляется только когда текущее или предыдущее сохранённое состояние коллекции соответствует public editorial eligibility: стабильный тип `editorial`, visibility `public`, moderation `approved`, feature включён, publication timestamp задан и soft delete отсутствует. Поэтому private/unlisted/unapproved/unfeatured коллекции не выбрасывают общие public recommendation snapshots; переход в eligible-состояние или из него остаётся немедленно видимым, а вызов без достаточного model context консервативно bump-ит Recommendations. Multi-collection membership Apply bump-ит выбранные global domains ровно один раз и затем каждый уникальный collection scope через `changedMany()`. Fulfilled create/edit/delete/restore/moderation/cover/membership/order retry повторяет bounded targeted bump без повторной domain mutation: это repair path на случай, когда DB commit прошёл, а предыдущая after-commit cache operation была недоступна. Membership no-op ограничен owner-scoped набором до 100 collections и существующим rate limit; flush store, wildcard key scan и user-specific membership cache не используются.

Directory/profile/home summaries могут использовать существующий versioned tier, но `/collections/{slug}`, management/editor, private/unlisted metadata, current-user membership, report draft, moderation notes и personal card state отвечают `private, no-store`. Cover delivery также всегда authorization-aware `private, no-store`, включая approved public cover: это намеренно предотвращает browser/shared stale exposure после public→private. Collection API и collection sitemap используют отдельные HTTP-cache profiles с `max-age=0`, `s-maxage=0` и без stale window; они немедленно revalidate и дополнительно зависят от API/Sitemap domain version. Public-to-private поэтому не ждёт старый общий documents/API TTL.

Cache key dimensions public summary включают domain version, collection identity, locale, page, normalized filters/sort и content/moderation versions там, где payload формируется. User ID, private notes, likes/follows/membership draft и signed upload paths никогда не входят в shared payload. Ошибка disposable cache не меняет policy/query truth: cold path повторно проверяет visibility/moderation/deleted state.

`CatalogCacheInvalidator::importedTitleChanged()` дополнительно проверяет canonical item table. Если обновлённый title не входит ни в одну approved public collection, collection cache не трогается; private/unlisted/pending memberships не имеют shared summary. Иначе invalidated только collection-dependent public domains и до 1 000 точных `collection:{id}` scopes. Title page уже получает собственный targeted bump, поэтому этот путь не сбрасывает global `TitleDetail`; превышение bounded fan-out даёт один global collection generation вместо неполной инвалидации.

Внутри этой title-driven boundary Recommendations bump-ится только при наличии среди bounded collection IDs действительно опубликованной approved/public/featured editorial collection. Остальные collection-dependent domains сохраняют прежнее поведение. При fan-out больше 1 000 точная scope-проверка намеренно заменяется консервативным global collection/recommendation bump, чтобы оптимизация никогда не создавала stale editorial выдачу.

Source-sync не создаёт новый cache store и не выполняет store-wide flush. Material reconciliation переиспользует collection after-commit invalidator, а `HdRezkaCollectionSignalSynchronizer` возвращает union тайтлов из добавленных и удалённых `editorial_collection:*` signals. Эти IDs помечаются dirty, после commit одна `ShouldBeUniqueUntilProcessing` job в configured Redis queue выполняет scoped recommendation rebuild под overlap lock; failed activation остаётся retryable и не публикует неполное поколение.

После успешной активации job записывает существующий catalog warm request и запускает `WarmCatalogCaches`; payload/page tiers продолжают использовать настроенные Redis/Memcached stores, а locks/unique state — выделенный lock store. При активном Seasonvar run collection job вообще не забирает dirty IDs: их обрабатывает импортный pipeline, поэтому более новый marker не теряется и преждевременный warm не запускается. Если активная feature-version ещё не совпадает с v6 или scoped limit превышен вне импорта, job оставляет dirty IDs штатному полному import boundary и не запускает отдельный full build. Complete source snapshot удаляет stale membership/signals и инвалидирует затронутые scopes, partial snapshot сохраняет прежние связи и не создаёт destructive cache transition. Public карточка всегда читает локальный WebP через authorization-aware `private, no-store`, поэтому CDN/browser immutable cache для imported covers не допускается.

Критический прогрев подтверждает coalesced intent после завершения bounded прохода, даже если отдельный HTTP target вернул ошибку, общий 240-секундный HTTP budget исчерпан или rebuild lock уже занят другим запросом. Успешные Redis/Memcached targets при этом не теряются и не пересобираются retry-штормом; lock contention записывается как safe skip, HTTP-ошибка — только fingerprint/status/class, а warming health становится `degraded` с bounded счётчиком failed/skipped. Job и отдельный worker согласованы на 600 s при Redis `retry_after=1200`; fatal data/cache infrastructure exception по-прежнему завершает job ошибкой и использует обычные три attempts. Request-time cold/stale path остаётся источником истины для пропущенной страницы; store-wide flush и автоматический retry failed history не выполняются.

## Cache lifecycle обсуждений

- Второй comment cache не создаётся. Guest SSR первой public page может входить только в существующий versioned target HTML cache; authenticated requests, Livewire updates, direct-comment redirects, profile/inbox/admin и ответы с cookie/auth/error обходят shared cache.
- Guest DTO содержит только published public body/excerpt, author public name, derived public reply/reaction totals и stable anchors. Current-user reaction, own pending/hidden/rejected/spam rows/replies, edit/delete/report permission, block/mute sets, restriction, notification state и moderator controls/notes всегда вычисляются private request overlay и глобально не кешируются.
- Create/reply/edit/delete/restore/moderation/reaction/spoiler-state mutation вызывает `CommentCacheInvalidator` after commit. Title/season/episode bump-ят existing `TitleDetail` version по root title; collection bump-ит `Collections`. Target merge bump-ит canonical title after commit. При author rename до 1 000 title identities invalidated scoped; больший fan-out безопасно bump-ит весь `TitleDetail` domain вместо stale embedded names.
- Recommendations generation меняется только когда комментарий с canonical target type `title` появляется в опубликованном non-deleted состоянии или исчезает из него. Create передаёт реальный initial status; update/delete/restore/moderation сравнивают состояние до и после. Reaction, body/spoiler-only edit, private moderation metadata, author identity/account anonymization и season/episode/collection discussion сохраняют presentation invalidation, но не выбрасывают shared recommendation candidates. Title merge и unknown callers остаются консервативными.
- Comment count и reaction totals derived из authoritative DB и обновляются вместе с version bump. Notification read/preference/block/mute/restriction changes не flush-ят public pages: эти состояния туда не входят. Target visibility/collection lifecycle используют собственные existing invalidators; permanent collection delete дополнительно privacy-retires rows.
- Account deletion до удаления reaction rows собирает union authored и reacted-to target identities. После commit он invalidates bounded title/season/episode roots и collection domain; более 1 000 title identities дают один global `TitleDetail` generation bump, а не неполный fan-out или полный cache flush.
- Comment URLs с `discussion_scope`, `discussion_sort`, `comments_page`, `thread` или `comment` не входят в allowlisted public-page dimensions и обходят shared full-response cache. Direct redirect имеет `private, no-store` и `X-Robots-Tag: noindex`; cache failure report-ится, но не отменяет committed comment mutation.
- Public-profile comments не вводят второй cache namespace или cached viewer overlay. `CommentProfileQuery` вычисляет опубликованные catalog-rooted rows и matching count из authoritative SQL с viewer-visible title subquery, а `CommentPresenter` формирует public-safe DTO; section privacy остаётся в profile domain. Spoiler body, current reaction, block/mute state, permissions и moderation data в эту проекцию и shared cache не попадают.

## Cache lifecycle отзывов

- Отдельный review cache не создаётся. Guest canonical title HTML содержит только lazy placeholder review-компонента в existing `TitleDetail` shell; published rows/count/totals загружаются отдельным `X-Livewire` response, который `PublicPageCachePolicy` всегда исключает. Authenticated title, `/profile/reviews`, `/admin/reviews`, notifications and direct-review redirect также bypass shared full-response cache. Public-profile review section использует тот же public predicate/presenter и только profile-scoped public version; viewer vote, permissions, moderation evidence и full spoiler text в её DTO отсутствуют.
- Livewire public presentation содержит только published non-deleted provider/user text (кроме unrevealed spoilers), public author label, canonical public rating, verified snapshot and derived helpful totals. Current-user vote, permissions, own pending/deleted visibility, block/mute sets, restriction, report eligibility/data, notification state and moderator notes/controls are request overlays in that private response and never become a shared payload.
- Create/edit/delete/restore/rating/spoiler/moderation/title-merge mutations register `ReviewCacheInvalidator` after commit and bump affected `TitleDetail`; public count changes may also bump Recommendations, while provider moderation/title merge bump existing API version. Vote changes bump only affected title. Preference/read/block/mute/restriction changes do not invalidate public pages because no public payload depends on them. Author/account fan-out above 1 000 title IDs uses one existing global `TitleDetail` version bump instead of thousands of cache writes; this is namespace invalidation, not store flush.
- Review sort/filter/page/highlight query URLs are not allowlisted full-page cache identities and canonicalize/noindex to the title. Direct `/reviews/{id}` is `private, no-store`. Derived DB count/average/totals are recomputed after version bump; no denormalized cache counter or full-store flush exists. Cache failure cannot undo a committed mutation and falls back to authoritative visibility query.

## Cache lifecycle тегов

- `TagSnapshotCache` переиспользует существующий `TieredCache` и `CacheDomain::Tags`; второго cache store/tagging mechanism нет. Public popular/related snapshots содержат только compact canonical summaries и dimensioned resource, stable tag UUID, interface locale, fallback locale, bounded limit и `audience=public`. Eloquent graphs, provider URLs/mappings и moderator data не сериализуются.
- `TagCacheInvalidator::publicChanged()` после commit делегирует единственной `CatalogCacheInvalidator`, которая bump-ит `Tags`, catalog pages/facets/stats, API, sitemap, recommendations, search suggestions и affected `TitleDetail` scopes. Create/rename/translation/alias/synonym/slug/visibility/moderation/archive/restore/merge/provider mapping/global assignment/import convergence проходят эту boundary; full application/store flush и wildcard deletion отсутствуют.
- Personal tags не помещаются в `TagSnapshotCache` или guest full-response cache. Owner mutation bump-ит только `Tags` scope `user:{internal_id}` after commit; web/API personal pages отвечают через authenticated request и `private, no-store`. Public keys/dimensions никогда не содержат personal UUID, label, assignment/count, selected set или owner controls.
- Public page cache применяет обычные guest/session/auth bypass rules. Eligible tag page зависит от общего catalog generation и перестраивается из public visibility query; alias/history URL — 301, private/unapproved/empty — 404, поэтому для них не создаётся самостоятельный HTML cache identity. Filter/search state подчиняется общему catalog allowlist и SEO policy.
- Visibility/moderation change bump-ит namespace немедленно after commit, поэтому скрытый tag исчезает из metadata/count/search/related/popular/API/sitemap variants. Cache/store failure не откатывает authoritative DB mutation: request использует cold visibility query, telemetry фиксирует failure, а queue warm остаётся best-effort существующей инфраструктурой.
- Rolling deploy использует `TagSchema`: если `TAG_CANONICAL_SCHEMA` не задан, capability определяется полным набором canonical columns/tables один раз на scoped container lifetime. До миграции legacy `tags/catalog_title_tag` reads продолжают работать; explicit boolean override предназначен только для управляемого rollback/diagnostics, а не для сокрытия незавершённой schema.

## Cache lifecycle аутентификации

- Guard user, session/remember state, verification, password/reset token, intended redirect, limiter secret input, OAuth-ready state, audit payload и anonymous preference merge никогда не попадают в shared response/data cache. Guest auth pages могут быть SSR, но page metadata noindex и forms/CSRF/session responses bypass shared full-page storage.
- Named authentication limiters use existing RateLimiter store with HMAC email/network/scope fingerprints; raw email, IP, user ID, password, provider token and session ID are absent from keys. Limiter outage does not create an allow decision inside credential/session/domain services.
- Login/logout/verification/password/email/device/session/deletion actions do not flush the application cache. Existing owner/domain invalidators remain authoritative; auth/session state is read from guard/database on each boundary. Account settings locale adoption changes only an unset owner row through its existing targeted lifecycle.
- `config/authentication.php` owns registration availability; `config/logging.php` owns the bounded authentication channel. Rolling deployment must rebuild config and route caches together, because disabled registration changes both web/API route registration. PHP-FPM/workers then require graceful reload; stale config is not repaired with global data-cache flush.

## Cache lifecycle профилей пользователей

- Public profile page/DTO и viewer-specific block/mute/owner controls намеренно не имеют второго shared cache: это исключает stale privacy и cross-user overlay leakage. Reads используют bounded indexed queries and eager relations.
- `content_version`, avatar/cover version и `UserProfileCacheInvalidator` дают exact user/version/locale key shape для будущих public summaries. Profile/detail/privacy/identity/media/moderation/delete changes bump only affected profile versions; application-wide flush запрещён.
- Private media URLs contain stable public user UUID, allowlisted kind and non-secret version, но response остаётся `private, no-store`. Email, private values, session IDs, report details/notes, raw disk paths and block/mute state никогда не входят в key/value.
- Public→private/moderated transition immediately changes policy/query/sitemap output; direct queries are the source of truth, so stale cached HTML cannot survive. Sitemap profile response remains streamed and privacy-filtered.

## Cache lifecycle настроек аккаунта

- Settings page HTML, account email, preferences, notification matrix, sessions, export/delete state и authenticated player overlays никогда не входят в shared response/data cache. `PrivateAccountResponse` запрещает browser/CDN reuse, а full-response middleware обходит authenticated traffic.
- `AccountSettingsService` читает одну owner row напрямую и memoizes её только загруженной relation в пределах request/component. Отдельный settings cache не создан: для one-row query он добавил бы invalidation/leakage risk без измеримой пользы.
- `settings_version` и versioned key `seasonvar.account-preferences.v1` разрешают device state без email/user ID в key. Имя browser key не выводится в гостевой public HTML, но остаётся неизменным в JS для совместимости с уже сохранённым state. Opaque HMAC owner scope изолирует accounts в одном browser; account version не позволяет старому local state бессрочно перекрывать новый server choice.
- Mutations обновляют только current-user row/notification preference и relation state. Profile, notification, collection, player, export/delete и session services сохраняют существующие targeted boundaries; settings не вызывает global flush и не прогревает private pages.
- Rolling schema capability request/job-scoped. До migration reads возвращают defaults, writes fail closed; после deploy PHP-FPM/long-running workers graceful-reload-ятся, чтобы новый process увидел schema.

## Cache lifecycle заявок на материалы

Public request directory/detail используют существующий full-response cache profile `requests` и `CacheDomain::ContentRequests`. Dimensions включают route, locale, allowlisted `type|status|sort|page` query и version; `q`, unknown/oversized values, authenticated requests, Authorization, Livewire, flash/error state и non-GET всегда bypass. Detail получает отдельный `request:{public_id}` scope и global domain version.

TTL policy определена в существующем `config/cache-architecture.php`: 120 секунд fresh, 900 stale, 60 hot, 15 negative и 60-second lock с 250 ms bounded wait. Новый cache-domain и его policy должны попадать в один config-cache rebuild; отсутствие policy считается deployment error, а не поводом silently применить чужой domain TTL.

Public card DTO содержит только public target, status, dates и grouped vote/follower counts. `hasVoted`, `isFollowing`, owner/can-* permissions, hidden source links, clarifications, private notes, notification preferences, rate state и importer details вычисляются только в authenticated overlay и никогда не входят в guest cache. Create/edit/vote/follow/status/clarification/withdraw/merge/completion after commit bump domain и affected detail; public eligibility changes additionally bump existing Sitemap domain. Safe merge наследует public eligibility source record и bump-ит оба UUID, поэтому cached legacy detail не переживает canonical redirect; private cross-requester merge отсутствует. Title/season/episode merge bump-ит affected request scopes. Global cache flush, key scan и второй request cache отсутствуют.

## Cache lifecycle рекомендаций

`CatalogRecommendationCache` переиспользует `TieredCache`, `CacheDomain::Recommendations`, существующие TTL/version/telemetry и хранит только bounded scalar candidate arrays. Public dimensions: stable type, interface locale, `audience=public`, trending period, one rating source, normalized filter hash, current/exclusion hash только когда контекст требует, и ranking version `task18-v6-r2`. Версия `v6-r2` наследует ужесточённую семантику trending/upcoming и отдельно изолирует `recently_added`, который теперь использует стабильный `catalog_titles.created_at`, от старого `indexed_at`-порядка без scan, flush или удаления чужих namespace. Page/per-page применяются после общего pool и не размножают keys. Full models, media URLs и translations graph не кэшируются.

Authenticated requests, включая optional Sanctum recommendation API, `personalized`, `random` и любой server-seeded refresh/repeat-aware context всегда bypass shared result. Seed и recently shown IDs не становятся shared dimension или value: стабильные unseeded public SSR/API requests сохраняют общий cache path, а refresh выполняет тот же bounded authoritative query. Initial Livewire mount передаёт `null` для deterministic public types; только `random`/`personalized` получают opaque seed сразу, а обычная public page становится seeded после явного refresh. Feedback, blacklist, history, progress, watchlist, statuses, private tags/collections, premium/region context и private explanations не являются global value/dimension. Recent-display suppression живёт bounded в current session (`96` IDs, `7` дней) с отдельным guest/user scope; authenticated homepage запоминает только реально выведенные восемь IDs, anonymous homepage остаётся unseeded и cacheable. Personalized cold-start сохраняет watching/completed/dropped exclusions при public fallback и при нехватке кандидатов ослабляет только recent-session set.

Public catalog/editorial/rating/comment/review/rebuild/title-merge mutations bump existing recommendation version after commit. Progress bump происходит только при первом meaningful threshold/completion, не на heartbeat; watchlist и rating используют canonical state invalidators. Cache failure не откатывает mutation и выполняет bounded authoritative query. Full-store flush, wildcard key scan, второй cache store или mandatory warming queue не добавлены.

Existing optional `PublicPageCacheWarmer` additionally resolves exactly the five stable default indexable discovery URLs from `CatalogRecommendationType`: `trending`, `popular`, `top_rated`, `recently_added`, `recently_updated`. They reuse the same same-origin HTTP timeout/retry, bounded URL manifest, sanitized guest page cache and scalar recommendation snapshots. Personalized/authenticated, random, editorial, upcoming, localized, filtered, paginated and private-feedback state is not proactively warmed. Queue/cache outage still falls back to the bounded authoritative discovery query; no second cache domain, queue or scheduler was added.

## Cache lifecycle file-size и downloads

Изменение persisted file-size metadata вызывает только существующий `CatalogCacheInvalidator::importedTitleChanged(catalog_title_id)`, чтобы следующий title/player render увидел size label. Sync importer, queued apply, external playlist, scheduled/manual size-only backfill и download-time trusted size correction используют ту же boundary; size-only command не добавляет global catalog bump, full application flush/key scan отсутствуют. Conditional source guard отбрасывает запоздалый ответ до invalidation, а freshness решения инспектора читают timestamp/status из БД, а не shared authorization cache.

Download route всегда возвращает `Cache-Control: private, no-store, max-age=0`, `Pragma: no-cache` и не участвует в public full-response profiles. Video body, PSR-7 chunks, Content-Range и user authorization никогда не записываются в Redis, Memcached, session, DB blob или Laravel cache. Малое size metadata отображается из title query; cached authorization не может пережить publication/audience/health change.

## Cache lifecycle технических обращений

Private ticket/list/detail/messages/attachments/diagnostics/internal notes/assignment/viewer confirmation/follow state никогда не попадают в global response/data cache. Current state и counts читаются viewer-scoped из DB; framework translation/config cache может хранить только static registry labels/rules. Ticket mutations не вызывают global flush. Только реальный authorized source-health change передаёт affected title ID в существующий `CatalogCacheInvalidator::catalogChanged()`: он обновляет зависимые public catalog generations и scoped TitleDetail без store flush/key scan. Создание, confirm/follow/message/status сами public catalog cache не меняют. Rolling deploy до миграции защищён `TechnicalIssueSchema`. Полный contract: [`technical-issues.md`](technical-issues.md).

## Cache lifecycle календаря релизов

`CacheDomain::ReleaseCalendar` версионирует только public schedule data/response. Public profile ограничивает `type`, `status`, `sort`, stable title ID и page; locale и canonical public timezone разделены. Произвольный пользовательский timezone группируется request-side и не размножает shared keys. Personal calendar, subscription, notification preferences/read state, library, premium/region context никогда не кэшируются глобально. Material schedule mutation after commit повышает календарь, homepage, sitemap и affected title generation без flush или wildcard scan. Полный key/invalidation contract — в [`release-calendar.md`](release-calendar.md).

## Browser cache и service-worker boundary Task 23

В текущем продукте нет service worker или Cache Storage namespace: browser получает hashed Vite assets по обычной HTTP static policy, а HTML/API продолжают использовать server response/cache contracts этого документа. Task 23 не создаёт application shell cache, offline HTML, authenticated page cache, IndexedDB data cache или user-agent/device cache variant.

Потенциальная будущая allowlist ограничена immutable hashed CSS/JS, локальными public fonts/icons, public placeholder и отдельной public noindex offline-help page. Denylist без исключений: authenticated HTML, owner profile/settings/security, premium/checkout/invoice/provider callback, tickets/attachments, personal library/history/progress/recommendations/calendar, notification/push state, protected media/grants/downloads и любые response с `private`, `no-store`, `Authorization`, signed/query credentials или non-GET method. Навигационный timeout нельзя автоматически называть offline или сохранять как fallback.

Asset deployment полагается на новый content hash; отсутствие worker исключает stale-worker trap. Изменение mobile navigation/translations/player/CSS требует обычного Vite build и deployment manifest, но не global server-cache flush. Mobile API sync responses остаются explicit public/private HTTP-cache contracts и не превращаются в browser video/offline cache.

## Cache lifecycle Premium

Premium не создаёт shared user cache: `PremiumAccessResolver` memoizes summary только внутри request и проверяет database UTC boundaries при следующем request. Payment/refund/dispute history, provider customer/subscription identity, checkout, coupon redemption и event payload глобально не кэшируются. Grant/revoke/payment/refund/chargeback/promotion path сбрасывает request memo, поэтому запись не остаётся активной за `ends_at`.

Public plans пока не кэшируются, а provider/currency registry пуст. Если измерения потребуют cache, key обязан включить locale, trusted region, allowlisted currency и plan/promotion/provider/feature versions; user key — opaque user ID/version/region с TTL не длиннее ближайшего expiry/grace. Полный flush, email/provider ID/token в key и public/private payload mixing запрещены. Полный contract — [`premium.md`](premium.md).

## Cache lifecycle центра помощи

`CacheDomain::HelpCenter` хранит только guest categories/featured/popular/contextual snapshots и sanitized published content. Key dimensions включают locale/fallback/route locale, stable article UUID, audience class, content/translation/presentation version. Arbitrary search query, current-user feedback, report, ticket context, preview/draft/staff/internal note не кэшируются глобально.

Mutation после commit повышает HelpCenter/SearchSuggestions, scoped article UUID и при public изменении Sitemap; feedback не трогает sitemap. Full flush, wildcard scan и user/search secret в key запрещены. Cache failure не блокирует reading. Полный контракт: [`help-center.md`](help-center.md).

## Cache lifecycle playback Task 07

Playback использует существующие versioned title/catalog/API/sitemap domains и guest HTML signed-link transformer; отдельный store/key/domain не создан. Public episode metadata/source summaries без private URL могут жить только в существующем title scope. Signed grants, raw providers, user progress, playback session, account/device preferences, failed-source IDs, audience/Premium/region/age decision и private playback context никогда не shared-cache-ятся.

Source publication/health/profile/import/admin mutation сохраняет targeted invalidation. Progress/restart публикуют owner sync и только material recommendation signal; global flush не выполняется. TTL кеша никогда не продлевает grant TTL. Полный key/privacy/invalidation contract: [`audits/video-playback-report.md`](audits/video-playback-report.md).

## Cache lifecycle личной библиотеки Task 09

Library HTML, counters, bookmarks, statuses, feedback, markers, progress, acknowledgments, private collection membership и filters всегда читаются owner-scoped и `private, no-store`; отдельный cache store/domain и глобальный user-state cache не добавлены. Public title/card metadata может переиспользовать существующие catalog versions, но owner overlay накладывается после shared boundary и никогда не записывается в public payload.

Bookmark/status/feedback/marker/acknowledgment/progress mutations используют существующие targeted title/user/recommendation/sync invalidators либо читаются непосредственно на следующем private request. Collection mutations остаются в collection cache lifecycle; смена public → private немедленно инвалидирует public collection scopes. Global flush, wildcard scan, email в key, shared signed URL и смешивание private/public collection payload запрещены.
