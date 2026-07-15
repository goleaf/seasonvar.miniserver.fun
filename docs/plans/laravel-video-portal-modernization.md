# Laravel / Livewire production modernization — living plan

Обновлено: 15.07.2026. Владелец выполнения: текущая работа на существующей ветке `main`. План не является списком пожеланий: каждый пункт должен закончиться кодом или документированным решением, тестом/измерением, фазовым commit и явным remaining risk. Исторические завершённые решения сохранены в Git и тематических документах.

## Цель и definition of done

Довести существующий Laravel 13 / Livewire 4 каталог до доказуемого production-ready состояния без удаления функций, выдуманных product-моделей, скачивания видео или скрытия ошибок. Математическая гарантия отсутствия будущих дефектов невозможна; итогом служит воспроизводимый набор автоматических и ручных доказательств.

Финальный gate требует:

- production assets build; PHPUnit, bounded/full changed-scope static analysis and critical browser flows pass;
- no pending application migration for the deployed code, after verified backup and safe writer stop;
- production debug off, config/routes/events/views cached as appropriate, graceful workers/FPM reload verified;
- deployment preflight completes within a bounded time and cannot report queue green while critical backlogs/workers are unhealthy;
- no overlapping import lifecycle, orphan terminal group or unbounded finalizer retry remains;
- no Blade `@php`, query/facade/service/application logic, `request()` business logic or `config()` presentation logic;
- critical mutations have server-side validation and authorization; API never returns raw models/private media/secrets;
- critical catalog/player pages have measured query/payload budgets and no confirmed N+1;
- docs, changelog, exact deployment/rollback and all remaining risks are current;
- clean `main`; every allowed change committed; push only after all commit-specific gates pass.

## Status vocabulary

- `[x]` verified complete in the current pass.
- `[ ]` pending.
- `BLOCKED-EXTERNAL` needs environment/operator authority and is not falsely marked complete.
- `REJECTED` means evidence says not to implement now, with reason and rollback impact recorded.

## Phase 0 — Baseline, evidence and safe boundaries

### 0.1 Repository/runtime inventory

- [x] Confirm branch/status/remotes; current `main` is ahead of origin by commit `70df36b`, tree initially clean.
- [x] Inventory 1088 tracked files, app/config/routes/migrations/tests/views/assets/CI/deploy documentation.
- [x] Record framework/runtime/package versions and official compatibility/release sources.
- [x] Enumerate 114 routes, middleware, 35 controllers, 22 Requests, 29 Resources, 39 models, 17 enums, 31 DTOs, 25 Livewire files, 8 jobs, 12 commands, 3 notifications, 2 policies and 150 services.
- [x] Inventory server OS/CPU/RAM/storage, nginx/PHP-FPM, Redis/Memcached, workers, cron, TLS/HTTP protocol, image/media tools without disclosing secrets.
- [x] Create/update current-state, dependency, database, security, performance, frontend, Livewire and playback reports through existing documentation ownership.

Verification: repository scans, `artisan about/route:list/event:list/schedule:list/migrate:status`, package metadata, read-only process/config inspection. Rollback: documentation-only commit revert.

### 0.2 Behavioral and quality baseline

- [x] PHPUnit: 826 tests / 815 passed / 11 skipped / 6751 assertions / 93.554 s.
- [x] Playwright/axe: 18/18 / 1.4 min on deterministic isolated DB.
- [x] Pint and PHP syntax pass.
- [x] Vite build pass; asset sizes recorded.
- [x] Composer/npm audits: 0 advisories.
- [x] Configured Larastan: 0; full app level 6: 547 diagnostics, no suppression baseline.
- [x] Blade forbidden-token baseline: 41 `request()`, 1 `config()`, no `@php`/PHP blocks/query facades.
- [x] Production readiness baseline: debug enabled, two pending migrations, external 25 s deployment-check deadline exceeded, health false green, 11 active imports.

### 0.3 Phase commit

- [x] Run docs-refresh check, Markdown/link checks, diff review and focused documentation contracts: docs-refresh green; 13 focused documentation tests / 157 assertions; `git diff --check` green.
- [ ] Commit audit/plan as one focused phase-0 commit.

## Phase 1 — Production blockers and truthful health

### 1.1 Deep SQLite deployment preflight cost

