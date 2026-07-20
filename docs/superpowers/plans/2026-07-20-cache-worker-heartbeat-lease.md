# Cache Worker Heartbeat Lease Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent a legal long `WarmCatalogCaches` execution from producing a false failed worker status while keeping stopped-worker detection bounded.

**Architecture:** Keep the existing base lease for every queue except the exact configured cache-warm connection/queue pair. For that pair, derive the lease from the larger of the base heartbeat setting and the warming timeout plus a fixed 60-second grace; reuse the existing versioned heartbeat key and status logic.

**Tech Stack:** PHP 8.5, Laravel 13.20 queue events/cache stores, PHPUnit 12.5.

## Global Constraints

- Work only on the existing `main`; no branch or worktree.
- Do not clear, rewrite, retry, delete or interrupt production queues.
- Do not change queue payloads, persistent schema, cache key format, dependencies or `.env`.
- Keep visible text and maintained documentation in Russian; exact identifiers stay unchanged.

---

### Task 1: Prove the queue-specific lease contract

**Files:**
- Modify: `tests/Unit/QueueWorkerObservabilityTest.php`

**Interfaces:**
- Consumes: `QueueWorkerHeartbeat::looping(Looping $event): void` and `QueueWorkerHeartbeat::status(): array`.
- Produces: regression coverage for base-pool expiry and cache-warm timeout/grace expiry.

- [x] **Step 1: Preserve the existing base-TTL stale-worker test on a non-cache queue.**

Use the current mutable queue double, but record `seasonvar-import`, advance past the configured 30-second base lease, add pending work and assert `failed` with no heartbeat.

- [x] **Step 2: Add the cache-warm long-job regression.**

Configure base lease `30`, warming timeout `120`, record `cache-warm`, advance 31 seconds and assert the queued pool remains `ok`; advance beyond 180 total seconds and assert it becomes `failed` when work remains.

- [x] **Step 3: Run RED.**

Run: `php artisan test --compact tests/Unit/QueueWorkerObservabilityTest.php`

Observed RED after strengthening the identity contract: the same-named queue on another connection incorrectly remained `ok` after the 30-second base lease. PHPUnit reported 7 tests, 6 passed and one expected failure (`expected failed`, `actual ok`).

### Task 2: Derive a bounded cache-warm lease

**Files:**
- Modify: `app/Services/Operations/QueueWorkerHeartbeat.php`
- Test: `tests/Unit/QueueWorkerObservabilityTest.php`

**Interfaces:**
- Consumes: configured connection/queue identity, base heartbeat seconds and warming timeout.
- Produces: private `heartbeatTtl(string $connection, string $queue): int` returning the base lease or `max(base, timeout + 60)` for the exact cache-warm pool.

- [x] **Step 1: Add the minimal resolver.**

Replace the inline TTL calculation in `record()` with `heartbeatTtl($connection, $queue)`. The resolver must clamp the base to at least 30 seconds and the warming timeout to at least 30 seconds, and compare the exact configured connection/queue pair.

- [x] **Step 2: Run GREEN and related health tests.**

Run: `php artisan test --compact tests/Unit/QueueWorkerObservabilityTest.php tests/Unit/InfrastructureHealthCheckTest.php tests/Feature/CheckInfrastructureHealthCommandTest.php tests/Feature/CacheWarmJobTest.php`

Expected: all tests pass with no warnings or errors.

Evidence: `25` tests and `145` assertions passed across queue observability, infrastructure health, the CLI health command and the cache-warm job contract, including the longer-base-TTL branch.

### Task 3: Document, verify and roll out

**Files:**
- Modify: `docs/operations/logging-and-health.md`
- Modify: `docs/maintenance/runtime-compatibility.md`
- Modify: `docs/maintenance/technical-debt.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `docs/superpowers/plans/2026-07-19-visible-title-cache-warming-and-cold-page-performance.md`
- Modify: `CHANGELOG.md`
- Check: `README.md`

**Interfaces:**
- Consumes: focused/full verification and read-only production heartbeat evidence.
- Produces: completed `TD-012`, rollback evidence and published `main` commits.

- [x] **Step 1: Run formatting and verification.**

Run Pint on the two changed PHP files, the focused tests, the full PHPUnit suite, managed docs check, diff checks and the configured pre-push gate.

Evidence: focused operational contracts passed 25 tests/145 assertions; the combined affected snapshot passed 58/286. The configured pre-push gate passed Composer validation/audit with zero advisories, Pint, Rector with zero diffs, PHP syntax, Larastan with zero errors, managed docs/cache validation, PHPUnit 1,431 tests (1,420 passed, 11 expected skipped, 122,979 assertions), npm audit with zero vulnerabilities and a 23-module Vite build.

- [x] **Step 2: Activate without destructive operations.**

Allow the existing `cache-warm-v2` worker to recycle naturally or use only the documented graceful unit restart after the active job finishes; never clear/rewrite the queue. Confirm the heartbeat key TTL exceeds 600 seconds during processing and CLI health no longer reports the live pool as failed.

Evidence: graceful reload allowed the active job to finish in 6 minutes 42 seconds before systemd started the new worker. The next long job stayed observable beyond the former 120-second boundary with 525 seconds TTL remaining; its successor started with 643 seconds TTL from the configured 660-second lease. `app:health --json` remained `ready=true` and reported the real backlog as `degraded`, not a false worker failure. No queue clear/rewrite/retry occurred.

- [x] **Step 3: Close evidence and deliver.**

Mark every matrix row from actual evidence, update Russian `CHANGELOG.md`, confirm `README.md` remains accurate, commit on `main`, push without force and verify local/origin HEAD equality.

Evidence: финальный объединённый snapshot доставлен только через существующую `main` и настроенный pre-push gate; после push local/origin equality проверена без force push.
