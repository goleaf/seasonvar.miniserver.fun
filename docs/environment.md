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
CACHE_LIMITER_STORE=redis-limiter
CACHE_HOT_STORE=memcached-hot
CACHE_DOMAIN_STORE=redis-domain
CACHE_LOCK_STORE=redis-locks
CACHE_VERSION_STORE=redis-locks
```

Production также обязан включить HTTPS-only session cookie, корректные domain/path/SameSite, секретный `APP_KEY`, warning-or-higher structured log policy и реальные credentials через secret manager/process environment. Эти значения не должны попадать в Git.

## Redis workloads

Shared defaults задают `REDIS_URL` либо `REDIS_HOST`, `REDIS_PORT`, `REDIS_USERNAME`, `REDIS_PASSWORD`. Workload-specific overrides используют префиксы `REDIS_CACHE_*`, `REDIS_QUEUE_*`, `REDIS_SESSION_*`, `REDIS_LIMITER_*`, `REDIS_LOCK_*`, `REDIS_BROADCAST_*` и поддерживают `URL/HOST/PORT/USERNAME/PASSWORD/PREFIX/CLIENT_NAME/TIMEOUT/READ_TIMEOUT/RETRY_INTERVAL/MAX_RETRIES/BACKOFF_ALGORITHM/BACKOFF_BASE/BACKOFF_CAP/PERSISTENT/PERSISTENT_ID/TCP_KEEPALIVE`.

Standalone default DBs: cache 1, queues 2, sessions 3, limiter 4, locks 5, broadcasting 6. При managed Redis/Cluster используйте отдельные endpoints и prefixes; DB numbers не считаются HA boundary.

## Memcached и application cache

`MEMCACHED_HOST[_2|_3]`, ports и weights описывают hot pool. `MEMCACHED_HOT_PREFIX` обязан различать application/environment. Timeouts, failed-server removal, binary protocol и consistent distribution задаются отдельными переменными из `.env.example`; eviction допустим и не должен нарушать корректность.

`CACHE_APPLICATION`, `CACHE_ENVIRONMENT`, `CACHE_SCHEMA_VERSION`, `CACHE_FORMAT_VERSION`, payload/dimension limits, metrics retention и warming queue policy принадлежат application layer. Любое несовместимое изменение cache payload требует format bump, изменение семантики ключей — schema bump.

`RUN_CACHE_INFRASTRUCTURE_TESTS=false` — только test/CI switch. Его включают в изолированном тестовом окружении с run-specific Redis/Memcached prefixes; это не runtime feature flag production-приложения.

## Проверка после изменения

```bash
php artisan config:cache
php artisan about --only=environment,cache,drivers
php artisan app:health --json
php artisan cache:warm-catalog --queue --refresh
php artisan cache:metrics --json
```

Не используйте `optimize:clear` или `cache:clear` как обычную реакцию на configuration error: default store может быть общим Redis application cache.