- [x] Read Laravel database/queue/error-handling rules and inspect `CheckDeploymentReadiness` plus collaborators.
- [x] Profile every private check independently, then confirm end-to-end: wall 24.45 s; SQLite quick/FK 23655 ms; migrations 13 ms; FTS 303 ms; failed jobs 98 ms; importer 140 ms; remaining checks 0 ms after integer rounding.
- [x] Correct the initial diagnosis: the external 25 s deadline was too short; no unbounded/materializing preflight path was proven.
- [x] Add safe integer `duration_ms` per check to JSON and text output without paths, payloads or exception text; RED/GREEN feature contract verified.
- [ ] Preserve full quick/FK corruption detection and add regression coverage for normal, pending-migration, failed-job-volume and unavailable-backend results.
- [x] Measure the complete command: deterministic completion in 24.45 s within the documented >=30 s budget on the current 14+ GB dataset; integrity pass and expected gate failure remained distinct.

Rollback: revert duration-only instrumentation if it changes the stable JSON contract. Do not replace the integrity check with a weaker sample and do not add an index without target SQL/plan evidence.

### 1.2 Queue health correctness

- [x] Characterize `app:health` queue component and existing aggregate heartbeat storage.
- [x] Add RED/GREEN tests for import/cache backlog with default queue empty and for idle multi-queue worker liveness.
- [x] Report each critical queue (`default`, `cache-warm`, `seasonvar-import`, `seasonvar-title-refresh`) separately with pending/delayed/reserved/oldest age.
- [x] Distinguish transport reachability, worker liveness and backlog saturation; missing pool heartbeat with work is `failed`, empty unserved queue is `idle`, threshold backlog is `degraded`.
- [x] Keep JSON low-cardinality and secret-free; heartbeat keys are scoped by connection and queue.
- [x] Record heartbeat from worker loops as well as processed jobs, with bounded writes and no impact on job execution after an observability failure.
- [x] Reject expired heartbeat even inside a long-lived process by reading the operational store directly instead of request memoization.
- [x] Make CLI operationally strict (`degraded`/`failed` exit 1) while preserving HTTP 200 traffic readiness when database/session/queue/lock transports remain usable.
- [x] Verify 7 health tests / 49 assertions and 12 combined preflight/health tests / 88 assertions, targeted Pint and changed-scope Larastan 0; live CLI now exposes 32325 pending and exits 1 rather than false success.
- [ ] Complete rollout: install/drain a reviewed cache-warm pool and restart verified existing pools so scoped heartbeats appear.

### 1.3 Pending migrations and runtime environment

- [ ] Rehearse both pending migrations on a fresh full temporary SQLite database and exercise practical rollback.
- [ ] Rehearse against a disposable copy of the production schema/data if disk/time allows; record duration/size.
- [ ] Wait for safe importer boundary after lifecycle fix; stop writers; create timestamped SQLite backup; verify backup quick/FK check and size.
- [ ] Apply only normal `php artisan migrate --force`; verify required indexes/API schema/data defaults.
- [ ] Rebuild FTS only if state/version gate requires it; compare documents/source counts.
- [ ] `BLOCKED-EXTERNAL`: set `APP_DEBUG=false` and appropriate production logging outside Git; never edit `.env` in this work.
- [ ] After environment fix, run `config:cache`, `route:cache`, `event:cache`, `view:cache`, graceful FPM reload, `queue:restart`, then smoke.

Rollback: additive migration `down()` only if safe; otherwise restore verified backup and prior code/assets/config cache. Do not resume writers on failed integrity/preflight.

### 1.4 Dependency patch group

- [ ] Record lock hashes and official Laravel 13.20.0 changes; search repository for changed QueueFake, image, session prefix, Storage and HTTP header APIs.
- [ ] Update only `laravel/framework` to 13.20.0-compatible lock graph.
- [ ] Run Composer validate/audit/platform, Pint, Larastan, full tests and browser suite.
- [ ] Independently update `laravel-vite-plugin` 3.1.0 → 3.1.3, run `npm ci`, audit, build, asset diff and browser suite.
- [ ] Evaluate host Node 26.5.0 patch through its installed runtime manager; do not mutate system Node until rollback path/version manager is known.
- [ ] Record result and remaining risk in dependency report/upgrade/changelog.

Rollback: restore pre-update lockfiles and install exactly from them. Laravel and frontend patch updates are separate commits.

### 1.5 Phase gate/commit

- [ ] Focused RED→GREEN tests, full PHPUnit, build, browser, audits, preflight/health production read-only smoke.
- [ ] Diff review and focused commit(s); no environment secrets or backup files tracked.

