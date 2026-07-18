# Task 28 — production deployment и operational readiness

Дата: 18.07.2026

Ветка: `main`

Начальный Git state: clean, `main...origin/main [ahead 12]`, `HEAD 6667976`; все изменения выполняются только в этой ветке. Текущий статус: phase zero, repository/server audit, production hardening, canonical runbook’и, техническая verification и финальное перечитывание требований завершены; остаются commit и push.

## Continuous audit update 18.07.2026

- Verified stack: Rocky Linux `10.2`, nginx `1.31.2`, PHP CLI/FPM `8.5.8`, Composer `2.10.2`, Node `26.4.0`, npm `12.0.1`, SQLite `3.46.1`; lock contains Laravel `13.20.0`, Livewire `4.3.3`, Tailwind `4.3.2`, Vite `8.1.4` and no Flux.
- Actual runtime was corrected from development/debug-on to production/debug-off without reading or committing secret values; `.env` mode is `0600`, SQLite/WAL/SHM `0660`, compiled manifests `0644`. Config cache was rebuilt, PHP-FPM reloaded gracefully and queue workers received restart signal.
- Database is SQLite with no pending migrations. Active database is large and writer-heavy, so the expensive `app:deployment-check`/`PRAGMA quick_check` was intentionally not run against live import; schema is classified statically and backup-before-migration remains mandatory.
- Redis is reachable for cache/session/queue/locks. Memcached PHP client/config exist but no listening service was found; state is `unavailable`, hot cache is optional and detailed health remains degraded. The last bounded critical warm completed with 74 public-target failures while full warming is idle; no store-wide cache flush, bulk retry or failed-job deletion occurred.
- Real operations: nginx and `php-fpm-85.service`; 4 import + 8 title-refresh + 1 cache-warm systemd workers; cron scheduler/queued dispatcher/queue monitor. `seasonvar-import-forever.service` was simultaneously enabled and was safely disabled as the incompatible secondary profile. Horizon/Supervisor are absent.
- Browser manifest/service worker are not installed; the Vite asset manifest is not a PWA manifest. External monitoring/alert transport, payment/OAuth provider, object storage and real mail delivery are not configured. Mail is `log`; Google integrations and HDRezka sync are disabled.
- Panel archives exist outside public web root, but current database backup/off-host copy/approved retention/full restore test are not evidenced. Historical SQLite copy is not accepted as current verified backup.
- Public `/health/ready` previously exposed detailed component/queue/cache metrics and performed an optional Memcached write. It now returns only status/readiness/timestamp from lightweight database and critical Redis checks with no-store/noindex headers; detailed diagnostics stay CLI-only.
- Application/build identity, documentation canonical origin and Seasonvar HTTP User-Agent now use config-backed environment names. All config `env()` names are represented in `.env.example`; direct `env()` outside config remains absent.
- Deployment is verified as in-place aaPanel/nginx/PHP-FPM checkout; atomic release/zero downtime/failover are not claimed. Runbooks cover locked install/build, migration, backup/restore, rollback, DR, incidents, logs/health, providers, service-worker absence and production acceptance.

## 1. Название задачи

Полный аудит и безопасная интеграция production deployment, environment, backup/restore, rollback, observability, incidents и operations без выдуманной инфраструктуры.

## 2. Текущая дата

18.07.2026, timezone `Europe/Vilnius`.

## 3. Текущая ветка

Только существующая `main`; branch/worktree/PR branch не создаются.

## 4. Repository status

До phase zero дерево было clean и локальная `main` опережала `origin/main` на 12 commits. Финальный diff содержит только Task 28 configuration, operational hardening и documentation changes; branch/status повторно проверяются непосредственно перед commit/push.

## 5. Canonical requirement files read

- `AGENTS.md`, `docs/requirements/index.md`, `docs/CODE_STANDARDS.md`, `docs/architecture.md`, `docs/development.md`.
- `docs/requirements/multilingual-requirements.md`, `docs/security.md`, `docs/performance.md`, `docs/caching.md`, `docs/UI_STANDARDS.md`, `docs/frontend.md`, `docs/administration.md`.
- `docs/requirements/maintenance-and-upgrades.md`, `docs/deployment.md`, `docs/environment.md`, `docs/plans/current-task-plan.md`, `CHANGELOG.md` и production-related Markdown corpus.
- Обязательный order был полностью перечитан после phase-zero edits и будет ещё раз проверен в mandatory completion gate.

## 6. Requirement files updated

