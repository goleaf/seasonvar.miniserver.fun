# Laravel / Livewire production modernization — living plan

Обновлено: 16.07.2026. Владелец выполнения: текущая работа на существующей ветке `main`. План не является списком пожеланий: каждый пункт должен закончиться кодом или документированным решением, тестом/измерением, фазовым commit и явным remaining risk. Исторические завершённые решения сохранены в Git и тематических документах.

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
- [x] Preserve full quick/FK corruption detection and add regression coverage for normal, pending-migration, failed-job-volume and unavailable-backend results.
- [x] Measure the complete command: deterministic completion in 24.45 s within the documented >=30 s budget on the current 14+ GB dataset; integrity pass and expected gate failure remained distinct.

Verified increment: 8 focused deployment-readiness tests / 60 assertions cover a fully ready runtime, full SQLite quick/FK execution, pending migration without application, 450 failed jobs across the bounded 200-row iterator and unavailable SQLite. The unavailable-backend RED case exposed `Schema::hasTable()` outside the FTS check's exception boundary; moving the capability probe inside the existing local `try` now returns stable secret-free fail checks and exit 1 instead of aborting the command.

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

Cross-mode closure increment: read-only production topology and database inspection found one live queued sitemap run and one live sync sitemap run, both with current heartbeat. The original queue-only coordinator and separate sync command lock made that state possible. `SeasonvarGlobalImportRunCoordinator` now serializes active lookup plus reservation across both execution modes; full sync CLI/legacy job execute the reserved row through the existing pipeline, while URL-targeted, inventory and status paths remain independent. No current run, worker, claim, queue row or catalog data was mutated. Focused result: 80 tests / 426 assertions; changed PHP syntax/Pint and bounded Larastan passed. Broad importer verification is pending completion after concurrent Recommendation V3 route work stops changing the shared tree.

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
- [x] Define tries, retryUntil, timeout, backoff, uniqueness and failed behavior on every importer job.
- [x] Finalize only after all required pages reach terminal prepared state; unique jobs plus group/global apply locks tolerate duplicate delivery.
- [x] Convert permanently impossible groups to explicit failed/abandoned state with user-safe reason code.

Approved design for the remaining transition: preserve the existing `failed/partial` terminal statuses and add one nullable stable reason code rather than a competing `abandoned` state or a machine-parsed error string. The watchdog may wake a non-ready group only after `max(retry_window, claim_window) + 300s`; reconciliation must recheck the active run, stale timestamp and absence of live claims transactionally. Expired nonterminal page rows become safe terminal failures so valid siblings can still apply as `partial`; an empty or structurally mismatched page set becomes `failed`. Fresh work, live claims, terminal groups and historical failed queue rows remain untouched. User-visible diagnostics use an allowlisted Russian message derived from the code and never raw URL/payload/exception text.

Verified increment: 52 importer lifecycle tests / 266 assertions; changed importer/model scope Larastan 0; targeted Pint pass; `schedule:list` exposes `*/10 * * * * seasonvar-import-finalization-watchdog`. Production failed-job aggregation found 4155 group-finalizer, 793 page and 9 preparation `MaxAttemptsExceededException` rows plus 1601 active groups. Historical rows/jobs were not retried, forgotten or cleared; code rollout and explicit reconciliation remain pending.

Queue contract increment: a matrix regression now covers all eight importer jobs, the attempt-bounded versus deadline-bounded split, exact timeout/backoff/deadline/unique identity and the invariant `900s timeout < 1200s retry_after`. RED inspection found only the watchdog without a terminal failure boundary; its new `failed()` emits low-cardinality job/class context and never the exception message. The source-page job remains deliberately non-unique because its claim token is the delivery ownership boundary. Focused result: 2 tests / 66 assertions; no historical queue row was retried, forgotten or cleared.

Impossible-group increment: production read-only aggregation found 43 active groups, all counter-ready with 181 prepared rows, no live claims, zero page-set mismatches and zero stale `queued/preparing` rows; no data was mutated. The bounded watchdog now also wakes active groups older than the full retry/claim window plus 300 seconds, while the finalizer transaction rechecks freshness and claims before applying one of five stable reason codes. Prepared siblings remain salvageable as `partial`; structurally impossible/all-unprepared groups become `failed`. The additive nullable code and `(status, updated_at, id)` watchdog index passed isolated SQLite up/down rehearsal. RED/GREEN focused result: 16 tests / 93 assertions; broader importer lifecycle result: 60 tests / 371 assertions; changed application scope Larastan 0.

### 2.4 Failed-job reconciliation

- [x] Aggregate failed jobs by class/reason/time without deserializing untrusted/full payloads in health paths. `app:deployment-check` selects only the allowlisted display-name and bounded exception prefix; payload/exception bodies never enter its result.
- [x] Map each failed finalizer to run/group/current live state through a separate bounded read-only operator command. It inspects only exact allowlisted finalizer envelopes below a fixed byte cap, validates the serialized root and one positive scalar target ID without `unserialize()`, and never exposes payload, exception, URL, token or queue UUID.
- [x] Classify mapped rows as `forget_candidate`, `retain`, `canonical_signal_candidate` or `manual_review`. These are audit hints, not authority to mutate: active ready targets use the current watchdog/signal path, never replay of a historical serialized envelope.
- [x] Retry only jobs proven recoverable after code fix; forget only obsolete duplicates after a recorded audit decision. The audit intentionally has no retry/forget/dispatch switch; current evidence proved no historical finalizer should be retried, while forget candidates remain preserved pending an explicit recorded disposition.
- [x] Never run `queue:clear`; keep a before/after count and sampled reason table. The read-only pass reported 6,714 before and the independent post-count remained 6,714.

Approved design: keep the mandatory deployment preflight fast and low-cardinality, share one allowlisted classifier for job kind and failure reason, and isolate finalizer state mapping in `app:failed-job-audit`. The audit streams `failed_jobs` in 200-row chunks, caps payload/exception reads at SQL selection, resolves current groups/runs/claims through grouped queries, and returns aggregate state/disposition counts plus bounded ID-only samples. Missing/terminal targets are only forget candidates; active work is retained; a current active target already eligible for the canonical watchdog is a signal candidate; malformed, oversized or inconsistent records require manual review. The report always includes zero mutation counters. Database unavailability fails closed with a Russian secret-free message. No migration, queue write, cache write or provider request is required.

Implementation slice:

- [x] RED/GREEN safe summary tests for current importer classes, stable reason buckets, malformed JSON and absence of payload/exception text.
- [x] RED/GREEN audit tests for exact scalar extraction, oversized/malformed envelopes, missing/terminal/active/inconsistent targets, grouped claim state, bounded samples and zero mutation.
- [x] Add the read-only command and JSON/table output; preserve `seasonvar:import` as the sole public import command.
- [x] Run focused tests, importer/operations regression, Pint, changed-scope Larastan, full PHPUnit and documentation checks.

Read-only production evidence: 6,714 total rows split into 4,155 title-group finalizers, 793 source-page jobs, 9 preparation jobs and 1,757 cache-warm jobs; reason buckets were 6,707 `attempts_exhausted` and 7 `provider_connection`. All 4,155 finalizer envelopes passed exact scalar parsing and every referenced group is now terminal, so the audit emitted only `forget_candidate`; no retry is justified and no row was forgotten. One bounded sample represented the same terminal/attempts-exhausted category. Report mutation counters were all zero, and the independent post-count remained 6,714.

Verification: 135 importer/operations tests / 827 assertions passed; full PHPUnit completed 939 tests with 928 passed, 11 skipped and 7,477 assertions. Targeted Pint, managed documentation refresh/link check, diff whitespace check and bounded application Larastan including the new command all passed with zero diagnostics.

Rollback: remove the optional command/classifier/report code and restore the prior summary shape. No data rollback is necessary because this slice introduces no schema or state mutation. Do not use historical failed-job retry/forget as rollback.

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

- [ ] Characterize output of the 1962-line Stats builder; AppLayoutData characterization is complete.
- [x] Remove unreachable SEO/query matrices after confirming no producer enables their flags and preserving canonical/OpenGraph/builder JSON-LD output.
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

- [x] Extend `BladeTemplateTest` to fail on `request`, `config`, app/container resolution, auth/gate directives, infrastructure facades, application static calls and PHP blocks.
- [x] Move header/footer route-active, audience and import-permission decisions into shared typed `LayoutNavigationItem` instances composed once per request.
- [x] Move catalog directory maxlength plus header/footer labels, URLs, class variants, year and permission visibility into prepared state; continue the same boundary for remaining feature templates as they are modernized.
- [x] Prepare hex-safe JSON-LD strings through Laravel `Js::encode()` before Blade; keep one audited raw scalar output with closing-script/XSS regression.
- [x] Replace method-heavy collection derivations in the giant layout with an explicit scalar/array contract; remove `extract()` and `get_defined_vars()`.
- [x] Verify all 52 Blade files against the strengthened forbidden-token scan and compile views; full URL/label presenter migration remains a broader follow-up rather than hidden inside this gate.

### 5.2 Shared presentation boundaries

- [x] Consolidate header/footer/directory navigation into one shared immutable prepared item schema.
- [ ] Reuse existing form/poster/status/panel components before creating new ones.
- [ ] Extract repeated components only for accessibility, semantics or stable reuse; no component-per-div abstraction.
- [x] Keep complete static Tailwind class maps in the PHP presenter/view-model boundary; production build confirms detection.

Verified increment: forbidden Blade scan returns zero across 52 files; `view:cache` and Vite production build pass; focused shell/auth/Blade tests 42/339, broader Blade/catalog tests 120/1126 and full PHPUnit 840/6882 pass; AppLayoutData/CatalogDirectoryBrowser/LayoutNavigationItem Larastan 0. The 21/21 desktop/mobile/tablet Playwright matrix passes. App CSS is now 156.01 kB / 33.09 kB gzip because Tailwind detects the complete prepared class maps; lazy player chunks are unchanged.

Verified layout/SEO increment: AppLayoutData/layout shrink from 1,928/783 to 411/96 lines; the same 40-term, 100-iteration presenter harness improves from 23.894/25.323 ms median/p95 to 0.536/0.834 ms. Focused 130/1,198, full 848/6,928, Larastan 0, compiled views, production build and the 21/21 desktop/mobile/tablet matrix pass; no package/schema/environment change was made.

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

## Task 01 — multilingual home integration

Status: application, route, translation, SEO, sitemap, cache, documentation and explicitly non-test static release gates completed on 16.07.2026. Task 01 was committed together with its overlapping Task 20 integration in `7753482` and pushed to the existing `main` branch. The mandatory 40-point baseline and design decision are recorded in [`../superpowers/specs/2026-07-16-multilingual-home-integration-design.md`](../superpowers/specs/2026-07-16-multilingual-home-integration-design.md); the executable checklist is [`../superpowers/plans/2026-07-16-multilingual-home-integration.md`](../superpowers/plans/2026-07-16-multilingual-home-integration.md).

- [x] Preserve the existing Laravel PHP catalog, `ApplyAccountPreferences`, account settings, partial localized routes and collection/tag translation tables; do not introduce a package, JSON catalog, title-translation schema or machine translation.
- [x] Add one localized `/{locale}` home alias, validated POST switcher, session/user persistence and web/Livewire locale reapplication with a strict `ru`/`en` allowlist.
- [x] Localize all homepage/layout copy, dynamic update codes, dates, numbers, plurals, empty/loading/error/end states and accessibility labels; preserve provider/original/studio/audio/UGC values.
- [x] Localize home canonical, Open Graph/Twitter payload, JSON-LD, reciprocal `hreflang`, x-default and sitemap entries without translated-slug invention.
- [x] Keep translated home cache keys separated by locale, warm every supported locale and invalidate all variants through the existing Homepage generation.
- [x] Document locale selection/fallback, translation storage/admin workflow, Livewire lifecycle, cache, SEO and translator validation.
- [x] Complete the explicitly non-test static/Pint/Vite/docs/diff gate, commit the reviewed Task 01/20 overlap as one coherent change and verify the pushed `main` SHA.

## Task 10 — canonical serial collections and curated lists

Status: implementation and disposable-browser acceptance complete; final static release gates, commit and push are in progress on 2026-07-15. This section replaces the former deferred named-collections decision and is the single implementation plan for the collection domain.

### Audited baseline

- [x] Repository routes, schema, models, policies, Livewire components, views, JavaScript, translations, cache, SEO, sitemap, API, importer merge, account deletion and documentation were inventoried before implementation.
- [x] No pre-existing named collection/list/playlist/folder model, route, pivot or legacy table exists in the repository or current SQLite schema. The only personal grouping is `catalog_title_user_states.in_watchlist`; UI labels «Избранное» and «Буду смотреть» are the same canonical watchlist and must remain independent from collections.
- [x] Collection candidates are serial-level `CatalogTitle` records only. Seasons, episodes, users and external links are not existing collection item types and will not be added speculatively.
- [x] There are no current collection rows, slugs, ordering values, covers or translated visibility values to reconcile. The migration can therefore add uniqueness before accepting writes without deleting legacy data.
- [x] Existing public title slugs are global with a history table. Collections will follow that proven global current-slug plus history pattern while using an opaque stable public ID and database ID as identity.
- [x] At the initial audit there were no reusable likes, follows, user comments, collaborator, general report or UGC moderation tables. During implementation Task 12 introduced the portal-wide discussion target/reaction/report/moderation boundary with an explicit `collection` target; Task 10 now embeds that one component and does not create a competing collection-comment table. Collection likes, follows and collaborators remain unsupported because no reusable domain exists for them.
- [x] The current portal has `ru` and `en` PHP translation catalogs but no locale middleware, prefixed public routes, database editorial-translation records or user locale preference. Collection aliases may localize interface chrome without claiming user content is translated.
- [x] Full guest HTML caching already separates authenticated requests and Livewire requests. Collection mutations must add a dedicated public version domain; private/unlisted management and current-user membership must stay `private, no-store` and outside shared cache.
- [x] Account deletion physically removes the user and cascaded personal state. Collection cleanup/export and private-cover cleanup must be explicit before user deletion. There is no current account export endpoint.
- [x] `SeasonvarTitleMerger` force-deletes duplicate titles after moving catalog relations; collection membership must be reconciled before that delete or foreign-key cascades would lose memberships.
- [x] Public profiles, role tables and RBAC do not exist. Editorial/system/moderation authority will reuse the existing `manage-catalog` administrator gate; public owner routes must use an opaque identifier and never expose email or numeric user IDs.
- [x] Laravel Boost is installed but its Artisan namespace is unavailable in this checkout, so version-dependent behavior is checked against installed Laravel/Livewire source and official Laravel 13 / Livewire 4 documentation.

### Canonical domain contract

- Stable identity: `catalog_collections.id` is the relational identity; `public_id` is the non-secret external identifier; name, slug, owner name, locale, cover and visibility are mutable attributes.
- Types: stable `user`, `editorial` and `system` codes only. User collections require an owner. Editorial/system mutation and featuring require `manage-catalog`; smart collections are not introduced.
- Visibility: stable `private`, `unlisted`, `public` codes. New user collections default to `private`. Private records are owner/admin-only and never indexed or shared-cached; unlisted records permit direct public-safe viewing but are excluded from directory/search/sitemap and use `noindex`; public records require an approved moderation state.
- Moderation: stable `pending`, `approved`, `rejected`, `hidden` and `archived` codes. Public/unlisted UGC changes return to pending review; private owner access remains available. Internal moderation/report notes are never serialized publicly.
- Items: one `CatalogTitle` per collection, enforced by service-level idempotency and a database unique key. One title may belong to many collections. Stored items contain identity, manual position, added-by identity and timestamps, not duplicated title metadata.
- Ordering: stored manual positions are deterministic and normalized by authorized transactional actions. Automatic modes change query ordering without rewriting manual positions. Up/down controls are the keyboard/touch baseline; drag-and-drop is not required.
- Slugs: global lowercase public slugs resolve to stable records. Renames retain historical slugs and authorized legacy requests redirect once to the canonical URL; current/history collisions are checked together.
- Covers: validated raster images use the existing private `uploads` disk and an authorization-aware delivery endpoint. No request-time collage generation is added; the first visible poster and existing placeholder are safe fallbacks.
- Text: user names/descriptions stay in their original language, are Unicode-normalized plain text, and are always escaped. No automatic translation or rich HTML is introduced.
- Social extensions: collection discussion reuses the canonical Task 12 target resolver, comments, reactions, reports, blocks/mutes and moderation UI. Permanent collection deletion privacy-retires active target comments while preserving their rows/evidence. Collection likes, follows and collaborators remain intentionally unsupported because no reusable domain exists; collection reporting/moderation remains the canonical collection-level boundary.

### Route, query and integration architecture

- Public directory/page routes use `/collections` and `/collections/{slug}`; authenticated management uses `/my/collections`; opaque owner profiles use `/profiles/{public_id}/collections`; `/lists` and `/selections` are compatibility redirects, not duplicate controllers.
- `/{locale}/collections...` aliases support the configured `ru`/`en` interface while the unprefixed URL remains canonical for untranslated user content. User-list aliases preserve identity/query state and remain noindex without `hreflang`. Editorial names/descriptions/SEO fields use the dedicated database translation rows: only real non-default translations are self-canonical/indexable and receive reciprocal locale alternates; the default locale resolves canonically to the unprefixed URL.
- A policy is authoritative for view/create/update/delete/restore/items/reorder/cover/moderate/feature/report. Owner IDs, types, moderation states and feature flags are assigned or allowlisted server-side.
- One collection query boundary owns public eligibility, owner-visible records, counts, filtered item pagination and title-membership existence. It must reuse catalog visibility and title-card eager-load contracts and avoid translation/pivot duplication and N+1 queries.
- One action/service boundary owns create/update/delete/restore, slug history, cover replacement, add/remove/batch membership and reorder. Mutations run in short transactions and invalidate collection public scope after commit without altering watchlist, ratings, progress or history.
- The title page receives a separate stable-ID Livewire membership component so staged multi-selection/create-and-add cannot reset player state. Public collection discovery containing the current title is a separate public-safe query.
- Profile/dashboard, public owner page, admin moderation, account export/deletion, title merge, public search within the collection directory, recommendations between collections, SEO presenter, structured data and streamed sitemap extend existing boundaries rather than duplicating them.

### Database, cache, SEO and compatibility risks

