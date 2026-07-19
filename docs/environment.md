# Переменные окружения

Обновлено: 18.07.2026

Полный безопасный шаблон находится в `.env.example`. Реальный `.env` не изменяется deployment-кодом и не коммитится.

## Verified production snapshot 18.07.2026

- Rocky Linux `10.2`, nginx `1.31.2`, PHP CLI/FPM `8.5.8`, Composer `2.10.2`, Node `26.4.0`, npm `12.0.1`, SQLite `3.46.1`.
- Installed application: Laravel `13.20.0`, Livewire `4.3.3`, Tailwind CSS `4.3.2`, Vite `8.1.4`; Composer project constraint remains PHP `^8.3`, while this host runs PHP 8.5.
- Runtime state is `APP_ENV=production`, `APP_DEBUG=false`, HTTPS `APP_URL`, configured `APP_KEY`, SQLite database, Redis cache/session/queue/locks, local private uploads and Laravel daily warning logs. Значения и private paths не выводились.
- Redis реально reachable, но владельца процесса нельзя выводить из отсутствующего `redis.service`: restart выполняется только через verified panel/system owner. Memcached extension/config есть, однако listener/service не обнаружен и health state — `unavailable`; correctness использует documented fallback.
- Mail использует `log`, active payment/OAuth/object-storage/monitoring providers не подтверждены. Google reporting и HDRezka sync выключены. Service worker не установлен.
- aaPanel/nginx/PHP-FPM и systemd/cron реально используются. Активный queued import profile: 4 import workers, 8 title-refresh workers, 1 cache-warm worker; scheduler, queued dispatcher и queue monitor запускаются cron. Взаимоисключающий `seasonvar-import-forever.service` отключён.

Эта проверка не подтверждает zero downtime, automatic failover, off-host backup, external alert delivery или full restore. Операционные ограничения и runbook’и: [`operations/README.md`](operations/README.md).

Canonical runtime compatibility states находятся в [`maintenance/runtime-compatibility.md`](maintenance/runtime-compatibility.md). Node 26 остаётся Current, поэтому future LTS migration требует отдельного decision и locked build verification. Composer self-update public keys устанавливаются на build host по официальной процедуре и не являются application `.env` secrets.

## Environment-variable inventory policy

`.env.example` — канонический безопасный inventory: имя и placeholder группируются по app/auth/log/database/session/queue/cache/Redis/Memcached/mail/storage/provider/import/playback/security/frontend subsystem. Required production keys — `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`, database, session/cache/queue, logging и configured provider keys. Optional keys имеют safe default/disabled behavior. `APP_VERSION` и `APP_BUILD_ID` задают public-safe release identity; `PROJECT_DOCS_PUBLIC_BASE_URL` — public docs canonical origin; `SEASONVAR_HTTP_USER_AGENT` — provider identity без hardcoded production hostname.

Любая переменная имеет sensitivity по subsystem: key/password/token/credential values secret; host/path/database/provider identifiers operational-private; version, bounded limits и feature switches non-secret. Изменение server-side `.env` требует `config:cache` и graceful PHP-FPM/worker refresh, если ключ читается Laravel config. Единственная client-exposed переменная `VITE_APP_NAME` содержит только publishable display name.

`SEASONVAR_INTEGRATION_HOME` — необязательный operator-only путь для CLI-команды `integrations:doctor`, если процесс не наследует корректный home directory. Значение не показывается в web/admin UI и не должно указывать на shared public directory; при отсутствии override конфигурация использует home процесса, в котором строится config cache.

## Production baseline

```dotenv
APP_ENV=production
APP_DEBUG=false
SESSION_DRIVER=redis
SESSION_CONNECTION=sessions
QUEUE_CONNECTION=redis
CACHE_STORE=redis-domain
CACHE_HOT_STORE=memcached-hot
CACHE_DOMAIN_STORE=redis-domain
CACHE_LOCK_STORE=redis-locks
CACHE_VERSION_STORE=redis-locks
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=14
```

Production также обязан включить HTTPS-only session cookie, корректные domain/path/SameSite, секретный `APP_KEY`, warning-or-higher structured log policy и реальные credentials через secret manager/process environment. Эти значения не должны попадать в Git.