## Phase 2 — Import lifecycle, retries and terminal consistency

### 2.1 Single active global cycle

- [x] Trace command → coordinator → run → page jobs → title groups → finalizers → completion events.
- [x] Write characterization tests for CLI/admin/repeated queued dispatch while another non-terminal global run exists.
- [x] Introduce one lifecycle-level atomic lock/active-run decision, not merely an enqueue critical section.
- [x] Return the existing run idempotently for duplicate cron/admin invocation; Russian command output explains that new work was not created.
- [ ] Harden stale-run recovery only after proving no-live-jobs/no-claims/no-terminal-signal conditions; current recovery contract remains intentionally conservative.
- [x] Keep browser-triggered title refresh separate from global-cycle ownership.
- [x] Update importer/queue runbooks so cron frequency cannot create new overlapping full runs; concurrent process stress remains a later verification layer.

Acceptance: repeated dispatch results in one active global run and no duplicate page/title-group work; command output explains reuse/skip in Russian.

### 2.2 Claims and state machine

- [ ] Enumerate every run/page/group status and legal transition using enums/typed methods.
- [ ] Add tests for crash before claim, crash after prepare, duplicate delivery, expired lease, missing title, page 404/503, permanent parse failure and manual retry.
- [ ] Centralize transition validation; use short transactions and atomic affected-row checks.
- [ ] Ensure terminal run/group cannot return to running through a stale job.
- [ ] Ensure live claim count reflects actual recoverable work.
- [ ] Add correlation/run/group/page context to logs without raw URLs/HTML/secrets.

### 2.3 Finalizer retry storm

- [x] Reproduce polling finalizer behavior with delayed/missing siblings and verify no release when work is not terminal.
- [x] Replace high-frequency self-release loop with deduplicated completion signals plus a bounded ten-minute unique watchdog.
- [ ] Define tries, retryUntil, timeout, backoff, uniqueness and failed behavior on every importer job.
- [x] Finalize only after all required pages reach terminal prepared state; unique jobs plus group/global apply locks tolerate duplicate delivery.
- [ ] Convert permanently impossible groups to explicit failed/abandoned state with user-safe reason code.

Verified increment: 52 importer lifecycle tests / 266 assertions; changed importer/model scope Larastan 0; targeted Pint pass; `schedule:list` exposes `*/10 * * * * seasonvar-import-finalization-watchdog`. Production failed-job aggregation found 4155 group-finalizer, 793 page and 9 preparation `MaxAttemptsExceededException` rows plus 1601 active groups. Historical rows/jobs were not retried, forgotten or cleared; code rollout and explicit reconciliation remain pending.

### 2.4 Failed-job reconciliation

- [ ] Aggregate failed jobs by class/reason/time without deserializing untrusted/full payloads in health paths.
- [ ] Map each failed finalizer to run/group/current live state.
- [ ] Retry only jobs proven recoverable after code fix; forget only obsolete duplicates after a recorded audit decision.
- [ ] Never run `queue:clear`; keep a before/after count and sampled reason table.

### 2.5 Queue worker topology

- [ ] Measure import/title-refresh DB write latency and CPU/memory at current 4+8 workers after duplicate work is removed.
- [ ] Install cache-warm systemd unit only after its queue is deduplicated and safe to drain.
- [ ] Verify heartbeat, restart, graceful stop, `queue:restart`, max-time/max-jobs recycling and boot persistence.
- [ ] Evaluate Horizon only after stable lifecycle; install only if its operational value exceeds systemd + project health metrics.

## Phase 3 — Import data efficiency and storage control

### 3.1 Semantic page fingerprints

- [ ] Capture representative unchanged/changed provider pages as legal deterministic fixtures.
- [ ] Define parser-owned canonical fingerprint that removes only proven volatile fields (timestamps/view counters/non-content tokens).
- [ ] Test that metadata, season, episode, media, subtitle, availability and relationship changes alter the fingerprint.
- [ ] Skip parse/write/derived rebuild when semantic fingerprint is unchanged while retaining bounded forensic metadata.
- [ ] Version the fingerprint algorithm so changes trigger safe re-evaluation.

### 3.2 Snapshot/prepared retention

- [ ] Inventory row/byte growth per day and recovery consumers.
- [ ] Separate latest operational snapshot from bounded historical evidence; compress only if measured and operationally supported.
- [ ] Prune in small indexed chunks only for rows older than documented windows and unrelated to active/non-terminal runs.
- [ ] Add dry-run/count mode, metrics, tests and rollback-by-backup procedure.
- [ ] Reclaim SQLite space only in a separately approved maintenance window; no automatic `VACUUM` on production.