- [x] The audit found no legacy collection tables or data, so there is no duplicate/visibility/position backfill to run. New uniqueness is safe at the empty-domain boundary; title merges still reconcile memberships idempotently before a duplicate title is force-deleted.
- [x] The first cover implementation exposed a stale-privacy risk by marking versioned public cover responses `immutable`; final delivery is authorization-aware and `private, no-store`, so public-to-private changes cannot continue through a shared/browser cover cache.
- [x] The first staged membership query performed per-collection membership/count reads. The final transaction uses one manageable set, one membership snapshot, one grouped count/max query and bulk insert/delete, with normalization only for changed removals.
- [x] Permanent deletion originally risked cascading collection reports and leaving live generic comments against a missing target. Reports now retain the stable collection UUID/content version with a nullable relation, while the shared comment lifecycle soft-retires active comments without erasing bodies, reactions, reports or moderation evidence.
- [x] The first public API directory reused the web paginator key `collectionsPage`, so validated API `page` values were ignored. The canonical query now accepts an explicit paginator key: web keeps `collectionsPage`, API uses conventional `page`, and both retain one deterministic query implementation.
- [x] Disposable runtime inspection found Laravel 13 passes a `HasMany` relation, not an Eloquent `Builder`, to constrained eager-load callbacks. Removing the invalid callback type from the two translation loads fixed the otherwise reproducible collection-directory HTTP 500 without weakening query constraints.
- [x] Browser/control inspection found presentation regressions before release: `wire:loading.delay.flex` left the loading notice visible in the compiled Livewire CSS contract, a missing-cover fallback incorrectly described a non-empty collection as empty, cover mutations lacked an explicit progress label, and edge reorder buttons reported success after a safe no-op. Loading now uses reliable outer wrappers/localized states; unavailable move directions are disabled and the service returns a truthful localized unchanged result for stale edge actions.
- [x] The first disposable browser process inherited the explicit production cache namespace even though its default Laravel store was overridden. Because the existing trusted-host full-page key is route/locale/state based, the collection domain was narrowly advanced from version 8 to 9; no global flush occurred, no audit URL existed in the warm manifest, and every subsequent smoke process used isolated array stores plus `CACHE_ENVIRONMENT=task10` and a separate config-cache path.
- [x] Final collection-scope Larastan found the item-filter relation map used singular `genre`/`country` names even though `CatalogTitle` exposes canonical plural relations. The map now uses `genres`/`countries`/`statuses`; filter option subqueries reuse visible title IDs, and malformed batch-membership or reorder values fail as one validated payload instead of being silently discarded. The focused domain analysis is green with zero diagnostics.
- [x] Final merge-path inspection found duplicate-title membership reconciliation materialized every affected row and registered repeated whole-domain cache callbacks. It now uses `eachById(500)` under the existing merge transaction and one after-commit invalidation. Disposable reconciliation retained the earliest timestamp/lowest position, normalized both collections to position 1, moved the duplicate-only membership and left zero duplicate-title rows.
- [x] Final deployment-boundary inspection found direct directory/profile/admin/resolver and account lifecycle calls could query collection tables while an additive migration was not yet available during a rolling deployment. The canonical schema probe now validates every required table plus `users.public_id`, fails closed on schema inspection errors, returns empty paginators/exports for read boundaries, makes account cleanup a safe no-op before the domain exists, rejects create with a safe `503`, and resolves direct collection identities as `404`; sitemap/search/home already had equivalent readiness guards.
- [x] The same rolling-deploy inspection found the model creation hook assigned `users.public_id` even before that additive column existed, so registration during a code-before-migration window could emit an unknown-column write. The hook now probes the actual user table before assignment; migration backfill and post-migration user creation still guarantee opaque IDs, while pre-migration account creation keeps the legacy insert shape.
- [x] Direct owner-profile inspection found `/profiles/{uuid}/collections` resolved `users.public_id` before entering the already guarded collection query, leaving one absent-column pre-migration path. The profile component now checks the complete canonical schema first and fails closed with `404`, matching direct collection identity resolution without revealing whether an owner exists.
- [x] Final cache-cost inspection found that one transactional multi-collection membership apply repeated every global collection/home/sitemap/title/recommendation/API version bump once per changed collection. `CatalogCollectionCacheInvalidator::changedMany()` now performs those global bumps exactly once and then advances each unique collection scope, preserving immediate privacy/discovery invalidation with bounded cache-backend work.
- [x] Final entitlement/discovery inspection found authenticated non-owner viewers were incorrectly reduced to the guest title set, so their allowed authenticated-audience cards and personal state were absent, while related collections could be connected solely by a hidden/deleted membership. The page now passes the real viewer through the canonical title entitlement and one batched personal-card overlay; guest output remains guest-only, and similarity uses guest-visible shared title IDs only.
- [x] Final moderation retry/durability inspection found collection decisions committed before their admin audit row, exact retries advanced content versions again, and bulk report closure was chunked in memory but unbounded per request. Moderation/feature/report resolution now re-authorize locked rows, write the decision and audit fingerprint atomically, return no-op on a completed identical state, and close at most the configured 100 open reports per action with a translated continuation notice.
- [x] Final destructive-lifecycle inspection found restore checked expiry and mutated the previously hydrated soft-deleted model without reloading it under a row lock, so concurrent restores could write a stale content version. Restore now locks/re-authorizes current state, evaluates the 30-day cutoff inside the transaction, returns a concurrent completed restore unchanged and invalidates public domains only for a material restoration.
- [x] Prune-path inspection found the scheduled command selected expired IDs first but did not recheck `deleted_at` under the final deletion lock. Each candidate now revalidates the cutoff in the same locking transaction as permanent deletion, so a concurrent restore or restore-then-delete cannot abort the batch or shorten the documented recovery window.
- [x] Final report TOCTOU inspection found report authorization and deduplication used a collection snapshot before insertion, allowing a concurrent public-to-private/moderation transition to outdate the decision. Submission now locks and re-authorizes the current collection, derives its evidence key from the locked content version and uses the database unique key through `insertOrIgnore`, so retries/concurrency converge without raw constraint errors or reports against newly inaccessible state.
- [x] Final moderation-audit inspection found report resolution locked the report but read its collection fingerprint without locking that row, so a simultaneous collection decision could make the audit evidence internally inconsistent. Resolution now locks both records in the same transaction before re-authorization, status transition and audit insertion.
- [x] Final card/loading-state inspection found a 10,000-character description could expand every summary card and several batch/destructive controls only disabled silently. The typed card presenter now emits a safe 180-character excerpt, while selector apply/create, delete/restore/permanent delete, moderation/report and item removal expose localized spinner text or an `aria-live` status without inline CSS/JavaScript.
- [x] Final passive-template scan found collection Blade still formatted enum labels, visibility branches, count pluralization and reorder boundaries even though it performed no queries. Those derived values are now prepared in typed Livewire render methods or the card ViewModel; the collection templates contain no permission, visibility, count, ordering or SEO calculation and continue to use only escaped prepared values and route helpers.
- [x] Final edit/idempotency inspection found an exact delivered-again form advanced content version/cache when the old expected version still matched, then became a false stale conflict after a lost response; approved editorial `private → public` could also leave publication metadata empty. The locked update now re-authorizes, returns an exact desired state as a material no-op even after a completed delivery, rejects genuinely divergent stale input, writes translations only when changed and enforces moderation/feature/`published_at` invariants for every type.
- [x] User-content locale inspection found the editor filled a previously unknown user `content_locale` from the active interface locale on first save. User collections now preserve the nullable original-language metadata unchanged; only editorial translations use the explicitly selected content locale, so switching Russian/English chrome never claims the author text was translated.
- [x] Disposable service exercise found a freshly inserted Eloquent collection could return before database defaults such as `content_version=1` were hydrated, even though the stored row and cache invalidation were correct. Canonical create now refreshes its result, so create-from-title and optimistic-edit callers always receive the actual stable version and timestamps.
- [x] Editorial hreflang inspection found the detail query's intentional active/fallback translation load was incorrectly reused as the complete locale inventory, so a default-locale page could omit a real non-default reciprocal alternate. SEO now reads only the bounded supported `locale` column for that one collection while display text remains active/fallback-only; alternates are emitted only for translation rows that really exist.
- [x] Encoded-HTML inspection found both plain-text boundaries stripped tags before entity decoding, so `&lt;script&gt;` could survive normalization as HTML-shaped stored text even though Blade/JSON-LD still escaped it. Entity decoding now precedes script/style removal and final tag stripping in canonical input and presentation sanitizers; Unicode and paragraph normalization remain unchanged, and no rich HTML is admitted.
- [x] Cache-failure recovery inspection found an after-commit version bump could fail after a successful privacy/publication mutation, while a delivered-again create/edit/delete/restore resolved as an idempotent no-op and therefore could not repair the stale namespace. Canonical fulfilled retries now leave domain rows/versions unchanged but repeat targeted invalidation; an ordinary exact edit with the current expected version remains a full no-op. Direct visibility enforcement never depends on cache, and no global flush is introduced.
- [x] Repair-path abuse inspection found an already-created UUID returned before the create limiter and repeated delete/restore had no mutation limiter, so fulfilled delivery could force cache generations without consuming the normal budget. Canonical create limiting now precedes both new/fulfilled paths, and delete/restore/permanent-delete use the existing mutation allowance; retry repair remains possible without becoming an unbounded invalidation endpoint.
- [x] Cover mutation inspection found repeated removal of an already absent file still incremented content/cover versions and bypassed the cover-specific limiter. Remove now consumes the existing bounded allowance, re-authorizes the locked row and returns a material no-op without domain-version writes when no cover exists; its targeted invalidation is deliberately repeatable to repair a previously failed post-commit cache bump. Replace also re-authorizes the locked current row before swapping storage metadata.
- [x] Cover-cleanup failure inspection found an exception from deleting an already replaced/private file could occur after the database commit and prevent the authoritative cache transition, while cleanup during a failed replace could mask the original exception. Old/orphan cleanup is now idempotent best-effort with safe exception reporting in replace/remove/permanent/account-deletion paths; storage unavailability cannot suppress cache/privacy invalidation or overwrite the original transaction failure.
- [x] Cover-delivery anomaly inspection found a lost file would reach the storage response builder and a corrupted database MIME could be emitted as a declared non-image type. The authorization-aware endpoint now accepts only JPEG/PNG/WebP metadata, verifies the safe relative path exists on the configured private disk and otherwise returns `404`; HTML/executable content types and missing files are never streamed.
- [x] Membership retry/TOCTOU inspection found exact add/remove/batch/order retries could not repair a failed post-commit cache bump and several paths authorized only the pre-transaction snapshots. Canonical item mutations now re-authorize locked current collections (and current title visibility where applicable), keep duplicate/no-change domain operations idempotent, and repeat bounded targeted invalidation so delivery retries converge without changing bookmarks, progress, history or other memberships.
- [x] Import-cache inspection found per-title importer completion advanced only the title-page scope until the eventual global-run finalizer, allowing a collection summary to retain an old visible count or fallback poster during a long run. The existing catalog invalidator now asks the canonical item table whether that exact title belongs to an approved public collection, skips unrelated/private/unlisted/pending memberships, advances collection-dependent domains plus at most 1,000 exact scopes, and falls back to one global collection generation above that bound without a second cache or mandatory job.
- [x] Nullable owner-identity inspection found cards/pages had been hardened but public JSON-LD and the API resource still built a profile route from `owner.public_id` unconditionally. A valid opaque ID now enables creator/profile URLs; anomalous nullable identity leaves only the escaped public display name with explicit null external fields and never falls back to email or numeric ID, preventing route-generation 500s without leaking internals.
- [x] Featured-workflow inspection found an approved public editorial collection disappeared from the moderation queue even though that queue owns the only authorized feature/unfeature control. The canonical admin query now includes pending rows, open-report targets and non-deleted approved public editorial records, so the real control remains reachable without exposing user/system featuring or introducing a second administration surface.

- [x] Add one reversible additive migration for collections, slug history, items, reports and opaque user public IDs; keep SQLite compatibility and document every index against an implemented query.
- [x] Backfill user public IDs idempotently. Current production snapshot has zero users and no collection data, but migration logic remains safe for non-empty deployments.
- [x] Reconcile title merges transactionally: retain earliest addition time and lowest manual position, remove only duplicate membership rows, then point the canonical row to the surviving title.
- [x] Add `Collections` cache version scope and bump it after collection/title visibility, merge, moderation, slug, cover, item, owner-public-name and feature changes. Authenticated/private responses never enter full-page cache.
- [x] Public SEO includes only approved public non-deleted collections and visible items; filter/search/sort URLs are noindex with a clean canonical. Private/unlisted pages emit no public-safe structured data; only eligible non-empty public collections enter the existing sitemap index.
- [x] Account deletion permanently removes owned collections/covers under the existing deletion promise. Export includes collection metadata, visibility, order, item slugs and public URLs but not raw storage paths or moderation notes.
- [x] Rollback drops only newly introduced collection/report/slug tables and the additive user public ID column. Disposable rollback confirmed zero collection tables/migration rows and removal of `users.public_id`; the wider historical batch later stops in the unrelated released importer migration `2026_07_09_204238`, which Task 10 deliberately does not rewrite. Deployed collection data must be exported before rollback; collection rollback never touches watchlist/progress/history/catalog tables.

### Phased implementation checklist

- [x] Domain enums, models, relationships, migration, casts, normalization, slug resolver/history and policy.
- [x] Transactional create/update/delete/restore/force-delete, covers, add/remove/batch membership, reorder and cache invalidation actions.
- [x] Public/owner queries with accurate visible counts, catalog visibility, search/filter/sort and stable pagination.
- [x] Public directory/page, private/unlisted owner view, collection cards, serial cards, sharing, loading/empty/error states and accessible mobile controls.
- [x] `/my/collections` create/manage/restore flow and title-page staged multi-selector with create-and-add.
- [x] Public owner profile, title discovery, related collections, administration, reporting and audit events.
- [x] Account export/deletion and Seasonvar title-merge reconciliation.
- [x] Russian/English interface translation parity and locale-aware aliases without translating user content.
- [x] Public cache separation/invalidation, canonical/robots/Open Graph/JSON-LD and existing streamed sitemap integration.
- [ ] Update owner documentation, changelog and manual acceptance matrix; inspect routes/schema/queries/privacy/SEO/a11y/browser behavior without creating or running automated tests for this task.
- [ ] Run allowed static diagnostics, Pint, route/view inspection, production asset build and browser smoke; inspect every changed/related file, commit on existing `main`, and push configured remote.

### Files expected to change

Expected: additive migration; collection enums/models/policy/services/actions/DTOs/view models/Livewire/controllers/resources; user/title relations; merger/account/cache/SEO/sitemap/routes/config/translations/navigation; collection Blade/JS; architecture/data/authorization/cache/SEO/API/storage/forms/views/security/performance/deployment documentation, this plan and `CHANGELOG.md`.

Must remain semantically unchanged: watchlist/rating/progress/history tables and mutations, imported reviews, title/season/episode identity, player/source grants, Seasonvar public import command, existing title URLs, API fields, public catalog filters and user-created text.

### Final manual verification checklist

- [ ] Routes and aliases resolve without conflicts; legacy/current slug redirects authorize before revealing canonical metadata and never loop.
- [ ] Private/pending/deleted metadata returns a safe 404 to unauthorized viewers; unlisted is direct/noindex; public approved pages, counts and sitemap entries contain visible data only.
- [ ] Every mutation re-authorizes stable IDs, validates allowlisted values, is retry-safe, preserves unrelated personal state and invalidates affected cache versions.
- [ ] Duplicate membership and position checks, merge reconciliation, soft-delete restoration/slug conflicts, account export/deletion and cover cleanup are inspected against the final schema.
- [ ] Public and owner item queries have deterministic pagination, bounded payloads, eager-loaded title card relations and no per-card count/membership/owner query.
- [ ] Russian and English keys match; user content is unchanged across locale aliases and Livewire hydration; no raw key, inline CSS, business JavaScript, `@php` or Blade query is introduced.
- [ ] Public/private/unlisted/editorial/system/moderation/reporting/sharing/mobile/keyboard/focus/loading/empty/failure states have a working control or an explicit documented unsupported boundary.

## Task 11 — canonical system, editorial and personal tags

Status: implementation and allowed verification complete on 2026-07-15; delivery commit/push remains the final workflow step. This is the single Task 11 plan. It extends the deployed `tags` / `catalog_title_tag` classification in place and deliberately does not create a competing public-user, season or episode tag system.

### Audited current architecture and discovered risks

- [x] Every repository Markdown file was read before implementation. Routes, bindings, middleware, authentication, catalog/user/title/season/episode/tag models, schema, migrations, queries, policies, APIs, Blade/Livewire/JavaScript, translation, cache, search, SEO, sitemap, recommendations, importers and administration boundaries were inventoried.
- [x] The only live global classification is `tags(id, name, slug, source_url, timestamps)` with the unique `catalog_title_tag` pivot. The old `taxonomies(type=tag)` representation is archival and has no live rows; it will not be revived. There was no personal-tag, tag-translation, alias, synonym, moderation, provider-mapping or slug-history model.
- [x] The current production-style SQLite snapshot has 571 global tags, 11,154 unique title assignments and 544 provider identities. Static/data inspection found no duplicate normalized global names, duplicate assignments, invalid current slugs or orphaned pivots. The source-less `субтитры` tag has 3,133 assignments and is a stable system classification.
- [x] Global tags were identified primarily by mutable display text/slug, provider alternate spelling was discarded, and imported tags were immediately public. The canonical extension must add opaque stable identity, optional immutable code, normalized comparison data, lifecycle/type/visibility/source values and provider provenance without changing existing numeric IDs, current slugs, source URLs or pivots.
- [x] Existing public routes are `/tags`, compatibility `/tags/{value}` and canonical `/titles/tag/{slug}`. No real locale-prefixed tag route or localized tag slug architecture exists. The old singular `/tag/{slug}` URL was not registered consistently and is restored only as a compatibility redirect. Canonical resolution must cover current slug, case variants, slug history, aliases and merge targets without redirect loops.
- [x] Public directory/detail queries did not consistently require an eligible visible title; empty pages could be indexable, alias pages could duplicate SEO, and generic sitemap generation included every tag record. Public tag eligibility, non-empty visible counts, canonical URLs and sitemap inclusion therefore need one resolver/query boundary.
- [x] Catalog cards already eager-load summary taxonomy data, but the title view omitted its loaded tags. Facet options were capped to a local top set, labels were not locale-aware, and translation/alias joins could cause future duplicate rows unless implemented through scoped subqueries and `distinct` title identity.
- [x] Full-text title search intentionally indexes titles, not tags. Dedicated public tag search/autocomplete is the compatible integration: active/fallback translated labels, canonical label and approved aliases are searched and de-duplicated to canonical tag IDs; related-search synonyms expand one bounded hop. Private personal labels never enter public FTS, suggestions, sitemap or shared cache.
- [x] Recommendations currently treat shared global tags as a weighted signal. Only public approved non-archived system/editorial/imported tags may remain in that public similarity profile; personal assignments are private personalization data and are not added to the public algorithm.
- [x] Provider relation synchronization used unique current pivots and `syncWithoutDetaching`, so retries did not duplicate assignments. However, provenance had no canonical mapping and stale provider observations were never marked non-current. Task 11 must reconcile a complete observed provider-tag set conservatively, retain explicit editorial assignments/suppressions, and detach only a stale assignment with no remaining current source.
- [x] The product has no intentional public user-tag, tag-report, hierarchy or season/episode tag feature. Task 11 therefore supports private personal tags only, imported-tag moderation through existing catalog administration, bounded synonym relationships without hierarchy inheritance, and title assignment only. Comments/reviews hashtags remain plain UGC text and never create catalog tags.
- [x] The portal supports `ru` and `en` interface catalogs with fallback but has no real localized public route family. System/editorial translations reuse database content translations plus the existing PHP UI catalogs; user-created labels retain their original Unicode text and are never machine-translated or written into language files. Tag pages suppress false `hreflang` alternates until real localized routes exist.
- [x] Shared full-page caching already bypasses authenticated/session-bearing and Livewire traffic. A public versioned `tags` domain is required for public metadata/counts/search/related/popular snapshots; personal versions are keyed by owner and personal responses are `private, no-store`. Visibility/moderation/assignment/merge/import changes must invalidate affected public catalog/search/SEO variants without flushing the application cache.
- [x] Final schema/query inspection found that the original `(type, visibility, moderation_status, archived_at, id)` index was not selected for the canonical public-eligibility predicate because `type != hidden_internal` led SQLite to prefer a low-selectivity merge index. The ordered `230060` migration replaces it with equality-first `(visibility, moderation_status, archived_at, merged_into_id, name, id)` coverage; isolated `EXPLAIN QUERY PLAN` confirms the intended index is selected.
- [x] Final normalization inspection found that the first canonical backfill did not exactly match runtime entity decoding, Unicode form, non-breaking-space, dash and punctuation-spacing rules. The additive idempotent `230075` repair and every migration-local fallback now reproduce `TagNormalizationService::comparison()` exactly before duplicate reconciliation and uniqueness enforcement; visible labels are preserved and already merged rows are skipped.
- [x] Final administration inspection closed mutation edges: a non-imported tag cannot be manually converted into the importer-owned type; alias locale is allowlisted server-side; alias creation and old-slug capture verify canonical ownership again after uniqueness races; reverse bidirectional synonyms cannot duplicate one relationship. Stable one-character integration codes are accepted by the documented code grammar.
- [x] Rolling-schema inspection aligned `TagSchema` with the existing scoped collection/comment/review capability guards. Canonical readiness is memoized only for one HTTP request or queue job, so a pre-migration false result cannot leak across long-lived worker jobs; deploy still restarts workers after migration.
- [x] Final browser inspection found that route-backed tag state was also being serialized as `?tag[0]=slug` after Livewire hydration. Locked route year/tag identity is now separate from query-bound form values; the explicit checked route option removes only the route context, additional filters remain shareable, and canonical `/titles/tag/{slug}` stays query-clean. The same correction covers year routes without adding a second filter system.
- [x] UI standards cleanup removed nested `overflow-y-auto` regions from personal tag selection and global administration, restored document-only scrolling, raised meaningful count/locale contrast and kept primary mutation buttons on the shared emerald action palette.
- [x] A disposable two-owner service exercise found that a newly inserted personal tag returned before the database default `content_version` was hydrated. `PersonalTagService::create()` now refreshes the created record, so immediate optimistic edits start at persisted version `1`; the repeated exercise also proved owner isolation, normalized duplicate scope, idempotent batch assignment/removal, markup stripping and soft-delete restoration without touching global tags.
- [x] Final title-merge inspection found that two different personal tags owned by the same user could arrive from the canonical and duplicate titles with the same saved position. Merge now preserves their relative `(position, tag ID)` order and compacts every affected owner's canonical assignment positions to `0..n-1`, without changing tag/title identity or another owner's ordering.

### Canonical domain contract

- Stable identity: existing `tags.id` remains the relational key; immutable opaque `public_id` is the external/API identity. `name`, translations, slug and aliases are mutable presentation/search fields. Optional language-independent `code` is unique and admin-only for system/editorial integration. A merge records source→target and keeps current/legacy URL resolution.
- Global types are stable `system`, `editorial`, `imported` and `hidden_internal` values. Global visibility is stable `public` or `internal`; moderation is `pending`, `approved`, `rejected`, `hidden`, `merged` or `archived`. Translated labels are never stored in any internal value. Newly discovered imported tags are pending until mapped/approved; the existing reviewed legacy snapshot is backfilled approved to preserve public behavior.
- Personal tags use the separate canonical `user_tags` owner-scoped table because their ownership, deletion, visibility, cache and SEO guarantees differ from global metadata. They have stable UUIDs, preserved original name/script, comparison value/hash, optional original content locale and soft deletion with a documented 30-day restoration window. They are always private; public/unlisted user tags are unsupported.
- Unicode normalization uses NFKC when available, trims/squishes whitespace, removes invisible control/format characters, normalizes dash variants, strips an optional leading hashtag for comparison and case-folds without removing meaningful diacritics. Display text preserves safe user casing/script. Exact normalized equality is authoritative; fuzzy similarity is suggestion-only and never auto-merges.
- Global translated content stores at most one row per tag/locale with label, descriptions and SEO fields. Queries load active and configured fallback values only. Aliases are locale-aware exact alternate identities resolving to one canonical tag; slug-bearing aliases redirect and never form pages. Synonyms are explicit directional/bidirectional bounded relationships for related-search or editorial related display, not automatic equivalence or inheritance.
- Global duplicate merge is authorized, previewed and transactional. It reconciles title assignments/provenance, translations, aliases, slug history, synonyms and provider mappings, keeps an idempotent merge event, prevents duplicate pivots, preserves canonical corrections and invalidates search/recommendation/cache/sitemap dependencies. Personal tags are never merged across owners.
- Assignment boundaries are separate: `TagAssignmentService` authorizes global editorial mutations and records explicit provenance/suppression; `PersonalTagService` derives owner from the authenticated user and transactionally reconciles only that owner's title assignments. Apply commits a bounded draft set, Cancel is client-only, remove is idempotent, and neither path changes bookmarks, progress, ratings, history, collections or titles.

### Routes, queries, interface and integration