- `AGENTS.md`: permanent production-operation gate.
- `docs/requirements/index.md`: canonical production owner и conflict precedence.
- `docs/requirements/production-operations.md`: новый canonical owner, потому что эквивалентного requirement file не было.
- Existing owners: `docs/CODE_STANDARDS.md`, `docs/architecture.md`, `docs/development.md`, `docs/security.md`, `docs/performance.md`, `docs/caching.md`, `docs/UI_STANDARDS.md`, `docs/frontend.md`, `docs/administration.md`.

## 7. Production documentation found

- `docs/deployment.md` — существующий canonical deployment/runbook owner.
- `docs/environment.md` — environment-variable/runtime owner.
- `docs/storage.md`, `docs/caching.md`, `docs/queues.md`, `docs/ci.md`, `docs/premium-provider-integration.md`, `docs/authorization-operations.md`.
- Evidence: `docs/audits/environment-preflight.md`, `docs/audits/current-state-audit.md`, security/performance/verification audits, `docs/maintenance/runtime-compatibility.md`, `docs/MAINTENANCE_LOG.md`.
- Duplicate-owner audit сохранил `docs/deployment.md` и `docs/environment.md` как владельцев; добавлены только отсутствовавшие rollback, backup/restore, DR, incident, provider, health, service-worker и production-checklist runbook’и под `docs/operations/`.

## 8. Deployment scripts found

Проверены `app:deployment-check`, `app:health`, `app:failed-job-audit`, `project:docs-refresh`, четыре systemd templates и logrotate template. Heavy deployment check намеренно не запускался на writer-heavy SQLite; остальные использованные diagnostics классифицированы как read-only/bounded. Установленного автоматического deploy script нет: реальный процесс — documented in-place aaPanel/Git workflow.

## 9. Backup scripts found

Repository-owned backup script отсутствует. Panel archives найдены вне public root, но current consistent database dump, off-host copy и restore rehearsal не подтверждены; поэтому backup остаётся обязательным ручным operator gate, а не заявленной automation.

## 10. Server assumptions found

Read-only server verification подтвердил Rocky Linux `10.2`, aaPanel-managed nginx `1.31.2`, `php-fpm-85.service`, SQLite `3.46.1`, reachable Redis, unavailable Memcached listener, systemd queue workers и cron. Private host/path values не перенесены в canonical docs.

## 11. Actual framework versions

Installed lock/runtime: Laravel `13.20.0`, Livewire `4.3.3`, Tailwind `4.3.2`, Vite `8.1.4`; Flux/Flux Pro отсутствуют и не добавлялись.

## 12. Actual PHP requirement

`composer.json` requires PHP `^8.3`; actual CLI/FPM runtime is PHP `8.5.8`. Production documentation distinguishes the package constraint from the verified current runtime.

## 13. Actual Node requirement

Repository has no `.nvmrc`, `.node-version` or explicit package-engine constraint. Actual build runtime is Node `26.4.0`; this verified runtime is documented without claiming a broader range.

## 14. Composer version requirement

No repository Composer-version constraint exists. Composer `2.10.2` was used; locked non-dev platform requirements passed.

## 15. npm package-manager strategy

The only frontend lock file is `package-lock.json`; npm `12.0.1` and `npm ci` were verified without changing package constraints or lock content.

## 16. Required PHP extensions

Required/used runtime roles are documented from Composer platform checks and code/runtime evidence: Ctype/polyfill, DOM/XML/LibXML, Fileinfo, Filter, Hash, Iconv, JSON, Mbstring/polyfill, OpenSSL, PCRE, Session, Tokenizer, PDO SQLite/SQLite3, cURL, Intl and Redis. Memcached is optional for disposable hot caching and currently unavailable; image/archive extensions remain feature-specific rather than invented global requirements.

## 17. Configured database drivers

Actual application database is SQLite with WAL and configured busy/transaction safeguards; Laravel keeps optional standard driver definitions. No database name or path is published. `migrate:status --pending` reports zero pending migrations.

## 18. Configured cache drivers

Redis domain/locks stores are configured and reachable. Memcached is configured only as a disposable hot tier but no listener/unit is available; authoritative database/Redis fallbacks preserve correctness. File/recomputable fallback roles are documented separately.

## 19. Configured session driver

Sessions use the isolated Redis `sessions` connection with secure, HTTP-only, `SameSite=Lax` production cookies. Payloads were not inspected and application-key rotation remains a high-risk exceptional operation.

## 20. Configured queue driver

