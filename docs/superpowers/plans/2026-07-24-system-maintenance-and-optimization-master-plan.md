# Безлимитная программа стабилизации, обновления и оптимизации — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. `superpowers:subagent-driven-development` допустим только при отдельном прямом разрешении пользователя на sub-agents. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Последовательно привести production-контур Seasonvar к воспроизводимому, наблюдаемому и восстанавливаемому состоянию, затем безопасно обновить зависимости, сократить storage/database/queue debt и оптимизировать измеренные hot paths без нарушения существующих public, data, access и playback contracts.

**Architecture:** Программа следует operations-first dependency graph. Сначала сохраняются evidence и recoverability, затем исправляются Redis persistence, process ownership, очереди, permissions и retention; только после устойчивого operational baseline разрешены data cleanup, release deployment, runtime/package updates, performance refactoring и решение о production database engine. Каждый workstream заканчивается самостоятельным проверяемым commit в существующей `main`, имеет rollback gate и не смешивает unrelated upgrades.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, SQLite 3, Redis 8, Memcached, PHP-FPM, nginx, Composer, PHPUnit 12, Laravel Pint, PHPStan/Larastan, Rector, Node.js, npm, Vite 8, Tailwind CSS 4, Playwright.

## Global Constraints

- Работать только в существующей `main`; не создавать branch, worktree или pull-request branch.
- До каждого workstream заново читать `AGENTS.md`, `docs/requirements/index.md` и все применимые canonical owners.
- Не изменять `.env`; безопасные новые runtime keys сначала добавлять в config и `.env.example`.
- Не выполнять `queue:clear`, `cache:clear`, `queue:retry all`, `migrate:fresh`, `db:wipe`, broad `VACUUM`, destructive checkout или массовое удаление файлов.
- SQLite database, private uploads, user state, audit, premium, rights, playback grants, failed jobs и Redis transport state считаются защищёнными до verified backup и task-specific authorization.
- Единственная публичная команда импорта остаётся `php artisan seasonvar:import`; maintenance-команды не должны запускать import или обходить canonical importer locks.
- Existing routes, route names, locale aliases, model binding, DB identities, event codes, permission codes, cache identities, queue names и serialized job compatibility сохраняются.
- Public HTML routes остаются class-based full-page Livewire; Volt, `@php`, DB/cache/service calls из Blade и inline business JavaScript/CSS запрещены.
- Package/runtime update получает отдельный update decision, locked diff, affected-feature matrix, production procedure и rollback.
- Каждый PHP change следует TDD: focused RED → minimal GREEN → focused verification → Pint → relevant static analysis → wider tests.
- Каждый frontend change завершает Vite build и relevant Chromium matrix; player/UI changes дополнительно требуют real-device limitation record.
- README меняется только при фактическом visitor/product/development/deployment change; русский `CHANGELOG.md` получает отдельный честный пункт для каждого delivered workstream.
- Completed workstream commit отправляется в configured remote; внешний auth/network отказ фиксируется `unresolved`, а не изображается successful push.

---

## Execution Ledger — 24.07.2026

| Workstream | Status | Evidence / next gate |
| --- | --- | --- |
| Task 1 — operational baseline | `completed` | Dated read-only evidence is committed in `2227c08`; no production state was mutated. |
| Task 2 — Redis persistence observability | `completed` | TDD implementation and documentation are committed in `2227c08`; `app:health --json` now reports `redis_persistence=failed` while preserving `ready=true`. |
| Task 1–2 remote delivery | `unresolved` | The normal push reached configured `origin` but failed because HTTPS credentials were unavailable. After the Task 34 evidence commit, local `main` is 27 commits ahead; this count grows with later evidence commits until Task 29 delivers them. No force push or history rewrite is allowed. |
| Task 3 — controlled Redis recovery | `unresolved` | Blocked before any mutation: independent protected backup, exact Redis process-manager ownership, maintenance/session-impact approval and producer stop boundary are not verified. |
| Tasks 4–6 and 8–28 | `not_started` | They remain ordered behind the Task 3 recoverability gate unless a task is explicitly marked cross-cutting and read-only. Task 7 is superseded by importer Task 3 and must not create a second retention boundary. |
| Importer child roadmap | `implementation_in_progress_uncommitted` | Importer Tasks 2–3 remain in the shared unstaged/untracked scope and Task 4 is now reported `in_progress_tdd`; production activation remains gated by Tasks 3–5 of this plan. No importer file belongs to the system planning manifest. |
| Player child roadmap | `roadmap_committed_local` | Seamless-player runtime work is committed in `1ded102`/`ab6532a`; its unlimited roadmap and delivery evidence are committed in `a14cdf5`/`f22e755`. Remote delivery remains unresolved and deployment must not be combined with Redis, database, dependency or process-control work. |
| Collection diagnostics workstream | `implementation_verified_uncommitted` | The collection-specific plan reports focused RED/GREEN, 72 tests / 431 assertions and Vite verification, but code, translations, owner docs and its task plan remain uncommitted in the shared checkout. Public collection, matching, route, cache and recommendation contracts are unchanged. |
| Shared delivery state | `repeated_collision_observed` | Task 30 base preparation is committed in `ad5c13c`; Task 32 standalone index approval is committed in `2a7f636` with evidence `cb83684`. During Task 32 verification another owner added a player-plan path to the staged set and later removed only its own path before `f22e755`; this proves that a reviewed digest needs both hook activation and a declared-path boundary. |
| Rolling extension Tasks 29+ | `in_progress` | Delivery recovery, shared-`main` ownership, cross-workstream reconciliation, reviewed-index binding, current-plan policy and declared-path ownership are evidence-backed Tasks 29–34. Task 34 planning is committed in `ebbbc85`; implementation has not started. Later discoveries continue with monotonic IDs without changing completed history. |

Task 1 and Task 2 were delivered as one isolated Batch 1 commit because they shared operations owners while unrelated importer/player changes already occupied the worktree. This is a recorded commit-granularity deviation, not a reason to rewrite `main`. Every later implementation task returns to one independently reviewable commit.

The Tasks 32–33 roadmap extension is committed locally in `cb432c7`. Task 32 standalone preparation is committed in `2a7f636` with delivery evidence `cb83684`; the player roadmap follow-up is committed in `f22e755`. Normal pushes to configured `origin` failed with unavailable HTTPS credentials, so remote delivery remains `unresolved` under Task 29; no force push, alternate branch, credential storage or history rewrite was used.

The Task 34 declared-path roadmap extension is committed locally in `ebbbc85`. Its normal push reached the configured HTTPS remote and failed because credentials are unavailable; no implementation, hook activation, force push, alternate branch, credential storage or history rewrite was performed.

## Baseline Evidence — 24.07.2026

| Boundary | Verified state |
| --- | --- |
| Git | `main` at `f22e755`; 25 local commits ahead of `origin/main`; index free at the dated snapshot; unstaged importer/collection scopes and unrelated `composer.lock` patch must not be mixed or absorbed accidentally |
| Application | PHP `8.5.8`; Laravel runtime `13.21.1`; Livewire `4.3.3`; Boost `2.4.13`; Pint `1.29.3`; PHPUnit `12.5.31` |
| Frontend | Node `26.4.0` Current; npm `12.0.1`; Vite `8.1.4`; Tailwind `4.3.2`; Playwright `1.61.1` |
| Services | nginx `1.31.3`; Redis `8.6.4`; Memcached `1.6.39`; SQLite `3.46.1`; PHP-FPM `8.5.8` |
| Host | Rocky Linux `10.2`; 4 physical CPU cores; 62 GiB RAM; no pending DNF/security update at audit time |
| Health | `app:health --json` returns `degraded`, `ready=true`; DB/Redis/Memcached reachable |
| Redis persistence | `rdb_bgsave_in_progress=1` for about 28 hours; child consumes about one CPU; more than 10.5 million changes since last acknowledged save; AOF disabled |
| Queues | 43,078 pending and 29,045 delayed; `seasonvar-import`, `seasonvar-title-refresh`, `cache-warm-v2` heartbeats absent |
| Processes | Generic `default,notifications`, `images,builds`, `payments` workers and `schedule:work` active; intended 4 import + 8 title-refresh + 1 cache-warm processes absent |
| Scheduler | Both `schedule:work` and per-minute cron `schedule:run` exist; queued import cron continues producing work |
| Failed jobs | 33,597 total; 12,851 parsed finalizers currently classified as terminal `forget_candidate`; no mutation performed |
| SQLite | Main file 28,154,552,320 bytes; zero freelist pages at audit time; full `PRAGMA quick_check` did not finish within the 60-second observation window |
| Large tables | comments 3,707,221; reactions 9,265,514; import events 3,690,976; title reviews 1,720,331; title user states 1,646,382 |
| Retention debt | 1,693,350 import events older than 7 days; 33,108 terminal title groups older than 7 days |
| Demo corpus | 100 demo users own millions of rows; synthetic timestamps extend to 2037, so corpus must be separated from real production state before cleanup |
| Storage | `storage/app/backups` about 48 GiB; database directory about 31 GiB; logs about 956 MiB; off-host backup and restore rehearsal unverified |
| Permissions | Hundreds of compiled view files owned by `root:root`; writable trees contain `777`; production log contains `touch(): Utime failed: Operation not permitted` |
| Assets | Production log contains missing Vite manifest incident; validation rebuild recreated ignored `public/build` artifact |
| PHP-FPM | `pm.max_children=60` on 4 cores; slowlog threshold 30 seconds |
| OPcache | default `max_accelerated_files=10000`; repository/vendor PHP inventory about 13,934 files |
| HTTP | warm home/catalog TTFB about 70–95 ms; cold representative title about 776 ms and immediate warm repeat about 78 ms |
| Verification | 1,453 PHPUnit tests passed, 123,094 assertions, 11 skipped; 42 Playwright passed, 6 skipped; Pint/PHPStan/Rector/syntax/audits/build passed |

## Compatibility Domains That Must Remain Stable

- Authentication, email verification, sessions, password recovery and mobile tokens.
- Policies, gates, administration roles, permission codes and account restrictions.
- Public RU/EN routes, canonical SEO, sitemap/feed streaming and search identities.
- `CatalogTitle`/`Season`/`Episode` hierarchy, slug binding and importer merge semantics.
- `seasonvar:import`, queue names, job payload compatibility, retry windows and finalizer checkpoints.
- Player source grants, entitlement, progress sequence, guest progress, HLS/MP4 behavior and raw-provider URL protection.
- Personal library, tags, collections, comments, reactions, reviews, profiles and account lifecycle.
- Premium, region, legal and advertiser boundaries even where optional capabilities are not installed.
- Public/private cache separation, Redis session/queue/lock roles and Memcached disposable hot tier.
- Existing API v1 shapes, Sanctum tokens, sync cursors and API Resources.
- Storage visibility, signed/private downloads, user uploads, backups and audit evidence.
- Service-worker state remains honestly `not_installed` until a separately approved capability exists.

## Program Dependency Graph

```text
Evidence freeze
  └─> Verified backup / restore evidence
       └─> Redis persistence recovery
            └─> Single process-manager and scheduler ownership
                 └─> Staged worker recovery and backlog drain
                      └─> Failed-job disposition + independent retention
                           └─> Demo corpus separation + SQLite compaction
                                ├─> Release-directory deployment
                                ├─> PHP-FPM / OPcache tuning
                                ├─> Node and dependency patch groups
                                └─> Performance and architecture refactors
                                     └─> SQLite-versus-PostgreSQL decision
```

Documentation, security review, monitoring and compliance evidence run through every node; they do not bypass the dependency order.

---

### Task 1: Record a New Dated Operational Baseline

**Status:** `completed` in `2227c08`; remote delivery remains `unresolved`.

**Files:**
- Modify: `docs/environment.md`
- Modify: `docs/queues.md`
- Modify: `docs/audits/current-state-audit.md`
- Modify: `docs/audits/environment-preflight.md`
- Modify: `docs/operations/logging-and-health.md`
- Modify: `docs/maintenance/runtime-compatibility.md`
- Modify: `docs/maintenance/technical-debt.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Current read-only evidence in this master plan.
- Produces: Dated source of truth that later operational tasks use as their before-state.

- [x] **Step 1: Re-read requirements and capture a clean task boundary**

Run:

```bash
git status --short --branch
git diff --check
php artisan about
php artisan migrate:status
php artisan schedule:list
```

Expected: branch is `main`; unrelated dirty files are identified and excluded; no migration or scheduler mutation occurs.

- [x] **Step 2: Repeat only bounded read-only operational probes**

Run:

```bash
php artisan app:health --json
php artisan app:failed-job-audit --json --samples=0
php artisan seasonvar:import --status
redis-cli INFO persistence
redis-cli INFO memory
redis-cli INFO stats
```

Expected: JSON/status evidence is available without queue retry, forget, cache flush, migration or service restart.

- [x] **Step 3: Update canonical evidence**

Record exact dated states `verified`, `degraded`, `unknown`, `not_installed` or `unresolved`. Replace stale claims that import/title/cache workers are active; do not delete the historical Task 28 evidence.

- [x] **Step 4: Add stable technical-debt records**

Reconcile the existing registry without renumbering or overwriting concurrent importer debt:

```text
TD-014 Redis persistence child does not terminate after RDB write.
TD-015 Intended Seasonvar/cache worker topology is inactive and scheduler ownership is duplicated.
TD-017 Local same-volume backups lack approved retention and restore rehearsal.
TD-018 Demo/load-test corpus dominates SQLite and contains synthetic future timestamps.
TD-019 Runtime permissions and in-place asset deployment permit ownership/manifest drift.
TD-020 PHP-FPM/OPcache limits are not derived from measured workload.
TD-025 Full deployment integrity check is unbounded for the current 28 GB SQLite file.
```

`TD-016` already owns failed-job/retention debt, while importer-specific work owns `TD-021`–`TD-024`; preserve those records. Each new or corrected row must use the existing registry columns and contain a measurable completion criterion.

- [x] **Step 5: Verify documentation**

Run:

```bash
bash scripts/ci-check.sh docs
php artisan project:docs-refresh --check
git diff --check
```

Expected: all commands pass; managed documentation blocks remain untouched.

- [x] **Step 6: Commit baseline evidence**

```bash
git add docs/environment.md docs/queues.md docs/audits/current-state-audit.md docs/audits/environment-preflight.md docs/operations/logging-and-health.md docs/maintenance/runtime-compatibility.md docs/maintenance/technical-debt.md docs/plans/current-task-plan.md CHANGELOG.md
git commit -m "docs: record production stabilization baseline"
git push origin main
```

Expected: only evidence files are committed; push succeeds or auth/network failure is recorded `unresolved`.

Actual: Task 1 evidence and Task 2 implementation were committed together as `2227c08`; the push failed only at the external HTTPS credential boundary and is recorded `unresolved`.

---

### Task 2: Add Redis Persistence Observability

**Status:** `completed` in `2227c08`; focused verification passed and remote delivery remains `unresolved`.

**Files:**
- Create: `app/Services/Operations/RedisPersistenceInspector.php`
- Modify: `app/Services/Operations/InfrastructureHealthCheck.php`
- Modify: `config/cache-architecture.php`
- Modify: `.env.example`
- Create: `tests/Unit/RedisPersistenceInspectorTest.php`
- Modify: `tests/Unit/InfrastructureHealthCheckTest.php`
- Modify: `docs/operations/logging-and-health.md`
- Modify: `docs/environment.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Laravel Redis connection `queues`; Redis `INFO persistence`.
- Produces: `RedisPersistenceInspector::inspect(): array` and detailed health component `redis_persistence`.

