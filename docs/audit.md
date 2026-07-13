# Final Seasonvar Backlog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: execute inline with `superpowers:executing-plans` in the existing `main` checkout. Project rules prohibit worktrees and additional branches. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Закрыть все подтверждённые repository/runtime gaps, перенести устойчивые контракты в тематическую документацию и удалить этот единственный план после полной проверки.

**Architecture:** Один read-only operations checker объединяет production config, failed jobs, SQLite/FTS, cache и importer process diagnostics без раскрытия payload. Security/CI gates добавляются на существующие middleware и GitHub Actions boundaries. Admin mutations получают отдельный append-only audit service. Неподтверждённые будущие продукты остаются documented non-goals, а не незавершённым кодом.

**Tech Stack:** PHP 8.5, Laravel 13.19, Livewire 4.3, SQLite, Redis/Memcached, PHPUnit 12.5, PHPStan/Larastan, Playwright, axe, Vite 8, systemd.

## Global Constraints

- Единственная публичная команда импорта остаётся `php artisan seasonvar:import`.
- Работа выполняется только в существующей ветке `main`; worktree, новая ветка и PR не создаются.
- Production dependencies не добавляются. Допустимы только development dependencies PHPStan/Larastan, Playwright и axe.
- Видео, raw provider payload, source URLs, failed-job payload, stack traces, secrets и cookies не сохраняются и не выводятся.
- Queue/database rows не очищаются; migrations additive; backup/log artifacts не удаляются.
- `.env` меняется только по безопасным ключам, перечисленным в Task 1, и никогда не добавляется в Git.

---

### Task 1: Harden production environment, logs and cache runtime

**Files:**

- Modify runtime only: `.env`
- Modify: `.env.example`
- Create: `deploy/logrotate/seasonvar`
- Modify: `docs/environment.md`
- Modify: `docs/deployment.md`
- Test: `tests/Unit/ProductionOperationsDocumentationTest.php`

**Interfaces:**

- Safe runtime keys: `APP_ENV=production`, `APP_DEBUG=false`, `LOG_STACK=daily`, `LOG_LEVEL=warning`, bounded `LOG_DAILY_DAYS`, named cache/session/queue connections already defined by config.
- Produces a versioned logrotate policy and documented reload/rollback sequence; no secret values enter tests or docs.

- [ ] **Step 1: Write a failing operations contract test**

Assert `.env.example` contains safe production defaults, `deploy/logrotate/seasonvar` has `daily`, bounded `rotate`, `compress`, `missingok`, `notifempty`, and docs require config cache plus PHP-FPM/importer verification. The test must not read `.env`.

- [ ] **Step 2: Run RED**

Run: `php artisan test --filter=ProductionOperationsDocumentationTest`

Expected: failure because versioned logrotate policy does not exist.

- [ ] **Step 3: Add versioned configuration and documentation**

Create the logrotate policy for `storage/logs/*.log` with user/group `www`, bounded daily retention and `copytruncate`. Update only safe example defaults and exact deployment commands.

- [ ] **Step 4: Run GREEN and docs check**

Run the focused test and `php artisan project:docs-refresh --check`.

- [ ] **Step 5: Apply runtime keys without exposing `.env`**

Patch only the allowlisted keys, run `php artisan config:cache`, reload the detected PHP-FPM service, install the logrotate policy, and verify `artisan about`, public HTTP and the importer service. Preserve the legacy log file; do not delete it.

### Task 2: Add safe failed-job and deployment preflight diagnostics

**Files:**

- Create: `app/DTOs/Operations/DeploymentCheck.php`
- Create: `app/Services/Operations/FailedJobSummaryBuilder.php`
- Create: `app/Services/Operations/DeploymentReadinessChecker.php`
- Create: `app/Console/Commands/CheckDeploymentReadiness.php`
- Test: `tests/Feature/CheckDeploymentReadinessCommandTest.php`
- Modify: `docs/deployment.md`
- Modify: `docs/queues.md`

**Interfaces:**