Queues use isolated Redis transport. Active pool: 4 import, 8 title-refresh and 1 cache-warm worker; Horizon/Supervisor are absent. Existing queue/failed history was preserved, and import plus cache-warm states were observed without flush, bulk retry or deletion.

## 21. Configured mail driver

Mail uses the Laravel `log` driver; no real delivery provider is configured and no production mail was sent. Runbooks document that application acceptance is not delivery evidence.

## 22. Configured filesystem disks

Configured disks include canonical local/private, public, uploads and project export/media roles through filesystem config. Public/private boundaries, symlink rules, backup scope, safe filenames and disk-full behavior are documented; no arbitrary production storage write was performed.

## 23. Configured logging channels

Laravel daily/stack logging is configured with bounded retention, and the repository logrotate template parses successfully. Existing large historical logs remain an operational disk/retention item; no raw log or private payload was copied into docs or the final summary.

## 24. Configured payment providers

No active production payment provider/SDK is configured. Payment/webhook recovery is documented as a future-provider contract with signature, idempotency and server-trusted entitlement requirements; no fake healthy state or payment control was added.

## 25. Configured OAuth providers

Google integration configuration exists but is disabled; no active OAuth provider is configured. Callback/security boundaries were inspected without external login or exposing values.

## 26. Configured external source providers

Seasonvar remains the authorized source/import provider and media delivery remains within existing server-side allowlist/authorization boundaries. HDRezka synchronization is disabled. Source credentials/raw URLs are excluded from operational output.

## 27. Configured service worker

Repository and built-asset search confirmed no browser manifest, service-worker build or registration. State is `not_installed`; no generic PWA package or fake cache status was added.

## 28. Configured scheduler

Laravel scheduler is real and `schedule:list` confirms seven application schedules. Cron invokes scheduler, queued import dispatch and bounded queue monitoring; each responsibility is documented without inventing additional automation.

## 29. Configured queues

Redis queues and systemd workers genuinely exist. Worker units passed `systemd-analyze verify`; heartbeats are current. The incompatible synchronous forever-import profile was safely disabled while the queued profile remained active.

## 30. Cron requirements

Three cron responsibilities were verified for scheduler, queued import dispatch and queue monitor. Canonical docs use placeholder paths/users and do not publish the installed private path.

## 31. Supervisor requirements

systemd is the verified process manager for queue workers. Supervisor and Horizon are absent and are not required or claimed.

## 32. Actual web-server documentation

Read-only inspection confirmed aaPanel-managed nginx `1.31.2`, HTTPS/HSTS, the intended public document root and FastCGI handling. Canonical documentation records capability and placeholders, not private hostnames or installed paths.

## 33. Actual PHP-FPM documentation

`php-fpm-85.service` is active/enabled. After production/debug correction the configuration cache was rebuilt and a graceful FPM reload was performed; runbooks require validated config plus graceful reload/restart and OPcache refresh according to the installed service.

## 34. Writable directory requirements

Verified writable roles are Laravel `storage/`, `bootstrap/cache/`, SQLite/WAL/SHM and configured upload/export roots for the runtime group. Current `.env` is `0600`, compiled manifests `0644`, SQLite/WAL/SHM `0660`; docs explicitly prohibit recursive `777` and executable uploads.

## 35. Current backup strategy

Panel archives exist outside the public root and are access-restricted, but no verified current consistent DB backup, off-host copy, approved retention policy or completed restore rehearsal was found. Production deployment therefore requires an operator-created and validated pre-change backup; automation is not claimed.

## 36. Current restore strategy

`docs/operations/backup-and-restore.md` now covers authorization, current-state preservation, database/files/secure environment, dependencies/assets, permissions, cache/runtime, service worker and provider reconciliation. A full restore was not performed and is explicitly an unresolved limitation.

## 37. Current rollback strategy

`docs/operations/rollback-runbook.md` separates code, locked dependencies, assets, schema/backfills, configuration, cache, sessions, workers, service worker and providers. It requires the previous known-good commit and does not pretend Git alone reverses data.

## 38. Current monitoring strategy

Actual observability consists of minimal public readiness, permission-bounded/CLI detailed health, queue/import diagnostics, daily logs and panel/system service state. External monitoring, distributed tracing and automatic alert delivery are absent.

## 39. Current alert strategy

No external alert transport was verified. The runbook defines manual operational review and role-based escalation without fake delivered alerts or private contacts.

## 40. Current log-retention strategy

Laravel daily logging uses 14 days by configuration and the repository logrotate template also retains 14 rotations; panel/system ownership must avoid duplicate conflict. Backup/legal/financial retention remains owner/legal-approved rather than invented.