### 3.3 Importer decomposition

- [ ] Add characterization coverage around the 2150-line importer, 1863-line parser and 1185-line pipeline before extracting boundaries.
- [ ] Extract cohesive capabilities only: identity, normalization, catalog persistence, media sync, relation sync, snapshot decision and derived invalidation.
- [ ] Keep external HTTP outside DB transactions; keep multi-table writes short and atomic.
- [ ] Prefer existing `Services/Seasonvar`, `Media`, `Crawler` patterns; no generic repository layer.
- [ ] Add `strict_types`, explicit generics/types while touching files; reduce full Larastan errors in each batch.

### 3.4 Derived work scheduling

- [ ] Record changed title IDs/signals during a completed cycle.
- [ ] Rebuild recommendations, FTS, cache and counters only for dirty scope where correctness allows.
- [ ] Run one full consistency reconciliation on a documented lower-frequency schedule.
- [ ] Ensure partial cycle failure never publishes a mixed derived version as complete.

## Phase 4 — Database/query/cache performance

### 4.1 Critical route profiling

- [ ] Capture home, catalog, search, title/player, directory, library, stats, sitemap and API v1 query counts/duplicate queries/bytes/timing under controlled state.
- [ ] Add missing query-budget tests for states not currently covered.
- [ ] Inspect SQL and `EXPLAIN QUERY PLAN` before any index or rewrite.
- [ ] Fix N+1, repeated counts, `select *`, non-sargable filters and rendering-time relationship loads.
- [ ] Record pre/post medians and p95 where sample size supports it.

### 4.2 Stats and layout builders

- [ ] Characterize output of 1962-line Stats builder and 1734-line AppLayoutData.
- [ ] Remove unreachable SEO/query matrices after confirming flags/consumers.
- [ ] Replace stats live aggregates with versioned compact snapshot(s) owned by catalog mutations/import finalization.
- [ ] Serve stale-safe previous snapshot during rebuild failure; lock against stampede.
- [ ] Keep public accuracy timestamp visible and cache invalidation explicit.

### 4.3 Cache inventory and invalidation

- [ ] For every domain key record owner/value/user scope/store/tags/TTL/stale/lock/max bytes/invalidation/fallback/security.
- [ ] Remove invalidation storms that bump thousands of domains when a narrow title changes.
- [ ] Ensure cache-warm jobs are deduplicated by version, not only a short TTL.
- [ ] Never cache user state, policy outcomes, raw media URL, signed grant or token in shared scope.
- [ ] Add stampede/failover/invalidation tests and low-cardinality metrics.

### 4.4 SQLite maintenance

- [ ] After backlog drain: checkpoint/optimize according to measured WAL state, update statistics and rerun plans.
- [ ] Review redundant/prefix indexes using actual reads and importer write cost; remove only via additive reversible migration with plan proof.
- [ ] Evaluate database engine migration only if stabilized workload still exceeds SQLite writer/SLA ceiling; do not perform speculative rewrite.

## Phase 5 — Passive Blade and Laravel architecture

### 5.1 Zero-logic Blade contract

- [ ] Extend `BladeTemplateTest` to fail on `request`, `config`, app/container resolution, facades, application static calls, service/query/action/policy calls and PHP blocks.
- [ ] Move route-active decisions into a typed navigation view model composed once per request.
- [ ] Move catalog directory maxlength and every option/label/URL/variant/permission flag into prepared state.
- [ ] Prepare safe JSON-LD scalar before Blade; keep one audited raw output only if required for valid JSON.
- [ ] Replace method-heavy collection derivations in the giant layout with prepared arrays/scalars.
- [ ] Verify all 52 Blade files and compile views.

### 5.2 Shared presentation boundaries

- [ ] Consolidate header/footer navigation into one shared prepared item schema.
- [ ] Reuse existing form/poster/status/panel components before creating new ones.
- [ ] Extract repeated components only for accessibility, semantics or stable reuse; no component-per-div abstraction.
- [ ] Keep complete static Tailwind class maps outside dynamic concatenation.

### 5.3 Controllers/actions/services/DTOs

