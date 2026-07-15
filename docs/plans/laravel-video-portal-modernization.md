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
- [x] Nullable owner-identity inspection found cards/pages had been hardened but public JSON-LD still built a profile route from `owner.public_id` unconditionally. A valid opaque ID now enables the creator URL; anomalous nullable identity leaves only the escaped public display name and never falls back to email or numeric ID, preventing a route-generation 500 without leaking internals.

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

Status: implementation complete and final verification in progress on 2026-07-15. This is the single Task 11 plan. It extends the deployed `tags` / `catalog_title_tag` classification in place and deliberately does not create a competing public-user, season or episode tag system.

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
- [ ] Run allowed PHP syntax, Pint, route/schema/query/translation/view/Vite/browser smoke diagnostics only; inspect all changed/directly related files, reread Task 11, commit on existing `main` and push configured remote.

### Expected files, protected boundaries and rollback

Expected: additive tag migration/config/enums/models/policies/DTOs/services/queries/Livewire/controllers/requests/resources/middleware; tag/title/user/account/import/recommendation/cache/SEO/sitemap/API/routes/navigation/translations/views/JavaScript integrations; architecture/data/API/cache/import/SEO/security/UI/maintenance docs, this plan and `CHANGELOG.md`.

Must remain semantically unchanged: existing tag IDs/current slugs/source URLs/title pivot data and ordering, title/season/episode identity, all player/media URLs and grants, watchlist/progress/history/bookmarks/collections/comments/reviews/ratings, Seasonvar public import command, catalog route names and API compatibility fields. There is no season/episode assignment, public user tag, automatic hashtag creation, hierarchy inheritance, machine translation or mandatory queue/scheduler added by Task 11.

Rollback is additive-schema rollback only before new Task 11 data is accepted. After deployment, export personal/global translation/alias/provider-provenance data and restore the verified pre-migration database/code rather than dropping live user data. Cache versions are disposable; original `tags` and `catalog_title_tag` remain the compatibility source throughout rollback.

### Final manual verification checklist

- [ ] Inspect current/history/alias/case/merge route resolution, query preservation and loop-free canonical/404 behavior for public, empty and ineligible tags.
- [ ] Inspect stable identity/code/type/source/visibility/moderation casts, translation fallback, Unicode normalization/validation, alias conflicts, bounded synonyms and merge transaction/data preservation.
- [ ] Inspect personal owner assignment server-side, duplicate protection, edit/delete/restore conflict policy, batch Apply/Cancel, idempotent removal, account export/deletion and absence from public source/search/cache/sitemap/counts.
- [ ] Inspect visible-title scopes, distinct results/counts, deterministic filter/sort/pagination, active/fallback translation queries, eager loads and query plans for directory, tag page, title cards, API, popular and related tags.
- [ ] Inspect import retry and stale-source convergence, rejected mapping preservation, editorial suppression/correction survival, recommendation/search timestamps and targeted invalidation.
- [ ] Inspect administrator authorization, archive/restore/merge impact confirmation, no destructive GET or raw internal notes/provider credentials, and safe localized validation/failure states.
- [ ] Inspect SEO canonical/robots/metadata/structured data/sitemap eligibility, no false hreflang, API no-store, shared cache separation, responsive wrapping, zoom, keyboard/focus/touch, loading/empty/error announcements and translation parity.
- [ ] Confirm no new Volt, `@php`, Blade query/service/facade call, inline CSS, business JavaScript, hardcoded visible string, dead control, debug output, console logging, unused class/import or duplicate tag logic remains.

## Task 12 — canonical serial comments and discussions