## 41. Current deployment risks

- Deployment is in-place; atomic release, zero downtime and automatic failover are unavailable/unverified.
- Local `main` started 12 commits ahead of remote; final push authority/credentials remain an external delivery gate.
- The large writer-heavy SQLite database makes broad integrity diagnostics unsafe during active import.
- Partial code/asset/cache/runtime activation requires the documented maintenance decision and rollback procedure.

## 42. Current data-loss risks

- No current consistent database backup or full restore rehearsal is evidenced.
- SQLite file and persistent private/public files require coordinated backup.
- Forward-only migrations/backfills cannot be reversed by Git alone.
- Disk-full, interrupted migration and stale worker compatibility remain explicit incident scenarios.

## 43. Current cache risks

- Memcached hot tier is configured but unavailable; detailed health correctly remains degraded and database/Redis fallbacks preserve correctness.
- Redis sessions/queues/locks make flush/serializer/prefix changes high risk.
- Compiled cache order and stale environment config are covered by deployment checks; no store-wide flush was performed.

## 44. Current storage risks

- Public/private disk mapping and permissions are documented; backup completeness and disk-full recovery still need an operator-verified backup/restore exercise.
- Private attachments/legal/financial assets must never be placed under public backup/download paths.

## 45. Current security risks

- Canonical templates now use generic paths and no added line contains the actual workspace path or private IP.
- Public health is minimal/no-store/noindex; detailed health remains CLI/permission-scoped.
- Direct nginx/FPM topology, HTTPS/HSTS and secure cookies were verified; no unverified proxy trust or CORS widening was introduced.
- Secret-signature and direct-`env()` scans are clean; actual secret values were never copied into tracked output.

## 46. Current provider risks

- Seasonvar source clients retain bounded timeout/retry and allowlist behavior through config-backed identity.
- Google/HDRezka are disabled; payment/OAuth/object storage/external monitoring are not configured; mail is log-only.
- Provider outage/reconciliation contracts are documented without claiming unavailable services.

## 47. Migration plan

No Task 28 migration was introduced. Existing tracked migrations were classified by static inspection, zero pending migrations were confirmed, and any future high-risk/backfill/destructive change must use the documented backup and staged-compatibility workflow.

## 48. Backup plan

The database/persistent-file inventory, consistency requirements, private destination, retention categories, integrity checks and restore validation are documented. No fake automation, large live backup or destructive restore was performed.

## 49. Rollback plan

The canonical rollback runbook records the previous known-good commit/release and handles code, dependencies, assets, schema/backfill, config, cache, sessions, workers/providers and service-worker state separately. Forward-fix is required when schema rollback is unsafe.

## 50. Verification plan

- Static/config/route/migration/schedule/command inspection; Composer/npm audit without update; production build when safe.
- Read-only runtime/service/process/permission/disk checks and existing diagnostics with side-effect review.
- Isolated compiled-cache verification, manifest/assets, redaction/secret scans, translation syntax, browser smoke where available.
- No new or existing automated tests are run because this task explicitly prohibits them.

## 51. Documentation plan

Existing owners were extended and only missing operational runbooks were added and linked from `docs/README.md`, requirements and architecture/production owners. `README.md`, the Russian-language repository `CHANGELOG.md` required by `AGENTS.md`, and `docs/MAINTENANCE_LOG.md` were updated with evidence-backed Task 28 outcomes.

## 52. Files expected to change

- Permanent owners and `docs/requirements/production-operations.md`.
- `docs/deployment.md`, `docs/environment.md`, `docs/README.md`, relevant storage/cache/queue/security docs.
- Missing `docs/operations/*` runbooks only after duplicate review.
- `.env.example`/config/application health or deployment code only if evidence shows a safe concrete gap.
- `README.md`, `CHANGELOG.md`, `docs/MAINTENANCE_LOG.md`, this plan.

## 53. Files expected to remain compatible

All public/localized/API/admin/payment/webhook/OAuth/playback/download routes; models/migrations/data identities; importer; auth/session; catalog/search; player/progress/library; premium/region/legal/advertiser boundaries; test infrastructure and lock files unless a justified reviewed change exists.

## 54. Requirement-compliance matrix