- [ ] Audit every controller by line count and dependency graph; move only demonstrated business/query logic.
- [ ] Keep Requests for non-trivial HTTP validation/authorization and Forms for Livewire; share rules through focused validators/value objects.
- [ ] Introduce Action only for meaningful state transitions, not single-call renaming.
- [ ] Break domain dependency cycles (Models↔Catalog, Catalog↔Seasonvar, Catalog↔API sync) with narrow interfaces/events only at real substitution boundaries.
- [ ] Convert expected business failures to domain exceptions mapped to safe HTTP/API/Livewire errors.

### 5.4 Static typing

- [ ] Group full Larastan diagnostics by identifier/file/domain and fix production-risk types first (casts/resources/nullability/queue locks).
- [ ] Expand `phpstan.neon` paths only after each domain reaches zero.
- [ ] No broad baseline, no ignore comments, no casts/widening used only to silence analysis.
- [ ] Add `strict_types` to touched application files; later run small mechanical batches with full tests.
- [ ] Evaluate Rector only after static types stabilize; dry-run one reviewed ruleset at a time, otherwise reject.

## Phase 6 — Livewire 4 modernization

### 6.1 Per-component budgets

- [ ] Inventory responsibility/public/locked state/actions/forms/events/query count/render frequency/snapshot bytes for every component.
- [ ] Add payload/request/query budgets for catalog, directory, title, player, library, stats and admin representative states.
- [ ] Ensure identifiers are locked where appropriate and every mutation re-authorizes regardless.
- [ ] Remove large collections/Eloquent graphs from public properties.

### 6.2 Polling and isolation

- [ ] Measure title refresh polling payload/count at idle, active import and navigation.
- [ ] Poll only while refresh state is active or use explicit completion/version signal; stop listeners/timers on destroy.
- [ ] Use lazy/defer/islands only for measured independently updating expensive regions.
- [ ] Preserve SSR metadata and no-JS navigation for catalog pages.

### 6.3 Component decomposition

- [ ] Split admin/player god components along actual workflows after characterization.
- [ ] Prefer explicit parent-child props/actions; document global event producer/consumer and cleanup.
- [ ] Prove `ViewingActivity` component is dead before removal while retaining legacy URL redirect.
- [ ] Ensure pending/success/empty/validation/permission/failure state for every action.

### 6.4 `wire:navigate` decision

- [ ] Build an isolated spike only after player cleanup tests exist.
- [ ] Verify title/meta/canonical, analytics, scroll, auth redirects, player teardown, event cleanup and back/forward.
- [ ] Enable only with measured UX benefit and no lifecycle regression; otherwise document rejection.

## Phase 7 — Player, accessibility and design system

### 7.1 Deterministic media fixtures

- [ ] Add tiny legal/local HLS master/media playlist, MP4 range fixture and subtitle fixture, or deterministic network mocks at the provider boundary.
- [ ] Cover valid/invalid manifest, timeout, expired/forbidden grant, MIME/CORS/range headers, subtitle failure and offline state.
- [ ] Keep real-provider checks optional and credential/network gated.

### 7.2 Player lifecycle

- [ ] Test one initialization/destruction per morph/navigation; no leaked global listeners/timers/media sessions.
- [ ] Verify native HLS before HLS.js fallback, retry policy and source switching.
- [ ] Verify progress throttle, pause/leave checkpoint, monotonic completion, restart and next-season resolution.
- [ ] Verify keyboard, touch, captions, speed, volume persistence, fullscreen, PiP, Media Session, orientation and reduced motion where supported.

### 7.3 Tailwind/design system

- [ ] Audit current theme variables against surfaces/text/status/focus/radius/shadow/spacing/type/content/player/poster/motion tokens.
- [ ] Remove arbitrary repeated colors/z-index/spacing only where evidence shows inconsistency.
- [ ] Audit all pages at 390, 768, 1024, 1440 and TV-like large viewport for overflow, touch targets, focus, image dimensions and layout shift.
- [ ] Preserve light UI owner standard; add dark mode only under a separate explicit product decision.
- [ ] Add meaningful skeleton/error/empty states without decorative loading noise.

## Phase 8 — API, security, observability and server

### 8.1 API contract inventory

- [ ] Generate/compare route → consumer/auth/ability/request/resource/errors/pagination/filter/sort/cache/rate-limit/idempotency matrix.
- [ ] Assert every public endpoint returns Resource/response object, no raw Eloquent/private fields.
- [ ] Verify consistent request-ID error envelope and safe exception mapping across 401/403/404/422/429/500.
- [ ] Keep rate limits on abuse-prone auth/search/playback/progress/import paths without harming normal navigation.

### 8.2 Security hardening