- [x] **Step 1: Write the failing inspector tests**

Test contract:

```php
$result = $inspector->summarize([
    'rdb_bgsave_in_progress' => 1,
    'rdb_current_bgsave_time_sec' => 3_601,
    'rdb_last_bgsave_status' => 'ok',
    'rdb_changes_since_last_save' => 10_500_000,
    'aof_enabled' => 0,
]);

$this->assertSame('failed', $result['status']);
$this->assertSame(3_601, $result['current_save_seconds']);
$this->assertSame(10_500_000, $result['changes_since_last_save']);
$this->assertFalse($result['aof_enabled']);
```

Also cover idle/healthy, recent active save, failed last save, missing fields and connection failure. Do not include Redis paths, endpoints or raw error messages.

- [x] **Step 2: Run RED**

```bash
php artisan test --filter=RedisPersistenceInspectorTest
```

Expected: FAIL because `RedisPersistenceInspector` does not exist.

- [x] **Step 3: Implement the typed inspector**

Required public surface:

```php
final class RedisPersistenceInspector
{
    /** @return array{status: string, current_save_seconds: int, last_save_age_seconds: int, changes_since_last_save: int, aof_enabled: bool, message?: string} */
    public function inspect(): array;

    /** @param array<string, mixed> $persistence
     *  @return array{status: string, current_save_seconds: int, last_save_age_seconds: int, changes_since_last_save: int, aof_enabled: bool, message?: string}
     */
    public function summarize(array $persistence): array;
}
```

Use `Illuminate\Redis\RedisManager`, connection `queues`, `INFO persistence`, bounded integers and Russian safe messages. Thresholds:

```php
'redis_persistence' => [
    'running_warning_seconds' => (int) env('REDIS_RDB_RUNNING_WARNING_SECONDS', 120),
    'running_failure_seconds' => (int) env('REDIS_RDB_RUNNING_FAILURE_SECONDS', 900),
    'last_save_warning_seconds' => (int) env('REDIS_RDB_LAST_SAVE_WARNING_SECONDS', 3600),
],
```

- [x] **Step 4: Integrate detailed health without changing readiness**

Add `redis_persistence` to `InfrastructureHealthCheck::run()`. Status `degraded|failed` degrades detailed health but does not make `ready=false` while sessions/queues/locks still answer. `readiness()` remains a lightweight connectivity check.

- [x] **Step 5: Run GREEN and wider health tests**

```bash
php artisan test --filter=RedisPersistenceInspectorTest
php artisan test --filter=InfrastructureHealthCheckTest
./vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Services/Operations/RedisPersistenceInspector.php app/Services/Operations/InfrastructureHealthCheck.php --memory-limit=1G
```

Expected: tests pass; Pint changes no unrelated files; PHPStan returns zero diagnostics.

- [x] **Step 6: Document and commit**

```bash
git add app/Services/Operations/RedisPersistenceInspector.php app/Services/Operations/InfrastructureHealthCheck.php config/cache-architecture.php .env.example tests/Unit/RedisPersistenceInspectorTest.php tests/Unit/InfrastructureHealthCheckTest.php docs/operations/logging-and-health.md docs/environment.md CHANGELOG.md
git commit -m "feat: report Redis persistence health"
git push origin main
```

Actual: inspector tests passed `8` tests / `27` assertions, infrastructure-health integration passed `5` / `21`, CLI health coverage passed `1` / `9`, targeted PHPStan returned zero diagnostics, and `app:health --json` reported the stuck save without changing readiness. Commit evidence is `2227c08`; push is `unresolved`.

---

### Task 3: Execute a Controlled Redis Persistence Recovery

**Status:** `unresolved` before Step 1. No producer stop, signal, restart, backup overwrite, persistence command or Redis/data mutation is authorized by this plan update.

**Files:**
- Modify: `docs/operations/incident-response.md`
- Modify: `docs/operations/backup-and-restore.md`
- Modify: `docs/operations/disaster-recovery.md`
- Modify: `docs/operations/logging-and-health.md`
- Modify: `docs/environment.md`
- Modify: `docs/maintenance/runtime-compatibility.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Task 1 evidence, Task 2 health component, verified protected backup destination, actual process-manager ownership.
- Produces: Terminated stale child, newly verified persistence artifact and documented restart/rollback evidence.

- [ ] **Step 1: Establish the stop boundary**

Confirm all of the following before any service mutation:

```text
new queued import dispatch is paused;
cache-warm production is paused;
current queue counts are recorded;
no migration or database repair is active;
an approved private destination has enough space;
the Redis process owner/restart mechanism is identified;
application maintenance and session-loss impact are approved.
```

If the process owner remains unknown, stop this task with `unresolved`; do not signal PID `4766` or child PID `1456139` directly.

- [ ] **Step 2: Preserve pre-recovery evidence**

Run:

```bash
redis-cli INFO server
redis-cli INFO persistence
redis-cli INFO memory
redis-cli INFO stats
redis-cli DBSIZE
php artisan app:health --json
php artisan app:failed-job-audit --json --samples=0
```

Store only safe aggregate output; do not store keys, session values, job payloads, endpoints or credentials.

- [ ] **Step 3: Create and verify an independent Redis artifact**

Use the operator-approved Redis/aaPanel backup procedure. Verification must include:

```text
non-empty artifact;
owner and private mode;
format validation with the matching redis-check-rdb tool;
recorded size and SHA-256;
copy outside the active Redis data directory;
documented relationship to the current unacknowledged BGSAVE.
```

Do not call the artifact verified if the stuck child prevents consistent capture.

- [ ] **Step 4: Perform the approved graceful recovery**

Stop application producers and Redis through the identified manager, wait for the parent/child lifecycle, then start the same reviewed build/config. Do not use `kill -9` unless a separately authorized incident decision records why graceful stop failed and what evidence was preserved.

- [ ] **Step 5: Verify post-recovery state**

```bash
redis-cli PING
redis-cli INFO persistence
redis-cli INFO memory
php artisan app:health --json
php artisan seasonvar:import --status
php artisan app:failed-job-audit --json --samples=0
```

Expected:

```text
rdb_bgsave_in_progress=0 outside a bounded save;
rdb_last_bgsave_status=ok after a new bounded save;
sessions/queues/locks reachable;
queue and failed-job counts preserved;
no bulk retry, forget or cache flush occurred.
```

- [ ] **Step 6: Decide persistence policy**

Record one explicit decision:

```text
retain reviewed RDB intervals;
or enable AOF everysec with periodic RDB;
or migrate queue/session Redis to a separately managed instance.
```

The decision must cover restart duration, disk growth, fsync behavior, queue/session durability, backup, restore, maxmemory and rollback. Redis 8.8 adoption remains a separate task.

- [ ] **Step 7: Commit evidence**

```bash
git add docs/operations/incident-response.md docs/operations/backup-and-restore.md docs/operations/disaster-recovery.md docs/operations/logging-and-health.md docs/environment.md docs/maintenance/runtime-compatibility.md docs/plans/current-task-plan.md CHANGELOG.md
git commit -m "docs: record Redis persistence recovery"
git push origin main
```

---

### Task 4: Make Process and Scheduler Ownership Singular

**Files:**
- Modify: `deploy/systemd/seasonvar-cache-warm-worker.service`
- Modify: `deploy/systemd/seasonvar-import-worker@.service`
- Modify: `deploy/systemd/seasonvar-title-refresh-worker@.service`
- Modify: `docs/deployment.md`
- Modify: `docs/queues.md`
- Modify: `docs/environment.md`
- Modify: `docs/operations/production-checklist.md`
- Modify: `tests/Unit/QueueWorkerObservabilityTest.php`
- Modify: `tests/Unit/ProductionOperationsDocumentationTest.php`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Recovered Redis and actual systemd/aaPanel evidence.
- Produces: One process manager for each queue pool and cron-only Laravel scheduler ownership.

- [ ] **Step 1: Write documentation contract tests**

Assert the canonical topology contains exactly:

```text
seasonvar-import worker template listens only to seasonvar-import;
seasonvar-title-refresh template listens only to seasonvar-title-refresh;
cache-warm unit listens only to cache-warm-v2;
queued and forever importer profiles remain mutually exclusive;
Laravel scheduler owner is cron schedule:run, not simultaneous schedule:work;
workers start one pool at a time after Redis recovery.
```

- [ ] **Step 2: Run RED**

```bash
php artisan test --filter=ProductionOperationsDocumentationTest
php artisan test --filter=QueueWorkerObservabilityTest
```

Expected: at least the single scheduler-owner assertion fails.

- [ ] **Step 3: Reconcile unit dependencies**

Do not keep fictitious `After=redis.service` or `memcached.service` ordering when those exact units do not exist. Use only confirmed local unit names, or `After=network-online.target` plus application health/restart behavior. Keep:

```text
User=www
Group=www
Restart=always
KillSignal=SIGTERM
queue-specific --queue
timeout < retry_after
bounded --memory, --max-time and --max-jobs
```

- [ ] **Step 4: Remove the duplicate scheduler owner operationally**

Keep the documented per-minute cron `php artisan schedule:run`. Remove/disable only the separately configured aaPanel `schedule:work` process after confirming cron runs, mutexes use `redis-locks`, and daily tasks remain scheduled.

- [ ] **Step 5: Verify static and runtime topology**

```bash
php artisan schedule:list
ps -eo pid,user,args | grep 'artisan queue:work'
ps -eo pid,user,args | grep 'artisan schedule'
php artisan app:health --json
```

Expected: one scheduler mechanism; no duplicate importer profile; worker pool status is truthful.

- [ ] **Step 6: Commit**

```bash
git add deploy/systemd docs/deployment.md docs/queues.md docs/environment.md docs/operations/production-checklist.md tests/Unit/QueueWorkerObservabilityTest.php tests/Unit/ProductionOperationsDocumentationTest.php CHANGELOG.md
git commit -m "ops: unify queue and scheduler ownership"
git push origin main
```

---

### Task 5: Drain Backlog with a Staged Worker Ramp

**Files:**
- Modify: `docs/queues.md`
- Modify: `docs/performance.md`
- Modify: `docs/operations/logging-and-health.md`
- Modify: `docs/operations/incident-response.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Tasks 3–4; preserved queue payloads; canonical 4+8+1 maximum baseline.
- Produces: Measured worker ramp, negative backlog slope and queue latency evidence.

- [ ] **Step 1: Record zero-worker before-state**

Capture queue pending/delayed/reserved, oldest age, Redis memory/ops, load average, SQLite busy/lock errors and current failed-job count.

- [ ] **Step 2: Start exactly one cache-warm worker**

Observe for at least one full `WarmCatalogCaches` timeout window plus heartbeat grace. Verify no false failed state and no SQLite lock storm.

- [ ] **Step 3: Start exactly one title-refresh worker**

Verify browser-triggered title refresh moves from pending to terminal while ordinary title requests stay available.

- [ ] **Step 4: Start exactly one import worker**

Verify page jobs checkpoint, finalizers progress and oldest import age decreases. Do not start a second full/forever importer profile.

- [ ] **Step 5: Ramp only on measured gates**

Add one worker at a time. After each addition require:

```text
backlog slope remains negative;
failed-job rate does not increase materially;
SQLite busy/locked errors remain within recorded baseline;
HTTP warm and cold probes do not regress beyond the approved budget;
Redis persistence remains healthy;
CPU has sustained headroom.
```

Stop at the lowest stable count; 4+8+1 is a ceiling for the current four-core SQLite host, not a target that overrides evidence.

- [ ] **Step 6: Commit operational evidence**

```bash
git add docs/queues.md docs/performance.md docs/operations/logging-and-health.md docs/operations/incident-response.md docs/plans/current-task-plan.md CHANGELOG.md
git commit -m "docs: record staged queue recovery"
git push origin main
```

---

### Task 6: Add Bounded Failed-Job Disposition

**Files:**
- Create: `app/Services/Operations/FailedFinalizerDispositionService.php`
- Create: `app/Console/Commands/DisposeFailedSeasonvarJobs.php`
- Modify: `app/Services/Operations/FailedFinalizerAuditBuilder.php`
- Create: `tests/Feature/FailedFinalizerDispositionCommandTest.php`
- Modify: `tests/Feature/FailedFinalizerAuditBuilderTest.php`
- Modify: `docs/queues.md`
- Modify: `docs/deployment.md`
- Modify: `docs/operations/incident-response.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Exact failed-job IDs and current-state classification from `FailedFinalizerAuditBuilder`.
- Produces: Dry-run-by-default exact-ID disposal command; never retries or clears queues.

- [ ] **Step 1: Write RED command tests**

Required command:

```text
app:failed-job-dispose
  {--ids= : Comma-separated exact failed job IDs, maximum 100}
  {--apply : Delete only currently revalidated forget_candidate rows}
  {--evidence-hash= : SHA-256 of the reviewed audit artifact}
  {--json : Safe JSON result}
```

Assertions:

```php
$this->artisan('app:failed-job-dispose', ['--ids' => (string) $failedId, '--json' => true])
    ->assertSuccessful();

$this->assertDatabaseHas('failed_jobs', ['id' => $failedId]);

$this->artisan('app:failed-job-dispose', [
    '--ids' => (string) $failedId,
    '--apply' => true,
    '--evidence-hash' => str_repeat('a', 64),
])->assertSuccessful();

