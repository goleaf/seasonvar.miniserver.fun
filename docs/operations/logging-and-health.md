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

Task 29 final read-only evidence found an observability limitation: the `cache-warm-v2` heartbeat lease is 120 seconds while `WarmCatalogCaches` may legally run for 600 seconds. During a confirmed active long job, the key expired and CLI health reported the pool as `failed` although the systemd unit and worker process remained active. This false-negative is tracked as `TD-012`; operators must correlate CLI output with unit/process/journal evidence until a separately verified heartbeat change is deployed. The queue was not cleared, rewritten, bulk-retried or interrupted during the audit.

## Observability and alerts

Available evidence: HTTP status, Laravel/system logs, queue/import heartbeat/backlog, cache metrics, disk usage, migration status and panel/system service state. External APM, distributed tracing and automatic alert transport were not found. Operational review is manual; no uptime, RTO/RPO or delivery guarantee is claimed.
