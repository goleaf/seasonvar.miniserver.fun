# Design: wall-clock budget for the video file-size backlog

Date: 2026-07-16

Status: approved by the user's standing instruction to continue implementation without additional confirmation.

## Problem

The authenticated download and exact remote file-size feature is deployed, and global backlog observability now shows the production scale of the legacy metadata gap. The current scheduler checks at most 20 direct-file rows every ten minutes. A clean production size-only sample (`seasonvar_import_runs.id = 883`) completed 20 checks in four seconds, while full imports observed a lower aggregate rate of roughly 0.9–1.9 size checks per second because they also perform discovery, parsing and catalog writes. With more than 866,000 due rows, the fixed count of 20 underuses the scheduler window.

Increasing only the row count is unsafe: one slow or degraded upstream can make a scheduled process overlap the next ten-minute window. A second queue, command or importer would duplicate the existing single-flight and import-run architecture. The selected design therefore keeps the same `seasonvar:import` command, shared backlog query, inspector, run recorder and scheduler lock, and adds a bounded wall-clock budget to the existing size-only mode.

## Selected behavior

- Add `--media-size-time-budget=` to `seasonvar:import`.
- Accept only positive integer seconds, clamp the supported public range to 3,600 seconds, and require `--refresh-media-sizes`.
- Keep the option incompatible with URL, queued, forever, inventory, status and page-type modes through the existing validation boundary.
- Configure scheduled size backfill with a hard cap of 500 rows and a default time budget of 480 seconds.
- Measure elapsed time with PHP's monotonic `hrtime(true)`, not the mutable system clock.
- Check the budget only between media records. Never interrupt a request after it has started; the existing connect/total timeout and bounded retries remain responsible for a single upstream operation.
- Stop before selecting the next row when the budget is exhausted. Already persisted file-size metadata and import counters remain committed.
- Treat budget exhaustion as a successful bounded completion, not as an import failure or cancellation.
- Persist the reason and elapsed metadata in the existing import event/summary path; do not add database columns or a second counter model.
- Keep ordinary full and queued imports on their conservative existing count-only limit. The wall-clock budget is for explicit/scheduled size-only backfill.

## Components

### `LicensedMediaFileSizeBackfillBudget`

An immutable media-domain value object owns monotonic start time, optional deadline, exhaustion checks, remaining seconds and elapsed milliseconds. It accepts `null` for an intentionally unbounded manual/internal cycle and rejects invalid seconds. This keeps time arithmetic out of the command, Blade and importer loop.

### Command and pipeline

`ImportSeasonvar` validates and forwards the optional seconds value. `SeasonvarImportPipeline` passes it only to the size-only backlog invocation. `refreshMediaFileSizeBacklog()` creates one budget at cycle start and returns the existing counters plus:

- `time_budget_seconds`
- `time_budget_exhausted`
- `elapsed_milliseconds`

When exhausted, it emits `seasonvar-media-size-backlog-time-budget-exhausted`, then the normal backlog-complete event. The run remains terminally successful and its JSON summary records the same bounded result.

### Scheduler and operations

`routes/console.php` reads and clamps both scheduled values. The command remains `withoutOverlapping(30)`, `onOneServer()` and protected by the existing global importer lock. A 480-second budget leaves roughly two minutes before the next ten-minute schedule tick; the possible overrun is bounded by one already-started inspection's configured timeouts/retries.

CLI `--status` and the existing admin backlog panel display both the hard row cap and time budget. They perform no upstream request and expose no remote URL.

## Security and performance invariants

- The budget does not change URL allowlisting, public-DNS checks, redirect policy, connection pinning, HEAD/one-byte Range inspection or error sanitization.
- No complete video body is read, buffered, cached or stored.
- No database transaction spans an upstream request.
- No new queue, process, public endpoint, dependency or background shell process is introduced.
- Lazy stable-ID iteration, eager-loaded bounded context and per-media persistence remain unchanged.
- Signal-driven stop still takes precedence and remains reported as `stopped`; time exhaustion has its own explicit flag.

## Alternatives rejected

1. Raise only `scheduled_backfill_limit`: does not bound slow-host wall time.
2. Add a parallel queue/job or second command: duplicates the canonical importer and weakens single-flight protection.
3. Interrupt an in-flight HTTP stream at the budget deadline: adds unsafe cancellation complexity and can lose a trustworthy response already within its configured timeout.

## Verification

No automated tests are created or executed, matching the task's explicit prohibition. Verification uses PHP lint, Pint, Larastan/static analysis, route/schedule inspection, config/view caches, asset build, migration status/pretend, translation parity, diff hygiene and focused static searches.