$this->assertDatabaseMissing('failed_jobs', ['id' => $failedId]);
```

Also assert: maximum 100, positive integer parsing, active target retained, unresolved payload retained, missing/invalid evidence rejected, no `queue:retry`, no dispatch and safe output without payload/exception.

- [ ] **Step 2: Run RED**

```bash
php artisan test --filter=FailedFinalizerDispositionCommandTest
```

Expected: FAIL because command/service do not exist.

- [ ] **Step 3: Implement exact revalidation**

Required service surface:

```php
final class FailedFinalizerDispositionService
{
    /** @param list<int> $failedJobIds
     *  @return array{requested: int, eligible: int, retained: int, missing: int, forgotten: int, applied: bool}
     */
    public function dispose(array $failedJobIds, bool $apply): array;
}
```

Within one short transaction, re-read each failed row and current group/run/claim state. Delete only rows still classified `forget_candidate`; never deserialize payload into PHP objects.

- [ ] **Step 4: Run GREEN and safety suite**

```bash
php artisan test --filter=FailedFinalizerDispositionCommandTest
php artisan test --filter=FailedFinalizerAudit
./vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Services/Operations app/Console/Commands/DisposeFailedSeasonvarJobs.php --memory-limit=1G
```

- [ ] **Step 5: Document operator sequence**

Sequence must be:

```text
audit JSON → store SHA-256 → exact IDs → dry run → repeat audit/count → apply maximum 100 → repeat audit/count.
```

Mass retry, mass forget and queue clear remain prohibited.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Operations/FailedFinalizerDispositionService.php app/Console/Commands/DisposeFailedSeasonvarJobs.php app/Services/Operations/FailedFinalizerAuditBuilder.php tests/Feature/FailedFinalizerDispositionCommandTest.php tests/Feature/FailedFinalizerAuditBuilderTest.php docs/queues.md docs/deployment.md docs/operations/incident-response.md CHANGELOG.md
git commit -m "feat: add bounded failed-job disposition"
git push origin main
```

---

### Task 7: Make Import Storage Retention Independent

**Status:** `superseded_by_importer_master_task_3`. Исполнимый контракт перенесён в [`2026-07-24-seasonvar-importer-improvement-master-plan.md`](2026-07-24-seasonvar-importer-improvement-master-plan.md), где реализован internal empty-payload queued job с общим row/chunk/time budget и выключенным по умолчанию schedule. Описанные ниже отдельная `app:seasonvar-storage-prune` command, другой DTO namespace и `04:31` schedule являются историческим proposal и не должны реализовываться параллельно.

**Files:**
- Create: `app/DTOs/SeasonvarImportStoragePreview.php`
- Modify: `app/Services/Seasonvar/SeasonvarImportStorageMaintenance.php`
- Create: `app/Console/Commands/PruneSeasonvarImportStorage.php`
- Modify: `routes/console.php`
- Modify: `tests/Unit/SeasonvarImportStorageMaintenanceTest.php`
- Create: `tests/Feature/SeasonvarImportStoragePruneCommandTest.php`
- Modify: `config/seasonvar.php`
- Modify: `.env.example`
- Modify: `docs/importer.md`
- Modify: `docs/queues.md`
- Modify: `docs/deployment.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Existing retention windows and `SeasonvarImportStorageMaintenance`.
- Produces: Independent dry-run/apply command and daily bounded schedule; no import dispatch.

- [ ] **Step 1: Write RED preview and command tests**

Required command:

```text
app:seasonvar-storage-prune
  {--dry-run : Count eligible rows without deletion}
  {--max-chunks=10 : Maximum chunks per category, 1..100}
  {--json : Safe JSON}
```

Dry-run must report eligible counts and return zero deleted rows. Apply must skip active runs and retain the newest snapshot per source page.

- [ ] **Step 2: Run RED**

```bash
php artisan test --filter=SeasonvarImportStorageMaintenanceTest
php artisan test --filter=SeasonvarImportStoragePruneCommandTest
```

- [ ] **Step 3: Introduce a typed preview**

```php
final readonly class SeasonvarImportStoragePreview
{
    public function __construct(
        public int $events,
        public int $snapshots,
        public int $titleGroups,
        public int $chunkSize,
        public int $maxChunks,
    ) {}
}
```

Add:

```php
public function preview(int $maxChunks): SeasonvarImportStoragePreview;
public function prune(int $maxChunks = 10): array;
```

Every category deletes no more than `chunk_size * max_chunks` per invocation.

- [ ] **Step 4: Schedule one bounded daily pass**

Add to `routes/console.php`:

```php
Schedule::command('app:seasonvar-storage-prune --max-chunks=10 --json')
    ->dailyAt('04:31')
    ->name('seasonvar-storage-prune')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->when(static fn (): bool => (bool) config('seasonvar.import.storage_maintenance_enabled', true));
```

This task does not add a second import command or run provider HTTP.

- [ ] **Step 5: Run GREEN**

```bash
php artisan test --filter=SeasonvarImportStorage
php artisan test --filter=SeasonvarImportMaintenance
php artisan schedule:list
./vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Commit**

```bash
git add app/DTOs/SeasonvarImportStoragePreview.php app/Services/Seasonvar/SeasonvarImportStorageMaintenance.php app/Console/Commands/PruneSeasonvarImportStorage.php routes/console.php tests/Unit/SeasonvarImportStorageMaintenanceTest.php tests/Feature/SeasonvarImportStoragePruneCommandTest.php config/seasonvar.php .env.example docs/importer.md docs/queues.md docs/deployment.md CHANGELOG.md
git commit -m "feat: schedule bounded importer retention"
git push origin main
```

---

### Task 8: Split Fast Deployment Preflight from Full SQLite Integrity

**Files:**
- Create: `app/Enums/DeploymentCheckProfile.php`
- Modify: `app/Console/Commands/CheckDeploymentReadiness.php`
- Modify: `app/Services/Operations/DeploymentReadinessChecker.php`
- Modify: `tests/Feature/CheckDeploymentReadinessCommandTest.php`
- Modify: `tests/Feature/CheckDeploymentReadinessUnavailableDatabaseTest.php`
- Modify: `docs/deployment.md`
- Modify: `docs/operations/production-checklist.md`
- Modify: `docs/operations/backup-and-restore.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Existing `DeploymentCheck` DTO and full integrity checks.
- Produces: Explicit `fast|full` profiles; existing default remains `full` for backward compatibility.

- [ ] **Step 1: Write RED profile tests**

Required enum:

```php
enum DeploymentCheckProfile: string
{
    case Fast = 'fast';
    case Full = 'full';
}
```

Required CLI:

```text
app:deployment-check
  {--profile=full : full or fast}
  {--json : machine-readable JSON}
```

Assert `fast` skips only `sqlite_integrity`, reports it as `skipped` with a stable safe reason, and still checks environment, debug, logging, migrations, required indexes, search, transports, failed jobs and importer process.

- [ ] **Step 2: Run RED**

```bash
php artisan test --filter=CheckDeploymentReadinessCommandTest
```

- [ ] **Step 3: Implement profile-aware checks**

Change surface:

```php
/** @return list<DeploymentCheck> */
public function check(DeploymentCheckProfile $profile = DeploymentCheckProfile::Full): array;
```

Do not add SQL timeout pragmas that mutate the connection globally. Full mode continues `PRAGMA quick_check` and `PRAGMA foreign_key_check`; fast mode never starts them.

- [ ] **Step 4: Update operator flow**

Activation preflight:

```bash
php artisan app:deployment-check --profile=fast --json
```

Maintenance-window integrity:

```bash
php artisan app:deployment-check --profile=full --json
```

Full completion time is observed, not given a fake success timeout.

- [ ] **Step 5: Run GREEN**

```bash
php artisan test --filter=CheckDeploymentReadiness
./vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Enums/DeploymentCheckProfile.php app/Console/Commands/CheckDeploymentReadiness.php app/Services/Operations/DeploymentReadinessChecker.php --memory-limit=1G
```

- [ ] **Step 6: Commit**

```bash
git add app/Enums/DeploymentCheckProfile.php app/Console/Commands/CheckDeploymentReadiness.php app/Services/Operations/DeploymentReadinessChecker.php tests/Feature/CheckDeploymentReadinessCommandTest.php tests/Feature/CheckDeploymentReadinessUnavailableDatabaseTest.php docs/deployment.md docs/operations/production-checklist.md docs/operations/backup-and-restore.md CHANGELOG.md
git commit -m "feat: split deployment check profiles"
git push origin main
```

---

### Task 9: Establish Verified Backup and Restore Rehearsal

**Files:**
- Modify: `docs/operations/backup-and-restore.md`
- Modify: `docs/operations/disaster-recovery.md`
- Modify: `docs/operations/production-checklist.md`
- Modify: `docs/deployment.md`
- Modify: `docs/environment.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Paused writers, approved private destinations and actual SQLite/private-file state.
- Produces: Verified backup manifest, off-host copy evidence and isolated restore rehearsal.

- [ ] **Step 1: Classify existing artifacts**

For every existing backup record:

```text
category;
creation time;
database size;
checksum availability;
integrity result;
private-file relationship;
same-volume/off-host state;
restore-tested state;
retention owner.
```

Do not delete any artifact during classification.

- [ ] **Step 2: Create a consistent database backup**

Use SQLite online backup API/CLI or stop all writers and capture a checkpoint-consistent state. Copying only the live main file while WAL writes continue is prohibited.

- [ ] **Step 3: Verify the artifact**

On the backup, not the live database:

```bash
sqlite3 backup.sqlite 'PRAGMA quick_check;'
sqlite3 backup.sqlite 'PRAGMA foreign_key_check;'
sha256sum backup.sqlite
stat backup.sqlite
```

Expected: quick check `ok`, zero FK rows, non-zero size and recorded SHA-256. Real tracked documentation stores no private path or checksum if that value is considered protected evidence.

- [ ] **Step 4: Rehearse restore in an isolated directory**

Boot the restored database with production code but isolated cache/session/queue/storage. Run:

```bash
php artisan migrate:status
php artisan app:deployment-check --profile=full --json
php artisan test --filter=ProductionBootSmokeTest
```

Do not connect the rehearsal to production Redis, mail, payments or providers.

- [ ] **Step 5: Approve retention**

Choose exact daily/weekly/release/pre-migration windows with the data owner. Preserve:

```text
latest verified backup;
only rollback backup for active release;
incident/legal hold evidence;
backup used by an open migration window.
```

Only then perform a separately reviewed bounded cleanup.

- [ ] **Step 6: Commit documentation**

```bash
git add docs/operations/backup-and-restore.md docs/operations/disaster-recovery.md docs/operations/production-checklist.md docs/deployment.md docs/environment.md docs/plans/current-task-plan.md CHANGELOG.md
git commit -m "docs: verify backup and restore procedure"
git push origin main
```

---

### Task 10: Separate Curated Demo Data from Load-Test Corpus

**Files:**
- Create: `app/Enums/DemoDataProfile.php`
- Modify: `app/DTOs/DemoData/DemoDataOptions.php`
- Modify: `app/Services/DemoData/DemoDataOrchestrator.php`
- Modify: `app/Services/DemoData/DemoDataAuditor.php`
- Modify: `database/seeders/PortalDemoSeeder.php`
- Modify: `config/demo-data.php`
- Modify: `.env.example`
- Modify: `tests/Feature/DemoData/PortalDemoSeederTest.php`
- Modify: `tests/Feature/DemoData/DemoCommunityStageTest.php`
- Create: `tests/Unit/DemoData/DemoDataProfileTest.php`
- Modify: `docs/development.md`
- Modify: `docs/environment.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Existing deterministic stages and exact demo email allowlist.
- Produces: Explicit `curated|load_test` profile; production cannot seed either profile accidentally.

- [ ] **Step 1: Write RED profile tests**

Required enum:

```php
enum DemoDataProfile: string
{
    case Curated = 'curated';
    case LoadTest = 'load_test';
}
```

Curated defaults:

```text
10 users;
maximum 200 title states per user;
maximum 120 comments per user;
maximum 300 reactions per user;
maximum 80 reviews per user;
timestamps bounded to now minus 365 days through now.
```

Load-test keeps configurable larger counts but requires `APP_ENV=local`, explicit `DEMO_DATA_PROFILE=load_test` and a database path different from the verified production database.

- [ ] **Step 2: Run RED**

```bash
php artisan test --filter=DemoDataProfileTest
php artisan test --filter=PortalDemoSeederTest
```

- [ ] **Step 3: Implement profile-bound options**

Extend `DemoDataOptions` with:

```php
public DemoDataProfile $profile;
public int $maxTitleStatesPerUser;
public int $maxCommentsPerUser;
public int $maxReactionsPerUser;
public int $maxReviewsPerUser;
```

All stage loops must use these exact bounds; no future timestamp may exceed `now()`.

- [ ] **Step 4: Preserve exact allowlist and idempotency**

Keep `user1@example.com` through `userN@example.com` as the only demo identity. Repeat seeding must update the same bounded corpus without multiplying rows.

- [ ] **Step 5: Run GREEN and full demo suite**

```bash
php artisan test --filter=DemoData
./vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Enums/DemoDataProfile.php app/DTOs/DemoData app/Services/DemoData database/seeders/PortalDemoSeeder.php --memory-limit=1G
```

- [ ] **Step 6: Document the visitor/development change**

README must state the curated default and separate load-test profile in Russian. Do not claim existing production rows were removed.

- [ ] **Step 7: Commit**

```bash
git add app/Enums/DemoDataProfile.php app/DTOs/DemoData/DemoDataOptions.php app/Services/DemoData/DemoDataOrchestrator.php app/Services/DemoData/DemoDataAuditor.php database/seeders/PortalDemoSeeder.php config/demo-data.php .env.example tests/Feature/DemoData tests/Unit/DemoData docs/development.md docs/environment.md README.md CHANGELOG.md
git commit -m "feat: separate curated and load-test demo data"
git push origin main
```

---

### Task 11: Reconcile Existing Demo Corpus without Touching Real Users

**Files:**
- Create: `app/Services/DemoData/DemoDataReconciliationPlanner.php`
- Create: `app/Console/Commands/AuditDemoDataScale.php`
- Create: `tests/Feature/DemoData/DemoDataScaleAuditCommandTest.php`
- Modify: `docs/development.md`
- Modify: `docs/operations/backup-and-restore.md`
- Modify: `docs/operations/incident-response.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Task 10 exact allowlist/profile and Task 9 verified backup.
- Produces: Read-only per-domain reconciliation plan; no automatic deletion.

- [ ] **Step 1: Write RED audit tests**

Required command:

```text
demo:audit-scale
  {--json : Safe aggregate output}
```

Output:

```php
[
    'users' => 100,
    'domains' => [
        'comments' => ['rows' => 3_707_221, 'future_rows' => 0],
        'comment_reactions' => ['rows' => 9_265_514, 'future_rows' => 0],
    ],
    'non_demo_rows' => ['comments' => 0],
    'mutations' => 0,
]
```

