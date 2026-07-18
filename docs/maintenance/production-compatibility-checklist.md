# Checklist production compatibility

- [ ] Exact Composer/npm lock files are committed and deployment uses locked installs.
- [ ] PHP version and required extensions match the compatibility matrix.
- [ ] Composer runtime/plugin policy and authentication storage are reviewed without exposing secrets.
- [ ] Node/package-manager/Vite versions and build image are pinned or explicitly reviewed.
- [ ] Vite manifest/assets exist and old immutable assets remain available through rollback window.
- [ ] Web-server rewrites, trusted proxies, security headers, upload/body limits and static caching reviewed.
- [ ] PHP-FPM pool identity, permissions, timeouts, OPcache and restart order reviewed.
- [ ] Production DB engine/driver/schema/index/backup/restore compatibility reviewed.
- [ ] Redis client/server/prefix/serializer/timeout/locks/session/queue responsibilities reviewed where used.
- [ ] Memcached client/server/prefix/serializer/TTL/eviction/fallback reviewed where used.
- [ ] Cache-key/serialization transitions and stale-key cleanup documented.
- [ ] Session compatibility, cookie encryption and intentional invalidation documented.
- [ ] Pending jobs, removed classes, worker restart, retries and failed-payload privacy reviewed.
- [ ] Scheduler entries, locks and operator commands remain bounded/idempotent.
- [ ] Mail/storage/payment/OAuth/external provider callbacks/signatures/timeouts reviewed where applicable.
- [ ] Service-worker/private-route exclusions and rollback reviewed; absence recorded if not installed.
- [ ] Deployment runbook names dependency/runtime changes, migrations/backups, health checks and rollback triggers.
- [ ] High-risk data migrations have a backup and forward-fix plan.
- [ ] Health/log evidence contains no secrets and optional dependencies degrade safely.
- [ ] Post-deploy affected routes and manual journeys have owners and acceptance evidence.

Rollback must name unsafe conditions: irreversible provider state, forward-only data conversion, expired signatures, incompatible sessions/cache/jobs or clients already activated on a new service-worker contract.