## PHP extensions

`composer check-platform-reqs --no-dev` на текущем runtime подтвердил PHP `8.5.8` и mandatory Composer platform extensions `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `json`, `libxml`, `openssl`, `pcre`, `session`, `tokenizer`; `ctype`/`mbstring` также реально загружены, хотя dependencies умеют polyfill. Для фактической архитектуры дополнительно required: `pdo_sqlite`/`sqlite3` (production database), `redis` (sessions/queues/locks/cache), `curl` (outbound provider HTTP), `intl` (locale dates/numbers), `fileinfo` (uploads), `openssl`/`sodium` (crypto) и `opcache` для FPM. `gd` и `imagick` поддерживают current raster upload/image flows. `memcached` client загружен, но server optional/unavailable; `pdo_mysql` — optional driver evidence, не production-engine proof; `zip` нужен для approved archive workflows. FFmpeg/transcoding не требуется и не подтверждено.

`RELEASE_CALENDAR_TIMEZONE=UTC` задаёт валидный IANA timezone публичного календаря для гостя. Вошедший пользователь использует собственный timezone из настроек аккаунта; произвольный request parameter не переопределяет эту границу. Изменение значения требует `config:cache` и graceful reload web workers, но не миграции или нового scheduler.

## Аутентификация

`AUTH_REGISTRATION_ENABLED=true` регистрирует web/API create-account routes; `false` убирает только регистрацию и links, сохраняя login/recovery/verification существующих accounts. `AUTH_AUDIT_DAYS=30` задаёт bounded retention отдельного `authentication` daily channel. Оба значения несекретные, но изменение требует совместной пересборки `config:cache` и `route:cache`, затем graceful reload web workers.

Production задаёт `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, `SESSION_SAME_SITE=lax`, узкие domain/path и HTTPS `APP_URL`. `SameSite=None` не используется без отдельной cross-site OAuth необходимости и обязательного Secure. `APP_KEY` подписывает/шифрует framework state и HMAC fingerprints, поэтому его не раскрывают и не меняют без отдельного key-rotation/session invalidation плана.

Versioned-профиль `deploy/logrotate/seasonvar` ежедневно ротирует все `storage/logs/*.log`, хранит 14 архивов и сжимает их. Установить его можно командой `sudo install -m 0644 deploy/logrotate/seasonvar /etc/logrotate.d/seasonvar`; проверка без ротации выполняется через `sudo logrotate --debug /etc/logrotate.d/seasonvar`. Профиль использует `copytruncate`, поэтому PHP-FPM и постоянный импортёр продолжают писать без остановки.

## Redis workloads

Shared defaults задают `REDIS_URL` либо `REDIS_HOST`, `REDIS_PORT`, `REDIS_USERNAME`, `REDIS_PASSWORD`. Workload-specific overrides используют префиксы `REDIS_CACHE_*`, `REDIS_QUEUE_*`, `REDIS_SESSION_*`, `REDIS_LOCK_*`, `REDIS_BROADCAST_*` и поддерживают `URL/HOST/PORT/USERNAME/PASSWORD/PREFIX/CLIENT_NAME/TIMEOUT/READ_TIMEOUT/RETRY_INTERVAL/MAX_RETRIES/BACKOFF_ALGORITHM/BACKOFF_BASE/BACKOFF_CAP/PERSISTENT/PERSISTENT_ID/TCP_KEEPALIVE`.

Standalone default DBs: cache 1, queues 2, sessions 3, locks 5, broadcasting 6. При managed Redis/Cluster используйте отдельные endpoints и prefixes; DB numbers не считаются HA boundary.