Tests use small fixtures and assert no row changes.

- [ ] **Step 2: Run RED**

```bash
php artisan test --filter=DemoDataScaleAuditCommandTest
```

- [ ] **Step 3: Implement grouped allowlisted counts**

The planner must use exact known demo user IDs and schema-aware grouped counts. It must not output emails, names, comment bodies, request text, private IDs beyond aggregate counts or arbitrary SQL.

- [ ] **Step 4: Run GREEN**

```bash
php artisan test --filter=DemoDataScaleAuditCommandTest
./vendor/bin/pint --dirty --format agent
```

- [ ] **Step 5: Create a separate destructive-change proposal**

Using audit output, write a task-specific design before any deletion. The design must enumerate every dependent table, account deletion/retention rule, real-user separation, backup, writer pause, compact-copy strategy and rollback. This master plan does not authorize deletion.

- [ ] **Step 6: Commit audit capability**

```bash
git add app/Services/DemoData/DemoDataReconciliationPlanner.php app/Console/Commands/AuditDemoDataScale.php tests/Feature/DemoData/DemoDataScaleAuditCommandTest.php docs/development.md docs/operations/backup-and-restore.md docs/operations/incident-response.md docs/plans/current-task-plan.md CHANGELOG.md
git commit -m "feat: audit demo data scale"
git push origin main
```

---

### Task 12: Compact SQLite by Verified Copy-and-Swap

**Files:**
- Modify: `docs/deployment.md`
- Modify: `docs/operations/backup-and-restore.md`
- Modify: `docs/operations/rollback-runbook.md`
- Modify: `docs/operations/disaster-recovery.md`
- Modify: `docs/operations/production-checklist.md`
- Modify: `docs/environment.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Tasks 9 and 11; approved demo/technical cleanup; stopped writers.
- Produces: Smaller verified SQLite file or an honest no-op decision.

- [ ] **Step 1: Measure reclaimable space**

Record:

```sql
PRAGMA page_count;
PRAGMA freelist_count;
PRAGMA page_size;
```

If freelist and approved deletion results do not justify compaction, record `not_performed` and stop.

- [ ] **Step 2: Pause every writer**

Stop PHP-FPM writes, queue workers, importer, scheduler mutations and admin write access through documented managers. Confirm zero reserved jobs/claims and maintenance state.

- [ ] **Step 3: Create a compact copy**

Use SQLite-supported copy/backup operation into a new private file on the same filesystem only when free space exceeds source size plus safety margin. Never compact the only live file in place.

- [ ] **Step 4: Verify new file**

Run full quick/FK checks, migration status, row counts for protected domains, FTS counts and SHA-256. Compare against the pre-copy manifest.

- [ ] **Step 5: Perform reversible swap**

Preserve the previous database as rollback artifact, atomically replace the configured database file within the stopped-writer window, restore owner/group/mode and boot with fast preflight first.

- [ ] **Step 6: Verify and document**

Check home, search, title, auth, profile, library, comments, reviews, administration, importer status, queue state and full integrity. Keep the old file until the verification window closes.

- [ ] **Step 7: Commit evidence**

```bash
git add docs/deployment.md docs/operations/backup-and-restore.md docs/operations/rollback-runbook.md docs/operations/disaster-recovery.md docs/operations/production-checklist.md docs/environment.md docs/plans/current-task-plan.md CHANGELOG.md
git commit -m "docs: record SQLite compaction evidence"
git push origin main
```

---

### Task 13: Build Reproducible Release-Directory Deployment

**Files:**
- Create: `scripts/verify-release-artifacts.sh`
- Create: `tests/Unit/ReleaseArtifactScriptTest.php`
- Modify: `scripts/ci-check.sh`
- Modify: `docs/deployment.md`
- Modify: `docs/operations/rollback-runbook.md`
- Modify: `docs/operations/production-checklist.md`
- Modify: `docs/maintenance/runtime-compatibility.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Exact commit, `composer.lock`, `package-lock.json`, built Vite manifest.
- Produces: Read-only artifact verifier and documented release-directory activation.

- [ ] **Step 1: Write RED script-contract test**

The script must reject:

```text
missing public/build/manifest.json;
manifest path traversal;
missing referenced asset;
empty referenced asset;
tracked .env;
writable executable upload;
dirty intended release source.
```

- [ ] **Step 2: Run RED**

```bash
php artisan test --filter=ReleaseArtifactScriptTest
```

- [ ] **Step 3: Implement verifier**

Shell interface:

```bash
bash scripts/verify-release-artifacts.sh
```

Exit `0` only when Composer/npm lockfiles exist, manifest JSON is valid, every `file`/`css`/`assets` entry resolves below `public/build`, and no source map is published unless explicitly approved.

- [ ] **Step 4: Define release sequence**

Document:

```text
checkout exact main commit in new release directory;
composer install --no-dev --classmap-authoritative --no-interaction;
npm ci;
npm run build;
artifact verification;
fast deployment preflight against isolated config;
approved migrations with writer pause/backup;
config/route/view cache as runtime user;
atomic current symlink switch after nginx/aaPanel compatibility proof;
graceful PHP-FPM reload and queue restart;
smoke and rollback window.
```

- [ ] **Step 5: Verify**

```bash
php artisan test --filter=ReleaseArtifactScriptTest
bash scripts/verify-release-artifacts.sh
bash scripts/ci-check.sh full
```

- [ ] **Step 6: Commit**

```bash
git add scripts/verify-release-artifacts.sh tests/Unit/ReleaseArtifactScriptTest.php scripts/ci-check.sh docs/deployment.md docs/operations/rollback-runbook.md docs/operations/production-checklist.md docs/maintenance/runtime-compatibility.md CHANGELOG.md
git commit -m "ops: verify immutable release artifacts"
git push origin main
```

---

### Task 14: Enforce Runtime Permission Policy

**Files:**
- Create: `scripts/check-runtime-permissions.sh`
- Create: `tests/Unit/RuntimePermissionScriptTest.php`
- Modify: `docs/deployment.md`
- Modify: `docs/storage.md`
- Modify: `docs/operations/production-checklist.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Release directory and known PHP-FPM/deployment group.
- Produces: Read-only permission check; no recursive chmod/chown.

- [ ] **Step 1: Write RED script tests**

Fixtures must prove the script rejects:

```text
mode 777 under storage or bootstrap/cache;
root-owned compiled view when expected runtime user is www;
world-readable private upload;
executable uploaded file;
missing write permission on required runtime directory.
```

- [ ] **Step 2: Implement read-only checker**

Interface:

```bash
bash scripts/check-runtime-permissions.sh www www
```

The script only reports paths relative to repository root and exits non-zero; it never changes ownership or modes.

- [ ] **Step 3: Add deployment repair sequence**

Document exact policy:

```text
runtime directories owned by deployment/runtime-compatible group;
setgid on shared writable directories;
private files 0660 and directories 2770 where current storage contract requires;
compiled caches created by runtime-compatible user;
public build readable but not runtime-writable;
no recursive 777.
```

Actual chmod/chown remains an operator step after target resolution and backup assessment.

- [ ] **Step 4: Verify**

```bash
php artisan test --filter=RuntimePermissionScriptTest
bash scripts/check-runtime-permissions.sh www www
git diff --check
```

- [ ] **Step 5: Commit**

```bash
git add scripts/check-runtime-permissions.sh tests/Unit/RuntimePermissionScriptTest.php docs/deployment.md docs/storage.md docs/operations/production-checklist.md CHANGELOG.md
git commit -m "ops: enforce runtime permission checks"
git push origin main
```

---

### Task 15: Tune PHP-FPM and OPcache from Measurements

**Files:**
- Modify: `docs/environment.md`
- Modify: `docs/deployment.md`
- Modify: `docs/performance.md`
- Modify: `docs/maintenance/runtime-compatibility.md`
- Modify: `docs/operations/logging-and-health.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: PHP-FPM status/slowlog, OPcache status, stable queue load.
- Produces: Dated reviewed pool/cache settings; repository does not overwrite panel config.

- [ ] **Step 1: Capture metrics under stable load**

Record:

```text
FPM active/idle/total/max children;
listen queue and max listen queue;
slow requests;
average and p95 worker RSS;
OPcache cached scripts, max scripts, hit rate, free/wasted memory and restarts;
HTTP p50/p95 for warm/cold representative routes;
SQLite busy/lock events.
```

- [ ] **Step 2: Calculate a safe FPM ceiling**

Use both:

```text
memory ceiling = available application memory / measured p95 worker RSS;
CPU/DB ceiling = lowest count before HTTP latency or SQLite contention regresses.
```

Do not retain `60` merely because RAM is available.

- [ ] **Step 3: Calculate OPcache settings**

Choose the first supported prime-sized `opcache.max_accelerated_files` above the measured hot script set; evaluate `20000` or `30000`, not repository file count alone. Increase `memory_consumption` only when measured free/wasted data justifies it.

- [ ] **Step 4: Apply through aaPanel/PHP-FPM owner**

Preserve old configuration export, syntax-test FPM config, graceful reload and immediate status/HTTP rollback checks.

- [ ] **Step 5: Verify**

Repeat the same metric window. Accept only if queue length, p95 latency, SQLite errors and OPcache restarts do not regress.

- [ ] **Step 6: Commit evidence**

```bash
git add docs/environment.md docs/deployment.md docs/performance.md docs/maintenance/runtime-compatibility.md docs/operations/logging-and-health.md docs/plans/current-task-plan.md CHANGELOG.md
git commit -m "docs: record PHP-FPM and OPcache tuning"
git push origin main
```

---

### Task 16: Pin an LTS Node Build Runtime

**Files:**
- Create: `.nvmrc`
- Modify: `package.json`
- Modify: `.github/workflows/ci.yml`
- Modify: `docs/development.md`
- Modify: `docs/deployment.md`
- Modify: `docs/maintenance/runtime-compatibility.md`
- Modify: `docs/maintenance/update-decisions.md`
- Modify: `docs/maintenance/technical-debt.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Existing lock v3 and Vite 8 engine requirements.
- Produces: Exact reviewed Node 24 LTS pin or an explicit wait-for-Node-26-LTS decision.

- [ ] **Step 1: Record update decision before changing files**

Decision must compare:

```text
Node 26.4.0 Current;
latest available Node 24 LTS patch at execution time;
npm version and lock v3 compatibility;
Vite/Tailwind/Playwright engines;
CI and production build hosts;
rollback to prior immutable assets.
```

- [ ] **Step 2: Test Node 24 in an isolated release directory**

Run:

```bash
node --version
npm --version
npm ci
npm audit
npm run build
npm run test:browser
```

Expected: locked install without lock rewrite, zero audit vulnerabilities, successful build and browser suite.

- [ ] **Step 3: Add the selected exact pin**

`.nvmrc` contains the exact approved Node 24 patch. `package.json` contains a compatible `engines.node` range that includes the pin and excludes EOL majors. CI uses the same exact version.

- [ ] **Step 4: Verify clean reproducibility**

Run a second clean `npm ci` and compare manifest/chunk inventory. Hashes may change only if runtime output legitimately differs; browser behavior must remain stable.

- [ ] **Step 5: Commit**

```bash
git add .nvmrc package.json .github/workflows/ci.yml docs/development.md docs/deployment.md docs/maintenance/runtime-compatibility.md docs/maintenance/update-decisions.md docs/maintenance/technical-debt.md CHANGELOG.md
git commit -m "build: pin Node LTS runtime"
git push origin main
```

---

### Task 17: Finalize the Laravel/Sanctum Patch Group

**Files:**
- Modify: `composer.lock`
- Modify: `docs/maintenance/dependency-inventory.md`
- Modify: `docs/maintenance/runtime-compatibility.md`
- Modify: `docs/maintenance/update-decisions.md`
- Modify: `docs/deployment.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Existing isolated lock diff for Laravel `13.21.1`, Sanctum `4.3.3`, Serializable Closure `2.0.15`, Laravel MCP `0.9.1`.
- Produces: Reviewed patch-only lock commit; no broad Composer resolution.

- [ ] **Step 1: Reconfirm exact lock ownership**

```bash
git diff -- composer.lock
composer show --locked laravel/framework laravel/sanctum laravel/serializable-closure laravel/mcp
composer audit
composer check-platform-reqs
```

If the diff contains any additional package, stop and split it into a separate decision.

- [ ] **Step 2: Record four update decisions**

For each package record version, purpose, release reason, affected domains, queue/session/serialization impact, deployment, rollback and tests.

- [ ] **Step 3: Verify**

```bash
composer validate --strict
composer audit
composer check-platform-reqs
./vendor/bin/pint --test
php artisan test
bash scripts/ci-check.sh full
```

- [ ] **Step 4: Commit only the reviewed group**

```bash
git add composer.lock docs/maintenance/dependency-inventory.md docs/maintenance/runtime-compatibility.md docs/maintenance/update-decisions.md docs/deployment.md CHANGELOG.md
git commit -m "chore: apply Laravel patch updates"
git push origin main
```

---

### Task 18: Apply Frontend Patch Groups Separately

**Files:**
- Modify: `package.json`
- Modify: `package-lock.json`
- Modify: `docs/maintenance/dependency-inventory.md`
- Modify: `docs/maintenance/update-decisions.md`
- Modify: `docs/deployment.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Task 16 LTS build runtime.
- Produces: Three independently revertible commits.

- [ ] **Step 1: Tailwind coherent patch**

Update only:

```text
tailwindcss 4.3.2 → reviewed latest 4.3.x;
@tailwindcss/vite 4.3.2 → the same reviewed 4.3.x.
```

Run build, CSS bundle comparison, translation/responsive/browser suite and commit:

```bash
git commit -m "chore: update Tailwind patch versions"
```

- [ ] **Step 2: Vite patch**

Update only Vite within `8.1.x`. Verify manifest schema, entrypoints, chunk loading, no source maps, browser suite and commit:

```bash
git commit -m "chore: update Vite patch version"
```

- [ ] **Step 3: FontAwesome patch**

Update only `@fortawesome/fontawesome-free` within `7.3.x`. Verify local fonts/icons, missing-glyph console errors, CSP asset paths and commit:

```bash
git commit -m "chore: update FontAwesome patch version"
```

- [ ] **Step 4: Push each commit**

```bash
git push origin main
```

Do not include `concurrently` 10 in any patch commit.

---

### Task 19: Prepare PHPUnit 13 without Immediate Adoption

**Files:**
- Modify: `phpunit.xml`
- Modify: `docs/maintenance/update-decisions.md`
- Modify: `docs/maintenance/technical-debt.md`
- Modify: `docs/development.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: PHPUnit `12.5.31`.
- Produces: Deprecation-clean PHPUnit 12 suite and a separate go/no-go decision.