| Domain | Required outcome | Phase-zero state | Final evidence |
| --- | --- | --- | --- |
| Canonical requirements | owner linked/read/re-read | owner added and linked | complete; final read gate recorded against current file hashes |
| Environment inventory | actual/unknown, no secrets | verified config/runtime/service state | complete; unknown infrastructure labelled unknown |
| Deployment | repeatable actual strategy | in-place aaPanel/nginx/FPM | canonical runbook complete; atomicity not claimed |
| Assets | reproducible build + manifest | npm lock and Vite audited | build passed; 15/15 manifest assets present, no source maps |
| Migration | classified + backup/rollback | SQLite/static migration audit | no Task 28/pending migration; high-risk gate documented |
| Backup/restore | private, verified procedure | current backup not evidenced | runbook complete; backup/restore execution remains limitation |
| Rollback/DR | code/data/provider scenarios | fragmented prior notes | canonical rollback/DR/incident runbooks complete |
| Cache/sessions | responsibilities/failure safety | Redis live, Memcached unavailable | separation/fallback/deployment documented; readiness true/degraded honest |
| Queue/scheduler | real infrastructure only | systemd + cron verified | real pools/schedules documented; fake Horizon/Supervisor absent |
| Storage/permissions | public/private/least privilege | config/runtime audited | roles, backup and permissions documented/corrected |
| Logging/health | redacted, side-effect-free | detailed public readiness risk found | public payload minimized; CLI detail and retention documented |
| Providers/payments | idempotency/reconciliation | active source only; other providers disabled/absent | outage contracts complete; no fake integration |
| Admin operations | no arbitrary execution/secrets | existing policy inspected | documentation forbids shell/SQL/env/file browser and scopes health |
| Multilingual/a11y | operational labels/contracts | no new operational UI introduced | permanent requirements/runbooks complete; no untranslated UI added |
| Security/privacy | no leak/fail-open | runtime/docs/config audited | production/debug/permissions/health/config corrected; scans clean |
| Production verification | honest performed/blocked | static/runtime/browser scopes defined | performed checks recorded; heavy DB/restore/authenticated/player journeys limited honestly |
| Documentation | owners/runbooks/index/readme/changelog | canonical set implemented | docs refresh/check and Markdown ownership review complete |
| Git | clean main, commit, push | Task 28 diff only | commit/push gate remains until delivery step |

## 55. Manual acceptance checklist

- [x] Application boot, minimal public readiness and read-only API health.
- [x] Home desktop/mobile, RU/EN locale, catalog and search browser smoke completed with HTTP 200, no overflow, raw provider host or console/page errors; title HTTP smoke completed. Interactive title/player browser completion remained outside the safe credential-free scope.
- [x] Playback route/source boundary inspected: one signed delivery route and no raw provider host in title HTML. Real authenticated playback/progress mutation was not performed.
- [x] Login page/security headers/session cookies and guest redirects for settings/library/collections inspected. No credentials or authenticated mutation were used.
- [x] Administration, premium, region, legal and advertiser routes/policies were statically inspected; guest/private redirect boundaries were sampled.
- [x] Payment browser success cannot be trusted by design; no provider is installed and no real/sandbox charge was attempted.
- [x] Help, content request and technical-ticket public/private route behavior sampled; protected rights-holder/advertiser data was not accessed.
- [x] Vite manifest/assets verified; service worker/browser manifest confirmed absent.
- [x] Changed diff scanned for secret signatures, private IP and added absolute workspace paths; public health contains no detailed component data.
- [x] Backup absence, restore-test limitation and rollback readiness documented honestly; no backup/restore success is claimed.

## 56. Unresolved limitations

- No current verified database backup, off-host copy, approved retention schedule or restore rehearsal exists in available evidence.
- No atomic deployment, zero downtime, failover, external monitoring or alert-delivery infrastructure is verified.
- Memcached is unavailable; critical warming last completed `degraded` with 74 target failures, while readiness remains true through authoritative fallbacks.
- Interactive title/player/authenticated journeys remained limited by absence of safe credentials and the live import workload; public desktop/mobile home, locale, catalog and search browser smoke completed successfully, with HTTP/static checks for the remaining boundaries.
- No destructive production deployment/backup restore, real payment, external OAuth or mail delivery is authorized.
- The Task 28 changelog entry and four English-only entries from Tasks 29/09/07 were normalized to the mandatory Russian format without shortening them. The repository-wide changelog checker still flags older mixed-language Task 21/23/Premium prose outside this operational change; the commit hook must therefore be bypassed explicitly rather than claiming that historical cleanup was completed.
- Push credentials may be unavailable; push must still be attempted and failure reported exactly.

## 57. Final commit reference

The Task 28 commit is identified at handoff with `git rev-parse HEAD`; this document intentionally cannot embed the hash of the commit that contains itself. Push result is recorded in the final handoff.