- `FailedJobSummaryBuilder::build(): array{total:int, jobs:array<string,int>, categories:array<string,int>, ages:array<string,int>}` returns allowlisted class/category labels only.
- `DeploymentReadinessChecker::check(): array<int, DeploymentCheck>` performs read-only checks.
- Command: `php artisan app:deployment-check {--json}`; exit `0` only when no failed checks, exit `1` otherwise.

- [ ] **Step 1: Write failing command/service tests**

Cover safe empty state, unsafe local/debug/single logging, pending migration, missing required indexes, FTS count mismatch, failed jobs with a payload containing a URL/token, JSON shape, and absence of raw payload/exception text.

- [ ] **Step 2: Run RED**

Run: `php artisan test --filter=CheckDeploymentReadinessCommandTest`

Expected: failure because command and services do not exist.

- [ ] **Step 3: Implement bounded read-only diagnostics**

Use config, Schema/SQLite PRAGMAs, search index state, cache transport probes, `failed_jobs` chunking and `SeasonvarImportProcessInspector`. Every check has stable `name`, `status`, Russian `message`, and scalar metadata. Never execute migrate/retry/forget/restart.

- [ ] **Step 4: Run GREEN and security scans**

Run focused tests, then scan output/tests for `https://`, `token`, payload fragments and stack traces.

- [ ] **Step 5: Document the atomic single-importer runbook**

Document preflight → safe importer stop point → backup → migrate → FTS/cache work → config cache → importer/PHP-FPM restart → preflight/HTTP checks. Keep retry/forget as explicit manual decisions outside the command.

### Task 3: Add CSP report-only boundary

**Files:**

- Modify: `config/security.php`
- Modify: `.env.example`
- Modify: `app/Http/Middleware/AddSecurityHeaders.php`
- Modify: `tests/Feature/SecurityHardeningTest.php`
- Modify: `docs/security.md`
- Modify: `docs/frontend.md`

**Interfaces:**

- `security.csp_report_only` contains `enabled` and allowlisted directive sources.
- Public HTML receives `Content-Security-Policy-Report-Only`; JSON/API/download responses do not receive a body-dependent policy.
- Policy contains no `unsafe-eval`; provider origins come only from config allowlists.

- [ ] **Step 1: Add failing header tests**

Assert public HTML has report-only CSP with `default-src 'self'`, local script/style/font, configured image/media/connect sources, `object-src 'none'`, `base-uri 'self'`, `frame-ancestors 'self'`; assert no `unsafe-eval` and API JSON remains valid.

- [ ] **Step 2: Run RED**

Run: `php artisan test --filter=SecurityHardeningTest`

Expected: header assertion fails.

- [ ] **Step 3: Implement deterministic policy assembly**

Normalize configured sources, reject whitespace/semicolon/control characters, deduplicate and join fixed directives in middleware. Default to report-only; do not add a public report collector.

- [ ] **Step 4: Run GREEN and public HTTP smoke**

Run focused security tests and inspect `/`, `/titles`, one title, `/api/titles` headers.

### Task 4: Add reproducible Playwright, axe and responsive CI gates

**Files:**

- Modify: `package.json`
- Modify: `package-lock.json`
- Create: `playwright.config.js`
- Create: `tests/browser/prepare-fixtures.php`
- Create: `tests/browser/catalog.spec.js`
- Modify: `.github/workflows/ci.yml`
- Create: `tests/Unit/BrowserCiContractTest.php`
- Modify: `docs/testing.md`
- Modify: `docs/ci.md`

**Interfaces:**

- Npm scripts: `test:browser` and `test:browser:install`.
- Browser suite uses a temporary SQLite path and local server, blocks external requests, runs Chromium at mobile and desktop sizes, and stores artifacts under ignored `output/playwright/`.
- Axe fails only critical/serious violations; geometry assertions fail horizontal overflow or controls below 44 px where the control contract applies.

- [ ] **Step 1: Add failing CI contract test**

Assert package scripts/dependencies, Playwright config, local fixture bootstrap, axe call, two viewports and CI browser job exist without external media access.

