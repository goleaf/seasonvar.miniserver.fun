# Переменные окружения

Обновлено: 13.07.2026

Полный безопасный шаблон находится в `.env.example`. Реальный `.env` не изменяется deployment-кодом и не коммитится.

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

Versioned-профиль `deploy/logrotate/seasonvar` ежедневно ротирует все `storage/logs/*.log`, хранит 14 архивов и сжимает их. Установить его можно командой `sudo install -m 0644 deploy/logrotate/seasonvar /etc/logrotate.d/seasonvar`; проверка без ротации выполняется через `sudo logrotate --debug /etc/logrotate.d/seasonvar`. Профиль использует `copytruncate`, поэтому PHP-FPM и постоянный импортёр продолжают писать без остановки.

## Redis workloads

Shared defaults задают `REDIS_URL` либо `REDIS_HOST`, `REDIS_PORT`, `REDIS_USERNAME`, `REDIS_PASSWORD`. Workload-specific overrides используют префиксы `REDIS_CACHE_*`, `REDIS_QUEUE_*`, `REDIS_SESSION_*`, `REDIS_LOCK_*`, `REDIS_BROADCAST_*` и поддерживают `URL/HOST/PORT/USERNAME/PASSWORD/PREFIX/CLIENT_NAME/TIMEOUT/READ_TIMEOUT/RETRY_INTERVAL/MAX_RETRIES/BACKOFF_ALGORITHM/BACKOFF_BASE/BACKOFF_CAP/PERSISTENT/PERSISTENT_ID/TCP_KEEPALIVE`.

Standalone default DBs: cache 1, queues 2, sessions 3, locks 5, broadcasting 6. При managed Redis/Cluster используйте отдельные endpoints и prefixes; DB numbers не считаются HA boundary.

`SEASONVAR_TITLE_REFRESH_FRESH_MINUTES` задаёт successful-only окно targeted refresh (по умолчанию 15 минут), а `SEASONVAR_TITLE_REFRESH_QUEUE` — отдельную приоритетную очередь `seasonvar-title-refresh`. `SEASONVAR_TITLE_REFRESH_FINALIZER_DELAY_SECONDS` задаёт короткую задержку повторной проверки незавершённой группы; `SEASONVAR_TITLE_REFRESH_STATE_TTL_SECONDS`, `SEASONVAR_TITLE_REFRESH_ACTIVE_SECONDS` и `SEASONVAR_TITLE_REFRESH_DISPATCH_LOCK_SECONDS` ограничивают operational state, stale-active recovery и atomic dispatch lock. `SEASONVAR_IMPORT_PREPARED_RETENTION_DAYS` управляет bounded очисткой старых terminal groups вместе с подготовленными payload. Connection и critical lock store остаются общими `SEASONVAR_QUEUE_*` настройками; лимита числа страниц или конкурентности в application config намеренно нет.

## Memcached и application cache

`MEMCACHED_HOST[_2|_3]`, ports и weights описывают hot pool. `MEMCACHED_HOT_PREFIX` обязан различать application/environment. Timeouts, failed-server removal, binary protocol и consistent distribution задаются отдельными переменными из `.env.example`; eviction допустим и не должен нарушать корректность.

`CACHE_APPLICATION`, `CACHE_ENVIRONMENT`, `CACHE_SCHEMA_VERSION`, `CACHE_FORMAT_VERSION`, payload/dimension limits, metrics retention и warming queue policy принадлежат application layer. `PUBLIC_PAGE_CACHE_*` ограничивают compressed/uncompressed guest HTML payload, query, LRU manifest, exact-origin self-warm URL, title/URL batch, connect/total timeout и retry; `PUBLIC_PAGE_CACHE_WARM_BASE_URL` обязан совпадать с origin `APP_URL`. `CACHE_WARM_REQUEST_*` ограничивают coalesced pending state и claim. Любое несовместимое изменение cache payload требует format bump, изменение семантики ключей — schema bump.

`RUN_CACHE_INFRASTRUCTURE_TESTS=false` — только test/CI switch. Его включают в изолированном тестовом окружении с run-specific Redis/Memcached prefixes; это не runtime feature flag production-приложения.

## Рекомендации каталога

`SEASONVAR_RECOMMENDATION_CHUNK_SIZE`, `MIN_SCORE`, `MAX_PER_TITLE`, `CANDIDATE_LIMIT` и `CANDIDATE_SCAN_PER_FEATURE` ограничивают локальную catalog-wide пересборку. `SEASONVAR_RECOMMENDATION_DIVERSITY_PENALTY` задаёт bounded MMR-штраф за повтор одинаковых тем и связей; default `120` не может изменить первый, самый релевантный результат. Эти параметры не включают HTTP и не меняют единственную публичную команду импорта.

## Обсуждения

`COMMENTS_ENABLED=true` включает UI только при наличии полной additive schema; `CommentSchema` всё равно проверяет comments/engagement/relationship tables и безопасно показывает disabled state при неполном deploy. Body limits, pagination, edit/restore windows, anti-spam и rate-limit budgets принадлежат versioned `config/comments.php`, а не environment. Их нельзя расширять client parameter-ом.

Database notifications синхронны и не требуют новой queue/worker. Existing cache/session/RateLimiter store используется без отдельного connector. После изменения `COMMENTS_ENABLED` или config необходимо пересобрать config cache; `.env` не редактируется приложением и secrets в comment config отсутствуют.

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