- Public pages continue to use `/tags` and `/titles/tag/{slug}`. `/tags/{value}` and `/tag/{value}` are compatibility-only redirects. `ResolveCanonicalTagRoute` authorizes public eligibility before redirecting case/history/alias/merged values, preserves query parameters and returns a non-disclosing 404 for private, internal, unapproved, archived or empty tags.
- The existing catalog directory/filter/page builder remains the single public filter architecture. Tag filtering uses allowlisted stable values, visible-title scopes, deterministic sorting/pagination, `distinct` title identity and canonical card eager loads. The title presenter displays public global badges and mounts a separate authenticated personal-tag selector; private labels are not emitted for guests or other users.
- Dedicated public API resources expose only opaque identity, canonical link, localized label, public visible count, approved aliases and bounded related tags. `/api/v1/me/tags` and title assignment endpoints are owner-scoped and `private, no-store`; owner IDs and model classes are never accepted. Search suggestions use canonical public tags and aliases without duplicate results.
- Personal management lives at `/library/tags/manage` and supports create, rename, delete, restore, search, assigned-title pagination and removal. The title selector supports keyboard/touch listbox behavior, debounced search, staged Apply/Cancel, loading/status announcements and mobile wrapping with existing Tailwind/components. System and personal badges are distinguished textually, not only by color.
- Catalog administration at `/admin/tags` reuses `manage-catalog` and canonical policies for create/edit/translations/aliases/synonyms/moderation/archive/restore/title assignment/provider inspection and merge preview/confirmation. Permanent global deletion and public-user reporting are intentionally absent; active global tags are archived or merged.
- Account export includes only the requesting user's personal tag labels/metadata/assignments. Existing account deletion cascades personal tags and pivots without orphaning private data. Title merging must reconcile both global and personal assignment pivots before the duplicate title is removed.

### Database, import, cache, SEO and compatibility plan

- [x] Add an ordered reversible migration set extending `tags` and creating translations, aliases, synonyms, slug history, provider mappings, assignment provenance, merge events, personal tags and personal assignments, followed by archive-state preservation, query-index optimization, normalization repair, provenance backfill and exact-duplicate reconciliation. Every uniqueness/index corresponds to canonical lookup, public eligibility, owner search, provider identity or pivot direction; SQLite-compatible keys are used and no existing row/table/slug/pivot is renamed or deleted.
- [x] Rehearse the complete ordered migration set on a fresh disposable SQLite database and inspect index/query plans without running Task 11 automated tests. Production rollout still requires normal backup/writer-stop/migrate controls from Phase 1.
- [x] Complete provider-set reconciliation: mark only same-title/same-provider prior observations stale after a complete successfully parsed set, retain editorial suppressions/corrections, detach only when no current provenance remains, update recommendations/search timestamps only for material changes and keep the operation idempotent.
- [x] Keep public cache dimensions to stable tag ID, locale/fallback, query/page/filter/sort and versioned catalog visibility context. Personal state uses owner-scoped versions and is never placed in shared HTML/snapshot keys. Public invalidation bumps tag/search/catalog domains narrowly after commit.
- [x] Public SEO uses localized real label/description, visible count, clean canonical, breadcrumb and public-safe CollectionPage/ItemList data. Alias/history/filter/search/private/pending/rejected/hidden/archived/empty pages are noindex or redirected and excluded from the existing streamed sitemap. False localized alternates are not emitted.
- [x] Preserve current `tags` fields, existing slugs/routes/API taxonomy fields and importer command. Rollback removes only additive Task 11 tables/columns; deployed personal/translations/alias/provenance data must be exported before rollback and legacy public behavior remains available from the original columns/pivot.

### Phased implementation checklist

- [x] Complete repository/data/route/schema/query/privacy/cache/search/SEO/import audit and record deliberate unsupported boundaries.
- [x] Add stable enums, models, relationships, additive schema, normalization, slug/history, resolver, DTOs, policies, cache and canonical query/service boundaries.
- [x] Integrate public directory/filter/detail/search/suggestions/API/title badges/recommendations/sitemap/canonical SEO and legacy redirects without a second catalog query system.
- [x] Add owner-only personal CRUD/restore/search/batch assignment, title selector, library management, export/deletion integration and private API resources.
- [x] Add authorized administration for lifecycle, translations, aliases, synonyms, provider mappings, assignments and merge/archive workflows.
- [x] Finish conservative provider-set convergence, title-merge reconciliation, cache invalidation audit and all static query/privacy/security checks.
- [x] Complete Russian/English translation parity, all relevant architecture/data/API/cache/import/SEO/security/UI documentation, maintenance log and English changelog.
- [x] Run allowed PHP syntax, Pint, route/schema/query/translation/view/Vite/browser smoke diagnostics only; inspect all changed/directly related files and reread Task 11 without creating or running automated tests.
- [ ] Commit the complete authorized working tree on existing `main` and push the configured remote.

### Expected files, protected boundaries and rollback

Expected: additive tag migration/config/enums/models/policies/DTOs/services/queries/Livewire/controllers/requests/resources/middleware; tag/title/user/account/import/recommendation/cache/SEO/sitemap/API/routes/navigation/translations/views/JavaScript integrations; architecture/data/API/cache/import/SEO/security/UI/maintenance docs, this plan and `CHANGELOG.md`.

Must remain semantically unchanged: existing tag IDs/current slugs/source URLs/title pivot data and ordering, title/season/episode identity, all player/media URLs and grants, watchlist/progress/history/bookmarks/collections/comments/reviews/ratings, Seasonvar public import command, catalog route names and API compatibility fields. There is no season/episode assignment, public user tag, automatic hashtag creation, hierarchy inheritance, machine translation or mandatory queue/scheduler added by Task 11.

Rollback is additive-schema rollback only before new Task 11 data is accepted. After deployment, export personal/global translation/alias/provider-provenance data and restore the verified pre-migration database/code rather than dropping live user data. Cache versions are disposable; original `tags` and `catalog_title_tag` remain the compatibility source throughout rollback.

### Final manual verification checklist

- [x] Inspect current/history/alias/case/merge route resolution, query preservation and loop-free canonical/404 behavior for public, empty and ineligible tags.
- [x] Inspect stable identity/code/type/source/visibility/moderation casts, translation fallback, Unicode normalization/validation, alias conflicts, bounded synonyms and merge transaction/data preservation.
- [x] Inspect personal owner assignment server-side, duplicate protection, edit/delete/restore conflict policy, batch Apply/Cancel, idempotent removal, account export/deletion and absence from public source/search/cache/sitemap/counts.
- [x] Inspect visible-title scopes, distinct results/counts, deterministic filter/sort/pagination, active/fallback translation queries, eager loads and query plans for directory, tag page, title cards, API, popular and related tags.
- [x] Inspect import retry and stale-source convergence, rejected mapping preservation, editorial suppression/correction survival, recommendation/search timestamps and targeted invalidation.
- [x] Inspect administrator authorization, archive/restore/merge impact confirmation, no destructive GET or raw internal notes/provider credentials, and safe localized validation/failure states.
- [x] Inspect SEO canonical/robots/metadata/structured data/sitemap eligibility, no false hreflang, API no-store, shared cache separation, responsive wrapping, zoom, keyboard/focus/touch, loading/empty/error announcements and translation parity.
- [x] Confirm no new Volt, `@php`, Blade query/service/facade call, inline CSS, business JavaScript, hardcoded visible string, dead control, debug output, console logging, unused class/import or duplicate tag logic remains.

## Task 12 — canonical serial comments and discussions

Status: implementation and final non-test acceptance complete on 2026-07-15; repository commit/push is the remaining operational handoff. This section is the single implementation plan for portal discussions and supersedes Task 10's temporary decision to defer comments until a portal-wide social boundary existed.

### Audited baseline

- [x] All repository Markdown files were read before implementation: 212 files / 41,109 lines / 204 distinct checksums, excluding dependency and generated-storage trees.
- [x] All 113 registered web/API routes, route names, bindings and middleware were inspected. There is no current comment, reply, reaction, vote, report, block, mute, restriction, moderation-queue, notification-inbox or legacy comment route/anchor.
- [x] The current SQLite schema and all migrations contain no user-comment, reply, reaction, vote, mention, block, mute, restriction or database-notification table. Therefore there are no legacy discussion rows, duplicate reactions, invalid parent links, translated moderation values or comment URLs to reconcile before adding uniqueness.
- [x] `catalog_title_reviews` is a provider-imported, read-only title-review source with author/body/hash/published time. It is not authenticated UGC, has no replies or mutation routes and must remain independent from portal comments.
- [x] There is no competing comment service, repository, policy, Livewire component, Blade partial, JavaScript module, cache key or API representation. A new additive domain is therefore the canonical first and only portal discussion system, not a replacement migration.
- [x] Stable catalog identities already exist for `CatalogTitle`, `Season` and `Episode`; slugs are presentation URLs only. The in-progress canonical collection domain adds stable collection identity and approved public/unlisted visibility. These four allowlisted types are the only supported discussion targets.
- [x] Title, season and episode publication/audience/window visibility is centralized in `CatalogEntitlementService`; collection visibility/moderation is owned by its policy. Target resolution must reuse those boundaries and never accept model class names.
- [x] Authentication uses verified-email write boundaries for personal catalog interactions. No anonymous comments, premium entitlement, public user profile, avatar, mention parser, custom emoji/sticker, edit-history or comment-draft architecture exists; those capabilities will not be invented or represented by dead controls.
- [x] Laravel `Notifiable` already owns account and operational mail notifications, but no database notifications table or user inbox exists. Comment events will extend Laravel's existing notification model with body-free stable payloads and no mandatory queue.
- [x] There is no user block/mute or comment restriction domain. Directional discussion blocks, private mutes and comment-only temporary/permanent restrictions must be additive and must not masquerade as account bans.
- [x] The only administrator identity boundary is the configured email allowlist behind `manage-catalog` / `manage-seasonvar-imports`. Discussion moderation will reuse that administrator decision through a dedicated gate and the existing append-only admin audit boundary.
- [x] The application supports Russian and English PHP translation catalogs. The runtime locale is Laravel's configured locale; there is currently no user locale column or general locale middleware. Every discussion key must have exact `ru`/`en` parity and user text must never be translated.
- [x] Guest title HTML may enter the existing versioned full-response cache; authenticated and Livewire update requests bypass it. Public discussion data may be present only in guest-safe SSR, while reactions, permissions, blocks, mutes, pending ownership, reports, restrictions and moderator controls remain request-private.
- [x] `SeasonvarTitleMerger` force-deletes duplicate titles/seasons/episodes after moving catalog relations. Discussion target identities must be reconciled inside that same transaction before a duplicate target is removed.
- [x] Account deletion currently hard-deletes the user after tokens/sessions. Discussion ownership must first be anonymized while threads remain intact; reactions and private preferences are removed, reporter identity becomes null, and body-free notifications cannot retain a deleted actor identity.
- [x] The baseline portal had no account-export endpoint. The integrated account export now returns a private `no-store` JSON download containing discussion data without moderator notes, reporter data, raw URLs or other users' private state. The existing export service materializes one account snapshot before the streamed HTTP response; this task does not claim a memory-bounded row stream or introduce queue infrastructure.
- [x] Existing sitemap, feed, Open Graph, FAQ/structured data and catalog API do not include user comments. Comments remain absent from sitemaps and structured data; direct/sort/page URLs canonicalize to the target and are noindex where query state could multiply pages.
- [x] Current public comment count, reply count, sorting, pagination, long-body, spoiler, edit, delete, restore, reaction, notification, report, moderation, anti-spam and cache invalidation behavior is absent rather than broken. All product rules below are new explicit canonical contracts.
- [x] The checked-in `routes/web.php` registers canonical and localized direct-comment, private discussion/inbox and moderator routes. The production-style workspace may have an older `bootstrap/cache/routes-v7.php`, so source verification uses an isolated uncached registry. Final inspection reports 161 uniquely named routes and confirms `comments.show`, `localized.comments.show`, `profile.discussions`, `notifications.index` and `admin.comments` with expected middleware; deployment must rebuild optimized caches after the release instead of treating a local optimized snapshot as source truth.
- [x] Static query inspection found that the first moderation author-filter draft passed an SQL escape character as Eloquent `where()`'s fourth (boolean connector) argument. The implementation now strips user-controlled wildcard metacharacters before a bound `LIKE` condition; it uses no raw column/operator input and remains portable across SQLite and the supported Laravel connections.
- [x] Merge inspection confirmed comment rows are remapped before duplicate title/season/episode force-deletes. The merge adapter now also schedules one canonical-title cache-version bump after commit, preventing the destination page from retaining a pre-merge discussion snapshot.
- [x] Viewer-state inspection found that the public reply count alone made an author's own non-public reply unreachable from the thread toggle after reload. A separate authenticated owner overlay for pending/hidden/rejected/spam replies now drives visibility/load-more while removed/deleted rows stay unavailable and the public reply aggregate and guest cache remain unchanged.
- [x] Locale/direct-context inspection found that a nested component could lose a collection locale during a standalone Livewire hydration and that public target resolution correctly made hidden/deleted moderator links unusable. Locked locale is now reapplied on hydration with localized editorial scope labels; moderator direct links fall back to a private selected queue context, while public target policy remains fail-closed.
- [x] Final mutation audit found and hardened two retry/ownership edges: a repeated identical moderation submission no longer increments the comment version or emits another notification, and an author-deleted comment subsequently placed in stable `removed` status is not author-restorable. Reaction writes now use the database uniqueness contract atomically rather than a race-prone read/update pair.
- [x] Reply-chain inspection found that a still-published child could accept a new logical reply after its structural root became hidden/rejected/spam/removed. The canonical create action and prepared `canReply` overlay now both require a published structural root, while an author-deleted but still-published tombstone may retain its existing discussion according to the documented deletion policy.
- [x] Composer/focus inspection found that a successfully created root could fall outside the current `oldest` or `popular` page before the focus event ran. Successful root publication now moves to deterministic `newest` page one; recoverable failures retain both body and sort. Plain-text scheme validation rejects executable schemes and dangerous `data:` MIME payloads without rejecting ordinary prose containing `data:`.
- [x] Retry inspection found that a server-side reaction toggle could invert an already committed vote when the same lost request was replayed. The rendered viewer overlay now submits the explicit desired `up|down|null` state to an atomic upsert/delete action. A repeated owner restore after a committed restore likewise returns the already-active row without another mutation.
- [x] Relationship query inspection confirmed no per-comment N+1, but the discussion page still loaded the viewer's complete block/mute sets. Comment presentation now filters both grouped relationship queries to author/reply-context IDs in the current top-level page or progressive reply batch, while existing collection/review consumers keep their established full-set behavior.
- [x] Notification fan-out inspection found that a logical reply-to-reply notified both the immediate recipient and the structural root author. Canonical delivery now notifies only the `reply_to` author; the root author is a compatibility fallback solely when an older/imported reply has no logical `reply_to_id`.
- [x] Post-commit failure inspection found that a synchronous database-notification exception could make a successfully committed comment/reaction/moderation/report action appear failed. Notification hooks remain body-free/deduplicated and are now best-effort boundaries that report inbox failures without misrepresenting or retrying the already committed domain mutation.
- [x] Direct-control inspection found that a non-public structural root kept as a tombstone for published replies still rendered a link that correctly ended in policy 404. Prepared presentation now omits the link when the current viewer cannot `view` that stable comment; eligible published/deleted, owner and moderator contexts keep their authorized direct URL.
- [x] Account-deletion inspection found that anonymizing `comment_reports.reporter_id` left the deterministic user-derived unresolved deduplication hash behind. Privacy cleanup now nulls that nullable key while preserving the report row, category/details/status and moderation evidence.
- [x] Final schema-boundary inspection found that `COMMENTS_ENABLED` was folded into the meaning of schema availability. Disabling the public feature could therefore skip account privacy cleanup, collection retirement and title/season/episode merge reconciliation even while discussion tables still existed. Schema capability and public writability are now separate fail-closed decisions: lifecycle/export/deletion continue to protect stored data while disabled UI and mutations remain unavailable.
- [x] Tombstone presentation inspection found that a non-public moderation state with surviving published replies removed the body but still exposed its author identity and relationship controls, while a blocked/muted tombstone retained a direct link that could only return to the same unavailable state. Public/relationship presentation now makes the author and direct interaction link unavailable whenever policy/preferences hide that viewer's body; owner-safe private state and gate-protected moderator context remain unchanged.
- [x] Mutation target-race inspection found that create correctly re-locked its target, while edit/delete/restore/reaction/report could validate a visible target before entering their comment transaction. Canonical user mutations now follow one user/target/comment/engagement lock order and re-resolve the allowlisted visible target before changing state, preventing a concurrent target retirement from accepting a late mutation.
- [x] Rate-limit inspection found that user+target buckets could be bypassed by rotating across many serial/season/episode/collection targets. Each action now has a second, deliberately looser user-global bucket in addition to the exact-scope bucket, with retry time derived from whichever safe server-owned key is exhausted.
- [x] Anti-spam ordering inspection found that a duplicate-content rejection occurred before the limiter hit, allowing repeated new-token duplicates to consume database work without consuming either bucket. After a same-token idempotent replay check, validated create/reply/edit attempts now hit the limiter before content-duplicate lookup.
- [x] Reaction state inspection found that replaying the same explicit desired vote still updated its timestamp and bumped public cache even though the semantic state was unchanged. The locked/unique action now returns a true no-op for the same enum or absent removal and notifies/invalidates only when the row is inserted, changed or deleted.
- [x] Delete/restore retry inspection found that already-satisfied idempotent requests still advanced the target cache version. Both actions now return mutation metadata from their locked transaction and invalidate the canonical target only when persisted state actually changes.
- [x] Create/reply transaction inspection found that a concurrent replay resolved through the unique submission key could still invalidate/notify twice, while the parent state was checked only before the transaction. The canonical action now distinguishes an actual insert from an idempotent return and re-locks/revalidates the reply target and structural root immediately before insertion.
- [x] Submission-token inspection found that reusing a committed UUID with a changed body, spoiler flag or logical reply target returned the old row and made the UI clear the new draft. Idempotent recovery now succeeds only when the normalized payload matches the stored stable comment; incompatible token reuse returns the localized recoverable submission error and preserves editor state.
- [x] Livewire hydration inspection found that target/submission/version arrays were locked but server-selected reply, edit and report mode IDs were not. These identities are now `#[Locked]` and can only be set by an authorized open action; bodies, spoiler booleans and allowlisted form codes remain the deliberately mutable inputs revalidated by canonical actions.
- [x] Moderation idempotency inspection found that changed private notes could be silently ignored when restriction/report status fields otherwise matched. No-op comparison now includes the private note; resolved report evidence cannot be silently rewritten, while a materially changed restriction produces the normal revoke-and-replace audit trail.
- [x] Composer inspection found that the displayed character count depended on deferred Livewire model synchronization and could remain stale while typing. The existing Vite comments module now updates Unicode code-point counts locally for create, reply and edit editors without per-keystroke server requests; server validation remains canonical.
- [x] Plain-text boundary inspection found that an array or arbitrary non-stringable object passed to a `mixed` action input could emit a cast warning and normalize to the literal word `Array`. `UserPlainText` now rejects unsupported value shapes before normalization so canonical form actions return their normal localized empty-input behavior without warnings or accidental content.
- [x] Runtime locale inspection found that explicit Russian `[2,4]` intervals produced incorrect forms for values such as 21/22/25. Comment, reply and report totals now use Laravel's native three-form Russian plural selection; dedicated empty-state copy remains separate and natural.
- [x] The restoration audit found that a newly soft-deleted owner comment could disappear from the current page before its restore control was rendered. Delete completion now retains the stable comment focus and expands its root thread when needed, so the authorized tombstone and restore action remain reachable without depending on a previously saved permalink.
- [x] The scalability audit found that the private block and mute management lists were the only discussion profile collections still loaded without a bound. They now use independent deterministic Laravel pagination (`blocks_page` and `mutes_page`) and return to a valid first page after removal.
- [x] Edit-path anti-spam inspection found that a newly registered author could publish link-free text and then add a link inside the edit window without re-entering pre-moderation. The canonical edit action now applies the same deterministic anti-spam decision and moves that real published edit to `pending`, with normal target cache invalidation and localized owner feedback.
- [x] Moderation side-effect inspection found that changing only a private note or internal reason bumped public target cache and notified the author even though their visible state was unchanged. Full audit/version history remains, while cache invalidation and moderation notification now require an actual status or deletion-visibility transition.
- [x] Account-deletion cache inspection found that reaction removal collected invalidation identities only from comments authored by the deleted user. The privacy service now unions authored and reacted-to discussion targets before deleting engagement, retaining the bounded 1,001-ID/global-generation fallback for large accounts and collection scopes.
- [x] Notification/account-deletion race inspection found that the shared polymorphic notifications table has no recipient FK, so a post-commit comment or review delivery could theoretically insert after inbox cleanup and immediately before user hard-delete. Both existing database delivery services now lock and re-resolve the recipient row; an event either precedes cleanup and is deleted there, or observes the completed deletion and writes no orphan notification.
- [x] Deleted-target lifecycle inspection found that the collection privacy retirement query inherited the soft-delete scope and skipped already author-deleted rows. The bulk lifecycle update now includes tombstones, preserves an existing deletion timestamp with portable `COALESCE`, clears the actor-specific deleter and closes every row under the stable privacy reason without deleting thread evidence.
- [x] Viewer-overlay query inspection found that moderators still loaded personal block/mute sets even though moderation visibility intentionally bypasses them. The prepared relationship context now returns immediately for `manage-comments`, removing two irrelevant queries without weakening mutation-time block enforcement.
- [x] Privacy-state inspection found that ordinary moderation could assign a non-removed status to a privacy tombstone while correctly refusing to restore its body, leaving a contradictory published/deleted row. Privacy-retired evidence is now terminal outside the dedicated legal/privacy lifecycle and can only remain in stable `removed` status.
- [x] Administration context inspection found that a hidden/deleted comment's safe direct link returned to the selected queue item but did not expose its root/reply neighborhood inside the protected interface. The moderation dialog now receives a typed, bounded root-plus-20-replies context and explicitly marks a selected reply outside the first batch without serializing a full thread.
- [x] Index inspection found that Laravel `morphs('notifiable')` created a two-column index duplicated by every required recipient composite's left prefix. The unreleased notification migration now declares the standard type/unsigned-ID columns explicitly so purpose-specific inbox indexes do not carry an extra morph-prefix duplicate.
- [x] Notification query-order inspection then separated the two real access patterns: recipient/created/id for deterministic paginated inboxes and recipient/read for unread updates/counts. Two purpose-specific composites replace the redundant morph prefix; comment inbox ordering now has stable UUID tie-breaking.
- [x] Final query/index inspection found that duplicate detection also scopes by structural root and the grouped current-user reaction overlay leads with user ID, while their indexes omitted those exact next columns. The unreleased empty-domain migrations now use `(user,target,parent,body_hash,created)` for duplicate lookup and a dedicated `(user,comment,type)` viewer-reaction composite; public totals and private export retain their distinct existing access paths.
- [x] Moderation payload inspection found that report previews and automatic report closure were not actually bounded despite the documented limit. Queue cards now eager-load five deterministic previews beside the exact total, and one comment decision resolves at most 100 oldest report rows atomically; any remainder stays open for the next explicit moderation request.
- [x] Render failure inspection found that the discussion list was protected by a localized query-failure boundary but the subsequent active-restriction read was not. Restriction lookup now participates in the same safe failure state and the composer remains unavailable whenever viewer permission state could not be determined.
- [x] Shared profile inspection found that review-inbox schema/query work happened outside the discussion page's guarded read boundary. Review notifications now have an independent localized failure state, so their outage cannot crash or suppress comment activity, preferences and relationship controls.
- [x] Reaction TOCTOU inspection found that policy was checked before the transaction, allowing a parallel moderation/delete transition between authorization and upsert. The mutation now locks/reloads the comment and rechecks policy plus bilateral block state immediately before its atomic desired-state write.
- [x] Report TOCTOU inspection found the same pre-transaction authorization window. Report creation now follows the moderation-compatible comment→report lock order, rechecks public/report/block eligibility under the comment lock and commits the unique unresolved deduplication row transactionally.
- [x] Audit durability inspection found that moderation/report/restriction mutations committed before `AdminAuditEvent` insertion; an audit failure followed by idempotent retry could therefore leave an unrecorded decision. All comment moderator audit records now commit atomically inside their locked domain transactions, while cache and body-free notifications remain post-commit effects.
- [x] Edit idempotency inspection found that saving an unchanged normalized body/spoiler still advanced `version`, set `edited_at` and invalidated the target. After validation, limiter and stale-version checks, an exact semantic match now returns the current stable row without a false edit mutation; duplicate-content lookup runs only for a material edit.
- [x] Direct-reply inspection found that an owner-authorized hidden/deleted reply under another author's hidden root could load no top-level container when that root had no public replies. Focus resolution now authorizes the exact comment first, derives its structural root and includes only that root as a safe tombstone; arbitrary focus query IDs cannot reveal non-public threads.
- [x] Pre-moderation notification inspection found that a pending reply correctly emitted no public event at creation but also failed to notify its recipient after approval. A real transition to published now invokes the same deterministic preference/block/mute-safe reply notification with the comment author as actor; later hide/publish retries cannot duplicate it.
- [x] Reply approval inspection found that a pending child could be approved after its structural root had been hidden/rejected/spam/removed. Publishing a reply now locks and revalidates the root's identity, target, stable status and permitted author tombstone state, matching the canonical create boundary and keeping moderated-closed threads closed.
- [x] Delayed reply-recipient inspection found that the immediate `reply_to` comment could become hidden/deleted before a pending reply was approved. Delivery now reloads and requires a published, non-deleted logical recipient context before resolving its author, preventing a stale or removed conversation from generating a social notification.
- [x] Target lifecycle inspection found a create/delete race after the initial visibility check, most importantly for collection targets without a direct foreign key. The canonical create transaction now resolves and locks the allowlisted title, season, episode or collection again before validating the reply and inserting the comment; a target removed while the composer was open therefore fails closed instead of leaving an orphan discussion row.
- [x] Notification-preference inspection found that delivery used `firstOrCreate` merely to read default opt-ins, causing an unnecessary write and a concurrent first-event race on the per-user primary key. Missing rows now use the model's non-persisted boolean defaults; a row is written only when the user explicitly saves preferences.
- [x] Failure-state inspection found that the public discussion already failed safely, while the private profile and moderation queue still let a read exception escape their render path. Both protected interfaces now report the exception, suppress state-dependent controls and render the existing localized recovery state instead of exposing a framework error.
- [x] Keyboard spoiler inspection found that reveal/hide replaced the active Livewire button and could lose focus. The stable body region now announces its change politely, and the Vite module restores focus to the newly rendered spoiler toggle while retaining reduced-motion-safe direct-comment behavior and keeping unrevealed text out of HTML.
- [x] Bulk report-resolution inspection found that the comment moderation transaction preserved every report row but recorded only the comment-level audit event. The existing bulk update now also writes one body-free `CommentReportResolved` fingerprint per affected report inside the same transaction, matching the individually resolved path without exposing reporter details or notes.
- [x] Notification index call-site inspection confirmed that every paginated and unread domain query constrains the stable `type` as well as recipient identity. Both purpose-specific composites now place that selective domain code before their order/read column, supporting comment and review inboxes without restoring the redundant standalone morph-prefix index.
- [x] Inbox identity inspection found that the owner-scoped mark-read query accepted an arbitrary string key before its safe lookup. It now rejects non-UUID values at the action boundary, then constrains the canonical database notification ID by both current recipient and stable `comment.activity` type.
- [x] Administration-filter inspection reconciled implementation with the documented boundary: author search now runs through the shared Unicode/control/bidi/HTML plain-text normalizer before removing `LIKE` wildcard metacharacters and applying a bounded bound-parameter query.
- [x] Lost-response edit inspection found that an already committed desired body retried with the former version still produced a stale error. Exact normalized body/hash/spoiler equality is now a semantic no-op before optimistic conflict rejection; a materially different stale edit still fails and never overwrites the newer row.
- [x] Account-deletion concurrency inspection found that author anonymization intentionally preserves comment version, so an edit authorized just before deletion needed an ownership predicate at write time. The atomic optimistic update now also requires the original `user_id`; a request arriving after anonymization cannot mutate the preserved discussion body.
- [x] Privacy linkage inspection found that the no-longer-useful idempotency `submission_key` retained a one-way derivation from the deleted user identity and random form token. Account anonymization now nulls it alongside the author and unresolved report deduplication key while preserving comment ID/body hash/thread/moderation evidence.
- [x] Disposable SQLite inspection now confirms a clean full-repository migration, a direct down/up cycle of all four discussion migrations plus the focused relationship-pagination index migration, all eight discussion tables, required composite indexes and an empty `foreign_key_check`. Representative target/block/mute query plans select the intended covering indexes. The earlier shared-worktree Task 11 migration blocker was resolved before final Task 12 verification.
- [x] Managed Chromium acceptance exercised a verified author, a second verified reader and an authorized moderator against an isolated database: stable create/edit/delete/restore, server-hidden spoiler and long-body reveal, desired-state reactions, reply context, report, mute/block, notifications, reversible hidden moderation and temporary restriction/revocation all worked. A hidden direct link returned 404, private evidence remained moderator-only, mobile 390×844 had no horizontal overflow, fresh reader/admin sessions had zero console errors and observed Livewire requests returned 200.