- [ ] **Step 2: Run RED**

Run: `php artisan test --filter=BrowserCiContractTest`

Expected: missing files/scripts.

- [ ] **Step 3: Add dev dependencies and deterministic suite**

Install `@playwright/test` and `@axe-core/playwright` as dev dependencies. Fixture bootstrap creates one public title/season/episode/media row using factories in a temporary DB. Tests cover catalog URL state, filter reachability/focus, title/player shell, local asset failures and overflow.

- [ ] **Step 4: Wire CI and run GREEN**

CI installs Chromium, migrates/prepares the temporary DB, builds assets, starts Laravel locally and runs browser tests. Run the contract test and local browser suite.

### Task 5: Lock Livewire payload and query budgets

**Files:**

- Modify: `tests/Feature/LivewireCatalogTitlesTest.php`
- Modify: `tests/Feature/CatalogTitlesQueryBudgetTest.php`
- Modify: `tests/Feature/CatalogTitleLivewireTest.php`
- Modify only if RED proves necessary: catalog/title Livewire builders and views
- Modify: `docs/performance.md`

**Interfaces:**

- Catalog initial/deferred/update responses have explicit byte ceilings based on deterministic fixtures.
- Query ceilings remain fixed and do not scale with facet option count or public collections.

- [ ] **Step 1: Add response-size and repeated-render assertions**

Measure `strlen(response content)` for initial and Livewire update payloads, assert no serialized Eloquent collection/source URL, and record exact query ceilings already expected by builders.

- [ ] **Step 2: Run focused tests**

If assertions pass, document measured budgets without code change. If RED, reduce only the proven duplicated snapshot/query data and rerun.

- [ ] **Step 3: Verify regression surface**

Run the three focused classes plus catalog search/facet tests.

### Task 6: Add append-only catalog admin audit events

**Files:**

- Create: `app/Enums/AdminAuditAction.php`
- Create: `app/Models/AdminAuditEvent.php`
- Create: `app/Services/Admin/AdminAuditRecorder.php`
- Create: `database/migrations/2026_07_13_210000_create_admin_audit_events_table.php`
- Modify: `app/Services/Catalog/CatalogAdministrationService.php`
- Modify: `tests/Feature/AdminCatalogPageTest.php`
- Create: `tests/Unit/AdminAuditRecorderTest.php`
- Modify: `docs/administration.md`
- Modify: `docs/DATA_RELATIONS.md`

**Interfaces:**

- `AdminAuditRecorder::record(User $actor, AdminAuditAction $action, Model $resource, string $beforeVersion, string $afterVersion, array $changedFields): void`.
- Stored fields: actor FK, action enum, resource type allowlist, resource ID, before/after version hash, sorted allowlisted changed-field names, timestamp.
- No update/delete service or route; model blocks mutation after creation.

- [ ] **Step 1: Write migration/model/recorder RED tests**

Cover additive schema/rollback, casts/relations, stable field sorting, rejection of unknown fields/resource types, no values/URLs/tokens, and model update/delete refusal.

- [ ] **Step 2: Run RED**

Run audit recorder tests and exact admin mutation tests.

- [ ] **Step 3: Implement additive append-only boundary**

Create enum/model/service/migration. Integrate recorder after successful admin transactions for publication, metadata, hierarchy/relation and media-source mutations using existing version fingerprints.

- [ ] **Step 4: Run GREEN and migration rehearsal**

Run focused tests, then isolated migrate → rollback → migrate. Confirm no importer/public code path writes audit rows.

### Task 7: Extend deterministic docs automation and static analysis

**Files:**

- Modify: `app/Services/ProjectDocumentation/ProjectDocumentationRefresher.php`
- Modify: `tests/Feature/RefreshProjectDocsCommandTest.php`
- Modify: `composer.json`
- Modify: `composer.lock`
- Create: `phpstan.neon.dist`
- Modify: `.github/workflows/ci.yml`
- Modify: `docs/development.md`
- Modify: `docs/ci.md`

**Interfaces:**

