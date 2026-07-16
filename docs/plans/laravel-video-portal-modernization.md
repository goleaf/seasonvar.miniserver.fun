Warning: truncated output (original token count: 63508)
Total output lines: 1288

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

Verified API sync controller increment 16.07.2026: the clean 193-line `SyncController` audit found duplicated catalog/owner checkpoint, cursor decode, bounded pull and cursor encode orchestration. One immutable `ApiSyncPullResult` and one constructor-injected `ApiSyncPullService` now own that repeated query flow; the controller retains readiness, authenticated owner resolution, safe `422`/`410` mapping, API Resource serialization and `private, no-store`. Routes, middleware, abilities, cursor encryption/retention, JSON fields, schema and push/manifest behavior are unchanged. RED→GREEN service coverage passes 3/7, the existing catalog/user characterization gate passes 14/184 before integration, the combined gate passes 17/191, and focused Pint, PHP syntax and Larastan report clean. This is one demonstrated controller increment, not a claim that the repository-wide controller audit is complete.

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

- [x] Add tiny legal/local HLS media playlist, fragmented MP4/init, direct MP4 Range and WebVTT fixtures at the existing allowlisted provider boundary; payloads remain committed text and in-memory browser responses.
- [x] Cover valid manifest/init/segment, `503 → success`, `503 → 410`, bounded terminal/manual retry, corrupt-fragment recovery, MP4 `206 Content-Range` and non-fatal subtitle failure without external network access.
- [x] Keep real-provider checks optional and credential/network gated; deterministic CI first validates the real signed redirect and never installs a production fixture route.

### 7.2 Player lifecycle

- [x] Test one initialization/destruction per morph/navigation, resize preservation, Livewire navigate, back/forward, pagehide/pageshow and HLS→MP4 source replacement without duplicate player sessions.
- [x] Verify one managed HLS.js instance when supported, native fallback otherwise, fresh-instance fatal retry, stale-timer cancellation and terminal/manual retry bounds.
- [x] Verify ordered start/pause browser progress events without duplicate positions; completion, replay and next-season resolution remain covered by the existing server/feature suite.
- [ ] Verify keyboard, touch, captions, speed, volume persistence, fullscreen, PiP, Media Session, orientation and reduced motion where supported.

Verified increment 16.07.2026: localized player PHP/asset/fixture/CI contracts passed; production Vite build passed; the isolated Playwright player suite completed with `7 passed` and `2` deliberate non-desktop detailed-matrix skips across Desktop/Mobile/Tablet Chromium. Keyboard focus, polite status regions, captions failure, responsive overflow and RU/EN copy are automated; fullscreen, PiP, Media Session, orientation and universal codec/browser coverage are not claimed.

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
- [x] Cache-failure recovery inspection found an after-commit version bump could fail after a successful privacy/publication mutation, while a delivered-again create/edit/delete/restore resolved as an idempotent no-op and therefore could not repair the stale namespace. Canonical fulfilled retries now leave domain rows/versions unchanged but repeat targeted invalidation; an ordinary exact edit with the current expected version remains a full no-op. Direct visibility enforcement never depends on cache, and no global …33508 tokens truncated…/Sanctum architecture remains; routes, guard, broker, signed verification, CSRF and noindex/sitemap boundaries are unchanged and inspectable.
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