### Canonical domain contract

- Identity: `comments.id` is the one stable database/public anchor identity. Body, locale, author name, target slug, parent order, page and edit state never participate in identity.
- Targets: stable `title`, `season`, `episode` and `collection` codes plus numeric target identity and an optional root title ID. One explicit resolver allowlists models, rechecks target visibility and ownership relations, locks the target again in the create transaction and produces canonical URLs; no Eloquent morph class is accepted from a request.
- Scope: every list and composer identifies its exact target. Title discussion is the default; the selected playable season/episode can be chosen explicitly; an eligible collection embeds the same component with collection scope. Scopes are never mixed in one page of results.
- Replies: one table and one model own both top-level comments and replies. A reply's structural `parent_id` always points to the top-level thread root; optional `reply_to_id` preserves reply-to-author context. This one-level structural depth makes cycles and unbounded recursive rendering impossible while allowing logical replies to replies. New replies require the structural root to remain published, so moderation closes the whole thread without deleting preserved children.
- Body: Unicode normalized plain text only, escaped with Blade, with meaningful line breaks preserved. HTML/Markdown/provider HTML are never interpreted; URL-like text remains non-clickable plain text, so scripts, event handlers, iframes, Blade fragments and dangerous schemes cannot execute.
- Validation: normalized non-empty text, configurable Unicode length/line/link/repetition limits, server-side executable/dangerous-data-scheme rejection and localized errors. Since URLs are never converted into links, ordinary prose such as `data:` remains valid. The same body value object serves create, reply and edit.
- Publication: verified users publish immediately unless bounded anti-spam signals place a submission in `pending`. Stable internal moderation values are `published`, `pending`, `hidden`, `rejected`, `spam` and `removed`; labels and reasons are translated only at presentation time.
- Spoilers: one boolean protects the whole comment. Spoiler body is omitted from initial HTML/Livewire DTOs, notification data/excerpts, profile previews and SEO; an explicit accessible server round trip reveals it to an authorized viewer.
- Long bodies: storage is never truncated. Initial prepared data contains a Unicode-safe excerpt only after the configured threshold; show-more/show-less loads or removes the full prepared body without placing it in hidden source markup.
- Editing: owner-only inside a configured window, with optimistic `version` checking, unchanged author/target/parent/creation time, body/spoiler revalidation, `edited_at` and cache invalidation. No edit history is added because none exists and current legal/moderation requirements do not demand it.
- Deletion: owner delete is soft deletion with a stable reason and safe tombstone when public replies remain. Replies/reactions/reports are not cascaded by ordinary deletion. Owner restoration is time-bounded and never overrides stable moderator `removed` status; moderation/privacy hard deletion is a separate service decision and cannot remove a parent with replies or evidence casually.
- Reactions: one canonical `up`/`down` record per user/comment, database-unique and idempotently replaceable/removable. Authors cannot react to their own comments; deleted or non-public comments cannot be reacted to. Score is derived as up minus down; no denormalized totals are stored.
- Mentions and premium enhancements: unsupported because the portal has neither capability. `@text` remains plain text and creates no links/notifications. Basic discussion is identical for all verified users; no speculative premium control or asset is rendered.
- Notifications: reply, reaction, moderation and report-resolution events use stable body-free database payloads and deterministic deduplication. Preferences, self-event suppression, blocks/mutes and target visibility are checked before delivery and again during presentation; spoiler/deleted/hidden bodies never become excerpts.
- Blocks/mutes: a directional block prevents direct replies/reactions/notifications between both sides and hides either side's comments from the other without revealing who blocked whom. A mute is private to the muter, hides that author's body and suppresses their notifications without preventing unrelated writes. Global counts still include otherwise public comments.
- Reports: authenticated verified users choose a stable allowlisted category and bounded optional detail. One unresolved user/comment/category key is idempotent; resolution releases only that deduplication key so a later valid report can retain new evidence. Reporter identity is private, historical evidence is retained, and public responses never reveal internal status or notes.
- Restrictions: comment-only temporary/permanent records contain stable reason code, start/expiry, moderator and private note. Permission checks compare expiry synchronously, so no cron is required; expired records cease applying automatically and do not affect login or unrelated library/playback use.
- Anti-spam: one service combines a 90-second, same-user/target/root comparison of normalized body text without case sensitivity, submission-token idempotency, per-action Laravel rate limits and bounded link/line/repetition/account-age signals. A weak signal can request review, never create an opaque permanent ban.
- Counts: the public discussion count is all non-deleted `published` comments including replies. Per-thread reply count is non-deleted published replies. Viewer-specific hidden state does not alter these public aggregates; moderator totals are labelled separately.
- Pagination: deterministic top-level pagination is URL-backed and offers stable `newest`, `oldest` and `popular` codes. Replies stay chronological and only one bounded thread is progressively expanded at a time; no initial query loads every reply.
- Caching: comment pages are queried directly rather than placed in a second data cache. Guest SSR contains public DTOs only. Mutations bump the existing scoped title/collection page-cache version after commit; notification reads and viewer overlays never invalidate or enter shared caches.
- Privacy: self-profile activity is private and paginated, includes the owner's eligible public and moderated states with safe labels, excludes deleted/removed rows and spoiler excerpts, and has stable target links. Account deletion anonymizes author linkage while preserving discussion integrity; export includes only the user's comments/reactions and public-safe target references.
- API: existing v1 contracts remain unchanged in this task. Web/Livewire discussions neither serialize Eloquent graphs nor expose raw target URLs/moderation state. A future mobile discussion API must reuse the same actions/policy/query/Resources rather than duplicate behavior.

### Database and compatibility plan

- [x] Add focused reversible migrations for comments, reactions/reports/restrictions, blocks/mutes/preferences and Laravel database notifications. Do not edit deployed migrations or apply pending migrations to the production-like SQLite file during this task.
- [x] Index exact implemented queries: target/root/status/order, parent/status/order, author activity, moderation queue, duplicate window, reaction aggregate/current user, report queue/deduplication, active restriction and both directions of blocks.
- [x] Add uniqueness only on the empty audited domain: submission key, user/comment reaction, report deduplication, directional block and directional mute. No legacy content is deleted or guessed.
- [x] Keep counts and reaction totals derived. No reply-count, score, upvote or downvote aggregate column is introduced, so there is no drift/reconciliation path to maintain.
- [x] Keep `target_id` deliberately non-polymorphic and resolve it through the stable enum/service. Root title identity supports targeted invalidation and title merge; collection targets remain independent.
- [x] Integrate title/season/episode merge mapping before existing force deletes. Preserve comment ID, body, replies, reactions, reports, timestamps, deletion/spoiler/edit/moderation state and anchors.
- [x] Rollback drops only new discussion/notification tables and relations. It never mutates reviews, catalog, collection, watchlist, rating, progress, history or imported data. Export discussion data before rollback after production writes exist.

### Phased implementation checklist

- [x] Enums, value objects, DTOs, models/relations, migrations, target resolver, visibility query and policy.
- [x] Canonical create/reply/update/delete/restore actions, body normalization, duplicate/idempotency/rate-limit/anti-spam and scoped cache invalidation.
- [x] Reaction, report, moderation, restriction, block/mute and body-free notification actions with append-only administrator evidence.
- [x] Account deletion/anonymization, password-confirmed private `no-store` JSON account export, private profile activity/settings and notification inbox/preferences. The shared exporter currently materializes the complete account snapshot before its streamed response; a genuinely incremental large-account export remains an existing cross-domain limitation and was not replaced with mandatory queue infrastructure.
- [x] Class-based Livewire discussion component, prepared reusable item/composer views, deterministic pagination/sorts, progressive replies, scope switcher, loading/empty/error/permission states and direct-link focus.
- [x] Accessible server-revealed spoilers, server-expanded long bodies, keyboard/touch controls, Russian/English parity, mobile wrapping and reduced-motion focus/highlight module.
- [x] Title/player integration, optional collection integration, direct route/canonical/noindex policy, admin moderation queue and title-merge reconciliation.
- [x] Update topic-owner documentation, manual acceptance matrix, maintenance log and English changelog without adding or running automated tests.
- [x] Run allowed PHP syntax/Pint/route/schema/query/translation/cache/security/Blade inspection, Vite build and browser smoke; reread the task and inspect all changed/directly-related files. No automated test runner was invoked.
- [ ] Commit only on existing `main`, preserve unrelated work, verify status/remote and push the completed commit.

### Files expected to change

Expected: additive migrations; comment enums/models/policy/actions/services/DTOs/value objects/notifications/controllers/Livewire components; title/season/episode/user relationships; account/merger/cache/admin/nav/route/request integration; comment Blade/JavaScript; `config/comments.php`; `lang/{ru,en}/comments.php`; architecture/data/authorization/security/validation/forms/views/frontend/caching/performance/notifications/administration/API/SEO/deployment documentation, this plan, maintenance log and `CHANGELOG.md`.

Must remain semantically unchanged: provider `catalog_title_reviews`, imported review API, watchlist/rating/progress/history, playback/source grants, title/season/episode public identity, Seasonvar import command, existing target and historical-slug routes, catalogue/search/filter/recommendation output, sitemap membership rules and user-authentication credentials.

### Final manual verification checklist

- [x] Stable comment IDs/anchors survive body edits, deletion/restoration, slug change and target merge; direct routes authorize before redirecting and never expose hidden content.
- [x] Target enum/resolver rejects arbitrary classes/IDs, cross-target replies, deleted/inaccessible targets, self/descendant cycles and non-root structural parents.
- [x] Plain-text normalization accepts Unicode/non-Latin scripts and rejects empty/control/dangerous/excessive input; Blade contains no raw comment body output.
- [x] Create/reply/edit/delete/restore remain idempotent where required, preserve composer text on recoverable failure, use no GET mutation and maintain accurate derived counts.
- [x] Newest/oldest/popular pagination is deterministic; replies are chronological/on-demand; eager loading and grouped viewer state avoid author/reaction/reply/block N+1 queries.
- [x] Spoiler/full long body is absent from initial HTML, notifications/profile/SEO and appears only after an accessible explicit action; short text is not collapsed.
- [x] One up/down reaction per user/comment, no self vote, safe change/remove, no deleted/hidden reaction and no current-user state in shared cache.
- [x] Reply/reaction/moderation/report notifications respect preferences, self suppression, blocks/mutes and deduplication; every excerpt is plain, bounded and spoiler/deletion safe.
- [x] Block/mute/report/restriction/moderation behavior is server-authorized, private state/notes/reporter identities never enter public DTOs, and expiry works without scheduler support.
- [x] Public counts/caches contain public aggregates only; every meaningful mutation invalidates only the affected title/collection scope after commit.
- [x] Account deletion anonymizes discussion safely, export omits private evidence, own activity respects visibility and no public profile capability is invented.
- [x] Comment pages are absent from sitemap/structured data; direct/sort/page state has target canonical/noindex behavior and no spoiler metadata.
- [x] Every visible control works with loading/disabled/success/error state; labels and ARIA text have exact Russian/English key parity; no Volt, `@php`, inline CSS, inline business JavaScript, Blade query or dead control is introduced.
- [x] Responsive inspection covers narrow/landscape phones, tablet, desktop and zoom with long names/text/links/replies/reactions/tombstones; focus, keyboard, touch targets and reduced motion remain usable.
- [x] Existing portal routes, home/search/catalog/player/library/reviews/ratings/recommendations/import/admin/API/cache/SEO behavior remain operational; allowed diagnostics/build/smoke are recorded truthfully.

## Task 13 — canonical serial reviews, ratings and moderation

Status: implementation and owner documentation complete; final verification in progress on 2026-07-15. This is the only Task 13 implementation plan. It extends the deployed `catalog_title_reviews` domain in place and does not introduce a competing review table.

### Audited baseline and discovered risks