- [ ] **Step 1: Run the full suite with deprecation visibility**

Use PHPUnit 12-supported display/fail settings in an isolated invocation. Record every project-owned deprecation by class/test and distinguish vendor-only output.

- [ ] **Step 2: Fix project-owned PHPUnit deprecations**

Each fix uses a focused RED/GREEN test and no production behavior change. Commit by coherent test subsystem.

- [ ] **Step 3: Require zero project-owned deprecations**

Only after this gate, create a separate PHPUnit 13 branchless experiment using a temporary lock copy outside the live checkout.

- [ ] **Step 4: Record decision**

Choose `update` only if all 1,453+ tests, process-isolated storage tests, coverage configuration and CI runner pass. Otherwise retain 12.5 with the supported-until date and exact blocker.

- [ ] **Step 5: Commit preparation**

```bash
git add phpunit.xml docs/maintenance/update-decisions.md docs/maintenance/technical-debt.md docs/development.md CHANGELOG.md
git commit -m "test: prepare suite for PHPUnit 13"
git push origin main
```

---

### Task 20: Establish Performance Budgets and Route Telemetry

**Files:**
- Create: `app/Services/Operations/PublicRoutePerformanceProbe.php`
- Create: `app/Console/Commands/MeasurePublicRoutePerformance.php`
- Create: `tests/Unit/PublicRoutePerformanceProbeTest.php`
- Create: `tests/Feature/PublicRoutePerformanceCommandTest.php`
- Modify: `config/cache-architecture.php`
- Modify: `.env.example`
- Modify: `docs/performance.md`
- Modify: `docs/operations/logging-and-health.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Exact same-origin allowlisted route names and public cache state.
- Produces: Bounded operator-only measurements without provider URLs or private routes.

- [ ] **Step 1: Write RED tests**

Command:

```text
app:measure-public-routes
  {--profile=warm : cold or warm}
  {--json : safe JSON}
```

Allowlisted routes:

```text
home;
titles.index;
search.index with an empty safe query;
one explicitly configured public title slug.
```

Reject redirects to private/external origins, authenticated cookies and arbitrary URL input.

- [ ] **Step 2: Implement bounded measurement**

Result fields:

```php
[
    'route' => 'titles.index',
    'status' => 200,
    'ttfb_ms' => 72,
    'total_ms' => 81,
    'transferred_bytes' => 34_197,
    'profile' => 'warm',
]
```

Do not persist full URLs, HTML, headers containing cookies or response bodies.

- [ ] **Step 3: Define initial budgets**

Budgets are provisional observations, not SLA:

```text
warm home/titles/title p95 under 250 ms;
cold title p95 under 1,500 ms;
gzip HTML under 80 KiB per measured public route;
zero 5xx and zero external redirect.
```

- [ ] **Step 4: Verify**

```bash
php artisan test --filter=PublicRoutePerformance
./vendor/bin/pint --dirty --format agent
php artisan app:measure-public-routes --profile=warm --json
```

- [ ] **Step 5: Commit**

```bash
git add app/Services/Operations/PublicRoutePerformanceProbe.php app/Console/Commands/MeasurePublicRoutePerformance.php tests/Unit/PublicRoutePerformanceProbeTest.php tests/Feature/PublicRoutePerformanceCommandTest.php config/cache-architecture.php .env.example docs/performance.md docs/operations/logging-and-health.md CHANGELOG.md
git commit -m "feat: measure public route performance"
git push origin main
```

---

### Task 21: Optimize Cold Catalog and Title Paths from Query Evidence

**Files:**
- Modify only after profiling: `app/Services/Catalog/CatalogTitlePageBuilder.php`
- Modify only after profiling: `app/Services/Catalog/CatalogTitleQuery.php`
- Modify only after profiling: `app/Services/Catalog/CatalogTitlesPageBuilder.php`
- Modify only after profiling: `app/Services/Catalog/CatalogPublicDiscoveryQuery.php`
- Test: `tests/Feature/CatalogPageTest.php`
- Test: `tests/Feature/CatalogQueryBudgetTest.php`
- Modify: `docs/performance.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Task 20 budgets and exact query traces.
- Produces: One independently measured query optimization per commit.

- [ ] **Step 1: Capture query traces**

For home, `/titles`, a cold title and warm repeat, record query count, SQL fingerprint, duration and repeated relationship access. Do not log bound private values.

- [ ] **Step 2: Select one dominant query family**

The first change must target the largest measured contribution. Do not combine title page, catalog sorting and discovery in one commit.

- [ ] **Step 3: Write a failing query-budget regression**

Example contract:

```php
$result = $this->get(route('titles.show', $title));

$result->assertOk();
$this->assertLessThanOrEqual(18, $this->recordedQueries());
$this->assertSame($expectedEpisodeIds, $result->viewData('showView')->episodeIds());
```

The exact budget comes from baseline minus the identified duplicate family, not an arbitrary lower number.

- [ ] **Step 4: Apply the smallest query change**

Use existing eager/grouped/aggregate/query-builder patterns. Preserve filters, visibility, ordering, pagination, locales, cache dimensions and SEO.

- [ ] **Step 5: Verify behavior and performance**

```bash
php artisan test --filter=CatalogQueryBudgetTest
php artisan test --filter=CatalogPageTest
./vendor/bin/pint --dirty --format agent
php artisan app:measure-public-routes --profile=cold --json
php artisan app:measure-public-routes --profile=warm --json
```

- [ ] **Step 6: Commit each optimization**

```bash
git commit -m "perf: reduce catalog cold-path queries"
git push origin main
```

Repeat Tasks 21.1–21.6 for the next measured family; never combine unrelated speculative changes.

---

### Task 22: Decompose the Largest Classes by Stable Interfaces

**Files:**
- Candidate: `app/Services/Catalog/CatalogStatsPageBuilder.php`
- Candidate: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Candidate: `app/Services/Seasonvar/SeasonvarImportPipeline.php`
- Candidate: `app/Services/Catalog/CatalogSeoBuilder.php`
- Candidate: `app/Services/Seasonvar/SeasonvarCatalogParser.php`
- Candidate: `app/Services/Tags/TagService.php`
- Candidate: `app/Services/Catalog/CatalogTitleRecommendationBuilder.php`
- Candidate: `app/Livewire/CatalogAdministrationManager.php`
- Test: existing feature/unit tests for the selected candidate
- Modify: `docs/architecture.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Characterization tests and existing public methods.
- Produces: Smaller application-owned query/action/stage classes without public-contract drift.

- [ ] **Step 1: Choose exactly one candidate**

Start with `CatalogStatsPageBuilder` unless a newer query/incident profile gives another class higher risk. Record current public methods, constructor dependencies, outputs and tests.

- [ ] **Step 2: Write characterization tests**

Serialize stable DTO/array shape, ordering, localization, visibility and empty/error states. Tests must fail if a field disappears or identity changes.

- [ ] **Step 3: Extract one responsibility**

Example first extraction:

```php
final class CatalogOperationalTimelineBuilder
{
    /** @return list<array<string, mixed>> */
    public function build(CatalogStatsCriteria $criteria): array;
}
```

The original builder delegates to the new class; route/view/public shape remains unchanged.

- [ ] **Step 4: Verify**

```bash
php artisan test --filter=CatalogStats
./vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/Services/Catalog/CatalogStatsPageBuilder.php app/Services/Catalog/CatalogOperationalTimelineBuilder.php --memory-limit=1G
```

- [ ] **Step 5: Commit one extraction**

```bash
git commit -m "refactor: extract catalog operational timeline"
git push origin main
```

Repeat one responsibility/commit at a time. Do not combine importer, SEO, tags, recommendations or Livewire decomposition.

---

### Task 23: Ratchet Strict Types and Full Larastan

**Files:**
- Modify: bounded PHP files lacking `declare(strict_types=1);`
- Modify: `phpstan.neon`
- Modify: `scripts/ci-check.sh`
- Modify: `docs/CODE_STANDARDS.md`
- Modify: `docs/maintenance/technical-debt.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: 95 non-strict app files and existing required PHPStan profile.
- Produces: Module-by-module zero diagnostics; no global suppression baseline.

- [ ] **Step 1: Inventory by domain**

```bash
comm -23 <(find app -type f -name '*.php' | sort) <(rg -l '^declare\\(strict_types=1\\);' app --glob '*.php' | sort)
```

Group files into models, catalog queries, importer, Google integrations, console, API and view components.

- [ ] **Step 2: Complete the first bounded catalog-definition batch**

The first batch is limited to:

```text
app/DTOs/CatalogDirectoryDefinition.php
app/Enums/CatalogFilterType.php
app/Enums/CatalogPublicationType.php
app/Enums/CatalogSort.php
```

Add only `declare(strict_types=1);` and fixes proven necessary by the named tests/static analysis. Do not expand into requests, models, queries or importer code in this commit.

- [ ] **Step 3: Run RED diagnostic**

Run PHPStan on the four selected files and record exact diagnostics. Do not create a broad ignore file.

- [ ] **Step 4: Fix minimal typing issues**

Use explicit scalar normalization at request/provider boundaries and typed domain methods internally.

- [ ] **Step 5: Verify and commit**

```bash
php artisan test --filter=CatalogTitlesRequestTest
php artisan test --filter=CatalogPageTest
./vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse app/DTOs/CatalogDirectoryDefinition.php app/Enums/CatalogFilterType.php app/Enums/CatalogPublicationType.php app/Enums/CatalogSort.php --memory-limit=1G
git add app/DTOs/CatalogDirectoryDefinition.php app/Enums/CatalogFilterType.php app/Enums/CatalogPublicationType.php app/Enums/CatalogSort.php docs/CODE_STANDARDS.md docs/maintenance/technical-debt.md CHANGELOG.md
git commit -m "refactor: add strict types to catalog definitions"
git push origin main
```

Before every later module batch, append its exact file list, characterization tests, PHPStan command and single-purpose commit message to `docs/plans/current-task-plan.md`. The inventory command does not authorize a broad repository rewrite.

---

### Task 24: Tighten CSP from Report-Only to Enforced Stages

**Files:**
- Modify: `app/Http/Middleware/AddSecurityHeaders.php`
- Modify: `config/security.php`
- Modify: `.env.example`
- Modify: `tests/Feature/SecurityHeadersTest.php`
- Modify: `tests/browser/catalog.spec.js`
- Modify: `tests/browser/player-lifecycle.spec.js`
- Modify: `docs/security.md`
- Modify: `docs/deployment.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Current report-only policy and local assets.
- Produces: Configurable report-only/enforce mode with exact allowlists.

- [ ] **Step 1: Write RED config/header tests**

Required config:

```php
'csp' => [
    'mode' => env('SECURITY_CSP_MODE', 'report_only'),
    'image_hosts' => [],
    'media_hosts' => ['11cdn.org', '*.11cdn.org'],
    'connect_hosts' => ['11cdn.org', '*.11cdn.org'],
],
```

Assert `report_only` emits only `Content-Security-Policy-Report-Only`; `enforce` emits only `Content-Security-Policy`.

- [ ] **Step 2: Collect violation evidence**

Exercise public/admin/player/auth/upload flows without logging URLs or private payloads. Identify whether Plyr requires `style-src-attr 'unsafe-inline'` and whether any broad `img-src https:` source is actually needed.

- [ ] **Step 3: Narrow one directive at a time**

Order:

```text
object/base/frame/form;
script;
connect;
media;
img;
style/style-src-attr;
enforcement.
```

- [ ] **Step 4: Verify**

```bash
php artisan test --filter=SecurityHeadersTest
npx playwright test tests/browser/catalog.spec.js tests/browser/player-lifecycle.spec.js
npm run build
```

- [ ] **Step 5: Commit each directive group**

```bash
git commit -m "security: enforce CSP document boundaries"
git commit -m "security: restrict CSP script sources"
git commit -m "security: restrict CSP connection sources"
git commit -m "security: restrict CSP media sources"
git commit -m "security: restrict CSP image sources"
git commit -m "security: restrict CSP style sources"
git commit -m "security: enable CSP enforcement"
git push origin main
```

Each command represents one isolated commit after its own tests; never run or combine all seven without the corresponding staged directive change. Do not enable enforcement until browser evidence is clean for all affected flows.

---

### Task 25: Add an Honest Optional Alert Boundary

**Files:**
- Create: `app/Contracts/Operations/OperationalAlertTransport.php`
- Create: `app/Services/Operations/NullOperationalAlertTransport.php`
- Create: `app/Services/Operations/OperationalAlertDispatcher.php`
- Create: `app/Notifications/OperationalHealthDegraded.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `config/notifications.php`
- Modify: `.env.example`
- Create: `tests/Unit/OperationalAlertDispatcherTest.php`
- Create: `tests/Feature/OperationalHealthAlertTest.php`
- Modify: `docs/operations/logging-and-health.md`
- Modify: `docs/operations/incident-response.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Safe aggregate health/queue/Redis/disk states.
- Produces: Optional deduplicated alert transport; default remains disabled/null.

- [ ] **Step 1: Write RED dispatcher tests**

Required interface:

```php
interface OperationalAlertTransport
{
    /** @param array<string, scalar|null> $context */
    public function send(string $code, string $severity, array $context): void;
}
```

Dispatcher must deduplicate by stable code/severity for a configured interval, omit exception messages/paths/URLs/tokens and never throw into the originating health/queue process.

- [ ] **Step 2: Implement null/default transport**

Default binding performs no external delivery and reports `not_configured`; it must not claim alerts are installed.

- [ ] **Step 3: Select a real transport separately**

Mail, webhook or monitoring provider requires its own package/provider/security decision. No dependency is added by this task.

- [ ] **Step 4: Verify**

```bash
php artisan test --filter=OperationalAlert
./vendor/bin/pint --dirty --format agent
```

- [ ] **Step 5: Commit foundation**

```bash
git add app/Contracts/Operations app/Services/Operations/NullOperationalAlertTransport.php app/Services/Operations/OperationalAlertDispatcher.php app/Notifications/OperationalHealthDegraded.php app/Providers/AppServiceProvider.php config/notifications.php .env.example tests/Unit/OperationalAlertDispatcherTest.php tests/Feature/OperationalHealthAlertTest.php docs/operations/logging-and-health.md docs/operations/incident-response.md CHANGELOG.md
git commit -m "feat: add operational alert boundary"
git push origin main
```

---

### Task 26: Decide SQLite versus PostgreSQL

**Files:**
- Create: `docs/audits/database-engine-decision.md`
- Modify: `docs/maintenance/runtime-compatibility.md`
- Modify: `docs/maintenance/update-decisions.md`
- Modify: `docs/maintenance/technical-debt.md`
- Modify: `docs/architecture.md`
- Modify: `docs/deployment.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Post-cleanup database size, p95 write latency, lock waits, backup/restore time and queue drain evidence.
- Produces: `retain_sqlite` or `migrate_postgresql` decision; no schema/data mutation.