`SEASONVAR_TITLE_REFRESH_FRESH_MINUTES` задаёт successful-only окно targeted refresh (по умолчанию 15 минут), а `SEASONVAR_TITLE_REFRESH_QUEUE` — отдельную приоритетную очередь `seasonvar-title-refresh`. `SEASONVAR_TITLE_REFRESH_FINALIZER_DELAY_SECONDS` задаёт короткую задержку повторной проверки незавершённой группы; `SEASONVAR_TITLE_REFRESH_STATE_TTL_SECONDS`, `SEASONVAR_TITLE_REFRESH_ACTIVE_SECONDS` и `SEASONVAR_TITLE_REFRESH_DISPATCH_LOCK_SECONDS` ограничивают operational state, stale-active recovery и atomic dispatch lock. `SEASONVAR_IMPORT_PREPARED_RETENTION_DAYS` управляет bounded очисткой старых terminal groups вместе с подготовленными payload. Connection и critical lock store остаются общими `SEASONVAR_QUEUE_*` настройками; лимита числа страниц или конкурентности в application config намеренно нет.

## Memcached и application cache

`MEMCACHED_HOST[_2|_3]`, ports и weights описывают hot pool. `MEMCACHED_HOT_PREFIX` обязан различать application/environment. Timeouts, failed-server removal, binary protocol и consistent distribution задаются отдельными переменными из `.env.example`; eviction допустим и не должен нарушать корректность.

`CACHE_APPLICATION`, `CACHE_ENVIRONMENT`, `CACHE_SCHEMA_VERSION`, `CACHE_FORMAT_VERSION`, payload/dimension limits, metrics retention и warming queue policy принадлежат application layer. `PUBLIC_PAGE_CACHE_*` ограничивают compressed/uncompressed guest HTML payload, query, LRU manifest, exact-origin self-warm URL, title/URL batch, общий HTTP budget, connect/total timeout и retry; `PUBLIC_PAGE_CACHE_WARM_BASE_URL` обязан совпадать с origin `APP_URL`. `CACHE_WARM_REQUEST_*` ограничивают coalesced pending state и claim. `CACHE_USER_PORTAL_WARMING_ENABLED` включает только post-commit постановку owner-scoped прогрева в общую `CACHE_WARM_QUEUE`; отключение сохраняет authoritative database cold path. `CACHE_VISIBLE_TITLE_WARM_ENABLED`, `CACHE_VISIBLE_TITLE_WARM_MAX_TITLES`, `CACHE_VISIBLE_TITLE_WARM_IMPORT_PAUSE_SECONDS`, `CACHE_VISIBLE_TITLE_WARM_UNAVAILABLE_PAUSE_SECONDS` и `CACHE_VISIBLE_TITLE_WARM_UNIQUE_SECONDS` управляют только bounded fan-out видимых карточек `/titles`; hard cap приложения остаётся 96 независимо от environment. Любое несовместимое изменение cache payload требует format bump, изменение семантики ключей — schema bump.

`RUN_CACHE_INFRASTRUCTURE_TESTS=false` — только test/CI switch. Его включают в изолированном тестовом окружении с run-specific Redis/Memcached prefixes; это не runtime feature flag production-приложения.

## Laravel Debugbar

`fruitcake/laravel-debugbar 4.4.0` является только development dependency. `config/debugbar.php` напрямую связывает `enabled` с уже существующим `APP_DEBUG` и запрещает `force_allow_enable`; отдельные `DEBUGBAR_*` variables отсутствуют в canonical inventory. В `local` при `APP_DEBUG=true` package регистрирует diagnostic routes/listeners и внедряет панель в подходящие HTML responses. При `APP_DEBUG=false`, а также в `production` и `testing`, package guard завершает boot до routes/listeners.

Production baseline остаётся `APP_ENV=production`, `APP_DEBUG=false` и locked `composer install --no-dev`, поэтому Debugbar не входит в runtime artifact. Database, Redis/Memcached, sessions, queues, storage, scheduler, service worker и Vite manifest этой dependency не меняются. После config change нужен обычный `config:cache`, согласованный `route:cache` и graceful process refresh; store-wide cache flush не применяется. В local среде route cache, созданный при выключенном Debugbar, нужно удалить или пересобрать после включения, иначе условно регистрируемые `_debugbar/*` routes в нём отсутствуют.

## Eloquent AutoCache

AutoCache обслуживает только явно кэшируемые публичные списки стран и жанров в фильтрах Top 100. Defaults принадлежат `config/autocache.php`:

| Переменная | Значение по умолчанию | Назначение |
| --- | --- | --- |
| `AUTOCACHE_ENABLED` | `true` | Общий kill switch этой узкой границы |
| `AUTOCACHE_STORE` | `recomputable-failover` | Production store Redis → file; PHPUnit принудительно использует `array` |
| `AUTOCACHE_TTL` | `300` | Конечный TTL query payload в секундах |
| `AUTOCACHE_TTL_JITTER` | `0.1` | Разброс TTL 10% против одновременного истечения |
| `AUTOCACHE_PREFIX` | `{APP_NAME}:{APP_ENV}:eloquent-autocache:v1` | Отдельный versioned namespace; имя приложения нормализуется через `Str::slug()` |
| `AUTOCACHE_USE_TAGS` | `false` | Version invalidation без store-specific tags |
| `AUTOCACHE_LOCK_FOR` | `5` | Bounded single-flight lock в секундах |
| `AUTOCACHE_MODE` | `opt-in` | Кешируются только запросы с явным `cache()` |
| `AUTOCACHE_ROW_CACHE` | `false` | Отдельный row cache выключен |
| `AUTOCACHE_CACHE_IN_TRANSACTIONS` | `false` | Reads внутри transaction всегда обходят cache |
| `AUTOCACHE_SWR` | `0` | Stale-while-revalidate выключен |
| `AUTOCACHE_MAX_ROWS` | `100` | Верхняя граница кэшируемого результата |
| `AUTOCACHE_STATS` | `false` | Низкокардинальные counters выключены до диагностики |

`AUTOCACHE_MODE=auto` не является операторским флагом поэтапного включения: он расширил бы scope на все запросы двух моделей и запрещён текущим контрактом. После изменения любой переменной выполнить `php artisan config:cache` и graceful reload PHP-FPM/workers. Для emergency rollback сначала используется `AUTOCACHE_ENABLED=false`; обычные Eloquent reads продолжают работать без package cache.

## Рекомендации каталога

`SEASONVAR_RECOMMENDATION_CHUNK_SIZE`, `MIN_SCORE`, `MAX_PER_TITLE`, `CANDIDATE_LIMIT` и `CANDIDATE_SCAN_PER_FEATURE` ограничивают локальную catalog-wide пересборку. `SEASONVAR_RECOMMENDATION_DIVERSITY_PENALTY` задаёт bounded MMR-штраф за повтор одинаковых тем и связей; default `120` не может изменить первый, самый релевантный результат. Эти параметры не включают HTTP и не меняют единственную публичную команду импорта.

## Редакционные подборки

`HDREZKA_COLLECTION_SYNC_ENABLED=false` является production kill switch отдельной синхронизации подборок. `SYNC_SCHEDULE`, `SYNC_DELAY_SECONDS`, `SYNC_MAX_RESPONSE_BYTES`, `SYNC_MAX_COLLECTIONS`, `SYNC_MAX_PAGES`, `SYNC_MAX_ITEMS`, `SYNC_LOCK_STORE` и `SYNC_LOCK_SECONDS` с тем же префиксом ограничивают расписание, сеть, объём обхода и single-flight. Они не расширяют точный HTTPS/host/path allowlist из versioned config.

`HDREZKA_COLLECTION_RECOMMENDATION_REBUILD_ENABLED`, `..._QUEUE_CONNECTION`, `..._QUEUE`, `..._TIMEOUT` и `..._UNIQUE_SECONDS` управляют только after-sync scoped recommendation job; defaults переиспользуют Redis и `seasonvar-import`. `HDREZKA_COLLECTION_COVER_MAX_SOURCE_BYTES`, `..._MAX_SOURCE_DIMENSION`, `..._MAX_SOURCE_PIXELS`, `..._MAX_WIDTH`, `..._MAX_HEIGHT` и `..._WEBP_QUALITY` ограничивают декодирование и локальный WebP. Полный безопасный набор/defaults находится в `.env.example`; изменение требует `config:cache` и graceful reload PHP-FPM/scheduler/workers.