- [x] The deployed review domain is title-only: `CatalogTitle::reviews()` owns `catalog_title_reviews`; neither `Season` nor `Episode` has a review relation or review route. Season/episode discussion belongs to the separate comment domain, so Task 13 will not invent season or episode reviews.
- [x] The only public review route is read-only `GET /api/v1/titles/{titleSlug}/reviews` (`api.v1.titles.reviews`). There is no web review route, localized alias, legacy web anchor, composer, profile history, directory or administration route to preserve. The existing API name, path, pagination and provider-safe fields remain compatible.
- [x] The production-style SQLite database contains 73,101 imported reviews for 22,474 titles. All 73,101 have no provider author, three lack a publication date, bodies range from 167 to 20,726 characters, and there are no empty bodies, invalid hashes, duplicate `(catalog_title_id, body_hash)` groups, orphan targets/sources, script tags, JavaScript schemes or detected HTML-like bodies.
- [x] Existing review identity is the numeric database ID. It is independent of body, title slug, locale, sort and page; importer deduplication uses `(catalog_title_id, body_hash)` but does not replace public identity.
- [x] Imported review bodies are normalized plain text. The API returns JSON text and no web view currently renders it. User-authored reviews will keep the same escaped plain-text boundary; Markdown, rich HTML, provider HTML, automatic links and automatic translation remain unsupported.
- [x] Imported reviews have no title, numeric rating, sentiment, spoiler flag, user relationship, verification, edit/delete state, helpfulness, report, moderation, restriction or notification data. These are absent capabilities, not corrupted legacy values.
- [x] Because no trustworthy legacy spoiler signal exists, provider rows retain the non-spoiler default rather than receiving an invented classification. Report category `unmarked_spoiler` and moderator spoiler correction provide the safe forward path; this legacy-content limitation is documented.
- [x] Portal user ratings are separate from provider ratings and already canonical in unique `catalog_title_user_states(user_id, catalog_title_id)`. They are optional integers from 1 through 10. Provider ratings stay in `catalog_title_ratings`; review work must not mix either source or create a third rating record.
- [x] Review submission will use the existing portal rating record when the author supplies a score. Rating without a review and review without a rating remain valid. Editing can update/clear that same record; deleting/restoring a review preserves the independent portal rating. Missing ratings are never zero and do not enter averages.
- [x] The current API query is deterministic but the database plan uses the body-hash unique index plus a temporary B-tree for `published_at DESC, id DESC`. A focused public-list composite index is required before exposing interactive sort/filter pagination.
- [x] There are no duplicate user reviews or helpful votes to reconcile because no user review/vote columns or tables exist. The existing 73,101 provider rows must remain byte-preserved and continue importing/upserting/merging safely.
- [x] Trusted watch evidence already exists only in `episode_view_progress`, written through authorized playback sessions. The audited database currently has no progress rows. Verification must be calculated server-side from meaningful persisted progress/completion and stored as a non-downgrading review snapshot; page visits, bookmarks and client flags never qualify.
- [x] The in-progress Task 12 discussion domain adds shared user blocks/mutes, Laravel database notifications and account-export/deletion boundaries. Task 13 will reuse their final generic relationships/notification infrastructure after rereading the settled files, while keeping review reports, votes and review-only restrictions semantically separate from comments.
- [x] Laravel uses Russian by default and currently has Russian/English PHP catalogs. Title routes are not localized; localized collection routes are unrelated. Review controls will have exact `ru`/`en` key parity and preserve the active locale across Livewire hydration without translating user content.
- [x] Guest title HTML may enter versioned full-response cache, but the review island is lazy: the shell stores only its placeholder, while every review row/aggregate is loaded by an `X-Livewire` request excluded from shared caching. Viewer vote, permissions, blocks/mutes, pending ownership, reports, restrictions and moderator controls therefore remain request-specific overlays rather than shared HTML.
- [x] Existing title SEO uses canonical target URLs and provider aggregate-rating structured data. Reviews have no sitemap or schema entry. Direct review URLs will resolve to the canonical title/anchor; sort/filter/page URLs remain canonicalized and noindex. Spoiler/pending/hidden/deleted bodies will not enter metadata, excerpts, notifications, search documents or JSON-LD.
- [x] Search does not index review bodies. Recommendation v4 switched from raw rows to the explicit public-review relation, so hidden/deleted community rows never influence its bounded quality signal; no personal text or private rating explanation is exposed.
- [x] Recommendation v4 has no bounded incremental builder: it consumes that public count only during the existing full importer rebuild. Task 13 invalidates the existing read namespace but does not introduce synchronous full-catalog work or mandatory infrastructure; ordering converges on the next scheduled/explicit import rebuild and this delay is a documented limitation.
- [x] Final Task 12 inspection confirmed that generic directional blocks, private mutes and Laravel database notifications are the reusable social boundary. Review schema capability checks fail closed during partial deployment, so authenticated legacy review reads do not query a not-yet-created block/mute table and writes remain disabled until every dependency exists.
- [x] Mutation retry inspection found insertion races for a first helpfulness vote and first report. Voting now uses the database unique key through atomic `upsert`; reporting uses the unique deduplication key through `insertOrIgnore`, so double clicks, retries and concurrent requests converge without duplicate rows or raw constraint errors.
- [x] Title-merge inspection found that hard-deleting an exact duplicate review would violate stable identity and that the deployed `(catalog_title_id, body_hash)` key prevents retaining two exact hashes on the canonical title. Exact duplicates are now archived with their original ID/body/timestamps/hash evidence, a collision-safe active-key hash, `merged_into_id` and an alias; votes/reports move once and direct legacy IDs resolve to the canonical review.
- [x] Rolling-schema merge inspection found that the pre-alias fallback still consolidated a colliding provider row by deleting its original ID. The fallback now always moves the original row and uses a deterministic collision-safe body key; the documented writer pause remains mandatory, but an ordering mistake no longer destroys review identity or text.
- [x] Merge report inspection found that two unresolved reports from titles merged into one review could retain one unkeyed open duplicate. Duplicate unresolved reports are now preserved as dismissed evidence with their original detail/timestamps while one canonical deduplication key remains active.
- [x] Administration inspection found that the queue-only `attention` filter leaked into the persisted moderation-status select, existing moderation reasons were reset to `approved`, and report resolution reused the review note field. Filter/status options are now separated, existing stable reasons are retained, report notes have independent state, and review/report/restriction mutations write their audit fingerprint in the same transaction.
- [x] Privacy-action inspection found that owner deletion unnecessarily resolved the target through current public visibility. Delete now authorizes the stable review directly and remains available even if the title becomes inaccessible; edit/restore/create still require a currently reviewable target.
- [x] Input-boundary inspection found that normalized character limits alone still allowed an oversized raw payload full of markup/whitespace to consume avoidable normalization work. Review title/body value objects now enforce a Unicode-safe raw byte ceiling before normalization and retain the canonical post-normalization character limits.
- [x] Query-payload inspection found that public/title and private self-history reads selected every review column, including hashes and private moderation fields later discarded by the presenter. Those reads now select an explicit presentation allowlist; only the gated administration query loads full moderation evidence.
- [x] Moderation side-effect inspection found that changing only an internal reason/private note invalidated public caches and emitted a misleading author status notification. Audit/version history remains complete, while cache invalidation now requires a real status/spoiler presentation change and notification requires a real status transition.
- [x] Final administration/profile inspection corrected the private/public column selection, fingerprints private-note changes by one-way hash without logging their contents, preserves localized reveal rate-limit errors in private history, and removes the redundant moderation self-link from the administration card.
- [x] Report-state inspection found that the stable `reviewed` value was not treated as unresolved by the attention queue. Both `open` and `reviewed` now remain actionable and counted until an explicit final transition to `resolved` or `dismissed`.
- [x] SEO request-state inspection confirmed review controls are excluded from the shared title-page cache; review highlight, pagination, sort and filter query shapes now additionally emit request-scoped `noindex,follow` while retaining the clean title canonical URL.
- [x] Hostile-input inspection found the raw byte guard could cast an unsupported `mixed` shape before canonical normalization. Title/body value objects now reject arrays and arbitrary non-stringable objects with their localized required-input errors before any cast or regex work.
- [x] Audit-field inspection found review/report events listed every possible field even for a note-only change. Atomic audit rows now list only the fields actually changed while the before/after fingerprints retain private-note integrity through hashes rather than note disclosure.
- [x] Cache inspection found that provider-review moderation and title merges also affect the existing read-only review API. Those paths now bump the existing API version in addition to the scoped title/recommendation versions; user-only vote/rating/profile state never causes a global API invalidation.
- [x] Merge inspection also found a null-date edge in playback reconciliation. Canonical title/episode merges now prefer an actual incoming `last_watched_at` over a missing existing value while retaining the most advanced completion/progress record; review verification snapshots remain unchanged and exact private progress is never exposed.
- [x] Large-merge inspection found offset-based `each()` loops that mutate their own title/review/episode scope and could skip rows after the first 1,000. Review, vote, user-state and progress reconciliation now use primary-key `eachById()` iteration, preserving deterministic coverage without loading full collections.
- [x] Focused static analysis found the duplicate-vote cleanup callback in title merge referenced the legacy review outside its captured scope. The callback now captures that stable row explicitly, so removing a conflicting/self vote also removes its deterministic legacy notification without an undefined-variable failure.
- [x] The additive migration was first applied successfully to an isolated disposable SQLite database and its foreign keys/indexes were inspected; Task 13 itself did not run a production migration or rollback. A later read-only audit found it present as batch 14 in the configured SQLite because a concurrent Task 11 disposable rehearsal reused cached configuration and applied all pending additive migrations. That separate incident is disclosed in `MAINTENANCE_LOG.md`; no destructive rollback was attempted, and Task 13 verification treats the configured database as migrated rather than repeating the false pre-rollout claim.
- [x] Fresh-versus-configured schema comparison found that the accidental early application used an older in-flight shape: four later canonical review columns were absent and report `deduplication_key` was still non-nullable, although migration `220000` was already recorded. Additive idempotent `235100` now converges exactly those five differences without touching provider rows or re-running the released migration; its no-op `down()` deliberately leaves fields owned by `220000`, whose full rollback remains authoritative.
- [x] Isolated PHP 8.5 runtime acceptance found that passing the built-in `is_string` directly to `Collection::filter()` receives both value and key and fails before review creation. `ReviewTitle` now uses an explicitly typed one-argument closure, preserving the same generic-title rules without a version-dependent callback error.
- [x] Direct-link browser acceptance found both that a lazy child request does not retain the outer title request's `review` query parameter and that a missing hash target cannot trigger viewport-lazy loading. `CatalogTitleDetail` now captures the positive stable review ID once in a locked property, passes it into the review island and disables viewport deferral only for that direct-link request, so canonical redirects load, focus and highlight the correct `review-{id}` without trusting hydrated client state; ordinary title pages remain lazy and shared-cache safe.
- [x] Pagination acceptance with 16 mixed user/provider rows found that Livewire's fallback paginator inherited a relative original path and omitted active review criteria. Title, private history and moderation paginators now set their canonical named-route path and append only validated sort/filter values; page 1/2 and browser back/forward preserve deterministic state without duplicate `titles/titles/...` URLs.
- [x] Isolated privacy acceptance exercised shared block/mute filtering plus account export/deletion. Blocked voting failed server-side and blocked/muted direct links returned safe 404s; export contained only the owner's review/vote fields, while deletion preserved anonymized review/report evidence and removed rating, vote, notification, preference, restriction and reporter/deduplication linkage.

### Canonical domain contract

- Storage and identity: extend `catalog_title_reviews` additively. Existing IDs remain stable for imported and user records. A stable internal origin distinguishes `provider` and `user`; provider author/body/hash/source/date behavior remains compatible.
- Target: one direct `catalog_title_id` foreign key is the complete allowlist. Target resolution reuses `CatalogTitleQuery::visibleTo()`/policy and rejects missing, deleted, hidden or inaccessible titles. Arbitrary class names and morph input are impossible.
- Scope: every UI label says the review concerns the complete serial. Season and episode reviews remain unsupported because the audited product does not require them; their conversational content belongs to comments.
- Separation from comments: comments are short threaded discussion with replies/reactions and title/season/episode/collection scopes. Reviews are non-threaded structured title opinions with required title/body, optional canonical portal score, whole-review spoiler protection, one-review-per-user, helpfulness, profile history and editorial moderation. One submission creates exactly one review and never copies text into comments.
- Body/title: Unicode NFKC-normalized plain text, server-side control/bidi cleanup, meaningful line breaks, required non-generic title, bounded title/body/lines/links/repetition and dangerous-scheme rejection. Blade only receives escaped text; no Markdown/HTML renderer or rich preview is added.
- Rating: `CatalogUserStateService` remains the one 1–10 integer source. Review queries join the author's title state for display, filter, sort and aggregate. Provider ratings and external aggregate schema remain separately labelled.
- Sentiment/reactions: numeric rating is sufficient and the product has no existing sentiment field, so Task 13 will not infer or store sentiment. Review engagement is exactly `helpful` / `not_helpful`; comment `up`/`down` reactions remain separate and no emoji reaction layer is added.
- Uniqueness/idempotency: one current review ownership key per user/title. A soft-deleted row keeps that key through the 30-day restoration window; after expiry, the next create transaction archives/releases the key without deleting the old ID/body/history and creates at most one new active row. The nullable SHA-256 key avoids nullable composite-unique differences across supported databases; provider rows remain unlimited. A UUID submission token maps to one unique submission key; user body hashes are author-scoped and archived collisions retain `original_body_hash`, so the deployed provider body-hash unique index remains compatible.
- Visibility/moderation: stable internal states are `published`, `pending`, `hidden`, `rejected`, `spam` and `removed`; only `published` non-deleted reviews are public. The owner may see a safe pending state; private notes/reasons remain moderator-only. New-account/link signals may request moderation but never permanently ban automatically.
- Editing/deletion/restoration: owner edit remains available while an active user review is `published` or `pending`, is optimistic-versioned, preserves author/target/creation/identity/votes/reports and records `edited_at`; no arbitrary edit deadline existed or was invented. Owner delete is soft, explicit and POST/DELETE-equivalent through Livewire and remains a direct owner privacy action even if the target later becomes inaccessible; author restoration is limited to 30 days and still requires a valid target. Moderator removal preserves evidence. Review deletion does not remove the canonical portal rating.
- Verification: a dedicated service accepts only meaningful authorized playback progress or a completed episode for the same title. It stores a boolean historical snapshot, never trusts the client and never exposes exact progress/time/device/translation. Later history deletion does not falsify a truthful snapshot.
- Spoilers/previews: one boolean protects the entire review. Unrevealed spoiler title and body are omitted from initial DTO/HTML rather than blurred. Explicit server reveal/hide is keyboard/screen-reader accessible. Spoiler text is replaced with a translated placeholder in profiles/notifications and excluded from SEO/search/schema.
- Helpfulness: one unique vote per user/review, idempotent create/change/remove, no self-vote, no deleted/non-public target, and shared block enforcement. Public totals and score (`helpful - not_helpful`) are derived in grouped subqueries; viewer vote is loaded separately and never shared-cached.
- Sorting/filtering/pagination: allowlisted deterministic `newest`, `oldest`, `most_helpful`, `highest_rated`, `lowest_rated`; filters for rating, spoiler state and verified watching. Missing ratings sort after rated entries. URL state uses a dedicated page name, preserves filters/sort/locale, and stable ID tie-breakers prevent duplicates/skips.
- Counts/aggregates: public count means published non-deleted provider plus user reviews. Review-rating count/average includes only published non-deleted user reviews whose canonical portal rating is non-null. Values are derived, correctly rounded and clearly separate from provider/external scores; no drift-prone stored aggregate is added.
- Profiles/privacy: authenticated self-profile review history is private, paginated and spoiler-safe. No public review-author page is invented while the account model lacks a public-profile privacy preference. Account export includes the user's review/votes/public status; account deletion anonymizes review ownership and removes votes/preferences while preserving moderated public text/evidence.
- Reports/restrictions/notifications: review-specific stable report categories, deduplication and moderation evidence; merge collisions close only the redundant unresolved key while retaining its row/detail as dismissed evidence. Review-only temporary/permanent restrictions evaluate expiry synchronously. Moderator status/reason/report-note state is independent and each review/report/restriction change records an atomic body-free audit fingerprint. Generic user blocks/mutes are reused. Body-free deterministic database notifications cover helpfulness, moderation and report resolution, respect preferences/blocks/mutes and never notify self.
- Cache/SEO: mutations bump only the affected existing title/API/recommendation cache versions after commit. No separate review cache or global flush. Individual reviews/direct anchors never enter sitemap. No user review or review aggregate JSON-LD is emitted until a separately validated editorial strategy exists; existing provider aggregate schema is not mixed with portal scores.
- Merge/deleted target: title merge moves user/provider reviews inside the existing merge transaction, preserves IDs/votes/reports/status/timestamps, reconciles a duplicate user's rows deterministically without discarding evidence, and keeps legacy anchors mapped. Even the temporary pre-alias fallback retains the original row/ID through a collision-safe key. Deleted/inaccessible titles hide reviews publicly and cannot accept create/edit/restore, while owner/account privacy deletion and moderator evidence remain available through their dedicated boundaries.

### Database, compatibility and migration plan

- [x] Add nullable user/title/moderation/spoiler/verification/version/submission/deletion fields, an archival original-hash field and soft deletes to `catalog_title_reviews` with `origin=provider` defaults that require no destructive provider backfill.
- [x] Add nullable ownership/submission unique keys plus exact public-list, author-history and moderation indexes. Retain the deployed provider `(catalog_title_id, body_hash)` unique contract and update importer/merger without rewriting provider rows.
- [x] Add review helpfulness votes, reports, restrictions and notification preferences with stable codes, foreign keys, deduplication and exact queue/active/export indexes. Do not edit deployed migrations.
- [x] Add an idempotent convergence migration for any environment that applied the in-flight `220000` shape before its final schema; a fresh install remains a no-op and full domain rollback is still owned by `220000`.
- [x] Keep rating aggregates derived from `catalog_title_user_states`; add no review rating column and no denormalized count/average/helpfulness field.
- [x] Guard code with a review-schema capability service so deploy order is safe while the additive migration is pending; legacy API/provider reads keep working before and after migration.
- [x] Migration rollback drops only Task 13 tables/indexes/columns. It cannot reconstruct user review data, so production rollback requires export first; provider review identity/body/source/date remain intact.

### Phased implementation checklist

- [x] Finalize the concurrent Task 12 boundary, reread every changed/shared file and refresh this audit before review code edits.
- [x] Add review enums, value objects, DTOs, model relations/policy, schema capability, configuration and additive migration.
- [x] Implement canonical create/update/delete/restore, rating synchronization, verified-watch, anti-spam/rate-limit, cache invalidation and duplicate/idempotency actions.
- [x] Implement list/profile/admin queries and presenters with grouped author/rating/vote/viewer/block data, deterministic sort/filter/pagination and direct-link page resolution.
- [x] Implement helpful voting, reports, moderation, temporary/permanent restrictions, notification preferences/events and account export/deletion integration.
- [x] Integrate title page, private profile history, administration, routes/anchors, legacy API compatibility, title merge, recommendations and SEO/noindex policy.
- [x] Add complete Russian/English interface catalogs and accessible mobile-first Livewire/Blade controls with server-side spoiler reveal, loading/empty/error/confirmation states and no Blade queries/business JS.
- [x] Update topic-owner documentation, maintenance log and English changelog; record known limitations and the complete manual acceptance checklist.
- [ ] Run only allowed static/Pint/route/schema/query/translation/security/cache/SEO/Vite/browser diagnostics; do not create or run automated tests for Task 13.
- [ ] Reread Task 13 and all changed/directly-related files, inspect final diff/status on `main`, commit completed work and push configured remote.

### Expected files and protected boundaries

Expected: the primary additive review migration plus one idempotent rollout-repair migration; review enums/model/policy/actions/services/DTOs/value objects/notification/controller/Livewire/view/config/translations; title/user/account/merger/recommendation/cache/route/profile/admin/API resource integration; owner documentation, this plan, maintenance log and `CHANGELOG.md`.

Must remain semantically unchanged: provider review bodies/IDs/source/hash/date and importer command; current API route/name and baseline fields; comment tables/targets/replies/reactions; portal rating table/range/offline sync; provider rating table/schema; title/season/episode IDs/slugs/bindings; player/progress/bookmark/library/collection/search/catalog/sitemap/auth behavior.

### Final manual verification checklist

- [ ] Existing 73,101 provider reviews retain IDs/content and remain importable/API-readable; no provider/user duplicate system exists.
- [ ] Title-only target allowlist, one-review rule, submission idempotency and merged-title reconciliation preserve stable review/legacy anchor identity.
- [ ] Plain-text title/body validation accepts Unicode and prevents stored/reflected XSS, dangerous schemes, excessive links/lines/repetition and mass assignment.
- [ ] Optional 1–10 review score uses the existing portal rating row, missing is not zero, external/provider scores remain separate, and edit/delete behavior is documented and accurate.
- [ ] Verified watching is server-only and privacy-safe; spoiler title/body are absent from initial HTML, profiles, notifications, search, SEO and structured data until explicit reveal.
- [ ] Edit/delete/restore, helpful vote/change/remove, report, moderation and restriction actions are authorized, rate-limited, retry-safe and expose no private notes/reporter/block state.
- [ ] Sorting/filtering/pagination/direct focus are deterministic and URL-safe; query plans use justified indexes and avoid author/rating/vote/verification/block N+1 queries.
- [ ] Public counts/averages/vote totals update after every mutation/merge/moderation; viewer vote/pending ownership/permissions/restrictions never enter shared cache.
- [ ] Profile/export/deletion/notifications/blocks/mutes/recommendations/admin integration preserves privacy and unrelated watchlist/progress/bookmark/collection state.
- [ ] Direct/filter/page URLs canonicalize safely, remain absent from sitemap and emit no invalid/duplicate/spoiler review JSON-LD.
- [ ] Every visible control has translated `ru`/`en` loading/empty/error/authorization state, keyboard/focus/touch/reduced-motion behavior and works from narrow phone through desktop/zoom.
- [ ] No Volt, new `@php`, inline CSS, business JavaScript, raw review HTML, Blade query, fake control, TODO/debug output, unused class/import or dead route remains.
- [ ] Allowed diagnostics/build/browser smoke pass without creating/running tests; all relevant docs/changelog are current; final commit is on existing `main` and pushed.

## Task 14: canonical public profiles, identity and privacy

Status: implementation and allowed non-test acceptance complete on 2026-07-16; the isolated `main` delivery commit and push complete this plan. This is the single Task 14 plan. It extends the existing `users`, collections, comments, reviews, library, blocks/mutes, account lifecycle and catalogue SEO boundaries without creating parallel user, activity, moderation, search or media systems.

### Audited current architecture and decisions

- [x] Every tracked Markdown file and the topic-owner map were read before implementation. The route/model/migration/policy/service/Livewire/Blade/Vite/translation/cache/SEO/sitemap/storage/export/delete/admin surfaces and the directly related Task 08–13, 15 and 16 contracts were inspected.
- [x] Before Task 14 the portal had private `/profile*` account pages and a legacy public collection-owner URL `/profiles/{userPublicId}/collections`, but no canonical public profile, username, biography, avatar, cover, profile privacy, username history or profile report tables. `users.name` was the display name and `users.public_id` was already the stable non-email cross-domain user identity.
- [x] Existing comments/reviews/collections already had their own publication, spoiler, moderation, pagination, cache and target-visibility contracts. Detailed history/progress, watchlist states, private collections, blacklist, personal tags, notification/security/provider/session data were private. Task 14 reuses those contracts and does not copy their write logic or broaden their public fields.
- [x] Directional `user_blocks` and private `user_mutes` already existed in Task 12. There was no follow graph, role/badge/rank model, favorite-genre/location profile model, public profile search index, public activity event stream or safe public-profile notification category. Those unsupported capabilities remain absent rather than appearing as dead controls or inferred data.
- [x] The existing storage boundary is `PrivateUploadStorage` on the configured private upload disk. The project has no approved image-derivative/EXIF pipeline, so Task 14 accepts only bounded JPEG/PNG/WebP raster files, uses server-generated paths, serves them through an authorized same-origin controller and does not claim responsive transcoding or metadata rewriting.
- [x] Existing public author cards loaded names from users and had no profile destination. Comment and review query/presenter boundaries now eager-load the small profile relation and emit a profile URL only for active public profiles; collection-owner pages use the same canonical URL. No author query loads email, password, provider, session or preference columns.
- [x] Migration risk was additive. The two Task 14 migrations were applied to the configured SQLite during schema diagnostics; they completed without destructive operations, created one profile per existing account and reported no duplicate username. Existing users retain the already-public presentation implied by their public collection page, while biography/member date/reviews/comments/watch lists/activity remain private; newly registered accounts start wholly private.