Status: implementation approved on 2026-07-15. This section is the single implementation plan for portal discussions and supersedes Task 10's temporary decision to defer comments until a portal-wide social boundary existed.

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
- [x] The checked-in `routes/web.php` registers canonical and localized direct-comment, private discussion/inbox and moderator routes. The production-style workspace still has an older `bootstrap/cache/routes-v7.php`, so an ordinary `route:list` reports a stale snapshot. An isolated uncached route inspection currently reports 160 routes and confirms `comments.show`, `localized.comments.show`, `profile.discussions`, `notifications.index` and `admin.comments` with expected middleware; deployment must rebuild optimized caches after the release instead of treating the stale local snapshot as source truth.
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
- [x] Moderation idempotency inspection found that changed private notes could be silently ignored when restriction/report status fields otherwise matched. No-op comparison now includes the private note; resolved report evidence cannot be silently rewritten, while a materially changed restriction produces the normal revoke-and-replace audit trail.
- [x] Composer inspection found that the displayed character count depended on deferred Livewire model synchronization and could remain stale while typing. The existing Vite comments module now updates Unicode code-point counts locally for create, reply and edit editors without per-keystroke server requests; server validation remains canonical.
- [x] Plain-text boundary inspection found that an array or arbitrary non-stringable object passed to a `mixed` action input could emit a cast warning and normalize to the literal word `Array`. `UserPlainText` now rejects unsupported value shapes before normalization so canonical form actions return their normal localized empty-input behavior without warnings or accidental content.
- [x] Runtime locale inspection found that explicit Russian `[2,4]` intervals produced incorrect forms for values such as 21/22/25. Comment, reply and report totals now use Laravel's native three-form Russian plural selection; dedicated empty-state copy remains separate and natural.
- [x] Moderation side-effect inspection found that changing only a private note or internal reason bumped public target cache and notified the author even though their visible state was unchanged. Full audit/version history remains, while cache invalidation and moderation notification now require an actual status or deletion-visibility transition.
- [x] Account-deletion cache inspection found that reaction removal collected invalidation identities only from comments authored by the deleted user. The privacy service now unions authored and reacted-to discussion targets before deleting engagement, retaining the bounded 1,001-ID/global-generation fallback for large accounts and collection scopes.
- [x] Deleted-target lifecycle inspection found that the collection privacy retirement query inherited the soft-delete scope and skipped already author-deleted rows. The bulk lifecycle update now includes tombstones, preserves an existing deletion timestamp with portable `COALESCE`, clears the actor-specific deleter and closes every row under the stable privacy reason without deleting thread evidence.
- [x] Viewer-overlay query inspection found that moderators still loaded personal block/mute sets even though moderation visibility intentionally bypasses them. The prepared relationship context now returns immediately for `manage-comments`, removing two irrelevant queries without weakening mutation-time block enforcement.
- [x] Privacy-state inspection found that ordinary moderation could assign a non-removed status to a privacy tombstone while correctly refusing to restore its body, leaving a contradictory published/deleted row. Privacy-retired evidence is now terminal outside the dedicated legal/privacy lifecycle and can only remain in stable `removed` status.
- [x] Administration context inspection found that a hidden/deleted comment's safe direct link returned to the selected queue item but did not expose its root/reply neighborhood inside the protected interface. The moderation dialog now receives a typed, bounded root-plus-20-replies context and explicitly marks a selected reply outside the first batch without serializing a full thread.
- [x] Index inspection found that Laravel `morphs('notifiable')` created a two-column index duplicated by every required recipient composite's left prefix. The unreleased notification migration now declares the standard type/unsigned-ID columns explicitly so purpose-specific inbox indexes do not carry an extra morph-prefix duplicate.
- [x] Notification query-order inspection then separated the two real access patterns: recipient/created/id for deterministic paginated inboxes and recipient/read for unread updates/counts. Two purpose-specific composites replace the redundant morph prefix; comment inbox ordering now has stable UUID tie-breaking.
- [x] Final query/index inspection found that duplicate detection also scopes by structural root and the grouped current-user reaction overlay leads with user ID, while their indexes omitted those exact next columns. The unreleased empty-domain migrations now use `(user,target,parent,body_hash,created)` for duplicate lookup and a dedicated `(user,comment,type)` viewer-reaction composite; public totals and private export retain their distinct existing access paths.
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
- [x] Disposable SQLite `:memory:` migration inspection confirmed all four discussion migrations. The subsequent full repository migration stopped in a separate untracked Task 11 tag backfill because its namespace imports are incomplete; this is recorded as an external shared-worktree rollout blocker rather than misreported as a comment-schema failure.

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
- [ ] Run allowed PHP syntax/Pint/route/schema/query/translation/cache/security/Blade inspection, Vite build and browser smoke; reread the task and inspect all changed/directly-related files.
- [ ] Commit only on existing `main`, preserve unrelated work, verify status/remote and push the completed commit.

### Files expected to change