- `project:docs-refresh --check` fails for a broken relative Markdown link and reports the owning file/path; external URLs are not fetched.
- Managed migration inventory is sorted by filename and generated only inside existing/new managed markers.
- Composer script `analyse` runs Larastan/PHPStan without a baseline over bounded high-value directories and zero ignored errors.

- [ ] **Step 1: Add failing docs link/inventory tests**

Use a temporary docs root/fixture to prove broken links fail, valid anchors/files pass, generated inventory is stable and double refresh is idempotent.

- [ ] **Step 2: Run RED**

Run: `php artisan test --filter=RefreshProjectDocsCommandTest`

- [ ] **Step 3: Implement deterministic validation/generation**

Resolve only repository-relative links, ignore fenced code/external/mailto/anchors, prevent path escape, and sort results. Generate migration inventory from `database/migrations` without querying production DB.

- [ ] **Step 4: Add bounded static analysis**

Install Larastan as dev dependency, configure PHPStan for `app/DTOs`, `app/Enums`, `app/Services/Operations`, `app/Services/Admin/AdminAuditRecorder.php`, and new command/model. Add `composer analyse` to CI.

- [ ] **Step 5: Run GREEN**

Run docs tests, double refresh/check, `composer analyse`, Composer validation/audit and CI contract tests.

### Task 8: Close non-goals and retention/operations contracts

**Files:**

- Modify: `docs/authorization.md`
- Modify: `docs/catalog-search.md`
- Modify: `docs/frontend.md`
- Modify: `docs/importer.md`
- Modify: `docs/administration.md`
- Modify: `docs/deployment.md`
- Modify: `docs/security.md`
- Modify: `docs/MAINTENANCE_LOG.md`
- Modify: `CHANGELOG.md`

**Interfaces:**

- Documents state that DRM/provider credentials, localized content records, household profiles/billing/PIN, personalized ranking, QoE telemetry, external search, PostgreSQL cutover and experiments are absent product capabilities, not implementation backlog.
- Existing importer retention windows and failed-job report have explicit owners; user history/legal data has no automatic deletion without policy.

- [ ] **Step 1: Update only topic owners**

Record implemented gates and explicit non-goals without copying long contracts across files.

- [ ] **Step 2: Verify no stale backlog language**

Search for AUD IDs, unfinished-marker words, old plan links, ten-worker runtime claims and removed limiter configuration in current owner docs.

- [ ] **Step 3: Refresh/check managed docs**

Run `php artisan project:docs-refresh` twice and confirm the second run is clean.

### Task 9: Production/runtime rollout and complete verification

**Files:**

- Modify runtime only: `.env`, config cache, installed logrotate/systemd/PHP-FPM state
- Delete after all checks: `docs/audit.md`
- Modify: `docs/README.md`
- Modify: `docs/markdown-review-2026-07-13.md`
- Modify: `CHANGELOG.md`

**Interfaces:**

- Final repository has no implementation plan file and no `docs/audit.md` ownership entry.
- Runtime is production/debug-off/daily-warning, one persistent importer process, safe cache transports and green deployment preflight.

- [ ] **Step 1: Run formatting and focused suites**

Run Pint and every focused test named above.

- [ ] **Step 2: Run full backend/static/frontend/browser verification**

Run `php artisan test`, `./vendor/bin/phpunit`, `composer analyse`, Composer validation/audit, PHP syntax lint, Laravel cache commands, `npm audit`, `npm run build`, Playwright and axe.

- [ ] **Step 3: Run operations verification**

Run deployment preflight, migration status, SQLite quick/FK/FTS checks, public HTTP smoke/latency sample, CSP header checks, logrotate dry run, config inspection without secrets, and importer PID/autostart verification.

- [ ] **Step 4: Delete the completed plan**

Delete `docs/audit.md`, remove its documentation-map/registry references, update changelog/maintenance evidence, run docs refresh/check and link validation.

- [ ] **Step 5: Commit clean final state**

Run `git diff --check`, confirm `main`, commit all authorized changes, verify clean tree. Do not push unless explicitly requested.
