# Implementation plan: bounded scheduled video-size backfill throughput

Date: 2026-07-16

Branch: `main` only

Constraint: do not create or run automated tests.

## Current-state audit

- [x] Confirm `git status --short --branch` reports exactly `main`; preserve all unrelated concurrent work.
- [x] Read the project/Seasonvar/Laravel skill instructions and the affected architecture, importer, performance and deployment documentation.
- [x] Inspect `ImportSeasonvar`, `SeasonvarImportPipeline`, progress formatting, scheduler, configuration, persisted runs/events and backlog/admin presentation.
- [x] Confirm one canonical size path: `seasonvar:import` → pipeline → `LicensedMediaFileSizeBacklog` → `InspectLicensedMediaFileSize` → typed inspector/metadata writer.
- [x] Confirm scheduler protection: Redis scheduler store, `onOneServer`, overlap lock and existing global importer/process lock.
- [x] Measure production read-only evidence: run #883 processed 20/20 in 4 seconds; larger full imports show variable aggregate throughput; current legacy due population exceeds 866,000 rows.
- [x] Compare count-only increase, parallel job topology and count-plus-wall-clock budget; select the third.
- [x] Record the approved design in the companion specification.
- [x] Discover committed merge-conflict markers and duplicated configuration prose in `docs/importer.md`; add their repair to this plan.

## Architecture and implementation

- [x] Add an immutable monotonic `LicensedMediaFileSizeBackfillBudget` value object with validated seconds, exhaustion, remaining-time and elapsed-time methods.
- [x] Add `--media-size-time-budget=` to the existing public command; do not add a second command.
- [x] Validate positive integer input, a 3,600-second ceiling, the required refresh mode and all existing conflicts.
- [x] Thread the optional budget through `ImportSeasonvar::handle()`, `SeasonvarImportPipeline::run()` and the size-only cycle only.
- [x] Stop stable-ID backlog iteration before the next media item when the budget expires, while preserving saved results and signal cancellation behavior.
- [x] Extend typed result PHPDoc, import summary and structured progress with budget seconds, exhaustion and elapsed milliseconds.
- [x] Add a Russian progress label and safe context labels; never include upstream URLs or secrets.
- [x] Keep normal sync/queued import backlog calls count-only and unchanged.
- [x] Centralize scheduled config clamps in immutable `LicensedMediaFileSizeBackfillSchedule` so scheduler, CLI and admin cannot drift.

## Scheduler, configuration and observability

- [x] Add `scheduled_backfill_time_budget_seconds` under the existing `seasonvar.media_file_size` configuration.
- [x] Raise the scheduled hard count default from 20 to 500 and set the default time budget to 480 seconds.
- [x] Add the new environment variable to `.env.example`; do not edit `.env`.
- [x] Pass both clamped values through the existing scheduled `seasonvar:import` invocation.
- [x] Preserve `everyTenMinutes`, `withoutOverlapping(30)`, `onOneServer`, enable flag and global import single-flight.
- [x] Show the planned hard cap and time budget in CLI `--status`.
- [x] Show the same operational values in the existing admin backlog panel using all supported translations and no Blade logic.

## Data, security, performance and compatibility

- [x] Add no migration: timing/result detail belongs in the existing JSON summary/events and current run counters remain sufficient.
- [x] Preserve HEAD then one-byte Range, public-DNS/SSRF controls, bounded retries/timeouts, response closure and error sanitization.
- [x] Preserve URL-only media storage; never read/store/cache a complete video during backfill.
- [x] Keep network calls outside database transactions and page rendering.
- [x] Treat time-budget exhaustion as successful bounded completion; do not roll back completed media or fail playback/import.
- [x] Bound possible schedule overrun to one already-started inspection.
- [x] Add no dependency, new worker, second importer, queue topology, route, controller, cache body or binary artifact.

## Documentation

- [x] Repair the committed merge markers in `docs/importer.md`, retaining both backlog observability and technical-issue contracts.
- [x] Update `.env.example`, `CHANGELOG.md`, `docs/importer.md`, `docs/performance.md`, `docs/deployment.md`, `docs/administration.md` and `docs/architecture.md` where materially affected.
- [x] Keep this discovered operational follow-up in its own dated project plan/spec so the concurrently changing original implementation record is not overwritten.
- [x] Keep automatic-import prohibition distinct from authenticated on-demand streaming; no wording authorizes permanent server-side video storage.
- [x] Record the final changed-file list and verification evidence here.

## Non-test verification

- [x] Run targeted `php -l` for every changed PHP file.
- [x] Run `./vendor/bin/pint --dirty --format agent`; because the shared tree contains another staged task, also run path-targeted Pint and exclude every unrelated file from this task commit.
- [x] Run focused Larastan/static analysis without a test runner (`0` errors).
- [x] Run `npm run build` because admin presentation translations/state change (Vite production build passed).
- [x] Run `php artisan route:list --path=download` and inspect authenticated scoped route (`web`, `auth`, `media-downloads`, `auth.session`, private response).
- [x] Run `php artisan schedule:list` and inspect the exact `--media-size-limit=500 --media-size-time-budget=480` ten-minute command.
- [x] Run `php artisan migrate:status` and safe `migrate --pretend --no-interaction`; this feature adds no migration and the unrelated pending recommendation index was not applied.
- [x] Run `config:cache`, `route:cache` and `view:cache` successfully.
- [x] Verify RU/EN translation key parity and absence of missing feature strings.
- [x] Run `git diff --check`, conflict-marker, forbidden-pattern and task-binary searches.
- [x] Confirm no tests were created, modified by this task or executed.
- [x] Confirm branch remains exactly `main`, commit only task files and push directly to `origin/main` (`a6dfaa2f83d347a374476f61b80b2180449c9075`).

## Final changed-files list

- [x] `.env.example`
- [x] `CHANGELOG.md`
- [x] `app/Console/Commands/Concerns/OutputsSeasonvarProgress.php`
- [x] `app/Console/Commands/ImportSeasonvar.php`
- [x] `app/Services/Media/LicensedMediaFileSizeBackfillBudget.php`
- [x] `app/Services/Media/LicensedMediaFileSizeBackfillSchedule.php`
- [x] `app/Services/Seasonvar/SeasonvarImportAdminService.php`
- [x] `app/Services/Seasonvar/SeasonvarImportPipeline.php`
- [x] `config/seasonvar.php`
- [x] `docs/administration.md`
- [x] `docs/architecture.md`
- [x] `docs/deployment.md`
- [x] `docs/importer.md`
- [x] `docs/performance.md`
- [x] `docs/superpowers/plans/2026-07-16-video-file-size-backfill-time-budget.md`
- [x] `docs/superpowers/specs/2026-07-16-video-file-size-backfill-time-budget-design.md`
- [x] `lang/en/catalog.php`
- [x] `lang/ru/catalog.php`
- [x] `routes/console.php`