- [ ] **Step 1: Gather decision metrics**

Record:

```text
database size and weekly growth;
largest table/index families;
writer lock/busy rate;
p50/p95 transaction duration;
queue throughput at stable worker count;
full backup and restore duration;
full integrity duration;
cold/warm route budgets;
required SQLite-specific FTS/triggers/PRAGMA behavior.
```

- [ ] **Step 2: Apply explicit decision criteria**

Retain SQLite when:

```text
writer contention stays within approved SLO;
backup/restore fits the approved maintenance window;
queue drain meets freshness goals;
growth and integrity checks are operationally manageable.
```

Choose PostgreSQL migration design when any protected criterion remains unsatisfied after cleanup/tuning.

- [ ] **Step 3: If PostgreSQL is selected, enumerate mandatory migration boundaries**

The decision document must cover:

```text
PDO extension and Laravel driver;
SQLite FTS/search adapter;
raw SQL migrations and triggers;
JSON/date/time/money semantics;
foreign keys and transaction isolation;
import merge/upsert behavior;
pagination/query plans;
backup/restore;
dual row-count/hash verification;
cutover, rollback and forward-fix;
continued SQLite in-memory PHPUnit compatibility.
```

- [ ] **Step 4: Verify documentation**

```bash
bash scripts/ci-check.sh docs
php artisan project:docs-refresh --check
git diff --check
```

- [ ] **Step 5: Commit decision**

```bash
git add docs/audits/database-engine-decision.md docs/maintenance/runtime-compatibility.md docs/maintenance/update-decisions.md docs/maintenance/technical-debt.md docs/architecture.md docs/deployment.md docs/plans/current-task-plan.md CHANGELOG.md
git commit -m "docs: decide production database direction"
git push origin main
```

---

### Task 27: Archive Completed Current Plans without Losing Evidence

**Files:**
- Create: `docs/plans/archive/README.md`
- Create: dated task files under `docs/plans/archive/`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `docs/development.md`
- Modify: `docs/README.md`
- Modify: `config/project-docs.php`
- Modify: `tests/Unit/ProjectDocumentationRefresherTest.php`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Existing 2,000+ line accumulated current plan.
- Produces: One genuinely current master section plus immutable linked completed evidence.

- [ ] **Step 1: Write RED documentation tests**

Assert:

```text
current-task-plan begins with one active task;
every moved completed section has a dated archive file;
archive index links every file;
current plan links archives;
project docs map includes archive owner;
no historical checklist/evidence text is deleted.
```

- [ ] **Step 2: Move completed sections mechanically**

Use exact heading boundaries. Preserve content byte-for-byte inside each archive file except relative link correction and one archive header.

- [ ] **Step 3: Keep parallel active streams explicit**

The current plan may contain:

```text
primary active master program;
parallel user-approved player/importer plan links;
blocked/unresolved external delivery state.
```

It must not copy the full body of completed tasks.

- [ ] **Step 4: Refresh managed docs**

```bash
php artisan project:docs-refresh
php artisan test --filter=ProjectDocumentationRefresherTest
bash scripts/ci-check.sh docs
git diff --check
```

- [ ] **Step 5: Commit**

```bash
git add docs/plans/archive docs/plans/current-task-plan.md docs/development.md docs/README.md config/project-docs.php tests/Unit/ProjectDocumentationRefresherTest.php CHANGELOG.md
git commit -m "docs: archive completed task plans"
git push origin main
```

---

### Task 28: Final Cross-System Acceptance

**Files:**
- Modify: `docs/operations/production-checklist.md`
- Modify: `docs/audits/verification-report.md`
- Modify: `docs/audits/current-state-audit.md`
- Modify: `docs/environment.md`
- Modify: `docs/maintenance/runtime-compatibility.md`
- Modify: `docs/maintenance/technical-debt.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md` only for actually delivered visitor/development/operations changes
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: Tasks 1–27, every applicable non-deferred rolling task that exists at execution time, and their completed commits. With the current ledger this includes Tasks 29–33 before final closeout.
- Produces: Final compliance matrix and honest remaining limitations.

- [ ] **Step 1: Re-read all applicable canonical requirements**

Follow `docs/requirements/index.md` in order and re-open every affected feature owner.

- [ ] **Step 2: Run full repository verification**

```bash
composer validate --strict
composer audit
composer check-platform-reqs
npm audit
./vendor/bin/pint --test
php artisan test
./vendor/bin/phpunit
npm run build
npm run test:browser
bash scripts/ci-check.sh full
```

Expected: all mandatory gates pass; documented skips remain explained. Do not run duplicate full commands when the central full gate already invokes the exact same command unless independent output is required for evidence.

- [ ] **Step 3: Run production-safe checks**

```bash
php artisan app:deployment-check --profile=fast --json
php artisan app:health --json
php artisan app:failed-job-audit --json --samples=0
php artisan seasonvar:import --status
php artisan schedule:list
```

Full integrity runs only inside the approved maintenance window or against the verified backup.

- [ ] **Step 4: Verify feature matrix**

Mark `verified|not_applicable|blocked|not_performed` for:

```text
home, search, catalogue, title, seasons/episodes, player, progress/history;
library, collections, tags, comments, reviews, profiles;
authentication, settings, calendar, recommendations, requests, tickets, help;
premium, mobile API, administration, imports;
cache/session/queue/Redis/Memcached, storage, assets, backup/restore and rollback.
```

- [ ] **Step 5: Scan for legacy and duplicate implementation**

```bash
rg -n "TODO|FIXME|HACK|deprecated|legacy|stale|queue:clear|cache:clear|migrate:fresh|db:wipe" app bootstrap config database deploy docs resources routes scripts tests
rg -n "@php|<script|<style|style=|env\\(" resources/views app routes
git diff --check
```

Every result is classified; text match alone never authorizes deletion.

- [ ] **Step 6: Update final evidence**

Close only debt with direct verification. Keep unavailable real-device, provider, off-host, HA, failover or alert-delivery states `unresolved`/`not_performed`.

- [ ] **Step 7: Commit and push**

```bash
git add docs/operations/production-checklist.md docs/audits/verification-report.md docs/audits/current-state-audit.md docs/environment.md docs/maintenance/runtime-compatibility.md docs/maintenance/technical-debt.md docs/plans/current-task-plan.md CHANGELOG.md
git add README.md
git commit -m "docs: close stabilization and optimization program"
git push origin main
```

Before staging `README.md`, confirm it contains a meaningful actual change; otherwise omit it and record `already_compliant`.

---

## Rolling Extension Protocol

The numbered roadmap has no artificial ceiling. Tasks 1–28 remain stable historical identities; new evidence appends Task 29, Task 30 and later tasks without renumbering, rewriting completed evidence or silently broadening an active change set.

A discovery becomes a numbered task only when all of the following are recorded:

1. measured business, security, compatibility, performance or maintenance reason;
2. exact owner files and public/internal contracts;
3. dependencies and cross-feature impact;
4. data-safety, production-impact and rollback boundary;
5. RED/GREEN or bounded operational verification;
6. documentation, `README.md`, Russian `CHANGELOG.md`, commit and push rules;
7. honest state `planned`, `in_progress`, `completed`, `already_compliant`, `not_applicable` or `unresolved`.

New tasks never grant authority to mutate production data, stop processes, overwrite backups, add production dependencies, change providers or expose secrets. Such authority must still come from the applicable canonical requirement and task-specific approval boundary.

### Task 29: Restore Authenticated Fast-Forward Delivery of `main`

**Status:** `unresolved_external`; after the Task 34 evidence commit local `main` is 27 commits ahead of `origin/main`, HTTPS authentication is still unavailable, and the shared worktree remains dirty with uncommitted importer and collection work. This task may run independently of production recovery only after exact stream commits and Task 30 ownership handoff make the checkout clean.

**Files:**
- Modify: `docs/development.md` only if the verified credential mechanism changes the documented workflow
- Modify: `docs/plans/current-task-plan.md`
- Modify: `CHANGELOG.md` only after actual remote delivery is verified
- Review only: `.git/config`, configured credential helper and remote refs; never track credential material

**Interfaces:**
- Consumes: existing local `main`, configured `origin`, all task-owned commits and a clean worktree.
- Produces: a normal fast-forward remote `refs/heads/main` equal to local `HEAD`, or a precise external `unresolved` result.
- Preserves: direct-to-`main`, no force push, no history rewrite, no alternate branch/worktree and no credential value in command output or tracked files.

- [ ] **Step 1: Freeze the exact delivery set**

Run:

```bash
git status --short --branch
git rev-list --count origin/main..main
git log --oneline --decorate origin/main..main
git remote get-url origin
git fsck --no-progress --connectivity-only
```

Expected: branch is `main`; every ahead commit is intentional; the tree is clean; Git object connectivity passes. Stop on unrelated dirty state, an unexpected remote or an unreviewed commit. Do not stash, reset, cherry-pick or rewrite history to manufacture a clean result.

- [ ] **Step 2: Establish credentials outside the repository**

An authorized operator configures an OS/user credential provider, SSH agent or approved short-lived GitHub credential outside tracked files. Never write a token to `.env`, `.env.example`, Git remote URLs, Markdown, shell history, logs or screenshots.

Probe without an interactive prompt:

```bash
GIT_TERMINAL_PROMPT=0 git ls-remote --exit-code --heads origin main
```

Expected: the remote main ref is returned. Authentication or network failure remains `unresolved`; it must not trigger a fallback force push or credential disclosure.

- [ ] **Step 3: Run the configured pre-push gate once**

```bash
git status --short --branch
bash scripts/ci-check.sh pre-push
git diff --check
```

Expected: the full configured backend/frontend gate passes against the exact clean `HEAD`. If concurrent work dirties the tree during verification, stop and repeat only after its owner finishes.

- [ ] **Step 4: Push normally and verify the exact ref**

```bash
git push --porcelain origin main
git rev-parse HEAD
git ls-remote --exit-code --heads origin main
```

Expected: push is a normal fast-forward and the remote hash equals local `HEAD`. `--force`, `--force-with-lease`, ref deletion and alternate branches are forbidden.

- [ ] **Step 5: Record delivery evidence**

Update the current-plan delivery row with the exact local/remote hash and verification time. Update `docs/development.md` only if the real supported credential workflow changed. Add a Russian `CHANGELOG.md` item only for an actual workflow/documentation change, not merely to announce that Git authentication happened.

- [ ] **Step 6: Commit and deliver any real documentation correction**

If Step 5 changed tracked documentation:

```bash
bash scripts/ci-check.sh docs
git diff --check
git add docs/development.md docs/plans/current-task-plan.md CHANGELOG.md
git commit -m "docs: record verified main delivery"
git push --porcelain origin main
```

Stage only files that actually changed. Verify the final remote ref again. If no tracked documentation needed correction, do not create an empty evidence commit.

---

### Task 30: Serialize Shared-`main` Delivery Ownership

**Status:** `preparation_completed_local`; Steps 1–3 were prepared, verified and committed in `main` as `ad5c13c` without touching live hooks. The configured remote rejected HTTPS authentication, so remote delivery remains `unresolved`. Player handoff is commit-backed through `f22e755`; Steps 4–7 remain blocked until current importer and collection owners finish or commit their exact work, because hook integration and shared documentation must not overwrite or interrupt their delivery.

**Reason:** Multiple independent tasks currently edit one checkout while project policy forbids branches and worktrees. The existing clean-tree guard prevents accidental partial commits but has no cooperative task-owner lease, leading to repeated guard bypass pressure and overlapping owner documents.

**Files:**
- Create: `scripts/task-workspace-lease.sh`
- Modify: `.githooks/lib/git-guard.sh`
- Modify: `.githooks/pre-commit`
- Modify: `.githooks/pre-push`
- Create: `tests/Unit/GitWorkspaceLeaseScriptTest.php`
- Modify: `tests/Unit/CiQualityGateContractTest.php`
- Modify: `docs/development.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md` only if the contributor quick-start actually changes
- Modify: `CHANGELOG.md`

**Interfaces:**
- Produces cooperative commands `acquire <task-id>`, `status`, `release <task-id>` and an exact repository-local lease below `git rev-parse --git-path ...`.
- Lease metadata may contain only task ID, process ID, timestamp and a one-way token digest; raw token, command arguments, environment values, file contents and credentials are forbidden.
- Hooks validate an ephemeral `SEASONVAR_TASK_LEASE_TOKEN` against the digest and fail closed for a different active owner.
- The lease never stages files, stashes work, switches branches, creates worktrees, kills processes, deletes changes or weakens existing main/conflict/path/clean-tree/documentation gates.

- [x] **Step 1: Write failing script tests**

Cover:

```text
first acquire succeeds atomically;
second acquire fails without changing the first lease;
status exposes safe owner metadata only;
wrong token cannot release or commit;
matching token releases the exact lease;
active PID cannot be recovered as stale;
stale recovery requires an explicit command and exact repository validation;
paths with spaces and concurrent acquire attempts remain safe;
no lease command changes tracked or staged files.
```

Use a temporary initialized Git repository and `Symfony\Component\Process\Process`; do not test against the active `.git` lease.

- [x] **Step 2: Run RED**

```bash
php artisan test --filter=GitWorkspaceLeaseScriptTest
```

Expected: FAIL because the lease script does not exist.

- [x] **Step 3: Implement the minimal cooperative lease**

Use atomic directory creation below the repository Git directory. `acquire` prints the raw token once to stdout for the caller to place only in its process environment; disk receives only a SHA-256 digest. `status` never prints the token/digest. `release` requires the matching token. Stale recovery refuses while the recorded PID exists and never removes any path outside the exact validated lease directory.

No generic recursive deletion command is allowed. Every removal target must be the resolved lease metadata/file/directory created by this script and must pass exact-name/repository checks first.

Preparation may complete this step and its dedicated test in an isolated commit while the shared tree is busy. That preparation must not modify `.githooks/*`, `CiQualityGateContractTest`, contributor documentation or the active hook behavior; those remain Step 4+ work after the shared-tree gate clears.

Preparation evidence: initial RED failed because `scripts/task-workspace-lease.sh` did not exist; GREEN passed 12 tests and 56 assertions. The dedicated suite also proves unexpected directory contents block release while preserving metadata.

- [ ] **Step 4: Integrate hooks without weakening current guards**