Expected: additive migrations; comment enums/models/policy/actions/services/DTOs/value objects/notifications/controllers/Livewire components; title/season/episode/user relationships; account/merger/cache/admin/nav/route/request integration; comment Blade/JavaScript; `config/comments.php`; `lang/{ru,en}/comments.php`; architecture/data/authorization/security/validation/forms/views/frontend/caching/performance/notifications/administration/API/SEO/deployment documentation, this plan, maintenance log and `CHANGELOG.md`.

Must remain semantically unchanged: provider `catalog_title_reviews`, imported review API, watchlist/rating/progress/history, playback/source grants, title/season/episode public identity, Seasonvar import command, existing target and historical-slug routes, catalogue/search/filter/recommendation output, sitemap membership rules and user-authentication credentials.

### Final manual verification checklist

- [ ] Stable comment IDs/anchors survive body edits, deletion/restoration, slug change and target merge; direct routes authorize before redirecting and never expose hidden content.
- [ ] Target enum/resolver rejects arbitrary classes/IDs, cross-target replies, deleted/inaccessible targets, self/descendant cycles and non-root structural parents.
- [ ] Plain-text normalization accepts Unicode/non-Latin scripts and rejects empty/control/dangerous/excessive input; Blade contains no raw comment body output.
- [ ] Create/reply/edit/delete/restore remain idempotent where required, preserve composer text on recoverable failure, use no GET mutation and maintain accurate derived counts.
- [ ] Newest/oldest/popular pagination is deterministic; replies are chronological/on-demand; eager loading and grouped viewer state avoid author/reaction/reply/block N+1 queries.
- [ ] Spoiler/full long body is absent from initial HTML, notifications/profile/SEO and appears only after an accessible explicit action; short text is not collapsed.
- [ ] One up/down reaction per user/comment, no self vote, safe change/remove, no deleted/hidden reaction and no current-user state in shared cache.
- [ ] Reply/reaction/moderation/report notifications respect preferences, self suppression, blocks/mutes and deduplication; every excerpt is plain, bounded and spoiler/deletion safe.
- [ ] Block/mute/report/restriction/moderation behavior is server-authorized, private state/notes/reporter identities never enter public DTOs, and expiry works without scheduler support.
- [ ] Public counts/caches contain public aggregates only; every meaningful mutation invalidates only the affected title/collection scope after commit.
- [ ] Account deletion anonymizes discussion safely, export omits private evidence, own activity respects visibility and no public profile capability is invented.
- [ ] Comment pages are absent from sitemap/structured data; direct/sort/page state has target canonical/noindex behavior and no spoiler metadata.
- [ ] Every visible control works with loading/disabled/success/error state; labels and ARIA text have exact Russian/English key parity; no Volt, `@php`, inline CSS, inline business JavaScript, Blade query or dead control is introduced.
- [ ] Responsive inspection covers narrow/landscape phones, tablet, desktop and zoom with long names/text/links/replies/reactions/tombstones; focus, keyboard, touch targets and reduced motion remain usable.
- [ ] Existing portal routes, home/search/catalog/player/library/reviews/ratings/recommendations/import/admin/API/cache/SEO behavior remain operational; allowed diagnostics/build/smoke are recorded truthfully.

## Task 13 — canonical serial reviews, ratings and moderation

Status: implementation complete; documentation and final verification in progress on 2026-07-15. This is the only Task 13 implementation plan. It extends the deployed `catalog_title_reviews` domain in place and does not introduce a competing review table.

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
- [ ] Update topic-owner documentation, maintenance log and English changelog; record known limitations and the complete manual acceptance checklist.
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

## Deferred product decisions, not hidden defects

- Collection collaborators, smart criteria, likes, follows and user comments require a future portal-wide social model; task 10 deliberately does not create isolated duplicates.
- Rights territories/subscriptions/profiles/PIN/DRM require legal/product specifications.
- Upload/transcode/object storage/CDN video pipeline is outside the current external-authorized-source architecture.
- Real-time broadcasting, Pennant rollout and dark mode require a demonstrated product need.
- Database engine migration requires post-stabilization SQLite SLA evidence.

## Phase update rule

After every phase: review diff, remove unrelated changes, run appropriate focused then broad gates, update this file’s checkboxes/evidence, update topic owner and changelog, create a focused `main` commit, and immediately add any newly discovered regression to the earliest applicable unfinished phase. A failing gate is work to diagnose, not text to explain away.
