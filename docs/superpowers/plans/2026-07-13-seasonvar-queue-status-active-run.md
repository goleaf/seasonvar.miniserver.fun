# Seasonvar Dominant Active Queue Run Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сделать `seasonvar:import --status` репрезентативным при нескольких одновременно running queued runs.

**Architecture:** `SeasonvarQueueStatus` выбирает dominant active run по числу живых claims и передаёт число running runs через существующий DTO. При отсутствии active runs сервис возвращает последний queued run, сохраняя текущую диагностику завершённых запусков.

**Tech Stack:** PHP 8.5, Laravel 13.19, Eloquent aggregates, PHPUnit 12.5.

## Global Constraints

- Публичной командой остаётся `php artisan seasonvar:import`.
- Режим `--status` остаётся read-only.
- Схема базы данных, queue payloads и importer execution не меняются.
- Видимый консольный текст остаётся русским.

---

### Task 1: Dominant active run status

**Files:**
- Modify: `app/DTOs/Seasonvar/SeasonvarQueueStatusData.php`
- Modify: `app/Services/Seasonvar/SeasonvarQueueStatus.php`
- Modify: `app/Console/Commands/ImportSeasonvar.php`
- Modify: `tests/Feature/SeasonvarQueueStatusTest.php`
- Modify: `docs/architecture.md`
- Modify: `docs/performance.md`

**Interfaces:**
- Consumes: `SeasonvarImportRun::claimedSourcePages()` and live claim timestamps.
- Produces: `SeasonvarQueueStatusData::$activeRuns` and dominant `runId/runStatus/selected/parsed/failed`.

- [x] **Step 1: Write the failing service test**

Create an older running run with two live claims and a newer running run with one live claim. Assert:

```php
$this->assertSame(2, $status->activeRuns);
$this->assertSame($dominantRun->id, $status->runId);
$this->assertSame(40, $status->parsed);
```

- [x] **Step 2: Verify RED**

Run:

```bash
php artisan test tests/Feature/SeasonvarQueueStatusTest.php
```

Expected: FAIL because `activeRuns` is missing and the service selects the newer run.

- [x] **Step 3: Implement the minimal query and DTO field**

Use `withCount()` constrained to live claims, order running runs by `live_claims_count DESC, id DESC`, and fall back to the latest queued run only when the running collection is empty. Pass `activeRuns: $runningRuns->count()` to the DTO.

- [x] **Step 4: Update console labels and command assertion**

Add table rows:

```php
['Активных queued runs', $status->activeRuns],
['Основной active/last run', $status->runId === null ? 'нет' : '#'.$status->runId],
```

Assert both labels in the command test.

- [x] **Step 5: Verify GREEN and formatting**

Run:

```bash
php artisan test tests/Feature/SeasonvarQueueStatusTest.php
./vendor/bin/pint --test --format agent app/DTOs/Seasonvar/SeasonvarQueueStatusData.php app/Services/Seasonvar/SeasonvarQueueStatus.php app/Console/Commands/ImportSeasonvar.php tests/Feature/SeasonvarQueueStatusTest.php
```

Expected: all focused tests and Pint PASS.

- [x] **Step 6: Verify runtime, full suite and docs**

Run:

```bash
php artisan seasonvar:import --status
php artisan test
php artisan project:docs-refresh --check
```

Expected: runtime shows run `#5` while it owns the dominant backlog; full suite and docs check PASS.

- [x] **Step 7: Commit and push main**

```bash
git add -A
git commit -m "fix: show dominant Seasonvar queue run"
git push origin main
```