Add `seasonvar_git_guard_require_workspace_lease` before staged/tracked path and clean-tree checks. Preserve `seasonvar_git_guard_require_main`, conflict detection, sensitive/temporary path checks, no-unstaged/no-untracked checks, documentation policy and pre-push quality gate.

The existing emergency `SEASONVAR_SKIP_GIT_GUARD=1` remains an explicitly reported exceptional path; this task must not make it the normal workflow.

- [ ] **Step 5: Run GREEN and hook contracts**

```bash
php artisan test --filter=GitWorkspaceLeaseScriptTest
php artisan test --filter=CiQualityGateContractTest
bash -n scripts/task-workspace-lease.sh .githooks/pre-commit .githooks/pre-push .githooks/lib/git-guard.sh
bash scripts/ci-check.sh docs
git diff --check
```

Expected: all pass; tests prove a second owner cannot acquire/release the active lease and hooks still run every existing guard.

- [ ] **Step 6: Document adoption and rollback**

Document exact acquire/status/release commands, token handling, stale recovery and failure messages. Rollback removes only the lease requirement/script/tests/docs and leaves all previous Git guards intact. Existing active leases are released before rollback; no source change is touched.

- [ ] **Step 7: Commit in isolation**

```bash
git add scripts/task-workspace-lease.sh .githooks/lib/git-guard.sh .githooks/pre-commit .githooks/pre-push tests/Unit/GitWorkspaceLeaseScriptTest.php tests/Unit/CiQualityGateContractTest.php docs/development.md docs/plans/current-task-plan.md CHANGELOG.md
git add README.md
git commit -m "feat: serialize shared main delivery ownership"
git push origin main
```

Before staging `README.md`, require a meaningful contributor-facing change. Do not include importer, player, dependency, application or production files.

---

### Task 31: Reconcile Registered Workstream Roadmaps before Production Activation

**Status:** `in_progress_read_only_snapshot`; Step 1 evidence is recorded below, but child files are concurrently owned and no child rollout may bypass Tasks 3–5. Reconciliation is not complete until every status is backed by an isolated commit.

**Files:**
- Modify: `docs/superpowers/plans/2026-07-24-system-maintenance-and-optimization-master-plan.md`
- Modify: `docs/superpowers/plans/2026-07-24-seasonvar-importer-improvement-master-plan.md`
- Modify: `docs/superpowers/plans/2026-07-24-player-seamless-episode-switching.md`
- Modify: `docs/superpowers/plans/2026-07-24-editorial-collection-health-diagnostics.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: affected canonical owner docs only when delivered behavior changed
- Modify: `README.md` only for actual visitor/development/operations change
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: completed commits and verification evidence from the system, importer, player, collection and later registered workstreams.
- Produces: one dated dependency/status matrix with no duplicated ownership and an explicit activation decision per registered stream.
- Preserves: system plan owns Redis/backup/process/deployment gates; importer plan owns importer internals; player plan owns playback behavior; collection plan owns collection diagnostics; current plan reports execution without redefining permanent requirements.

- [x] **Step 1: Snapshot exact child status**

```bash
git status --short --branch
git log --oneline --decorate -30
rg -n "^\\*\\*Status|^Статус:|^- \\[[ x]\\]" docs/superpowers/plans/2026-07-24-system-maintenance-and-optimization-master-plan.md docs/superpowers/plans/2026-07-24-seasonvar-importer-improvement-master-plan.md docs/superpowers/plans/2026-07-24-player-seamless-episode-switching.md docs/plans/current-task-plan.md
```

Classify only commit-backed work as `completed`. Dirty or unpushed work stays `in_progress`/`unresolved`.

Read-only snapshot — 24.07.2026:

| Stream | Verified state | Next non-bypassable gate |
| --- | --- | --- |
| System | `main` contains local commits through player roadmap evidence `f22e755`, is 25 commits ahead of `origin/main`, Task 3 is still `unresolved`, Task 30 preparation exists in `ad5c13c`, and Task 32 preparation/evidence exists in `2a7f636`/`cb83684`. | Independent backup/process/maintenance evidence for Task 3; clean shared checkout and authenticated fast-forward for Tasks 29–30/32. |
| Importer | Tasks 2–3 remain uncommitted in the shared checkout and Task 4 reports active TDD implementation. Production activation is disabled and blocked by importer Task 1/system Tasks 3–5. | Commit exact importer scope only after an ownership handoff; no production schedule/retention/data mutation. |
| Player | Runtime implementation/evidence is committed in `1ded102`/`ab6532a`; unlimited roadmap/evidence is committed in `a14cdf5`/`f22e755`. Remote delivery remains unresolved. | Keep deployment separate from Redis/database maintenance and reconcile remote status only after verified delivery. |
| Collections | Diagnostics plan reports verified additive admin metrics, but collection PHP/Blade/translations/tests/docs remain uncommitted in the shared checkout. | Commit the exact collection manifest without importer/system files; keep provider sync, matching, public output and production state unchanged. |
| Current plan | `docs/plans/current-task-plan.md` is 3,435 lines in the current shared snapshot, has 41 H1 headings and 8 active-like H1 headings. | Task 27 archives completed bodies; Task 33 then creates one linked registry and prevents recurrence with a docs policy gate. |
| Shared index | During Task 32 delivery another owner inserted a player-plan path into the staged set; Task 32 commit stopped until that owner removed its path and completed `f22e755`. | Task 30 serializes ownership, Task 32 binds the final index, and Task 34 requires an exact declared path set before approval. |

- [ ] **Step 2: Reconcile the dependency map**

Record these non-bypassable mappings:

```text
System Task 3 -> safe Redis persistence/restart boundary.
System Task 4 -> Importer Task 1 process/scheduler ownership.
System Task 5 -> importer worker canary and backlog trend.
System Task 6 -> failed-job disposition.
Importer Task 3, which supersedes System Task 7 -> retention rollout.
System Tasks 13–14 -> immutable player/importer deployment and runtime permissions.
System Tasks 20–21 -> shared performance budgets before optimization claims.
System Tasks 24 and 28 -> player security/browser and final cross-system acceptance.
```

Importer code preparation may precede activation, but Redis/process/data mutations may not. Player commits may proceed independently, but deployment must not overlap a Redis/database maintenance window.

- [ ] **Step 3: Re-run affected verification**

Run only the matrix required by actual child changes, then the shared docs gate:

```bash
bash scripts/ci-check.sh docs
git diff --check
```

PHP/frontend/browser/full checks are selected from the child plans; no completed status is copied without its exact evidence.

- [ ] **Step 4: Update compliance and release decision**

For authentication, authorization, translations, caching, search, notifications, SEO, privacy, mobile, administration, audit, imports, premium, regional/legal, player, deployment, backup and rollback, record `completed`, `already_compliant`, `not_applicable` or `unresolved` with evidence.

- [ ] **Step 5: Commit only reconciliation evidence**

```bash
git add docs/superpowers/plans/2026-07-24-system-maintenance-and-optimization-master-plan.md docs/superpowers/plans/2026-07-24-seasonvar-importer-improvement-master-plan.md docs/superpowers/plans/2026-07-24-player-seamless-episode-switching.md docs/superpowers/plans/2026-07-24-editorial-collection-health-diagnostics.md docs/plans/current-task-plan.md CHANGELOG.md
git add README.md
git commit -m "docs: reconcile system and feature roadmaps"
git push origin main
```

Stage only files with factual changes. If a child owner is concurrently editing one of them, stop rather than overwrite, stage or reformat its work.

---

### Task 32: Bind a Reviewed Git Index Snapshot to the Workspace Lease

**Status:** `standalone_preparation_delivered_local_pending_activation`. The observed staged-player/unstaged-importer collision proves that owner identity alone does not prove the final index was reviewed. Steps 1–3 are committed in local `main` as `2a7f636`, with delivery evidence in `cb83684`; the normal push reached `origin` but failed because HTTPS credentials are unavailable. During final verification another owner added a player-plan path to the staged set, so Task 32 stopped until that owner removed only its path and completed `f22e755`. Hook/guard integration, contributor workflow activation and the final Task 32 commit contract remain blocked behind the Task 30 handoff gate while importer and collection owners retain their unstaged scopes. The preparation RED was `13` passed / `10` expected failures; standalone GREEN is `23` tests / `122` assertions, while the unchanged hook contract remains `17` tests / `95` assertions.

**Reason:** Task 30 serializes cooperative owner handoff, but an active owner can still inherit or accidentally accept previously staged paths. A commit must be bound to the exact reviewed final Git index. A SHA-256 snapshot detects every final index difference, but cannot prove the history of commands when an index is restored byte-for-byte before verification; the documented workflow therefore still requires explicit re-review and re-approval after staging operations.

**Files:**
- Modify: `scripts/task-workspace-lease.sh`
- Modify: `.githooks/lib/git-guard.sh`
- Modify: `.githooks/pre-commit`
- Modify: `tests/Unit/GitWorkspaceLeaseScriptTest.php`
- Modify: `tests/Unit/CiQualityGateContractTest.php`
- Modify: `docs/development.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md` only if the contributor quick-start materially changes
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: an active Task 30 lease, matching `SEASONVAR_TASK_LEASE_TOKEN`, a conflict-free Git index and the existing staged path/security/documentation policies.
- Produces: `approve-index <task-id>` and `verify-index <task-id>` commands plus an exact `approved-index` file inside the validated lease directory.
- The approval file contains only `task_id`, UTC `approved_at` and a SHA-256 digest of the NUL-safe full index entries returned by `git ls-files --stage -z`; repository paths, blob contents, raw token, token digest, command arguments and environment values are not stored.
- `approve-index` refuses an empty staged diff, unresolved conflicts, a wrong task ID or token, and does not stage/unstage any path.
- Pre-commit first validates Task 30 ownership, then requires the approved digest to equal the current index digest. Any later `git add`, `git reset <path>`, conflict resolution or index replacement that leaves a different final index invalidates approval and requires a new explicit review/approval. A byte-identical restoration is cryptographically the same snapshot, so the contributor procedure—not an unverifiable operation-history claim—requires re-approval after any staging operation.
- Existing main/conflict/sensitive/temporary/unstaged/untracked/docs/README/CHANGELOG gates remain mandatory. Approval never converts an unsafe staged set into an allowed one.

- [x] **Step 1: Write failing index-approval tests**

Add tests covering:

```text
approval without an active lease fails;
approval with a wrong token or task ID fails;
an empty staged diff cannot be approved;
approval stores only task ID, UTC timestamp and SHA-256 index digest;
status reports only index_approved=yes|no and never prints the digest;
verify succeeds for the unchanged reviewed index;
any staged add, edit, delete, rename, mode change or unstage that changes the final index invalidates approval;
restoring and re-approving the exact index succeeds;
release removes only the exact approval file and lease metadata;
approve/verify never change the index or working tree;
paths with spaces and NUL-safe index input remain deterministic.
```

Use only a temporary initialized Git repository. Never approve the active shared index from PHPUnit.

- [x] **Step 2: Run RED**

```bash
php artisan test --filter=GitWorkspaceLeaseScriptTest
```

Observed preparation RED: `23` tests executed; `13` existing contracts passed and `10` failed on the missing commands/status/file boundary.

- [x] **Step 3: Implement the minimal fingerprint boundary**

The digest source is the complete binary-safe index listing:

```bash
git ls-files --stage -z -- |
    php -r 'echo hash("sha256", stream_get_contents(STDIN));'
```

Before creating approval, require:

```bash
git diff --cached --quiet -- && exit 1
[[ -z "$(git ls-files --unmerged)" ]] || exit 1
```

The implementation must use the existing exact lease-path validator and token comparison. Write approval through a mode-`0600` temporary file inside the exact lease directory and atomically rename it. Cleanup may use only validated exact files and `rmdir`; recursive deletion remains forbidden.

- [ ] **Step 4: Integrate pre-commit after all existing safety checks are preserved**

Add a guard function such as:

```bash
seasonvar_git_guard_require_approved_index
```

Call it after main/lease/conflict/path checks and before documentation/build commands. The function recomputes the SHA-256 digest from the current index and fails closed on missing, malformed or stale approval.

Do not require a non-empty staged-index approval during `pre-push`: after commit the index is normally empty. Pre-push keeps Task 30 ownership, clean-tree and full quality gates.

- [ ] **Step 5: Run GREEN and regression contracts**

```bash
php artisan test --filter=GitWorkspaceLeaseScriptTest
php artisan test --filter=CiQualityGateContractTest
bash -n scripts/task-workspace-lease.sh .githooks/pre-commit .githooks/pre-push .githooks/lib/git-guard.sh
bash scripts/ci-check.sh docs
git diff --check
```

Expected: all pass; tests prove changed index content invalidates approval while every previous Git guard remains in the hook.

- [ ] **Step 6: Document the exact safe sequence**

Document:

```bash
scripts/task-workspace-lease.sh acquire <task-id>
git add -- <reviewed-paths>
git diff --cached --name-status
git diff --cached --check
SEASONVAR_TASK_LEASE_TOKEN=... scripts/task-workspace-lease.sh approve-index <task-id>
git commit
SEASONVAR_TASK_LEASE_TOKEN=... scripts/task-workspace-lease.sh release <task-id>
```

The documentation must say that approval is not authorization to include unrelated files and that the raw token exists only in the owner process environment.

- [ ] **Step 7: Rollback and commit**

Rollback removes only the approval command/file/check and keeps the base Task 30 owner lease plus every pre-existing Git guard.

```bash
git add scripts/task-workspace-lease.sh .githooks/lib/git-guard.sh .githooks/pre-commit tests/Unit/GitWorkspaceLeaseScriptTest.php tests/Unit/CiQualityGateContractTest.php docs/development.md docs/plans/current-task-plan.md CHANGELOG.md
git add README.md
git commit -m "feat: bind commits to a reviewed index"
git push origin main
```

Stage `README.md` only for an actual contributor-workflow change. Never include child application/importer/player files in this commit.

---

### Task 33: Enforce One Active Current-Plan Registry after Archival

**Status:** `planned_after_tasks_27_and_31`. Task 27 performs the one-time lossless archive; Task 31 reconciles registered workstream status; this task prevents the now 3,435-line, 41-H1 multi-owner current plan from growing back.

**Reason:** The current plan contains several historical and parallel full task bodies and multiple headings that claim to be current. Manual discipline has not preserved a single active owner, while the unlimited rolling protocol requires history to grow through linked archives and monotonic task IDs rather than one unbounded working file.

**Files:**
- Create: `scripts/check-current-plan-policy.php`
- Create: `scripts/check-current-plan-policy.sh`
- Create: `tests/Unit/CurrentPlanPolicyScriptTest.php`
- Modify: `scripts/ci-check.sh`
- Modify: `tests/Unit/CiQualityGateContractTest.php`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `docs/plans/archive/README.md`
- Modify: `docs/development.md`
- Modify: `docs/README.md`
- Modify: `README.md` only if the documented development workflow materially changes
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: the lossless archive produced by Task 27 and the commit-backed stream matrix produced by Task 31.
- Produces: a read-only documentation policy used locally, by `scripts/ci-check.sh docs`, pre-commit through the existing docs profile and CI.
- The current file has exactly one H1 beginning `# Текущая задача`, one active workstream registry, one blocked/unresolved registry, one task-specific compliance matrix and linked latest evidence.
- Completed historical bodies live only in dated archive files. Parallel importer/player/collection/system work appears as links and status rows, not copied implementation plans.
- Importer, player, collection and every later registered workstream has one row with exact owner plan, commit-backed status, declared owner/path manifest link and next non-bypassable gate.
- The policy has no line-count ceiling and no maximum task ID: “безлимитный” remains evidence-driven and monotonic. It validates ownership/structure, not an artificial number of tasks.
- Allowed machine statuses are exactly `planned`, `in_progress`, `completed`, `already_compliant`, `not_applicable` and `unresolved`, with optional documented qualifiers after `:`; unknown optimistic status text fails.