### Canonical contract

- Identity and routes: `users.id` remains the internal FK and `users.public_id` the stable opaque cross-domain identity. A lowercase ASCII username is the route-safe public alias, never email or mutable display name. Canonical routes are `/users/{username}` and the interface-only `/{locale}/users/{username}` alias; case changes and `user_profile_username_histories` resolve then redirect once to the unprefixed canonical route. The legacy UUID collection-owner route remains compatible but canonicalizes SEO and owner links to the username profile.
- Username: `ProfileUsername` owns trim/lowercase/ASCII format, 3–32 length, separators and a narrow reserved-route/support/admin list. Current and historical normalized usernames are independently unique. Change requires the authenticated owner, current password, Laravel rate limiting and a locked transaction; it records the former alias before updating the same user/profile row. A database uniqueness race is translated into the same safe validation result.
- Display and biography: `users.name` remains the existing display-name source and is never used for identity. Biography is optional original-language Unicode plain text, bounded to 1,200 characters, line-break preserving and HTML/control/bidi stripped. Blade escapes it; links/Markdown/rich embeds are not activated, so script/data/javascript URL execution is impossible.
- Public/private separation: `PublicUserProfileData` is an explicit allowlist of display name, username, initial, optional public biography/member month, private-controller media URLs, public counts/section flags, safe viewer actions, canonical URL and presentation version. It has no email, provider ID, permissions, security state, private collection, exact episode/progress/history/timestamp, translation choice, blacklist, personal tag, report or moderator note. The owner `/profile` page remains authenticated, noindex and `private, no-store`.
- Visibility: profile and section values are stable `public|private` codes. A profile must be both public and moderation `active`; each biography/member-since/collections/reviews/comments/watching/completed section must then be explicitly public. Detailed history and exact progress have no public field or control. The reserved activity column remains private and has no UI because no canonical activity event stream exists.
- Existing-account defaults: the idempotent backfill creates deterministic collision-safe usernames, marks the profile/public-collections boundary public to preserve existing collection presentation, and leaves every behavioral/text/date section private. New registration calls the same service but gets model privacy-first defaults. No existing explicit collection visibility, comments, reviews, progress, history or account preference is rewritten.
- Public sections: one page-level Livewire component keeps only locked username, allowlisted URL tab and bounded report draft. It loads only the selected reviews, comments, collections, watching or completed paginator. Review/comment excerpts reuse their public statuses and omit spoiler bodies; inaccessible targets are excluded. Watch sections expose title-level card data only—never episode, progress, viewed time or player choices. Counts use the same predicates and cannot reveal hidden rows.
- Media: avatar and cover mutations reauthorize the owner, validate image/MIME/extension/dimensions/size, reject SVG and executables, rate-limit changes, store under a server-generated private path, lock the profile and delete only a replaced owned path after commit. Delivery checks public/owner/moderator policy plus stable public ID, kind and media version, returns `private, no-store` and `nosniff`; raw disk paths are never public. Account deletion schedules best-effort owned-file cleanup.
- Blocks and mutes: bilateral block lookup is enforced by `UserProfilePolicy` and returns a non-disclosing 404; direct URLs cannot bypass it. The canonical Task 12 block action remains the only mutation and its existing account controls provide unblock. Mute remains private/viewer-specific, suppresses the muted author's presentation through the existing relationship action and never enters shared output.
- Reports and moderation: a verified non-owner may submit one bounded allowlisted category/detail per unresolved fingerprint under independent user/profile rate limits. Public IDs are random; reporter, detail and status remain private. `/admin/profiles` requires the existing `manage-catalog` gate on route, hydration and service. Moderation uses only the implemented stable `active|hidden|suspended` states, can hide biography, remove profile media and resolve/dismiss reports with a private note; deletion remains the separate account lifecycle. It cannot change email/password/role/premium or expose reporter data publicly.
- Roles, badges and ranks: the repository has only the existing email-configured administrator gate and no durable public role/badge/rank assignment architecture. Task 14 does not derive badges from premium/activity, expose permission lists or let users self-assign authority. Adding these concepts requires a future canonical authorization/presentation model and migration.
- Search/follows/activity: public profile search, follows/followers and activity notifications/feed remain deliberately unsupported. Catalogue search continues to search catalogue content only and never email/private biography. Public profiles can still be reached from safe author/collection links. No empty tab, fake follow button or notification claim is rendered.
- Localization and accessibility: `lang/ru/profiles.php` and `lang/en/profiles.php` have exact key parity. Stable usernames/codes and user-created display/biography text are never translated. Interface labels, safe errors, moderation values, report categories and SEO templates are localized. Public navigation wraps without horizontal scrolling, has current-page semantics, keyboard/touch controls, labeled fields, native selects/file inputs, loading disablement, confirmations, status/error announcements and reduced-motion-compatible existing styles.
- Cache and SEO: profiles are queried directly; no second global profile HTML/DTO cache exists, which prevents stale privacy and viewer overlays. `content_version` plus `UserProfileCacheInvalidator` provide targeted versioned invalidation hooks for future safe summaries without an application flush. Public active canonical overview pages may index and emit public-safe `ProfilePage` JSON-LD; localized aliases, tabs/query variants, private/limited/hidden/suspended/deleted profiles and management/media routes are noindex or inaccessible. Sitemap streams only verified, active/public, non-empty canonical profiles and excludes all private/profile-tab URLs.
- Lifecycle and compatibility: export adds username/display name/biography/privacy and non-secret media metadata but never paths, passwords, sessions, providers or moderation notes. Existing deletion service purges media and cascade-removes profile/history/report links while existing comment/review anonymization and collection/domain policies preserve discussion integrity. Registration, auth, settings, player, progress/history, bookmarks, collections, comments/reviews, recommendations, API, importer and localized catalogue routes retain their existing identity and behavior.

### Database, indexes, rollback and protected boundaries

- [x] `user_profiles` is one row per user (user FK primary key), with unique normalized username, stable privacy/moderation codes, private media metadata/version and content version. The public-list composite supports sitemap/admin eligibility.
- [x] `user_profile_username_histories` has one unique historical normalized alias and a user/time index; it preserves redirects without duplicating users or rewriting comments/reviews/collections.
- [x] `user_profile_reports` keeps random public identity, nullable lifecycle FKs, stable target UUID, category/status, private evidence, one unresolved deduplication fingerprint and exact target/queue/reporter indexes. No report content enters public cache or export.
- [x] The backfill is forward-idempotent and its `down()` intentionally retains rows; the schema migration owns table removal. Before accepting profile writes, rollback can drop the additive tables. After rollout, export/restore the new profile data and deploy a forward repair instead of dropping live usernames/privacy/media metadata.
- [x] Protected unchanged boundaries are existing user/public IDs, display names/emails/passwords/verification/roles, sessions/providers/premium/settings, collection slugs/items/visibility, comment/review IDs and moderation, watch status/progress/history/bookmarks/personal tags, catalogue/media identities, API fields, importer command and all existing route names.

### Verification and final acceptance

- [x] Static syntax, focused Pint, route-cache/list, schema/index/duplicate/translation/DTO/privacy/forbidden-Blade inspections completed without creating or invoking automated tests, as required.
- [x] Managed Chromium verified public and authenticated owner pages over the real HTTPS deployment at 1440px and 390px: HTTP 200, zero console/page errors, no horizontal overflow, public canonical/index metadata, owner noindex metadata, two file inputs, biography/privacy fields and long mobile layout. A production-only 500 was traced to stale Laravel route cache; rebuilding the canonical cache removed it without a business-code workaround.
- [x] Public payload inspection confirmed absence of email, provider/session/security/progress/history/private collection/report/moderation fields; current and historical/case username resolution and no duplicate current aliases were inspected against the configured database.
- [x] Final race/failure review moved current-password verification and content-version increments under fresh locks, added deterministic UUID-suffixed registration collision retry, block/mute-aware author links and localized public/admin query-failure states.
- [x] Production Vite build, managed documentation refresh/check, PHP syntax/Pint/Blade compilation, route cache/list, migration/index/FK inspection, RU/EN parity and guest/owner/admin Chromium acceptance pass.
- [x] Reread Task 14, inspect every changed/directly related file and `main`, commit only Task 14 changes and push the configured remote.

Manual acceptance matrix: owner edit/username/password failure/privacy/media/remove states; guest/owner/other/admin public access; both block directions and private mute; private/public/hidden/suspended/deleted/no-content profiles; current/case/history/invalid username URLs; comments/reviews/collections/watch tabs and pagination; spoiler/inaccessible target exclusion; report duplicate/rate/moderation resolution; export/delete; Russian/English labels; mobile/desktop/zoom/long text; canonical/noindex/JSON-LD/sitemap; route cache and private media headers. Unsupported roles/badges/ranks/follows/profile search/favorite genres/location/public activity must remain absent, not simulated.

## Task 15: canonical registration, authentication and session security

### Audit snapshot and integration decision

- [x] The portal has one canonical browser guard (`web`) backed by the Eloquent `User` provider and Laravel sessions, plus Sanctum personal tokens for the mobile API. Breeze, Fortify, Jetstream and Laravel UI are not installed; the existing Livewire pages and typed services are the implementation boundary to harden rather than replace.
- [x] Browser routes are the single guest set `/login`, `/register`, `/forgot-password`, `/reset-password/{token}` plus the signed verification callback and authenticated verification/password-confirm/profile/security routes. The API has one `/api/v1/auth/*` set for register/login/verify/resend/recovery/reset and owner-scoped Sanctum device revocation. No duplicate modal backend, translated auth route set or legacy auth controller was found.
- [x] Registration and login remain email/password only. Task 14 later added a separate public presentation username/history mapped to the same stable user, but it deliberately does not become a credential or registration field; adding username login would require an explicit authentication policy rather than coupling a mutable public alias to the guard. Email lookup is case-insensitive and uses the canonical normalization/privacy-safe limiter boundary.
- [x] Passwords use Laravel's hashed model cast, `Hash` facade or guard provider; reset tokens use Laravel's broker table and hashing. Session login regenerates the session, logout invalidates it and rotates the CSRF token, and `auth.session` invalidates browser authentication after a password-hash change. Password policy rules are currently duplicated across web/API inputs.
- [x] Verification callbacks are temporary signed URLs and compare the email hash before setting `email_verified_at`; resend and recovery have bounded rate limits. The current mail notifications and Livewire validation/status text are Russian literals rather than the existing `ru`/`en` translation catalogs.
- [x] Session configuration is deployment-aware (`Secure` from environment, `HttpOnly=true`, `SameSite=lax`, scoped path/domain) and defaults to Redis. Database-session management is intentionally available only when the database driver is active; safe summaries omit payload, raw session ID, raw user agent and IP. Sanctum device tokens are hashed by Sanctum and owner-scoped.
- [x] No Socialite/OAuth package, configured provider, external-identity table, encrypted provider-token store or callback state exists. No magic-link, MFA, trusted-device, account-status, soft-deleted-user, account-merge mapping or authentication-audit table exists. Adding controls or claiming provider/merge support would create fake behavior; adding Socialite is also a new production dependency requiring separate approval under project rules.
- [x] No anonymous bookmark/progress/watch-status store exists. The only anonymous authentication-adjacent browser state is the versioned Task 16 device preference payload; its allowlisted, idempotent merge fills only unset account preferences and never blocks login. Auth work must not claim migration of data the guest portal never persists.
- [x] Profile name/email and password/delete/export already use `AccountService` and related services. Exact history, progress, collections, comments, reviews, notification preferences, premium-independent account state and moderation data remain attached to the same user ID and are not rewritten by authentication hardening.
- [x] Authenticated account pages are `private, no-store` and noindex; guest authentication pages declare noindex and are absent from sitemap/structured data. No authentication state, token or private intended URL is placed in shared cache.
- [x] Main compatibility risks are email-case duplicates on database engines with case-sensitive uniqueness, raw identifiers in rate-limit keys, duplicated password rules, unsafe future use of arbitrary intended destinations, incomplete locale coverage, and security actions that must continue to require password ownership checks. Schema changes are not required for the safe in-place hardening.

### Canonical contract

- Framework and guards: retain Laravel's `web` guard, Eloquent provider, `users` password broker, signed verification and Sanctum. Browser and API presentations call the same account/authentication services; no manual password algorithm or second user/provider model is introduced.
- Identity: canonical email normalization trims surrounding whitespace, lowercases without provider-specific dot/plus rewriting, rejects invalid input through validation and is reused by registration, login, recovery, reset and uniqueness checks. Stable HMAC fingerprints, never raw identifiers/passwords, partition limiter and security-log context. Login evaluates independent pair (`5/minute`), normalized-identifier (`20/10 minutes`) and network (`60/minute`) buckets across web and API so distributed account attacks and credential spraying cannot bypass a single composite key.
- Passwords: one Laravel `Password::defaults()` policy owns the 12-character letters/mixed-case/numbers/symbols rule; all write boundaries add a 255-character resource ceiling and confirmation where appropriate. Hashing, rehash-on-login, remember-token rotation and broker invalidation remain Laravel-owned. Authenticated password change re-resolves and locks the user row, validates the current password against that fresh hash, then rotates the hash and revokes applicable tokens in the same transaction so a stale concurrent request cannot replace a newer credential. Email change and account deletion apply the same fresh-row verification inside their write transaction, so an already superseded password cannot authorize identity replacement or destruction.
- Registration: a configuration flag can disable both web and API creation safely. The service accepts an explicit name/email/password allowlist, normalizes user text, creates exactly one normal unverified user transactionally, dispatches the canonical registration event and sends a locale-aware verification notification after commit. Role, premium and verification state are never client inputs.
- Login and redirect: the guard performs browser credential checks with optional explicit remember-me and session regeneration. Mobile login performs the necessary Sanctum credential boundary and rehashes a valid password when Laravel requests it. Post-authentication destinations are resolved only from safe internal relative paths or known named-route fallbacks; path authorization uses the twice-decoded security representation so encoded auth routes, malformed percent sequences, repeated slashes and dot segments cannot bypass loop protection. Absolute, protocol-relative, control-character, callback/logout and malformed destinations are discarded.
- Verification/recovery: temporary signed verification and Laravel broker reset tokens remain canonical and idempotent. Recovery and reset use independent HMAC pair/identifier/network buckets in both web and API (`3/3/30` per 10 minutes for requests; `3/10/30` for reset attempts), so rotating IP or recipient cannot bypass email-flooding and spray controls. Recovery responses stay generic, successful reset clears only pair/identifier counters, reset rotates the remember token and revokes mobile tokens, and browser sessions become invalid through Laravel's authenticated-session password hash check. Tokens are never logged, cached, placed in SEO metadata or browser storage.
- Sessions: logout remains CSRF-protected Livewire state change. Database-session revoke/logout-other requires current password and an HMAC action token; Sanctum token actions remain owner-scoped. Refresh re-resolves and locks the persisted current token inside the transaction, revokes it before replacement issuance, and rejects a stale/concurrent replay with the generic authentication boundary so one source token cannot create multiple replacements. Web security UI additionally requires current password before revoking mobile devices, and clears sensitive component state after every outcome.
- Localization/accessibility: every auth page, validation state, notification and loading/confirmation label uses the existing PHP translation catalogs with exact `ru`/`en` parity. Forms retain visible labels, native input semantics, autocomplete, password-manager/paste support, error associations, keyboard submit, touch targets and no-JavaScript page availability.
- Social/MFA/merge capability: providers, linking, unlinking, social recovery, account merge, magic links and MFA remain explicitly unsupported and absent from the UI until their provider configuration, proof-of-control policy, encrypted storage and approved dependencies exist. Matching email alone never links or merges accounts.
- Auditing/privacy: security events use bounded stable event codes and one-way identifier/network fingerprints; passwords, reset/verification/OAuth tokens, cookies, session IDs and raw request bodies are forbidden. Retention follows the existing application log policy and normal administrators receive no secret fields.
- Data/cache/lifecycle: auth mutations never globally cache or flush a user. Existing user ID and all profile/library/progress/collection/comment/review/notification/moderation data are preserved. Export continues excluding hashes/tokens/sessions; deletion continues through the transactional account service, revoking sessions/tokens and preventing later authentication.

### Phased implementation checklist

- [x] Inventory all project Markdown plus auth routes, guards/providers/broker, middleware, cookies, CSRF, rate limits, models/schema/indexes, Livewire/API/services, notifications, localization, cache, export/deletion and absent optional capabilities.
- [x] Record the canonical/unsupported capability decisions, compatibility risks, protected files, rollback notes and manual acceptance matrix in this existing plan before code changes.
- [x] Centralize email normalization, password defaults, private rate-limit fingerprints, security event recording and internal redirect resolution; reuse them from browser and Sanctum flows.
- [x] Localize web auth pages/forms/emails and supported API auth messages in every existing locale; retain accessible loading/error/empty/unavailable states and Russian default behavior.
- [x] Harden registration availability/defaults, password rehash/rotation, email-change confirmation, device/session revocation and sensitive Livewire state without renaming routes or changing user/data identity.
- [x] Final race/failure review maps concurrent duplicate account/email writes to localized validation, makes audit transport best-effort, rejects control/bidi device names and clears registration secrets after every recoverable refusal.
- [x] Run only static/Pint/route/middleware/config/schema/translation/security/cache/accessibility/Vite/browser diagnostics; do not create or invoke automated tests for Task 15.
- [x] Update owner docs, maintenance record and English changelog; reread Task 15, inspect every changed/directly related file, commit only Task 15 paths on `main` and push the configured remote.

### Rollback and protected boundaries

No database migration or destructive reconciliation is planned. Rollback is a code/config/catalog revert: existing users, password hashes, verification dates, reset rows, remember tokens, sessions, Sanctum tokens and all portal-domain rows remain untouched. Existing route names/paths, guard/provider/broker names, cookies, session keys, mobile API fields, public IDs and settings browser-storage keys are protected. Optional provider/merge/MFA functionality stays unavailable rather than partially stored.

### Final manual verification checklist

- [x] One web/Sanctum architecture remains; routes, guard, broker, signed verification, CSRF and noindex/sitemap boundaries are unchanged and inspectable.
- [x] Registration enabled/disabled states, canonical email/name validation, case-insensitive duplicate prevention and unprivileged unverified defaults work without mass assignment.
- [x] One password policy, Laravel hashing/rehash, remember behavior, session regeneration, generic failures and privacy-safe layered rate limits cover browser and API authentication.
- [x] Verification/recovery/reset are localized, signed/broker-owned, expiring, replay-safe and token-free in logs/cache/metadata; password reset/change rotates credentials and invalidates other access according to the documented driver limits.
- [x] Logout, database sessions and Sanctum device actions are CSRF/owner/password protected where applicable, preserve unrelated user data and expose no raw ID/payload/cookie/IP/user-agent/token.
- [x] Internal redirect resolver rejects external, protocol-relative, malformed and auth-loop destinations while preserving a safe intended portal path and locale fallback.
- [x] Provider/link/unlink/merge/magic/MFA controls are absent because their canonical domains are absent; matching email cannot cause takeover or automatic merge.
- [x] Anonymous preference migration remains allowlisted/idempotent/non-blocking; no nonexistent anonymous bookmarks/progress capability is advertised.
- [x] Russian/English keys have exact parity; web/API notifications and forms expose accessible labels, autocomplete, errors, loading states, mobile layout and password-manager-friendly semantics.
- [x] Export/deletion/admin/cache boundaries expose no hash/token/session secret and preserve all existing profile, library, progress, collection, discussion, premium-independent and moderation data.
- [x] No Volt, new `@php`, Blade query, inline CSS/business JavaScript, unsafe GET mutation, fake control, debug/TODO, unused class/import or unrelated behavior remains.
- [x] Allowed diagnostics and browser smoke pass without automated tests; relevant docs/changelog match the implementation; only Task 15 files are committed on existing `main` and pushed.

## Task 16: canonical account settings and preferences

### Audit snapshot and integration decision

- [x] The authenticated account surface is currently split across `/profile`, `/profile/security`, `/profile/discussions`, `/profile/reviews`, `/notifications`, `/library`, `/my/collections` and the password-confirmed `/profile/export`. These routes remain canonical for the domain actions they already own; Task 16 adds one private settings navigation and does not copy their write logic.
- [x] The account has one `users` row plus dedicated comment/review notification-preference rows, database sessions and Sanctum tokens. There is no profile model, username history, avatar/cover/biography store, general preference store, premium/subscription/entitlement store or external-identity/OAuth store.
- [x] Player choice currently uses explicit URL-backed `variant`, `quality` and `format` values and a typed playback resolver. Plyr owns immediate browser state; no account playback row, documented precedence or anonymous-to-account migration exists.
- [x] Interface locale currently comes only from supported `ru`/`en` localized collection routes, while application fallback is Russian and application timezone is UTC. No account locale/timezone or browser-timezone persistence exists.
- [x] Detailed history, progress, watchlist/rating and personal tags are owner-only. Collection visibility already uses stable `private`, `unlisted` and `public` codes with a safe application default of `private`, but no per-account default exists.
- [x] Actual notification categories are database/in-portal comment reply/reaction/moderation/report and review helpful/moderation/report events. Their delivery services already enforce the dedicated rows. Email/push/update/premium notifications do not exist and will not be represented by dead controls.
- [x] Premium and linked-provider controls are intentionally omitted until their canonical domains exist. Password/email, Sanctum devices, database sessions, export and deletion continue through the existing authentication services; no billing or OAuth state is inferred from user-editable data.
- [x] Private routes already require `auth` plus `auth.session`, and their page metadata is noindex. Task 16 adds explicit private/no-store response headers, keeps settings out of sitemap/structured data/social metadata and never accepts a user ID in a settings URL.
- [x] Existing staged Task 10–13/profile/auth work is the integration base and must remain intact. The new migration is additive, one-row-per-user, nullable for explicit-choice detection and cascade-deleted with the account; it neither backfills guesses nor rewrites legacy browser state.

