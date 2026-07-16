# Seasonvar active import status design

Date: 2026-07-16

## Context and evidence

`php artisan seasonvar:import --status` currently combines Redis queue transport metrics with a single `runId` projection. `SeasonvarQueueStatus` limits that projection to `execution_mode=queue`. In production, synchronous global sitemap run `#887` remained active with a current heartbeat and 11,736 completed file-size inspections while a newer targeted queued URL run `#889` had already completed. The command therefore labelled `#889` as the primary active/last run even though the canonical global import coordinator correctly considered `#887` active.

The global file-size backlog snapshot is intentionally cached and timestamped because aggregating more than 870,000 eligible media rows during an active SQLite write workload is expensive. It must not be forced fresh merely to compensate for the wrong run projection.

## Considered approaches

1. Rename the current row to “latest queued run.” This makes the label less misleading but still hides a running synchronous global import and its live counters.
2. Force a fresh `licensed_media` aggregate in every status invocation. This increases SQLite contention and does not fix the run-selection boundary.
3. Reuse the existing canonical global-run selection and expose its already-persisted counters separately from queue transport and cached backlog metrics. This is the selected approach because it fixes the source of the incorrect state with one bounded query and no new persistence.

## Design

`SeasonvarQueueStatus` will continue to calculate Redis queue sizes, live claims and active queued-run count exactly as today. For the primary run projection it will first request the canonical active sitemap lifecycle from `SeasonvarGlobalImportRunCoordinator`. If no global lifecycle is active, it will select the latest sitemap lifecycle regardless of sync/queue execution mode. Targeted URL refreshes remain visible in the admin run history but cannot replace the primary global lifecycle shown by CLI status.

`SeasonvarQueueStatusData` will gain deterministic scalar fields already stored on `seasonvar_import_runs`: execution mode, heartbeat, file-size checked/known/unknown/unsupported/failed counters and known exact bytes. No model queries, cache reads or network calls will occur in the DTO.

The command will relabel the primary row as the global active/last run, display its execution mode and heartbeat, and show live file-size counters plus human/exact known bytes. The separately timestamped global backlog aggregate remains unchanged. This distinction makes it clear that run counters can advance while the bounded operational snapshot remains temporarily stale.

## Data flow

```text
seasonvar:import --status
    -> SeasonvarQueueStatus
       -> Redis queue transport metrics
       -> queued run/live-claim metrics
       -> SeasonvarGlobalImportRunCoordinator active sitemap run
          or latest sitemap fallback
    -> SeasonvarQueueStatusData
    -> Russian console tables

LicensedMediaFileSizeBacklog cached aggregate
    -> separately timestamped file-size backlog table
```

## Compatibility and safety

- `seasonvar:import` remains the only public importer command.
- Existing queue transport counters and active queued-run count retain their meanings.
- No migration, queue, scheduler, external HTTP request or cache invalidation is added.
- The status command stays read-only and bounded.
- No remote URL, signed query string, process command or error secret is displayed.
- The admin dashboard continues to show its bounded recent-run list and already-present media-size counters.

## Verification

Per the task constraint, no automated tests will be created or executed. Verification will use PHP lint, path-targeted Pint, focused Larastan, a read-only production status invocation, query-count inspection, `git diff --check`, forbidden-pattern searches and exact commit-file review. The before/after production reproduction must change the primary run from completed targeted URL `#889` to active global sitemap `#887` while preserving the cached backlog capture timestamp.