- [ ] **Step 1: Write failing policy tests**

Create temporary Markdown fixtures covering:

```text
one active H1 plus valid registry and archive links passes;
two “Текущая задача” H1 headings fail;
embedded “Завершённая задача” or copied parallel plan H1 fails;
missing archive target fails;
unknown status fails;
unresolved status without evidence fails;
arbitrarily high monotonic task IDs pass;
large but structurally valid active evidence passes without a line ceiling;
the repository current plan passes only after Task 27/31 migration.
```

- [ ] **Step 2: Run RED**

```bash
php artisan test --filter=CurrentPlanPolicyScriptTest
```

Expected: FAIL because the policy scripts do not exist.

- [ ] **Step 3: Implement the read-only parser**

`scripts/check-current-plan-policy.php` must:

```text
read only the provided path or docs/plans/current-task-plan.md;
parse H1/H2 headings and Markdown links without executing content;
reject duplicate active H1 and embedded completed/parallel H1 bodies;
validate relative archive links below docs/plans/archive;
validate registry status values and non-empty evidence;
return exit 0 without modifying any file;
return exit 1 with Russian path/line diagnostics;
never print file contents, secrets or absolute private paths.
```

The shell wrapper resolves the repository root and invokes the PHP policy exactly as the existing README/CHANGELOG policy wrappers do.

- [ ] **Step 4: Migrate the current file through Tasks 27 and 31**

Do not delete historical evidence. Verify every moved heading has an archive target and the archive index links it. The active registry must include:

```text
system stabilization master plan;
importer master plan;
player plan until its delivery/activation closes;
collection diagnostics plan until its delivery closes;
remote delivery state;
production activation blockers;
latest commit-backed compliance evidence.
```

- [ ] **Step 5: Integrate the docs gate**

Add:

```bash
bash scripts/check-current-plan-policy.sh docs/plans/current-task-plan.md
```

to `run_docs()` in `scripts/ci-check.sh`. Extend `CiQualityGateContractTest` to prove the policy runs before managed documentation freshness checks and remains read-only.

- [ ] **Step 6: Run GREEN and full documentation verification**

```bash
php artisan test --filter=CurrentPlanPolicyScriptTest
php artisan test --filter=CiQualityGateContractTest
php artisan test --filter=ProjectDocumentationRefresherTest
php -l scripts/check-current-plan-policy.php
bash -n scripts/check-current-plan-policy.sh scripts/ci-check.sh
bash scripts/check-current-plan-policy.sh docs/plans/current-task-plan.md
php artisan project:docs-refresh --check --no-interaction
bash scripts/ci-check.sh docs
git diff --check
```

Expected: all pass; the policy accepts monotonic Tasks 34+ without a hard ceiling and rejects a second copied current task body.

- [ ] **Step 7: Document rollback and commit**

Rollback removes the automated policy invocation/script/tests only. It does not restore archived bodies to the current file and never deletes archive evidence.

```bash
git add scripts/check-current-plan-policy.php scripts/check-current-plan-policy.sh tests/Unit/CurrentPlanPolicyScriptTest.php scripts/ci-check.sh tests/Unit/CiQualityGateContractTest.php docs/plans/current-task-plan.md docs/plans/archive/README.md docs/development.md docs/README.md CHANGELOG.md
git add README.md
git commit -m "docs: enforce one active task registry"
git push origin main
```

Stage `README.md` only when the supported development workflow actually changed.

---

### Task 34: Bind Declared Task Paths to the Reviewed Index

**Status:** `planned_commit_backed_after_tasks_30_and_32`. The exact implementation plan is committed in local `main` as `ebbbc85`; implementation has not started and remote delivery is blocked by unavailable HTTPS credentials. This task is a separate hardening layer, not a replacement for the owner lease, final-index SHA-256, human review or existing Git guards.

**Reason:** During Task 32 delivery, another owner added a player-plan path to an already prepared staged set. The commit stopped and the foreign owner removed only its own path, but the incident proves a remaining pre-approval gap: an owner could accidentally review and approve a new snapshot that already contains a foreign path. The lease must therefore know the exact intended task path set before staging and require exact equality before index approval.

**Files:**
- Modify: `scripts/task-workspace-lease.sh`
- Modify: `.githooks/lib/git-guard.sh`
- Modify: `.githooks/pre-commit`
- Modify: `tests/Unit/GitWorkspaceLeaseScriptTest.php`
- Modify: `tests/Unit/CiQualityGateContractTest.php`
- Modify: `docs/development.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md` only when the supported contributor sequence changes
- Modify: `CHANGELOG.md`

**Interfaces:**
- Consumes: an active Task 30 lease, matching process-scoped `SEASONVAR_TASK_LEASE_TOKEN`, a human-reviewed NUL-delimited repository-relative path set declared before staging, and the Task 32 index-approval boundary.
- Produces: `declare-paths <task-id>` and `verify-paths <task-id>` commands, an exact mode-`0600` `declared-paths` NUL file and an atomic mode-`0600` `declared-paths.meta` file inside the validated lease directory.
- `declare-paths` reads only NUL-delimited stdin, requires a terminating NUL and at least one record, rejects empty or duplicate byte-identical records, absolute paths, any `.`/`..` component and `.git` itself or its descendants. Spaces, tabs and newlines remain valid filename bytes. It canonicalizes with `LC_ALL=C` binary-safe NUL sorting and never stages a file.
- `verify-paths` compares the declared set with `git diff --cached --name-only -z --no-renames --`, also canonicalized with NUL-safe sorting. `--no-renames` intentionally represents a rename as the old deletion plus the new addition, so both owned paths must be declared.
- A mode-only edit has the same single path; add/edit/delete paths are exact; any missing or additional staged path fails closed before `approve-index`.
- `approve-index` invokes the path verification first. Pre-commit requires Task 30 ownership, Task 34 path equality and Task 32 final-index approval while preserving every existing main/conflict/sensitive/temporary/clean-tree/documentation guard.
- Safe `status` may add only `paths_declared=yes|no`; it never prints paths, hashes, tokens, arguments, file contents or private absolute paths.
- `release`/`recover` delete only the exact expanded allowlist after full preflight; symlinks, malformed metadata or unexpected entries keep the lease intact.

- [ ] **Step 1: Write failing declared-path tests**

Extend the temporary-repository PHPUnit suite with exact cases:

```text
declaration without an active lease fails;
wrong task ID or token fails without replacing the first manifest;
empty input, duplicate paths, absolute paths, dot segments and .git paths fail;
tracked, deleted and new working-tree paths can be declared before staging;
spaces and newline-containing paths remain NUL-safe and deterministic;
status reports only paths_declared=yes|no;
exact staged add/edit/delete/rename/mode sets verify successfully;
one missing or one additional staged path fails;
approve-index refuses a path mismatch even when the index is conflict-free;
re-declaring an explicitly reviewed new set invalidates the previous index approval;
release/recover remove only exact manifest/approval/lease files;
declare/verify/approve do not change the index or working tree.
```

Never declare paths in the active shared repository from PHPUnit.

- [ ] **Step 2: Run RED**

```bash
php artisan test --filter=GitWorkspaceLeaseScriptTest
```

Expected: existing Task 30/32 tests pass and the new cases fail because `declare-paths`, `verify-paths` and manifest files do not exist.

- [ ] **Step 3: Implement the minimal NUL-safe manifest boundary**

Use an exact repository-local temporary directory and binary-safe tools:

```bash
LC_ALL=C sort -zu
git diff --cached --name-only -z --no-renames --
```

Write both manifest and metadata through mode-`0600` sibling temporary files and atomic renames only after every input path and lease/token/task field passes. Metadata contains exactly `task_id`, UTC `declared_at` and SHA-256 of the canonical NUL manifest. Parsing rejects duplicate, missing, unknown or malformed fields and all symlinks.

Any successful new declaration removes only the exact existing `approved-index` file after complete validation because the previously approved index no longer has a proven relation to the new scope. Validation failure preserves the previous declaration and approval unchanged.

- [ ] **Step 4: Integrate the hook after Tasks 30 and 32**

Add a guard such as:

```bash
seasonvar_git_guard_require_declared_paths
```

Call order in pre-commit:

```text
main and exact workspace lease;
conflicts and staged path safety;
declared-path exact equality;
reviewed final-index approval;
unstaged/untracked clean-tree checks;
README/CHANGELOG/docs and other quality gates.
```

Pre-push keeps the owner lease and clean-tree/full quality gates but does not require a non-empty path manifest or index approval after commit.

- [ ] **Step 5: Run GREEN and regression verification**

```bash
php artisan test --filter=GitWorkspaceLeaseScriptTest
php artisan test --filter=CiQualityGateContractTest
bash -n scripts/task-workspace-lease.sh .githooks/pre-commit .githooks/pre-push .githooks/lib/git-guard.sh
php artisan project:docs-refresh --check --no-interaction
bash scripts/ci-check.sh docs
git diff --check
```

Expected: all pass; the hook rejects undeclared, missing and foreign staged paths while every Task 30/32 and pre-existing Git guard remains active.

- [ ] **Step 6: Document the contributor sequence and rollback**

Document an exact path declaration before staging:

```bash
scripts/task-workspace-lease.sh acquire <task-id>
printf '%s\0' path/one path/two |
    SEASONVAR_TASK_LEASE_TOKEN=... scripts/task-workspace-lease.sh declare-paths <task-id>
git add -- path/one path/two
SEASONVAR_TASK_LEASE_TOKEN=... scripts/task-workspace-lease.sh verify-paths <task-id>
git diff --cached --name-status
git diff --cached --check
SEASONVAR_TASK_LEASE_TOKEN=... scripts/task-workspace-lease.sh approve-index <task-id>
git commit
SEASONVAR_TASK_LEASE_TOKEN=... scripts/task-workspace-lease.sh release <task-id>
```

Documentation must state that task paths are repository metadata, not authorization; every staged diff still requires human review. Rollback removes only declared-path commands/files/guard/tests/docs and retains Task 30 ownership, Task 32 index approval and every older Git gate.

- [ ] **Step 7: Commit and push in isolation**

```bash
git add scripts/task-workspace-lease.sh .githooks/lib/git-guard.sh .githooks/pre-commit tests/Unit/GitWorkspaceLeaseScriptTest.php tests/Unit/CiQualityGateContractTest.php docs/development.md docs/plans/current-task-plan.md CHANGELOG.md
git add README.md
git commit -m "feat: bind staged paths to task ownership"
git push --porcelain origin main
```

Stage `README.md` only for the actual contributor-workflow change. Never include importer, player, collection, dependency, application or production files.

---

## Deferred until a Separate Approved Decision

- Redis `8.6.x → 8.8.x` major/minor update after persistence recovery, build provenance, module/client/session/queue/lock compatibility and restore proof.
- `concurrently 9 → 10` major update until Node LTS migration is stable and signal/exit behavior has an identified benefit.
- PHPUnit 13 until the PHPUnit 12 suite is project-deprecation-free.
- PostgreSQL migration until Task 26 selects it from measured criteria.
- Horizon, Octane, Pulse, service worker/PWA, CDN, object storage, HA/failover and managed monitoring until a concrete operational/product need, ownership and rollback exist.
- Real payment/OAuth/mail/provider SDK adoption until a provider contract and credentials boundary are separately approved.

## Program Completion Criteria

- Redis persistence completes within the approved bound and survives a verified restart/restore path.
- Exactly one scheduler owner and one process-manager owner exist; intended workers have current heartbeats.
- Queue backlog and oldest age have a sustained negative/acceptable trend without SQLite lock storm.
- Failed jobs have bounded exact-ID disposition; no mass retry/clear was used.
- Import retention executes independently and old technical rows stay inside approved windows.
- Verified off-host backup and isolated restore rehearsal exist.
- Demo/load-test corpus is bounded and separated; any production cleanup is separately authorized and reconciled.
- No runtime `777`, root-owned compiled cache conflict or missing active Vite manifest remains.
- Deployment activates immutable code/assets together and has a verified rollback release.
- Node uses an approved LTS pin; patch groups are isolated and fully verified.
- PHP-FPM, OPcache, cold/warm public routes and SQLite writers meet measured budgets.
- Large-class refactors and static-analysis ratchets preserve every compatibility domain.
- CSP/alerts/database direction reflect implemented, verified capabilities rather than aspirational claims.
- Local and remote `main` refs match after a normal authenticated fast-forward push; no credential or rewritten-history workaround was used.
- Shared-checkout delivery has one cooperative owner at a time while every existing main/conflict/path/clean-tree/documentation guard remains enforced.
- Every commit from a shared checkout is bound to an explicitly reviewed final staged-index SHA-256; any final index difference invalidates approval without storing paths, contents or credentials, while byte-identical restoration remains an explicit procedural re-approval boundary.
- Every reviewed index has an exact predeclared NUL-safe task path set; any missing or foreign staged path fails before approval without printing paths, hashes, tokens or private absolute locations.
- System, importer, player, collection and later registered roadmaps contain commit-backed statuses and an explicit non-conflicting production activation/deployment order.
- The current-plan registry has one active owner and linked commit-backed stream rows; completed bodies remain losslessly archived, and the docs gate prevents a copied second active plan without imposing a task/line ceiling.
- README, canonical owners, maintenance registries, current plan, Russian changelog and final compliance matrix match actual state.
- Every completed allowed change is committed in existing `main`; push result is reported truthfully.