### Canonical contract

- Storage: profile identity stays on `users`; comment/review notification choices stay in their existing dedicated tables; a new one-to-one `user_account_settings` row owns only validated cross-device interface, timezone, playback, accessibility and new-collection defaults; volume/mute also use a versioned device copy for immediate playback behavior.
- Precedence: an explicit title URL remains authoritative for source variant/quality/format; authenticated account preference then supplies cross-device defaults; valid versioned device state supplies immediate volume/mute and anonymous defaults; configuration is the final fallback. Anonymous values only fill null/unset account fields and never overwrite an explicit account choice.
- Validation: locale is limited to the existing supported-locale registry; timezone must be an IANA identifier; booleans remain booleans; volume is clamped server-side to `0..100`; speed and quality use configured allowlists; translation preference uses a canonical available media `variant_key`; collection visibility reuses its enum. Arbitrary preference keys/JSON paths are never accepted.
- Navigation: stable `profile`, `appearance`, `playback`, `privacy`, `notifications`, `collections`, `security` and `data` sections use owner-only routes without user IDs. Each request renders only the active section; normal links provide browser history and localized equivalents preserve the section.
- Profile/security reuse: profile settings link to the existing canonical name/email form. Username/avatar/cover/biography are not fabricated while their storage/security/media services do not exist. Password, email verification, sessions, API devices, export and deletion link to or extend the current security boundary.
- Playback reuse: account quality/variant preferences are passed into the Task 07 resolver only when no explicit URL choice exists. Plyr receives autoplay, remember-volume, safe volume/mute, allowlisted speed, subtitle-enabled, keyboard-shortcut and reduced-motion values; progress/history/bookmarks are never reset with playback settings.
- Privacy: exact history/progress, private tags, blacklist/mute/block state and private collections remain private. The settings page explains these enforced boundaries and exposes only the real default visibility for newly created collections; changing the default never mutates existing collections.
- Notifications: the settings matrix edits the existing comment/review rows through dedicated actions. Only the configured in-portal channel and real event categories appear; critical authentication mail remains mandatory and outside optional social notification controls.
- Sessions: database-session summaries select safe fields only and expose an HMAC-derived opaque action token rather than the raw session ID, user-agent, cookie, payload or exact IP. Revocation verifies current ownership and password; logout-all preserves the current session where the driver permits it.
- Lifecycle: export is the existing password-confirmed private streamed JSON download and gains the non-secret resolved settings summary. Deletion remains the canonical transactional account workflow and receives the new row through its foreign-key cascade; it is never a GET mutation.
- Locale/timezone: authenticated locale applies across normal portal requests unless an explicit supported route locale wins. Anonymous requests preserve a supported temporary session locale before falling back to the configured default. A settings locale change saves first and redirects to the same localized section. Timezone is stored as IANA identity and used by one account formatter for previews/session timestamps without changing media language.
- Caching/SEO: settings are read directly by user identity rather than placed in shared cache. Responses use `private, no-store`, `X-Robots-Tag: noindex, nofollow`, no social metadata/JSON-LD and no sitemap membership. Mutations therefore require no global flush and cannot poison public catalogue caches.
- Browser compatibility: `seasonvar.account-preferences.v1` is the only new app-owned local-storage key. Its name remains browser-internal on guest responses and is not emitted into public catalog HTML. Valid legacy Plyr values can seed it without deleting Plyr storage; migration is idempotent and the anonymous key is cleared only after an acknowledged server merge.

### Phased implementation checklist

- [x] Audit routes, middleware, guards, models, tables/indexes, profile/auth/session/export/delete services, player resolver/JavaScript, history/library/collections, notification delivery, localization, cache/SEO and existing documentation.
- [x] Add the reversible one-to-one settings migration, explicit model/relation, enums/value object, typed DTOs, resolver/update service and account-export integration.
- [x] Add private response and authenticated preference middleware, canonical/localized settings routes, legacy-safe navigation and application locale/accessibility context.
- [x] Implement the sectioned Livewire page with per-section staged save/cancel/reset, complete server validation, translated loading/success/error/empty states and no model/service calls from Blade.
- [x] Integrate account playback defaults with source resolution and the Vite-managed versioned device bridge without changing explicit URL selection, progress/history or unsupported source capabilities.
- [x] Reuse comment/review preference actions, apply the collection default only to newly created user collections, and enhance safe database-session summaries/revocation through the existing security service.
- [x] Update architecture, data, authorization, security, frontend/views, caching, notification, account/auth, development and owner-map documentation as applicable; update this plan continuously and the English changelog.
- [x] Run only static/Pint/route/middleware/schema/translation/cache/security/accessibility/build/browser diagnostics and the manual checklist; do not create or invoke an automated test runner for Task 16.
- [x] Reread Task 16, inspect all changed and directly related files, confirm clean `main`, commit the complete integrated work and push the configured remote.

### Rollback and protected boundaries

Rollback drops only `user_account_settings` after exporting any user choices created after deployment. Existing user/profile/auth credentials, comment/review preferences, sessions, tokens, collections and browser/Plyr storage remain untouched. Code must tolerate absent optional premium/provider/profile capabilities by omitting their controls, not by storing placeholders. Existing route names, localized collection URLs, preference keys, player URLs, cookie/session names and all Task 07–15 data identities remain unchanged.

### Final manual verification checklist

- [x] Owner-only default/localized section routes, browser history, selected section, no user ID, noindex/no-store/no sitemap/social/structured metadata and no shared-cache leakage.
- [x] Locale/fallback and IANA timezone validation, same-section locale redirect, date/time preview and strict separation from source/audio/subtitle language.
- [x] Profile/security links reuse canonical services; no fake username/media/premium/provider/billing control appears.
- [x] Autoplay, remember volume, `0..100` volume, mute, allowlisted speed/quality/variant, subtitles, keyboard shortcuts and reduced motion apply to the real player with documented precedence and safe unavailable-source fallback.
- [x] Playback reset removes no progress, history, watchlist, rating, bookmark, collection, profile, credential or notification data.
- [x] Viewing history/progress/private library remain private; new collection default starts private and never retroactively alters an existing collection.
- [x] Existing comment/review notification services enforce every displayed category; unsupported email/push/update channels remain absent and security mail remains mandatory.
- [x] Database sessions show only safe summaries, use opaque owned revocation identities, preserve current session appropriately and never expose raw IDs/payload/user-agent/IP/cookies.
- [x] Export includes settings but excludes provider/session/token/password secrets; deletion remains strongly confirmed and canonical.
- [x] Anonymous migration is versioned, allowlisted, idempotent and fills only unset fields; device/account/config precedence is consistent and legacy Plyr storage is not destroyed.
- [x] Russian/English keys are complete; controls are keyboard/touch/screen-reader accessible, responsive at phone/zoom widths and have loading/success/error/confirmation states.
- [x] No arbitrary mass assignment/key/category/provider/session value, destructive GET, Volt, new `@php`, Blade query, inline CSS/business JavaScript, debug/TODO/dead control or global cache flush remains.
- [x] Allowed diagnostics and browser smoke pass without automated tests; owner docs, plan and changelog match implementation; final commit is on `main` and pushed.

## Task 19: canonical content requests

### Confirmed audit snapshot and integration decision

- [x] Route, model, migration, service, policy, Livewire, Blade, locale, cache, notification, moderation, importer, API and sitemap inventories contain no dedicated content-request, support-ticket, serial-suggestion or missing-content domain. There are no legacy request routes, tables, votes, followers, histories, merge mappings, comments, imported request rows or cache keys to reconcile.
- [x] Existing comments, reviews, reports and collections are independent product domains with different targets, privacy and moderation contracts. They must not become surrogate tickets or be deleted/renamed; Task 19 is the first and only content-request aggregate.
- [x] Public search already has canonical title normalization, FTS/alias matching and bounded suggestions. Catalog titles, seasons, episodes and licensed media provide stable target IDs; translated display names never become request identity.
- [x] The current portal supports `ru` and `en`, normal and locale-prefixed routes, shared public HTML caching with authenticated/Livewire bypass, database notifications with deterministic UUIDs, owner-only account settings/export/deletion, gated administration and a single Seasonvar importer command.
- [x] The importer stores allowlisted `https://seasonvar.ru/` source pages and can refresh an existing title. A request handoff may create/update an approved source-page reference or schedule the existing targeted refresh, but normal users may never execute an import or see commands, raw importer failures or fictitious progress.
- [x] Current request-like abuse controls are domain-local. Content requests therefore need their own bounded creation/edit/vote/follow/clarification limiters and plain-text/link validation without invasive fingerprinting or a mandatory queue.
- [x] Request pages are absent from the current sitemap/SEO contract. Canonical public request URLs will use an opaque stable UUID; merged UUIDs redirect to the canonical request, private/moderation/filter pages remain noindex, and user prose is never presented as translated content.

### Canonical domain contract

- Identity: database ID is internal and UUID `public_id` is the stable route identity. Mutable title, locale, requester name, type, status, priority, votes and slug do not participate in route identity. Exact active uniqueness is represented by an indexed identity hash built from the stable target/type dimensions; fuzzy title similarity only produces bounded candidates.
- Types: stable codes are `serial`, `season`, `episode`, `translation`, `subtitles`, `quality_upgrade`, `metadata_correction`, `episode_list_correction`, `broken_content_restoration` and `other_content_request`. A server-side type rules service owns required/optional target, season/episode, language, translation, quality, correction and evidence fields.
- Targets: season/episode/translation/subtitle/quality/correction/restoration requests use canonical catalog IDs when the target exists. Missing serial identity uses normalized original/alternative title, year and allowlisted external identifiers. Season and episode numbers are canonical numbers, never database IDs or translated labels.
- Existing-content and duplicates: one service reuses catalog search normalization and bounded database candidates to return exact/probable/related/none. Exact existing content or an exact active request prevents creation and points to the canonical content/request; probable matches are shown and require a distinct explanation, never an automatic merge.
- Creation: authenticated, verified, unrestricted users submit one typed input to one transactional action. The server assigns requester, `submitted` status and normal priority, stores initial history, creates one idempotent requester vote/follow, validates up to the configured link/provider limits, invalidates scoped caches and emits deterministic notifications after commit.
- Statuses: stable codes are `submitted`, `pending_review`, `clarification_needed`, `approved`, `planned`, `in_progress`, `partially_completed`, `completed`, `rejected`, `duplicate`, `merged`, `cancelled` and `withdrawn`. One transition service validates the matrix, authorization and optimistic version, appends public-safe history, separates private notes, links verified results and deduplicates notifications.
- Editing/withdrawal: requester edits are limited to evidence, alternative title, explanation and language preferences in submitted/review/clarification states. Withdrawal keeps immutable history; a community-supported request remains open and becomes anonymized instead of being destroyed.
- Engagement: one unique user/request vote and follow row supports idempotent POST/Livewire add/remove. Totals are grouped query aggregates, identities remain private, terminal states retain historical totals but reject new engagement, and viewer overlays never enter shared cache.
- Moderation: `manage-content-requests` gates search/filter/inspect, clarification, approval, rejection reason, merge, priority, planning, processing, partial/full completion, result linking, private notes and importer handoff. Merge upserts votes/follows/evidence/external IDs without duplication, preserves both histories and makes the old route redirect.
- Clarification: the aggregate has a restricted requester/moderator plain-text thread, not a public discussion system. A moderator question moves to clarification; an authorized requester reply moves back to review and preserves history. Private moderator notes never enter it.
- Notifications: database-only delivery follows dedicated request preferences for requester, voter and follower updates. Deterministic `(request revision, recipient, category)` UUIDs prevent retries, merge and repeated callbacks from duplicating notices; actor self-notifications and hidden recipients are suppressed.
- Import handoff: an authorized moderator may pass only validated canonical request data to the existing Seasonvar source-page/targeted-refresh boundaries. The request stores the resulting existing source-page/import-run reference, shows only truthful public statuses and completes only after a moderator verifies visible canonical content.
- Privacy/lifecycle: public DTOs omit email, internal user ID, private links/notes, raw errors and importer state. Account export includes the owner’s requests/votes/follows/clarifications only; deletion removes private engagement/preferences and anonymizes community-valued requests. Title merge retargets requests and completion links without changing request identity.
- Cache/SEO: a dedicated cache version scopes public directory/detail/search/count data by locale/filter/sort/page/version. Votes, follows, permissions, drafts, clarification and moderation data are viewer/private overlays. Mutations bump only request/detail/sitemap dependencies. Public eligible canonical pages may be indexed; merged/rejected-thin/withdrawn/private/admin/filter state is noindex and excluded from sitemap.
- Interface: page-level Livewire components keep only locked IDs and validated URL/form state, use deterministic pagination and debounced bounded autocomplete, and pass prepared DTOs to passive Blade. All visible and ARIA text has `ru`/`en` parity; responsive controls reuse project components, 44px targets, visible focus, live regions and reduced-motion behavior.

### Risks, dependencies and rollback

- Security risks: arbitrary target/provider/status/priority IDs, mass assignment, unsafe schemes, SSRF, stored/reflected XSS, IDOR, duplicate mutations, spam and cache leakage are rejected at policy/value-object/action boundaries. User URLs are stored for review and never fetched automatically; only the existing Seasonvar allowlist may enter the importer.
- Privacy risks: requester email, voter/follower identity, clarification, private evidence and moderator/importer notes are never selected for public presenters. Authenticated/private responses remain no-store.
- Performance risks: directory/detail queries must use grouped counts/eager target loads and bounded duplicate candidates; exact identity hashes and composite target/status indexes avoid scanning all requests in PHP. Public and viewer queries stay separate.
- Compatibility risks: no existing request data exists, so migrations are additive with nullable foreign keys and reversible tables. Existing catalog, comments, reviews, reports, notifications, API fields, importer command, route bindings, locales and cache keys remain semantically unchanged.
- Known limitations: anonymous submissions remain unavailable because the portal had no verified anonymous request workflow; creation, engagement and clarification therefore require an authenticated verified account. The current media schema has title-level translation labels and a subtitle-presence flag, but no canonical per-language audio/subtitle-track aggregate, so language-specific translation/subtitle requests are not rejected from coarse availability alone and require moderator verification before completion.
- Known limitations: arbitrary provider lookup, user-link scraping, public discussions, attachments, bulk moderation, public import errors, queue position, percentage progress and completion estimates are deliberately absent. Evidence URLs and allowlisted provider IDs are stored for moderator review without fetching; clarification is requester/moderator-only; importer integration records only a real existing source-page/import-run reference; the existing read-only mobile API is unchanged.
- Rollback drops only the new request tables after exporting post-deployment request data. It cannot reverse notifications already delivered or source pages intentionally handed to the existing importer; those references remain valid importer data.

Expected changes: additive content-request migrations; request enums/models/policy/DTOs/value objects/actions/query/search/duplicate/existence/cache/notification/import/lifecycle services; public/private/admin Livewire pages and views; routes/navigation/title links/settings preferences/account export/delete/title merge; `ru`/`en` catalogs; scoped cache/SEO/sitemap integration; topic-owner documentation and English changelog.

Protected boundaries: existing request-independent tables and identities, all catalog/title/season/episode/media IDs and route binding, public/mobile API contract, comment/review/report/collection/tag domains, personal library/playback/progress, notification preference keys, importer command/parser workflow, locale codes, existing sitemap/feed URLs and cache keys.

### Phased implementation checklist

- [x] Review the complete tracked Markdown inventory and fully audit every request-adjacent route, schema, model, relationship, policy/gate, service/action/query/DTO, Livewire/Blade/JS asset, locale, cache, search, notification, moderation, account lifecycle, importer, API and SEO boundary.
- [x] Add reversible schema, enums, typed models/relations, stable identity, exact indexes/uniqueness and rolling-schema guard without touching legacy tables destructively.
- [x] Implement type rules, URL/provider/language/quality validation, catalog existence lookup, bounded duplicate search, transactional idempotent creation and anti-spam/rate limits.
- [x] Implement policy-enforced edit/withdraw, idempotent voting/following, centralized transitions/history, clarification, rejection, priority, merge and partial/full completion.
- [x] Integrate deterministic preference-aware notifications, account export/deletion, title merge and the existing Seasonvar importer handoff without a second importer or mandatory queue.
- [x] Implement public directory/detail/create, private My Requests and gated administration with validated URL state, deterministic pagination, prepared presenters, localized accessible responsive UI and truthful states.
- [x] Integrate navigation/title entry points, scoped public cache invalidation, canonical/noindex/hreflang/structured-data policy and the existing streamed sitemap responder.
- [x] Update all topic-owner Markdown, owner map, maintenance log and English changelog; record known limitations and complete manual acceptance evidence.
- [x] Run only allowed Pint/static syntax/routes/schema/query/index/authorization/translation/cache/SEO/Vite/browser/accessibility diagnostics; do not create or run automated tests for Task 19.
- [x] Reread Task 19 and changed/directly-related files, inspect final diff/status on `main`, commit only Task 19 changes without absorbing pre-existing work and push the configured remote.

Manual evidence: every Task 19 PHP file passed syntax inspection and focused Pint formatting; the additive migration executed against a disposable SQLite database and passed foreign-key and indexed-query-plan inspection; route, authorization, transition, privacy, cache, SEO/sitemap, translation parity and forbidden-template-pattern inspections passed. Vite production build passed. HTTP-kernel and managed-Chromium smoke covered default/localized directory and detail routes, merged redirect, guest administration denial, private-field absence, desktop/phone/landscape/tablet/200% zoom, long Unicode titles and long evidence URLs without console, asset, focus-label, touch-target or horizontal-overflow failures. No automated test was created or invoked, as required; the production database was not migrated during verification.

Production activation follow-up (16.07.2026): the first rollout attempt stopped before backup/DDL because legacy sync run #875 continued real writes after graceful signals; no Task 19 production table was created. The discovered importer defect is fixed at the canonical command/pipeline boundary with Laravel-native signals, page-level checkpoints, truthful selected counters, stop-phase events and `cancelled` terminal state. The already running old PID cannot load that code in place. Remaining rollout checklist: `[ ]` wait for both legacy runs to become terminal and confirm no live claims/writers; `[ ]` take and verify a consistent SQLite backup; `[ ]` apply only migration `2026_07_16_180000_create_content_request_domain.php`; `[ ]` inspect eight tables/FKs/indexes and rebuild stale catalog FTS; `[ ]` rebuild production config/routes/events/views/assets with debug disabled, reload PHP-FPM/workers and repeat preflight/health/request-route smoke. Do not use `SIGKILL`, a database-only status edit or claim release as a substitute for a writer-free boundary.

### Final manual acceptance checklist

- [x] Stable opaque identity, canonical target/type/language/quality dimensions, exact active uniqueness and probable/related bounded matching work without translated labels or fuzzy-only blocking.
- [x] Every supported request type validates its real fields; existing serial/season/episode/media matches prevent misleading missing-content creation and provide canonical links.
- [x] Source/external IDs are allowlisted and safe; no video upload, arbitrary scraping, internal-network fetch, dangerous scheme, raw HTML or client-assigned status/priority is possible.
- [x] Creation/edit/withdraw/vote/follow/clarify and all moderation mutations are authenticated as required, authorized, rate-limited, idempotent, non-GET and leave append-only history.
- [x] Status transition, rejection, merge, partial/full completion and importer handoff preserve evidence, links, votes/followers and private notes while emitting one preference-aware notification per real revision.
- [x] Public directory/detail/search/filter/sort/count/pagination and My Requests/admin queues are deterministic, scoped, privacy-safe, noindex where required and free of target/requester/count/viewer N+1 queries.
- [x] Public cache excludes viewer/private state and invalidates after each mutation; canonical/merged URLs, locale alternates and sitemap eligibility follow the documented SEO policy without guaranteed-availability schemas.
- [x] Account export/deletion, user/title merges, catalogue/search/title/player/library/profile/settings/recommendations/comments/reviews/tags/import/API routes and existing cache keys remain compatible.
- [x] All `ru`/`en` visible/ARIA/loading/empty/error/confirmation text resolves; phone/landscape/tablet/desktop/zoom, keyboard/focus/touch/reduced-motion and long title/URL layouts pass inspection.
- [x] No Volt, new `@php`, Blade query, inline CSS/business JavaScript, fake control/progress, TODO/debug output, unused class/import, duplicate request architecture or automated Task 19 test remains.
- [x] Allowed diagnostics and browser smoke pass; owner docs/plan/changelog match implementation; the focused commit is on existing `main` and pushed.

## Task 20: canonical private technical issues and support workflow

### Confirmed audit snapshot and domain boundaries

