# Performance audit

Проверено: 15.07.2026. Host: 4 physical cores, 62 GiB RAM, NVMe/XFS, SQLite database >14 GB, Redis, 64 MiB Memcached, PHP-FPM and nginx. Active import contention makes wall-clock samples workload-dependent; all measurements below state their context.

## Реестр выводов

| ID | Класс | Наблюдение | Изменение | Статус | Verification / remaining risk |
| --- | --- | --- | --- | --- | --- |
| PERF-01 | Confirmed problem | 11 overlapping import runs, thousands of jobs/claims | Lifecycle-level single-flight and terminal recovery before tuning workers | Pending P0/P1 | Current work amplification invalidates capacity estimates |
| PERF-02 | Confirmed problem | Cache-warm queue accumulated work without installed worker | Install only after dedupe/backlog review; verify heartbeat and drain rate | Pending | Uncontrolled drain can increase SQLite contention |
| PERF-03 | Confirmed problem | Cold/repeat home under load measured ~18/8 s; stats exceeded 60 s; sitemap index ~10 s | Profile builders/SQL/cache misses after lifecycle stabilization | Pending P3/P4 | Samples are not isolated benchmarks |
| PERF-04 | Confirmed performance cost, instrumented | External 25 s deadline expired, but instrumented run completed in 24.45 s: SQLite quick/FK 23655 ms; every other check 0–303 ms | Preserve integrity semantics, retain safe `duration_ms`, allow >=30 s outside writer load; optimize only with equivalent corruption detection | Implemented; rollout pending | Cost grows with the 14+ GB SQLite dataset; no evidence of an infinite scan |
| PERF-05 | Confirmed problem | AppLayoutData performs large unreachable SEO preparation (~7.3 ms ordinary render in local microbenchmark) | Remove dead preparation and shrink layout contract | Pending P4 | Browser/SEO regression required |
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
