# Performance audit

Проверено: 15.07.2026. Host: 4 physical cores, 62 GiB RAM, NVMe/XFS, SQLite database >14 GB, Redis, 64 MiB Memcached, PHP-FPM and nginx. Active import contention makes wall-clock samples workload-dependent; all measurements below state their context.

## Реестр выводов

| ID | Класс | Наблюдение | Изменение | Статус | Verification / remaining risk |
| --- | --- | --- | --- | --- | --- |
| PERF-01 | Confirmed problem, code fixed | 12 legacy overlapping sitemap runs, thousands of jobs/claims; failed-job aggregate includes 4155 group finalizers | Shared lifecycle single-flight plus event-driven finalizers and bounded watchdog before tuning workers | Implemented; rollout/reconciliation pending | Existing backlog still invalidates capacity estimates until workers are safely restarted and legacy jobs dispositioned |
| PERF-02 | Confirmed problem | Cache-warm queue accumulated work without installed worker | Install only after dedupe/backlog review; verify heartbeat and drain rate | Pending | Uncontrolled drain can increase SQLite contention |
| PERF-03 | Confirmed problem | Cold/repeat home under load measured ~18/8 s; stats exceeded 60 s; sitemap index ~10 s | Profile builders/SQL/cache misses after lifecycle stabilization | Pending P3/P4 | Samples are not isolated benchmarks |
| PERF-04 | Confirmed performance cost, instrumented | External 25 s deadline expired, but instrumented run completed in 24.45 s: SQLite quick/FK 23655 ms; every other check 0–303 ms | Preserve integrity semantics, retain safe `duration_ms`, allow >=30 s outside writer load; optimize only with equivalent corruption detection | Implemented; rollout pending | Cost grows with the 14+ GB SQLite dataset; no evidence of an infinite scan |
| PERF-05 | Confirmed problem, fixed | No producer enabled generated SEO flags, but AppLayoutData still built and discarded the complete matrix; a controlled 40-term rich payload measured 23.894 ms median / 25.323 ms p95 across 100 iterations | Remove dead preparation, explicit-return only bounded layout state, pre-encode JSON-LD | Implemented and fully gated | Same harness: 0.536 ms median / 0.834 ms p95 (97.8% / 96.7% lower); 411 vs 1,928 PHP lines and 96 vs 783 Blade lines; focused 130/1198, full 848/6928, Larastan 0, build and browser 21/21 |
| PERF-06 | Confirmed problem | Recommendation full rebuild ~8.5–9.2 min and invoked per import cycle | Dirty-title/versioned rebuild or once-per-completed-cycle aggregation | Pending P3 | Must preserve atomic v3 consistency |
| PERF-07 | Confirmed problem | Raw snapshots/prepared payloads dominate storage growth | Semantic unchanged-page skip + bounded retention | Pending P2 | Recovery/legal retention constraints first |
| PERF-08 | Confirmed control | Catalog/title/player/sitemap have selected query/payload budgets and eager loading | Preserve; extend to uncovered states | In progress | No claim that every component/path is covered |
| PERF-09 | Intentional | HLS.js is a 104.61 kB gzip lazy chunk | Keep lazy; review light-build capability | Accepted pending measurement | Media functionality outweighs initial bundle because it is not initial |
| PERF-10 | Proposed | Octane | Reject until long-lived state audit and benchmark after core fixes | Rejected now | Livewire/player/DI state risk exceeds unproven gain |
| PERF-11 | Proposed | PHP JIT | Do not enable without representative benchmark | Rejected now | Laravel web workloads rarely justify risk; host currently allocates JIT buffer |

## Baseline evidence

- PHPUnit: 826 tests in 93.554 s; browser: 18 scenarios in 1.4 min; Vite build: 2.51 s.
- Memcached sample: 2234 hits, 1365 misses, 0 evictions, 64 MiB limit; this is infrastructure counter data, not application cache hit ratio.
- Redis sample: ~45 MB used, no configured maxmemory and 0 evictions; memory policy/alerting needs an operational decision.
- `licensed_media` has ~864k rows; recommendations ~387k rows; source snapshots and prepared pages contain the largest text payloads.
- Catalog FTS optimization previously reduced a production-scale count from seconds to milliseconds; its query-shape tests remain mandatory.

## Measurement protocol

1. Record import/queue load, cache state and dataset counts with every sample.
2. Use at least five runs after one warm-up; report median and p95 where sample size allows.
3. Record query count, duplicate count, SQL, `EXPLAIN QUERY PLAN`, response bytes and Livewire snapshot bytes for critical paths.
4. Compare the same route/state/user and do not mix cold and warm values.
5. Reject changes whose apparent improvement comes only from removing behavior, weakening authorization or hiding work asynchronously without user-safe semantics.

## Task 10 collection query audit

The initial title membership selector showed a confirmed N+1 pattern (membership/count per collection). Final code performs one owner-scoped collection+exists query for display and one locked manageable set, one membership snapshot, one grouped count/max query and bulk changes for Apply. Summary cards eager-load owner/translations and grouped counts/fallback; item pages reuse title visibility/card eager loads with stable paginated unique-pivot join. No request-time collage, all-items summary hydration, per-card owner/count or random personalization query remains.

Indexes were added only for owner/public/featured collection scans, manual order, title membership/merge, editorial locale lookup and moderation reports. A migrated disposable SQLite dataset with public/private/unlisted/editorial collections and ordered memberships confirmed the intended owner, public, manual-order, title-membership, report and translation indexes through representative `EXPLAIN QUERY PLAN`; browser/API pagination and grouped counts were also inspected. Production cardinality/latency remains a rollout measurement and no fabricated improvement percentage is reported.

## Task 12 discussion query audit

Initial architecture had no query to optimize. Final read boundary uses deterministic 15-row root pagination, grouped up/down/public-reply subqueries, eager author/reply context, one viewer-reaction query and two bounded relationship queries. One expanded root loads chronological replies in batches of 20; full recursive discussion load and per-item author/reaction/reply/block queries are absent. Moderator/profile/inbox flows paginate and batch related state.

Indexes match these exact predicates and are documented in `DATA_RELATIONS.md`; denormalized counts/score were rejected to avoid drift. Shared guest HTML may contain only public DTO, while auth/Livewire overlays bypass it; mutations bump only affected existing title/collection versions. Dataset contains no discussion rows before rollout, so latency/selectivity improvement is not fabricated. Representative production-like row/query-plan/browser payload measurement remains a post-migration operational gate.

## Task 13 review query audit

Baseline provider API was deterministic but used a temporary B-tree for published-date ordering. The additive schema introduces exact title/status/deleted/date, author history and moderation indexes, while canonical rating remains a single join and helpful totals are grouped subqueries. Final list paginates SQL with ID tie-breaker, eager-loads author/title, loads viewer votes once and block/mute sets in bounded queries; verified state is a stored privacy-safe snapshot, so no per-review progress read exists.

Public count/average/helpfulness are derived rather than denormalized. Moderator/profile/direct-link reads are paginated/bounded, and title/user-state/progress/review merge loops use `eachById()` to avoid offset skip while mutating rows. Production review latency is not fabricated: the live data has provider rows only and community schema is not deployed. Isolated SQLite `EXPLAIN`, route payload and browser observations are recorded in verification report; Task 13 creates/runs no tests.
