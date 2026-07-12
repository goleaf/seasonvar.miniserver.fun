# Seasonvar Queue Architecture Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сделать ошибки queued-импорта типизированными и наблюдаемыми, выделить отдельные архитектурные границы и добавить безопасную диагностику через существующую команду `seasonvar:import`.

**Architecture:** `SeasonvarCatalogImporter` делегирует запись failure отдельному action и повторно выбрасывает только transient exceptions в queue mode. Queue lifecycle находится в отдельном provider, а команда получает readonly DTO от status service.

**Tech Stack:** PHP 8.5, Laravel 13.19, Redis queue/cache locks, SQLite catalog database, PHPUnit 12.5, systemd.

## Global Constraints

- Работать только в существующей ветке `main`.
- Не запускать и не очищать production importer queue.
- Не добавлять production dependencies.
- Не скачивать видео; хранить только внешние playback metadata.
- Сохранять `php artisan seasonvar:import` единственной публичной Seasonvar-командой.
- Писать тест до production-кода и наблюдать ожидаемый RED.

---

### Task 1: Typed failure boundary

**Files:**
- Create: `app/Enums/SeasonvarImportFailureType.php`
- Create: `app/Exceptions/Seasonvar/SeasonvarSourceRequestException.php`
- Create: `app/Services/Seasonvar/SeasonvarImportFailureClassifier.php`
- Test: `tests/Unit/SeasonvarImportFailureClassifierTest.php`

**Interfaces:**
- Produces: `SeasonvarImportFailureClassifier::classify(Throwable $exception): SeasonvarImportFailureType`
- Produces: `SeasonvarSourceRequestException::forStatus(int $status): self`

- [ ] Write tests asserting 408/425/429/500 are transient, 404/422 are permanent, `ConnectionException` is transient, and SQLite lock `QueryException` is transient.
- [ ] Run `php artisan test --filter=SeasonvarImportFailureClassifierTest` and confirm failure because classes do not exist.
- [ ] Implement enum, typed exception, previous-exception traversal and SQLite lock detection.
- [ ] Re-run the focused test and confirm it passes.

### Task 2: Single failure recording action

**Files:**
- Create: `app/Actions/Seasonvar/RecordSeasonvarPageFailure.php`
- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Test: `tests/Feature/SeasonvarPageFailureActionTest.php`

**Interfaces:**
- Consumes: `SeasonvarImportFailureClassifier::classify()`
- Produces: `RecordSeasonvarPageFailure::handle(SourcePage $page, Throwable $exception, ?int $runId): SeasonvarImportFailureType`

- [ ] Write tests for one `failure_count` increment, HTTP 404 `gone`, transient retry timestamp, error message and run ID.
- [ ] Run `php artisan test --filter=SeasonvarPageFailureActionTest` and confirm RED.
- [ ] Implement the action with typed updates and bounded exponential delay.
- [ ] Inject the action into `SeasonvarCatalogImporter`, replace its catch update, and replace raw unsuccessful HTTP exception with `SeasonvarSourceRequestException`.
- [ ] Re-run action and importer focused tests.

### Task 3: Queue retry semantics and transaction-safe dispatch

**Files:**
- Modify: `app/Services/Seasonvar/SeasonvarCatalogImporter.php`
- Modify: `app/Jobs/ImportSeasonvarSourcePage.php`
- Modify: `app/Services/Seasonvar/SeasonvarQueuedImportDispatcher.php`
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`

**Interfaces:**
- Extends: `SeasonvarCatalogImporter::parsePages(..., bool $retryTransient = false): array`

- [ ] Add tests proving transient failures escape queued parsing, permanent failures return a failure result, claims survive retry, exhausted failure releases one claim, and dispatched jobs have `afterCommit=true`.
- [ ] Run `php artisan test --filter=SeasonvarParallelImportTest` and confirm the new assertions fail.
- [ ] Add the queue-mode flag, preserve synchronous behavior, and make `failed()` increment run failure only when its claim was released.
- [ ] Add `afterCommit()` to page and finalizer dispatch.
- [ ] Re-run `SeasonvarParallelImportTest`.

### Task 4: Queue lifecycle provider

**Files:**
- Create: `app/Services/Seasonvar/SeasonvarQueueMonitor.php`
- Create: `app/Providers/SeasonvarQueueServiceProvider.php`
- Modify: `bootstrap/providers.php`
- Modify: `config/seasonvar.php`
- Modify: `.env.example`
- Test: `tests/Unit/SeasonvarQueueMonitorTest.php`

**Interfaces:**
- Produces: `SeasonvarQueueMonitor::exceptionOccurred(JobExceptionOccurred $event): void`
- Produces: `SeasonvarQueueMonitor::failed(JobFailed $event): void`
- Produces: `SeasonvarQueueMonitor::busy(QueueBusy $event): void`

- [ ] Write tests for queue filtering and throttled busy logging.
- [ ] Run the monitor test and confirm RED.
- [ ] Implement structured logging, Redis throttle and provider queue hooks.
- [ ] Add transaction rollback in `Queue::looping()`.
- [ ] Re-run focused tests.

### Task 5: Typed queue status

**Files:**
- Create: `app/DTOs/Seasonvar/SeasonvarQueueStatusData.php`
- Create: `app/Services/Seasonvar/SeasonvarQueueStatus.php`
- Modify: `app/Console/Commands/ImportSeasonvar.php`
- Test: `tests/Feature/SeasonvarQueueStatusTest.php`

**Interfaces:**
- Produces: `SeasonvarQueueStatus::read(): SeasonvarQueueStatusData`
- Extends: `seasonvar:import --status`

- [ ] Write tests for queue sizes, oldest timestamp, live claims, latest run and no job dispatch.
- [ ] Run `php artisan test --filter=SeasonvarQueueStatusTest` and confirm RED.
- [ ] Implement readonly DTO, service and Russian console table.
- [ ] Re-run status and command tests.

### Task 6: Architecture guards and operations

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `deploy/systemd/seasonvar-import-worker@.service`
- Modify: `README.md`
- Modify: `docs/architecture.md`
- Modify: `docs/performance.md`
- Test: `tests/Unit/AppServiceProviderArchitectureTest.php`

**Interfaces:**
- Replaces two Eloquent calls with `Model::shouldBeStrict(! app()->isProduction())`.
- Adds `DB::prohibitDestructiveCommands(app()->isProduction())`.

- [ ] Write a test asserting all three Eloquent strictness switches in testing.
- [ ] Run the provider test and confirm missing-attribute strictness is currently false.
- [ ] Implement the guards and add systemd `--memory=256`.
- [ ] Document architecture rules, `--status`, monitor cron and deploy restart.
- [ ] Run focused provider tests and `git diff --check`.

### Task 7: Verification and rollout

**Files:**
- Format all changed PHP files.
- Install the versioned systemd unit without starting workers.
- Preserve the existing importer cron and add a read-only queue monitor cron.

- [ ] Run `./vendor/bin/pint --dirty --format agent`.
- [ ] Run importer/queue focused tests.
- [ ] Run `php artisan test` while all workers are inactive.
- [ ] Run `git diff --check` and inspect the complete diff.
- [ ] Copy the systemd template, run `systemctl daemon-reload`, and verify instances remain inactive.
- [ ] Commit all changes on `main`, push `origin main`, and verify a clean synchronized tree.