`UPLOADS_RUNTIME_GROUP=www` задаёт общую Unix-группу PHP-FPM, scheduler и ручного CLI для локального private uploads disk. Flysystem создаёт приватные файлы с `0660` и каталоги с `0770`; importer дополнительно назначает эту группу, `setgid` и те же права только дереву `catalog-collections/*/imported`. Файлы остаются вне `public/` и выдаются контроллером. Production должен заранее назначить ту же группу и `setgid` корню `storage/app/private/uploads`; на сервере с другой runtime-группой default обязательно переопределяется до синхронизации.

## Размер внешних видеофайлов

`SEASONVAR_MEDIA_FILE_SIZE_ENABLED` включает bounded metadata inspection. `CONNECT_TIMEOUT`, `TIMEOUT`, `RETRY_TIMES`, `RETRY_SLEEP_MS`, `KNOWN_TTL`, `UNKNOWN_RETRY`, `FAILED_RETRY`, `BACKFILL_CHUNK_SIZE` и `MAX_CHECKS_PER_CYCLE` с тем же префиксом ограничивают сеть, freshness и catalogue backlog. `SEASONVAR_MEDIA_FILE_SIZE_SCHEDULED_BACKFILL_ENABLED` включает десятиминутное постепенное обслуживание legacy rows, а `SEASONVAR_MEDIA_FILE_SIZE_SCHEDULED_BACKFILL_LIMIT` задаёт размер одной пачки (default 20, дополнительно hard-clamped кодом). Defaults перечислены в `.env.example` и `config/seasonvar.php`; ни один параметр не разрешает чтение полного video body.

`PLAYBACK_ALLOWED_HOSTS` остаётся download allowlist, а production обязан держать `PLAYBACK_ENFORCE_PUBLIC_DNS=true`. Authenticated download принудительно выполняет public-DNS validation даже при compatibility override. Chunk, stream/connect timeout, retry и rate-limit budgets являются versioned `config/playback.php` (`playback.downloads`) и не принимаются из request. После изменения environment/config необходимы `config:cache` и graceful reload PHP-FPM/import workers.

## Обсуждения

`COMMENTS_ENABLED=true` включает UI/writes только при наличии полной additive schema. `CommentSchema` fail-closed проверяет обязательные columns в canonical comment table и все engagement/relationship/notification tables; неполный deploy показывает disabled state. Schema capability намеренно не зависит от feature flag: при `false` уже сохранённые rows всё равно участвуют в private export/deletion, merge reconciliation и collection privacy retirement. Body limits, pagination, edit/restore windows, anti-spam и rate-limit budgets принадлежат versioned `config/comments.php`, а не environment. Их нельзя расширять client parameter-ом.

Database notifications синхронны и не требуют новой queue/worker. Existing cache/session/RateLimiter store используется без отдельного connector. После изменения `COMMENTS_ENABLED` или config необходимо пересобрать config cache; `.env` не редактируется приложением и secrets в comment config отсутствуют.

## Теги

`TAG_CANONICAL_SCHEMA` по умолчанию пуст. В этом режиме `TagSchema` безопасно определяет полный набор canonical columns/tables и сохраняет legacy reads до завершения additive migrations. `true` можно выставить только после schema verification, `false` — только как кратковременный управляемый rollback/diagnostic override; это не product feature flag и не замена migration.

Tag label/description/batch/restoration/search/rate/reserved-name bounds находятся в versioned `config/tags.php`, не в request и не требуют секретов. Personal data использует existing database/cache/auth stores, public snapshots — existing `CacheDomain::Tags`; новая queue/cron/connector не нужна. После изменения override/config обязательны `config:cache` и graceful process reload.

## Проверка после изменения

```bash
php artisan config:cache
php artisan about --only=environment
php artisan app:health --json
php artisan cache:warm-catalog --queue --refresh
php artisan cache:metrics --json
```

После пересборки config cache нужно выполнить graceful reload PHP-FPM тем способом, которым он управляется на сервере, и проверить `seasonvar-import-forever.service`. Сам импортёр нельзя дублировать вторым процессом.

Не используйте `optimize:clear` или `cache:clear` как обычную реакцию на configuration error: default store может быть общим Redis application cache.
