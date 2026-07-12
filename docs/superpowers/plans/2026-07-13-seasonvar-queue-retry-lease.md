# Seasonvar Queue Retry Lease Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Не допустить истечения queued page jobs Seasonvar раньше настроенного claim lease, пока jobs ожидают обработки в Redis.

**Architecture:** `ImportSeasonvarSourcePage` продолжает вычислять абсолютный `retryUntil` при dispatch, но использует наиболее длинный из retry window и page claim lease с существующим минимальным порогом 300 секунд. Claim ownership, backoff, finalizer и dispatcher не меняются; regression test проверяет обе конфигурационные ветви на фиксированном времени.

**Tech Stack:** PHP 8.5, Laravel 13.19, Laravel Queue, Carbon, Redis queue configuration, PHPUnit 12.5.

## Global Constraints

- Работать только в существующей ветке `main`; branches и worktrees не создавать.
- Сохранить `php artisan seasonvar:import` единственной публичной командой импорта.
- Не добавлять production dependencies, migrations или новые environment keys.
- Не редактировать уже сериализованные Redis payload и не очищать production queue.
- Видео не скачивать; сохранять только внешние URL и metadata.

---

### Task 1: Align queued job expiration with its claim lease

**Files:**
- Modify: `tests/Feature/SeasonvarParallelImportTest.php`
- Modify: `app/Jobs/ImportSeasonvarSourcePage.php`
- Modify: `docs/performance.md`
- Modify: `docs/superpowers/plans/2026-07-13-seasonvar-queue-retry-lease.md`

**Interfaces:**
- Consumes: `config('seasonvar.queue.retry_window_seconds')` and `config('seasonvar.queue.claim_seconds')` as integer seconds.
- Produces: unchanged `ImportSeasonvarSourcePage::retryUntil(): DateTimeInterface`, whose timestamp is dispatch time plus `max(300, retry_window_seconds, claim_seconds)`.

- [x] **Step 1: Write the failing expiration test**

Add this test after `test_parallel_import_schema_and_defaults_are_available()` in `tests/Feature/SeasonvarParallelImportTest.php`:

```php
public function test_page_job_retry_deadline_covers_the_longer_retry_or_claim_window(): void
{
    $this->travelTo('2026-07-13 12:00:00');

    config([
        'seasonvar.queue.retry_window_seconds' => 21600,
        'seasonvar.queue.claim_seconds' => 86400,
    ]);

    $claimBoundJob = new ImportSeasonvarSourcePage(1, 1, 'claim-token', 'seasonvar-title:1');

    $this->assertSame(now()->addDay()->getTimestamp(), $claimBoundJob->retryUntil()->getTimestamp());

    config([
        'seasonvar.queue.retry_window_seconds' => 172800,
        'seasonvar.queue.claim_seconds' => 86400,
    ]);

    $retryBoundJob = new ImportSeasonvarSourcePage(1, 1, 'claim-token', 'seasonvar-title:1');

    $this->assertSame(now()->addDays(2)->getTimestamp(), $retryBoundJob->retryUntil()->getTimestamp());
}
```

- [x] **Step 2: Run the test and verify RED**

Run:

```bash
php artisan test --compact --filter=test_page_job_retry_deadline_covers_the_longer_retry_or_claim_window
```

Expected: FAIL on the first assertion because the current job expires after 21 600 seconds instead of 86 400 seconds.

- [x] **Step 3: Implement the minimal deadline calculation**

Replace the current `retryUntilTimestamp` assignment in `app/Jobs/ImportSeasonvarSourcePage.php` with:

```php
$retryWindowSeconds = max(
    300,
    (int) config('seasonvar.queue.retry_window_seconds', 21600),
    (int) config('seasonvar.queue.claim_seconds', 86400),
);
$this->retryUntilTimestamp = now()
    ->addSeconds($retryWindowSeconds)
    ->getTimestamp();
```

Do not change `tries`, `backoff()`, `handle()`, `failed()` or `SeasonvarPageClaimManager`.

- [x] **Step 4: Run focused tests and verify GREEN**

Run:

```bash
php artisan test --compact --filter=test_page_job_retry_deadline_covers_the_longer_retry_or_claim_window
php artisan test --compact tests/Feature/SeasonvarParallelImportTest.php tests/Feature/SeasonvarQueuedFailureTest.php
```

Expected: the new test and all parallel/failure tests PASS with no failures.

- [x] **Step 5: Document the queue lifetime invariant**

In `docs/performance.md`, directly after the existing connection-failure/backoff paragraph, add:

```markdown
- Абсолютный `retryUntil` page job не короче настроенного claim lease: большой Redis backlog не завершает job до первого `handle()`. Уже сериализованные payload сохраняют старый deadline; после его истечения `failed()` освобождает claim, и следующий queued cron выбирает страницу повторно.
```

- [ ] **Step 6: Format and run full verification**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan project:docs-refresh --check
php artisan test --compact
git diff --check
```

Expected: Pint passes, docs are current, full PHPUnit suite has zero failures, and `git diff --check` prints no errors.

- [ ] **Step 7: Record verification, commit, push and reload workers**

Mark Steps 1–6 complete in this plan, then run:

```bash
git status --short --branch
git add -A
git commit -m "fix: align Seasonvar queue retry lease"
git push origin main
php artisan queue:restart
```

Expected: branch is `main`, project Git hooks accept the complete staging set, origin receives the commit, and workers restart without queue cleanup.

- [ ] **Step 8: Verify runtime and complete the plan**

Run:

```bash
systemctl is-active seasonvar-import-worker@21.service seasonvar-import-worker@22.service seasonvar-import-worker@23.service seasonvar-import-worker@24.service seasonvar-import-worker@25.service seasonvar-import-worker@26.service seasonvar-import-worker@27.service seasonvar-import-worker@28.service seasonvar-import-worker@29.service seasonvar-import-worker@30.service
php artisan seasonvar:import --status
git status --short --branch
```

Expected: ten `active` lines, queue status remains readable with the dominant run, and the working tree is clean. Mark Step 8 complete and commit/push the final documentation checkbox as a follow-up docs commit.