- [x] The complete tracked Markdown inventory, topic-owner map and current Task 19 implementation have been read before implementation. No technical-ticket, playback-error, support-ticket, diagnostic, ticket-message, ticket-attachment, ticket-history, assignment, confirmation, follower or merge table/route/model exists in the deployed schema.
- [x] Existing `comment_reports`, `catalog_title_review_reports` and `catalog_collection_reports` are private moderation complaints about user-generated content. They retain their current tables, policies, routes and queues and are not technical tickets.
- [x] Task 19 `content_requests` is a separate public missing/new-content and correction workflow with public UUID pages, votes and importer handoff. Task 20 defects remain private and never enter the content-request public cache, sitemap or directory. A support reroute records guidance/linkage without silently copying diagnostics or attachments.
- [x] Task 20 `missing_episode` is limited to an already represented catalog episode that disappeared from an existing season list/mapping; a genuinely new episode request remains Task 19. The defect uses a canonical existing title/season target and never invents an absent episode ID.
- [x] Current web/API/localized route inventories contain no legacy issue/support aliases to migrate. Existing report links are moderation-only. Task 20 therefore adds the first and only technical-issue aggregate while preserving all current route names and identities.
- [x] Catalog titles, seasons, episodes and `licensed_media` provide stable server-side targets. Player media URLs/provider credentials remain private. The report URL carries only an encrypted, expiring context envelope and all IDs/relationships are resolved again server-side.
- [x] There are no normalized audio-track or subtitle-track records. Audio/subtitle defects target a validated media/episode and store allowlisted language/translation/quality context; no arbitrary file path, translated track label or fictitious track entity is accepted. Title-level `translations` may be linked only after relationship validation.
- [x] There is no normalized subscription/territory or release-calendar entity. Premium, regional, account, notification and calendar issue types are supported as private feature/page issues using server-derived capability/context only; client claims never become entitlement, region or calendar truth.
- [x] `licensed_media.health_status` and `MediaSourceHealthManager` are the canonical automated source-health boundary (`active`, `degraded`, `unavailable`, `disabled`). One user ticket never changes or disables a source; only an authorized staff action may link an under-review/disable/restore decision to a ticket.
- [x] Existing private raster upload storage accepts PNG/JPEG/WebP outside the public web root, and Livewire cleans abandoned temporary uploads after 24 hours. Technical screenshots add actual-content verification, pixel/dimension limits and image re-encoding; no SVG, archive, HTML, executable or arbitrary log upload is accepted. No fake antivirus capability is claimed.
- [x] Database notifications already use deterministic UUIDs and user preferences. Technical-ticket notification payloads reuse that channel with stable codes and contain no user prose, attachment, diagnostics, raw user-agent, IP, source URL, token or private support note.
- [x] Existing private middleware supplies `private, no-store` and `X-Robots-Tag: noindex, nofollow`; sitemap generation is allowlisted and streamed. My Tickets, details, support queue and attachment responses remain authenticated/noindex/no-store and are never added to the shared public page cache or structured data.
- [x] Current session storage contains IP/raw user-agent for the authentication security boundary only. Task 20 does not copy them. Diagnostics store only an allowlisted parsed summary; headers, cookies, session/CSRF/reset/verification/OAuth/provider tokens, storage contents, precise location, payment data, protected media URLs and arbitrary request/query data are prohibited.
- [x] Final Livewire/query inspection found two requester-leak risks in the initial implementation draft: participant hydration populated report/admin properties, and requester card counts included internal messages/attachments. Both were removed at the server query/state boundary; participants now hydrate no requester prose or staff assignment/classification, and requester aggregates count only requester-visible records.
- [x] Final exact-duplicate attachment inspection found that a confirmer screenshot could inherit the canonical requester's ordinary attachment visibility. Root screenshots are now selected and authorized by uploader ownership (or the support gate), while requester-visible message attachments keep their separate conversation visibility; merge and duplicate participation cannot expose one reporter's evidence to another.
- [x] Final attachment resource inspection added original-extension validation and deterministic GD image/output-buffer release around JPEG/PNG/WebP re-encoding; malformed or failing encoders cannot retain a large decoded raster or leave a response buffer open.
- [x] Final recoverable-form inspection found that the first session draft could retain pre-sanitized prose. Draft persistence now runs the canonical text sanitizer first, replaces redacted spans in Livewire public state and accepts only allowlisted language/quality codes, so raw credentials/private URLs cannot survive hydration while non-sensitive original-language input remains recoverable.
- [x] Final public-error-code inspection confirmed that the current portal does not emit a canonical client code. The free-text intake was removed instead of accepting a token-like arbitrary value; the nullable database field remains reserved for a future trusted server context, and quality input is constrained to real resolution codes.
- [x] Final persistence-language inspection found a translated screenshot display name in the initial attachment row. Storage now keeps stable `screenshot-N.ext`; presenters and account export localize it at the viewer boundary, so locale changes cannot mutate persisted attachment identity.
- [x] Final SQLite query-plan inspection found that the status-prefixed requester index narrowed ownership but still required a temporary sort for the default recently-updated directory. A separate reversible `(requester_id, updated_at, id)` index now covers that exact deterministic pagination query, while the original status-prefixed index remains for filtered lists; the schema inventory is 13 tables and 39 justified indexes.
- [x] Final account-support route inspection found that verified-only creation prevented the exact `verification email not received` defect from being reported. Ticket creation is now authenticated-only while confirmation/following of another incident remains verified-only; anonymous intake is still unavailable and every ticket remains private, owner-scoped and rate-limited.

### Canonical ticket, issue-type and target contract

- Identity: the database primary key is internal. A UUID `public_id` is the canonical private route key and an independently random, unique `ISS-...` number is the stable support reference. Neither is an authorization token; policy ownership/staff capability is checked for every view and mutation. Mutable title, locale, summary, status and assignee never participate in identity.
- Visibility: all technical tickets, messages, diagnostics and attachments are private. The requester sees their submission and requester-visible timeline/messages. Confirmers/followers who are not the requester see only a prepared canonical status summary. Staff-only notes, diagnostics, priority reasoning, source detail and other users' evidence never enter requester DTOs or Livewire state.
- Types: one enum/registry owns stable codes for unavailable/loading/stopping/buffering/wrong video; wrong/missing/duplicate/order/number episode/season; audio/language/sync/studio; subtitle missing/language/sync/text; quality/label; fullscreen/autoplay/player controls; progress/continue watching; page/Livewire/link/image/search/filter/notification/calendar/account/region/premium/accessibility/performance and `other_technical_issue`.
- Rules: every type registry entry defines eligible target types, required and optional fields, diagnostics, duplicate dimensions, conservative default severity, support team, privacy class, allowed resolutions and `ru`/`en` title/help/accessibility keys. Irrelevant fields are not rendered or accepted.
- Targets: one allowlisted enum resolves `title`, `season`, `episode`, `media`, `translation`, `page`, `account`, `notification`, `calendar`, `search` and `general`. Explicit nullable foreign keys replace arbitrary polymorphic class input. Resolver checks title visibility and every season/episode/media/translation ownership chain in one server-side query path.
- Page context: only allowlisted feature code, known route name, locale and a sanitized relative path are stored. Query strings, search text, signed/private/reset/verification/OAuth URLs and unknown route parameters are discarded.
- Player context: a server-created encrypted expiring envelope may prefill title/season/episode/media, selected variant/quality, translation/subtitle language, player component code, route and approximate numeric position. It never contains playback/source URLs, storage paths, credentials, cookies, session IDs, bearer/CSRF/signed tokens or DRM/provider internals. The create action revalidates every relation and clamps position to known duration.
- User text remains plain Unicode in its original language. Summary, expected/actual behavior, reproduction steps, clarification, reply, resolution feedback and reopen reason have separate limits, preserve line breaks, reject control/bidi abuse and execute/linkify no HTML or URL. Interface labels alone follow the active locale.

### Persistence, privacy, security and performance plan

- Add reversible tables for `technical_issues`, typed diagnostics, private screenshot attachments, requester-visible/internal messages, append-only status histories, assignment history, confirmations, followers, merge mappings, redaction audit and source-health action linkage. Add user notification preferences without altering Task 19/moderation preference tables.
- Use additive nullable catalog/media/translation foreign keys with restrictive/nulling deletion behavior appropriate to evidence retention. Account deletion anonymizes requester/author relationships while preserving operational history; account merge has an idempotent service hook that deduplicates confirmations/follows and moves owned evidence safely.
- Exact query indexes support public number, requester/status/order, target/type/status, media/type/status, assignee/status/priority/order, messages/history by ticket/time and unique user confirmation/follow, submission token and duplicate mapping. Candidate text comparison is bounded after indexed narrowing and never scans all tickets in PHP.
- Creation is authenticated because no legacy anonymous technical workflow exists. Final account-support inspection removed the email-verification barrier so a logged-in user can report failed verification/reset notification delivery; manual confirm/follow of another incident remains verified-only. An exact duplicate instead stays inside the create limiter and identity lock, records one occurrence and participant/follow relationship, and redirects to the private canonical ticket without creating a second row. One transactional action validates account, type/rules, target ownership chain, text, optional diagnostics/attachments, rate limit/idempotency and duplicates; assigns requester/submitted/default severity/normal priority; appends history; follows requester; and dispatches deterministic notifications after commit.
- Diagnostics use explicit columns/codes, not arbitrary JSON/request graphs. Optional browser diagnostics require an unselected consent control. The server parses/allowlists browser family/major, OS family, device category, viewport, locale/timezone, online state, safe application error code and playback context; it never retains raw user-agent or IP.
- Secret sanitization detects common credential/token/cookie/private-link/contact patterns conservatively before persistence, replaces detected spans with a stable redaction marker and records only field/reason/before-after hashes for audit. Staff redaction repeats this process without retaining the removed secret plaintext. No automatic URL fetch exists, so diagnostic/attachment input cannot create SSRF.
- Screenshots are PNG/JPEG/WebP only, MIME and decoded raster must agree, dimensions/pixels/bytes are bounded, GD re-encodes to a generated UUID filename on the private disk and strips original metadata/name. Authorized controller streaming uses safe type/disposition plus no-store, nosniff, noindex and restrictive CSP headers. Root screenshots require uploader ownership or support authorization; requester-visible message attachments use conversation visibility. Duplicate participation and merge never grant access to another reporter's evidence.
- Private issue pages are never globally cached. Query services separate base rows, counts and viewer overlay; use eager selected relations/`withCount`/existence queries and deterministic secondary ordering. Support lists never load message bodies, attachment bytes or raw diagnostics. Mutations rely on authoritative DB reads; catalog cache invalidation occurs only for an actual authorized source/content health change.

### Workflow contract

- Status codes: `submitted`, `triage_pending`, `clarification_needed`, `confirmed`, `assigned`, `in_progress`, `waiting_for_external_source`, `waiting_for_requester`, `resolved`, `resolution_verified`, `closed`, `reopened`, `rejected`, `merged` and `withdrawn`. Duplicate is a stable resolution/merge reason rather than an unreachable placeholder status. One transition service owns the matrix, policy, revision, timestamps, idempotency, public reason/private note split, history and notifications.
- Requester edits are limited to evidence fields in submitted/triage/clarification/waiting-for-requester/reopened states. Target/type cannot be changed after creation. Withdrawal is non-destructive; an issue with other confirmations remains the canonical incident and requester ownership can be anonymized rather than deleting shared evidence.
- Severity (`low`, `medium`, `high`, `critical`) expresses impact. Priority (`low`, `normal`, `high`, `urgent`) expresses processing order. Users choose neither. Conservative type defaults never make a self-described outage critical; authorized support can correct both with history.
- Duplicate service returns exact/probable/related/none based on type, target/media/translation, active statuses, timestamp bucket, route/error/language/quality/browser dimensions and then bounded normalized evidence. Exact active duplicate creation becomes an idempotent confirmation/follow of the canonical issue, and uploader/content-hash screenshot retries remain one private attachment; probable candidates are shown but never auto-merged.
- One unique user/ticket confirmation and follow record supports idempotent add/remove by non-GET actions. Identities stay private, counts do not automatically set severity/priority, preferences control updates and merge upserts engagement without duplication.
- Assignment is staff-only, validates an active configured administrator and stable support-team code, preserves assignment history and sends only internal routing notification. Triage/confirmed/reopened to assigned and assigned back to confirmed on explicit unassignment use the same transition matrix; a same-assignee retry can complete the pending status transition without duplicating assignment history. Public requester updates never disclose staff identity unless a safe message is deliberately sent.
- Clarification and support replies share the ticket message aggregate with explicit visibility. Requester replies can move waiting tickets back to triage/in-progress; internal notes are selected only for authorized support and are excluded from requester DTOs, notifications, caches and exports.
- Resolution codes are stable (`fixed`, `source_replaced`, `fallback_enabled`, `metadata_corrected`, `episode_mapping_corrected`, `subtitle_corrected`, `audio_corrected`, `configuration_corrected`, `user_setting_guidance`, `cannot_reproduce`, `duplicate`, `external_provider_issue`, `unsupported_environment`, `intended_behavior`, `rejected`). Resolution stores a public-safe summary and separate private note/history.
- Requester verification can mark fixed and move resolved to verified, or still broken and reopen with a bounded reason. Reopening is available only through the dedicated policy/rate-limited action, never the generic staff status selector; it increments a counter, preserves prior resolution/history and notifies the queue. No scheduler-based or elapsed-time fake verification/closure is introduced.
- Merge first requires the complete allowlisted target identity to match (type/target/title/season/episode/media/translation/feature/route), so same-type reports for different episodes or sources within one title remain separate related incidents. It then keeps each duplicate ticket and its requester-owned evidence private, upserts confirmations/follows, records a canonical mapping, moves the duplicate to merged and makes its authorized route present a safe canonical link. Messages/attachments remain on the original ticket; no cross-requester evidence disclosure occurs.
- Source health changes require the support gate, locked media row and explicit action/reason. Ticket creation/confirmation only aggregates evidence. Disable/restore uses the existing health enum/manager contract, preserves source data/fallbacks, links history and passes the affected title to the existing catalog invalidator so dependent public generations and TitleDetail advance without a store-wide flush.
- Reroute/rejection uses stable reason/destination codes and safe guidance. Content request, moderation, account-security or future rights-holder destinations are not conflated. Automatic conversion is performed only when a destination action and permission can preserve privacy; otherwise linkage/guidance is truthful and diagnostics/attachments are not copied.

### Interface, routes, notifications and lifecycle

- Canonical authenticated routes provide create, My Technical Tickets, UUID detail and authorized private attachment download; localized aliases redirect/preserve locale consistently. Gated administration reuses the existing Livewire admin architecture. Mutations remain Livewire/POST, never GET.
- Entry links on player/title/season/episode, catalogue/search, account settings, notification inbox, release surface and general support all feed the same creation component/action with a prepared context. Page/browser/mobile/accessibility/performance/link types accept server-resolved catalogue and account/notification/calendar/search targets as well as generic page context, so each portal surface retains canonical feature/content identity and private targets keep requester-scoped duplicate matching. Unsupported targets are omitted rather than rendered as fake controls.
- Class-based Livewire components expose locked scalar IDs, allowlisted URL filter/sort/tab state and bounded form strings only. Eloquent graphs, diagnostic arrays, source URLs and attachment bytes are never public properties. Loading/disabled/live-region/focus/error/duplicate/empty/terminal states are explicit; no polling or Volt is introduced.
- A Vite-managed module only contributes optional safe diagnostics, screenshot preview/removal, player time/context and focus/live announcements. Server validation remains authoritative. No inline ticket JavaScript/CSS or Blade business/query logic is added.
- My Tickets is requester-scoped with open/waiting/in-progress/resolved/closed/followed filters, validated search/type/status/target/sort and deterministic pagination. Detail presenters split requester, limited follower and staff views. Support queue adds bounded authorized filters/counts/assignment/duplicate/attachment/age indicators without N+1 queries.
- Notifications use stable ticket/revision/recipient/category/channel identity and preference categories for submitted, clarification, reply, work/status, resolved/verified, closed/reopened/rejected/merged and assignment. Payload contains only public number, stable type/status codes and private route identity; no prose excerpt, diagnostics, attachment/source URL, recipient list or internal note.
- Retention is documented: operational tickets/history are retained; optional diagnostics and attachments have a bounded manual/normal-maintenance cleanup policy without inventing mandatory cron. Livewire removes abandoned temporary uploads after 24 hours. Cleanup never deletes active evidence or merge-owned requester attachments.
- Account export includes only the owner's tickets, requester-visible messages/history, their engagement and protected attachment manifest; no staff note/assignment/source detail/other-user diagnostic. Deletion removes preferences/engagement, anonymizes author/requester and revokes attachment access while preserving necessary history/evidence.
- Russian and English translation files have exact key parity for every visible label, validation/loading/empty/error/confirmation/ARIA/status/type/severity/priority/resolution code. Long translations and original user prose remain mobile/zoom safe. No raw key or translated internal database value is stored.
- Every private/support/attachment route is authenticated as appropriate, noindex/nofollow/no-store, excluded from sitemap/structured data/Open Graph and absent from public cache. No public known-issues directory is added because the current product has none and private ticket creation must not publish an incident.

### Phased implementation checklist

- [x] Read all Markdown and audit route/schema/model/report/request/player/progress/account/notification/moderation/import/source-health/storage/cache/localization/SEO/admin boundaries; record absence of legacy technical data and discovered capability limits.
- [x] Add reversible schema, enums, models/relations, registry/resolver/value objects, stable identity and justified indexes/uniqueness with a rolling-schema guard.
- [x] Implement canonical creation, diagnostics/redaction, screenshot processing/storage, bounded duplicate/idempotency/anti-spam and issue-specific validation.
- [x] Implement policy, requester edit/withdraw, confirmation/follow, messages/clarification/internal notes, transitions/history, assignment/severity/priority, resolution/verification/reopen/reject/merge/reroute and source-health linkage.
- [x] Integrate deterministic preferences/notifications, account export/delete/merge and title/season/episode/media retargeting without changing Task 19/moderation contracts.
- [x] Implement create/My Tickets/detail/admin Livewire routes/views/JS and real player/title/search/account/notification/support entry points; unsupported release-calendar surface is documented rather than represented by a fake control.
- [x] Add `ru`/`en` parity, private noindex/no-store/canonical/sitemap/cache boundaries and update every topic-owner document, maintenance log and English changelog.
- [x] Run no automated tests; perform Pint, PHP/static syntax, fresh route, migration-on-disposable-SQLite/schema/index/query/policy/translation/cache/SEO/Vite/browser/accessibility and full manual acceptance inspection.
- [x] Reread Task 20 and changed/directly-related files, commit the reviewed Task 01/20 overlap as `7753482` on existing `main` and verify the configured remote at the same SHA; unrelated concurrent working-tree changes were excluded.

### Rollback, protected files and risks

Rollback drops only new technical-issue tables after exporting post-deployment ticket evidence and removes new private routes/UI. It cannot retract already delivered database notifications or reverse an explicit staff source-health decision; the existing media record remains canonical and must be restored through its normal action before rollback when needed. No legacy tickets exist to backfill or delete.

Expected changes: additive technical-issue table and requester-order index migrations; enums/models/policy/DTOs/actions/services/notification/account/merge/source-health integration; private controllers/Livewire/Blade/Vite; gate/limiters/routes/navigation/player/search/settings/notification integration; `lang/{ru,en}` catalogs; data/architecture/authorization/security/validation/forms/views/frontend/caching/performance/notifications/administration/API/SEO/deployment owner documentation, maintenance log, this plan and `CHANGELOG.md`.

Protected boundaries: all Task 19 `content_requests`, moderation report tables/routes/actions, catalog/title/season/episode/media IDs and playback grants/URLs, progress/history/library, auth/session/token credentials, notification preference keys outside this domain, importer public command/parser, API contracts, public cache keys, sitemap/feed URLs and every existing translated internal code.

### Final manual acceptance checklist

- [x] One private canonical aggregate, random stable route/reference identity, complete stable type/rule registry and allowlisted target resolver exist; no technical defect is stored as a content request or moderation report.
- [x] Creation and every mutation are authenticated, rate-limited, idempotent and authorized; manual third-party confirm/follow additionally require verification, while unverified account/notification intake remains possible. Client cannot choose status/severity/priority/assignee/resolution or arbitrary type/target/source/track/attachment/merge identity.
- [x] Player/page context contains no protected URL/credential/token/session/header/cookie/storage/IP/raw-UA data; timestamp and every catalog/media relationship are server-validated and reporting never changes progress.
- [x] Diagnostics are consent-aware and column-allowlisted; secret redaction, raster content validation/re-encoding/private serving and abandoned-upload cleanup are real and truthful, with no SSRF/executable/SVG/public attachment path.
- [x] Exact/probable/related matching is indexed and bounded; exact duplicate confirmation/follow, unique engagement, requester privacy and merge behavior work without exposing another user's prose/messages/diagnostics/attachments.
- [x] Transition/history, severity versus priority, assignment, clarification/public reply/internal note, resolution/verification/reopen/reject/reroute and staff-only source health all enforce their documented rules and preserve evidence.
- [x] Requester/follower/support presenters, search/filter/sort/count/pagination and notification recipients/payloads are efficient, deterministic and viewer-scoped with no N+1/raw diagnostic/private cache leakage.
- [x] Account export/deletion/merge, title merge, notifications, player, search/filter/title/season/episode, Task 19, moderation, importer, admin, API, localized routes and public caches retain compatibility.
- [x] Every visible/ARIA/loading/empty/error/confirmation string resolves in `ru` and `en`; phone/landscape/tablet/desktop/zoom, keyboard/focus/touch/reduced-motion and long text/attachment/timeline layouts pass inspection.
- [x] Private ticket/admin/attachment pages are noindex/no-store, absent from sitemap/structured/social metadata and shared cache; no public known-issue page, fake ETA, fake scanner, fake queue position or unsupported capability appears.
- [x] No Volt, new `@php`, Blade query, inline CSS/business JavaScript, TODO/debug/dead control, destructive migration, duplicate architecture or Task 20 automated test remains; all required docs/checklists/changelog match the committed code.

## Deferred product decisions, not hidden defects

- Collection collaborators, smart criteria, collection-level likes and follows require a future reusable product model; Task 12 now supplies the shared collection discussion/reaction/report boundary, so Task 10 embeds it without a second comment architecture.
- Rights territories/subscriptions/profiles/PIN/DRM require legal/product specifications.
- Upload/transcode/object storage/CDN video pipeline is outside the current external-authorized-source architecture.
- Real-time broadcasting, Pennant rollout and dark mode require a demonstrated product need.
- Database engine migration requires post-stabilization SQLite SLA evidence.

## Phase update rule

After every phase: review diff, remove unrelated changes, run appropriate focused then broad gates, update this file’s checkboxes/evidence, update topic owner and changelog, create a focused `main` commit, and immediately add any newly discovered regression to the earliest applicable unfinished phase. A failing gate is work to diagnose, not text to explain away.
