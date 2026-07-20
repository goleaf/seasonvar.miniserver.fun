# Logging and health

Проверено: 20.07.2026.

## Actual logging

Production config uses a `stack` containing Laravel `daily`, warning threshold and bounded `LOG_DAILY_DAYS=14`. A repository logrotate profile also rotates `storage/logs/*.log` daily with 14 generations and compression. Before installing it, replace `/srv/seasonvar/current` with the verified checkout. Laravel daily files plus system logrotate must be monitored so two retention mechanisms do not create unexpected disk growth; no business/legal retention is implied.

Allowed context is stable IDs/codes, bounded counters, timestamps and exception class where useful. Redact passwords, tokens, Authorization/Cookie/session headers, reset links, provider credentials/responses, payment data, raw source URLs, legal paths/content and private attachments. Production debug is false; public errors remain generic.

Log access is shell/panel operational access. No unrestricted browser log viewer, arbitrary path, `.env` editor, SQL or Artisan shell is provided.

## Public health boundary

`GET /health/ready` performs lightweight read-only checks for database plus critical Redis session/queue/lock connections. Response is only:

```json
{"status":"ok","ready":true,"checked_at":"..."}
```

It returns 503 when unavailable, `Cache-Control: no-store, private` and `X-Robots-Tag: noindex, nofollow`. It does not expose component names, latency, hostnames, database names, paths, package lists, queue counts, memory metrics or exception messages.

`GET /api/v1/health` remains a minimal API liveness/version response. Neither endpoint sends mail, creates records, flushes cache, runs migrations or fetches protected media.

## Operator diagnostics

- `php artisan app:health --json` is detailed CLI-only evidence for database, Redis roles, Memcached, workers and cache warming.
- `php artisan app:deployment-check --json` is a heavier preflight. On the current large active SQLite database it must run outside writer load with an explicit time budget; it was not run during Task 28 production import.
- `php artisan seasonvar:import --status`, `queue:monitor` and `app:failed-job-audit` provide bounded importer/queue state.

Actual Task 28 result after the bounded worker refresh: database and critical Redis roles were reachable; the import, title-refresh and cache-warm pools reported current heartbeats; full warming was idle. The latest bounded critical warming pass completed with 74 public-target failures and therefore reported `degraded`; Memcached was configured but unavailable. The application remained ready while detailed health honestly remained degraded. Queue history and failed work were preserved for normal review; they were not flushed, retried in bulk or presented as healthy.

Историческая проверка Task 29 до исправления обнаружила ограничение наблюдаемости: lease `cache-warm-v2` составлял 120 секунд, тогда как `WarmCatalogCaches` могла законно выполняться 600 секунд. Во время подтверждённой активной долгой job ключ истёк, и CLI health сообщил `failed`, хотя systemd unit и worker process оставались активны. False-negative был зарегистрирован как `TD-012`; его закрытие и актуальный операторский контракт зафиксированы ниже. Очередь во время аудита не очищалась, не переписывалась, массово не повторялась и не прерывалась.

Канонический контракт исправления `TD-012`: базовый heartbeat lease остаётся ограниченным для обычных очередей, а lease точной пары `cache-architecture.warming.connection`/`cache-architecture.warming.queue` должен быть не короче разрешённого `cache-architecture.warming.timeout` плюс 60 секунд запаса. Это сохраняет быстрое обнаружение остановки import/title/default pools, не требует heartbeat изнутри доменной job и ограничивает ложноположительное окно cache-warm после аварийного завершения. Изменение lease не разрешает очищать, переписывать, массово повторять или прерывать очередь.

Контракт внедрён 20.07.2026. Focused queue/health regression прошёл 25 тестов и 145 утверждений. После graceful recycle production worker продолжал `WarmCatalogCaches` дольше прежних 120 секунд: heartbeat оставался доступен с TTL 525 секунд, `app:health --json` показывал `ready=true`, а pool — `degraded` по реальному backlog вместо ложного `failed`. Следующая job получила 660-секундный lease, read-only TTL составлял 643 секунды. Проверка остановленного worker выполнена unit-контрактом без остановки production service.

## Observability and alerts

Available evidence: HTTP status, Laravel/system logs, queue/import heartbeat/backlog, cache metrics, disk usage, migration status and panel/system service state. External APM, distributed tracing and automatic alert transport were not found. Operational review is manual; no uptime, RTO/RPO or delivery guarantee is claimed.