- [ ] Complete mutation authorization/validation inventory including Livewire actions.
- [ ] Re-run XSS/raw HTML, SQL raw fragments, SSRF redirects/DNS/private IP, upload MIME/path, process execution, secret/log and CORS scans.
- [ ] Add hostile JSON-LD/import URL/media URL regression fixtures.
- [ ] Narrow CSP from observed origins and stage report-only → enforcement with rollback flag.
- [ ] Verify debug off, secure cookies, trusted hosts/proxies, HSTS/TLS and no diagnostic exposure.

### 8.3 Observability package decisions

- [ ] Define required metrics/alerts first: queue oldest age, active runs, failed transition rate, import throughput, DB write latency, cache hit/rebuild/eviction, HTTP p95/5xx, disk growth, backup age.
- [ ] Evaluate Pulse for protected production visibility; document storage/auth/retention.
- [ ] Evaluate Horizon only if Redis worker management/visibility improves after lifecycle fix.
- [ ] Keep Telescope development-only or reject; never expose publicly or retain sensitive payloads.
- [ ] Reject Reverb/Pennant without product use case; reject Octane without long-lived audit/benchmark.

### 8.4 Server tuning

- [ ] Inspect actual aaPanel PHP-FPM pool and per-process RSS; calculate sustainable workers from 4 CPU and memory rather than current count alone.
- [ ] Review production OPcache (memory, interned strings, files, timestamp validation); benchmark after config cache.
- [ ] Disable JIT unless a representative benchmark proves benefit; current 64 MiB allocation is not evidence.
- [ ] Verify gzip/Brotli/Zstd, HTTP/2/3, TLS renewals, asset cache headers and provider redirects.
- [ ] Verify logrotate installation, backup schedule/restore drill, disk alerts, cron/scheduler ownership and process boot persistence.
- [ ] Record absence/presence of FFmpeg/ffprobe as informational because application does not transcode.

## Phase 9 — Final verification, rollout and handoff

### 9.1 Automated gates

- [ ] `composer validate --strict`, `composer audit`, `composer check-platform-reqs`.
- [ ] `npm ci`, `npm audit --audit-level=high`, production build and asset-size diff.
- [ ] Pint test, PHP syntax lint, bounded Larastan zero and all newly expanded scopes zero.
- [ ] Focused tests for every change, then full `php artisan test` and PHPUnit parity if needed.
- [ ] Full Playwright/axe matrix with console/network guard, auth/admin/catalog/player/library/error/mobile scenarios.
- [ ] Blade zero-logic scan, route/event/config/view cache builds, docs-refresh and `git diff --check`.

### 9.2 Production rollout

- [ ] Confirm clean verified commit and backup/rollback artifacts outside Git.
- [ ] Stop dispatcher/writers at safe boundary; backup and verify; deploy dependencies/assets/code in documented compatible order.
- [ ] Migrate, rebuild required derived state, cache config/routes/events/views, restart/reload workers/FPM.
- [ ] Run bounded preflight, health, queue/import status, HTTP/API/browser smoke and error-log sampling.
- [ ] Resume exactly one importer dispatch profile and all required worker pools; observe one full cycle.
- [ ] Push verified commits only when remote credentials are available and `main` status is confirmed.

### 9.3 Final report

- [ ] Executive summary; original/fixed architecture/security/performance/DB/API/Livewire/Tailwind/player/server findings.
- [ ] Packages installed/removed/rejected, migrations, commands, tests and exact results.
- [ ] Before/after queries, timings, payloads and asset sizes with measurement context.
- [ ] Browser scenarios, docs, commits/push state, remaining risks/deferred work.
- [ ] Exact deployment and rollback steps. No “zero bugs” claim.

## Deferred product decisions, not hidden defects

- Multiple named watchlists/favorites/collections beyond the existing watchlist require a product model and migration.
- Rights territories/subscriptions/profiles/PIN/DRM require legal/product specifications.
- Upload/transcode/object storage/CDN video pipeline is outside the current external-authorized-source architecture.
- Real-time broadcasting, Pennant rollout and dark mode require a demonstrated product need.
- Database engine migration requires post-stabilization SQLite SLA evidence.

## Phase update rule

After every phase: review diff, remove unrelated changes, run appropriate focused then broad gates, update this file’s checkboxes/evidence, update topic owner and changelog, create a focused `main` commit, and immediately add any newly discovered regression to the earliest applicable unfinished phase. A failing gate is work to diagnose, not text to explain away.
