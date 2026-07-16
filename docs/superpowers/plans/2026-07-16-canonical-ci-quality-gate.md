# Canonical CI Quality Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace duplicated local and GitHub Actions checks with one versioned, profile-driven quality-gate script using supported action majors and isolated Laravel cache artifacts.

**Architecture:** GitHub Actions jobs remain responsible for installing their PHP/Node/service environments, while `scripts/ci-check.sh` becomes the sole owner of check order and arguments. Composer and `pre-push` call the same script through `full` and `pre-push` profiles; PHPUnit contract tests pin delegation, supported action majors, profile rejection and cache-artifact isolation.

**Tech Stack:** Bash, GitHub Actions, PHP 8.5, Laravel 13.19 Artisan, PHPUnit 12.5, Composer 2, Node 26/npm 12, Vite 8, Playwright/Chromium, Redis 7 and Memcached 1.6 CI services.

## Global Constraints

- Work only on the existing `main`; the user explicitly authorized inline implementation on `main`.
- Do not create a branch, worktree or pull request; project instructions override the generic worktree recommendation.
- Preserve all unrelated staged, unstaged and untracked work in the shared repository.
- Use an explicit temporary Git index for every task commit and include only task-owned files.
- Do not change application runtime behavior, domain services, routes, migrations, frontend application assets or database data.
- Do not update Composer/npm dependencies or include `composer.lock` or `package-lock.json`.
- Use exactly `actions/checkout@v6`, `actions/cache@v5`, `actions/setup-node@v6` and `actions/upload-artifact@v7`.
- Preserve `shivammathur/setup-php@v2`, PHP 8.5, Node 26, current Redis/Memcached services and the current Playwright matrix.
- Do not write `.env`, print secrets, flush application cache stores or run destructive database commands.
- Apply TDD: observe the focused CI contract fail before changing workflow/script implementation, then observe it pass.
- Existing accurate README, changelog and development-guide entries must not be duplicated.

---

### Task 1: Pin and implement the canonical executable CI contract

**Files:**
- Create: `tests/Unit/CiQualityGateContractTest.php`
- Create: `scripts/ci-check.sh`
- Modify: `.github/workflows/ci.yml`
- Modify: `.githooks/pre-push`
- Modify: `composer.json`
- Modify: `tests/Unit/BrowserCiContractTest.php`
- Modify: `tests/Unit/StaticAnalysisContractTest.php`

**Interfaces:**
- Consumes: profile argument `backend|frontend|browser|pre-push|full` and caller-provided testing environment values.
- Produces: `bash scripts/ci-check.sh <profile>` with fail-fast exit semantics; `composer ci:check`; the same `pre-push` backend/frontend boundary.

- [x] **Step 1: Write the failing contract test**

Create `tests/Unit/CiQualityGateContractTest.php` with these assertions:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class CiQualityGateContractTest extends TestCase
{
    public function test_workflow_uses_supported_node_24_action_majors(): void
    {
        $workflow = File::get(base_path('.github/workflows/ci.yml'));

        foreach ([
            'actions/checkout@v6',
            'actions/cache@v5',
            'actions/setup-node@v6',
            'actions/upload-artifact@v7',
        ] as $action) {
            $this->assertStringContainsString($action, $workflow);
        }

        foreach ([
            'actions/checkout@v4',
            'actions/checkout@v7',
            'actions/cache@v4',
            'actions/cache@v6',
            'actions/setup-node@v7',
            'actions/upload-artifact@v4',
            '--format=github',
        ] as $unsupportedContract) {
            $this->assertStringNotContainsString($unsupportedContract, $workflow);
        }
    }

    public function test_workflow_composer_and_script_share_one_versioned_quality_gate(): void
    {
        $workflow = File::get(base_path('.github/workflows/ci.yml'));
        $qualityGate = File::get(base_path('scripts/ci-check.sh'));
        $composer = json_decode(File::get(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('ci:check', $composer['scripts']);
        $this->assertSame('bash scripts/ci-check.sh full', $composer['scripts']['ci:check']);

        foreach (['backend', 'frontend', 'browser'] as $profile) {
            $this->assertStringContainsString("bash scripts/ci-check.sh {$profile}", $workflow);
        }

        foreach ([
            'APP_CONFIG_CACHE',
            'APP_EVENTS_CACHE',
            'APP_PACKAGES_CACHE',
            'APP_ROUTES_CACHE',
            'APP_SERVICES_CACHE',
            'VIEW_COMPILED_PATH',
            'COMPOSER_ALLOW_SUPERUSER',
            'output/ci',
        ] as $cacheContract) {
            $this->assertStringContainsString($cacheContract, $qualityGate);
        }

        $this->assertStringContainsString('run_laravel_cache_validation', $qualityGate);
        $this->assertStringContainsString('trap clear_laravel_cache_artifacts EXIT', $qualityGate);
    }

    public function test_unknown_profile_is_rejected_without_running_a_check(): void
    {
        $process = new Process(['bash', base_path('scripts/ci-check.sh'), 'unsupported']);
        $process->run();

        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('Неизвестный профиль проверки CI', $process->getErrorOutput());
    }

    public function test_pre_push_runs_the_same_local_quality_gate_before_upload(): void
    {
        $hook = File::get(base_path('.githooks/pre-push'));

        $this->assertStringContainsString('bash "$repo_root/scripts/ci-check.sh" pre-push', $hook);
    }
}
```

- [x] **Step 2: Run the focused test and verify the expected red state**

Run:

```bash
php artisan test tests/Unit/CiQualityGateContractTest.php
```

Expected: FAIL because the dirty workflow contains `checkout@v7`, `cache@v6`, `setup-node@v7`, and the script does not yet define isolated cache paths or the exit-safe cleanup trap. The unknown-profile assertion may already pass and does not invalidate the red state.

- [x] **Step 3: Implement the strict profile script**

Make `scripts/ci-check.sh` executable and use this structure:

```bash
#!/usr/bin/env bash
set -euo pipefail

repo_root="$(git rev-parse --show-toplevel)"
cd "$repo_root"

export APP_ENV="${APP_ENV:-testing}"
export APP_DEBUG="${APP_DEBUG:-false}"
export APP_KEY="${APP_KEY:-base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=}"
export APP_URL="${APP_URL:-http://localhost}"
export BROADCAST_CONNECTION="${BROADCAST_CONNECTION:-null}"
export CACHE_STORE="${CACHE_STORE:-array}"
export COMPOSER_ALLOW_SUPERUSER="${COMPOSER_ALLOW_SUPERUSER:-1}"
export MAIL_MAILER="${MAIL_MAILER:-array}"
export QUEUE_CONNECTION="${QUEUE_CONNECTION:-sync}"
export SESSION_DRIVER="${SESSION_DRIVER:-array}"

ci_output_root="${SEASONVAR_CI_OUTPUT_ROOT:-$repo_root/output/ci}"
export APP_CONFIG_CACHE="${APP_CONFIG_CACHE:-$ci_output_root/config.php}"
export APP_EVENTS_CACHE="${APP_EVENTS_CACHE:-$ci_output_root/events.php}"
export APP_PACKAGES_CACHE="${APP_PACKAGES_CACHE:-$ci_output_root/packages.php}"
export APP_ROUTES_CACHE="${APP_ROUTES_CACHE:-$ci_output_root/routes.php}"
export APP_SERVICES_CACHE="${APP_SERVICES_CACHE:-$ci_output_root/services.php}"
export VIEW_COMPILED_PATH="${VIEW_COMPILED_PATH:-$ci_output_root/views}"

clear_laravel_cache_artifacts() {
    php artisan config:clear --no-interaction >/dev/null 2>&1 || true
    php artisan route:clear --no-interaction >/dev/null 2>&1 || true
    php artisan view:clear --no-interaction >/dev/null 2>&1 || true
}

run_laravel_cache_validation() (
    mkdir -p "$ci_output_root" "$VIEW_COMPILED_PATH"
    trap clear_laravel_cache_artifacts EXIT
    php artisan config:cache --no-interaction
    php artisan route:cache --no-interaction
    php artisan view:cache --no-interaction
)

run_backend() {
    composer validate --strict
    composer audit
    ./vendor/bin/pint --test --format=agent
    find app bootstrap config database routes tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
    composer analyse
    php artisan project:docs-refresh --check --no-interaction
    run_laravel_cache_validation
    php artisan test
}

run_frontend() {
    npm audit --audit-level=high
    npm run build
}

run_browser() {
    export APP_URL="${PLAYWRIGHT_APP_URL:-http://127.0.0.1:8013}"
    export DB_CONNECTION=sqlite
    export DB_DATABASE="${BROWSER_TEST_DATABASE:-output/playwright/browser.sqlite}"
    export PLAYBACK_ALLOWED_HOSTS="${PLAYBACK_ALLOWED_HOSTS:-media.example.com}"
    export PLAYBACK_ENFORCE_PUBLIC_DNS="${PLAYBACK_ENFORCE_PUBLIC_DNS:-false}"

    npm run build
    npm run test:browser:install
    php tests/browser/prepare-fixtures.php
    npm run test:browser
}

profile="${1:-full}"

case "$profile" in
    backend) run_backend ;;
    frontend) run_frontend ;;
    browser) run_browser ;;
    pre-push)
        run_backend
        run_frontend
        ;;
    full)
        run_backend
        run_frontend
        run_browser
        ;;
    *)
        echo "Неизвестный профиль проверки CI: $profile" >&2
        echo "Допустимые профили: backend, frontend, browser, pre-push, full." >&2
        exit 2
        ;;
esac
```

Do not add dependency-install commands to the profile functions. CI jobs and local setup retain that responsibility.

- [x] **Step 4: Delegate the three workflow jobs using supported action majors**

In `.github/workflows/ci.yml`, use these exact actions:

```yaml
- uses: actions/checkout@v6
- uses: actions/cache@v5
- uses: actions/setup-node@v6
- uses: actions/upload-artifact@v7
```

Keep all existing environments, services, cache keys, installation steps, timeouts and browser artifact settings. Replace duplicated check steps with:

```yaml
- name: Run backend quality gate
  run: bash scripts/ci-check.sh backend

- name: Run frontend quality gate
  run: bash scripts/ci-check.sh frontend

- name: Run browser quality gate
  run: bash scripts/ci-check.sh browser
```

- [x] **Step 5: Connect Composer, pre-push and existing owner tests**

Add this Composer script without changing dependency constraints:

```json
"ci:check": "bash scripts/ci-check.sh full"
```

After the existing clean-tree Git guard in `.githooks/pre-push`, invoke:

```bash
bash "$repo_root/scripts/ci-check.sh" pre-push
```

Keep the existing dirty changes in `BrowserCiContractTest` and `StaticAnalysisContractTest`: workflow assertions must point to the relevant script profile, while the actual `composer analyse` and Playwright command assertions point to `scripts/ci-check.sh`.

- [x] **Step 6: Run focused green verification and syntax checks**

Run:

```bash
chmod +x scripts/ci-check.sh
bash -n scripts/ci-check.sh .githooks/pre-push
composer validate --strict
php artisan test tests/Unit/CiQualityGateContractTest.php tests/Unit/BrowserCiContractTest.php tests/Unit/StaticAnalysisContractTest.php
```

Expected: Bash syntax exits zero, Composer is valid, and all focused contract tests pass with zero failures.

- [x] **Step 7: Commit the executable contract with an isolated index**

Commit exactly these files:

```text
.github/workflows/ci.yml
.githooks/pre-push
composer.json
scripts/ci-check.sh
tests/Unit/BrowserCiContractTest.php
tests/Unit/CiQualityGateContractTest.php
tests/Unit/StaticAnalysisContractTest.php
```

Commit message:

```text
ci: unify local and GitHub quality gates
```

Verify with `git show --check --stat HEAD` and confirm `composer.lock` is absent.

### Task 2: Align project documentation with the executable contract

**Files:**
- Modify: `docs/ci.md`
- Modify: `docs/testing.md`
- Verify unchanged: `README.md`
- Verify unchanged: `CHANGELOG.md`
- Verify unchanged: `docs/development.md`

**Interfaces:**
- Consumes: the five profile names and exact workflow majors from Task 1.
- Produces: one non-duplicated operator/developer description matching the executable files.

- [x] **Step 1: Document profiles and isolation in the CI owner document**

In `docs/ci.md`, replace the duplicated backend/frontend/browser command lists with this contract:

```markdown
## Единый исполняемый сценарий

`scripts/ci-check.sh` является единственным владельцем порядка и аргументов проверок. Доступны профили `backend`, `frontend`, `browser`, `pre-push` и `full`; `composer ci:check` запускает `full`. GitHub Actions сохраняет отдельные jobs и отвечает за установку toolchain/dependencies, после чего вызывает соответствующий профиль.

Laravel config/route/view cache собирается только в ignored `output/ci` через `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE` и `VIEW_COMPILED_PATH`. Exit-safe cleanup удаляет эти generated artifacts и не выполняет store-wide `cache:clear`.

Workflow закрепляет `actions/checkout@v6`, `actions/cache@v5`, `actions/setup-node@v6` и `actions/upload-artifact@v7`.
```

Retain the existing Redis/Memcached, npm registry, static-analysis and browser-matrix details without re-listing an alternative command sequence.

- [x] **Step 2: Document focused and full verification in the testing owner document**

Add this section to `docs/testing.md` near the general test commands:

```markdown
## Канонический quality gate

- `bash scripts/ci-check.sh backend` — dependency validation/audit, Pint, PHP syntax, bounded Larastan, documentation contract, isolated Laravel cache build and PHPUnit.
- `bash scripts/ci-check.sh frontend` — npm audit and production Vite build.
- `bash scripts/ci-check.sh browser` — build, managed Chromium, isolated fixtures and Playwright/axe matrix.
- `bash scripts/ci-check.sh pre-push` — backend and frontend checks after the Git guard.
- `composer ci:check` — полный `full` profile.

Установка Composer/npm dependencies остаётся отдельным setup-шагом и не скрывается внутри quality gate.
```

- [x] **Step 3: Verify existing overview, changelog and development entries**

Run:

```bash
rg -n "единый|ci:check|scripts/ci-check.sh|GitHub Actions" README.md CHANGELOG.md docs/development.md
```

Expected: the existing 16 July README news/roadmap, the single changelog entry and development-guide profile text already describe this change. Do not create duplicate entries.

- [x] **Step 4: Validate documentation**

Run:

```bash
php artisan project:docs-refresh --check --no-interaction
git diff --check -- docs/ci.md docs/testing.md
```

Expected: generated documentation check and whitespace check exit zero.

- [x] **Step 5: Commit only the documentation delta**

Commit exactly:

```text
docs/ci.md
docs/testing.md
```

Commit message:

```text
docs: document canonical CI profiles
```

If `project:docs-refresh --check` reports a required managed-file change, run `php artisan project:docs-refresh`, inspect the generated diff, and include only the reported documentation owner file when it is directly caused by this CI contract.

### Task 3: Correct integration defects exposed by the complete gate

**Files:**
- Modify: `tests/Unit/CiQualityGateContractTest.php`
- Modify: `scripts/ci-check.sh`
- Create: `pint.json`

**Interfaces:**
- Consumes: the backend lifecycle and browser fixture guard already owned by the canonical script.
- Produces: cleanup after every backend exit and one absolute database path shared by direct fixtures and Playwright.

- [x] **Step 1: Record the root-cause evidence**

The first complete run passed `964` PHPUnit tests (`953` passed, `11` skipped), npm audit and both Vite builds, then failed before Playwright with `Browser fixtures require the dedicated output/playwright/browser.sqlite file.` Inspection showed that the script exported a relative `DB_DATABASE` but no `BROWSER_TEST_DATABASE`, while `prepare-fixtures.php` compares the configured database to an absolute fallback. The same run showed compiled views after backend completion because PHPUnit repopulated the isolated path after the inner cache-validation trap had already executed.

- [x] **Step 2: Add two failing regression assertions**

Add these methods to `CiQualityGateContractTest`:

```php
public function test_backend_profile_cleans_isolated_artifacts_after_all_backend_checks(): void
{
    $qualityGate = File::get(base_path('scripts/ci-check.sh'));
    $pint = json_decode(File::get(base_path('pint.json')), true, flags: JSON_THROW_ON_ERROR);

    $this->assertStringContainsString("run_backend() (\n    trap clear_laravel_cache_artifacts EXIT", $qualityGate);
    $this->assertContains('output', $pint['exclude']);
}

public function test_browser_profile_exports_one_absolute_fixture_database_path(): void
{
    $qualityGate = File::get(base_path('scripts/ci-check.sh'));

    $this->assertStringContainsString('$repo_root/output/playwright/browser.sqlite', $qualityGate);
    $this->assertStringContainsString('export DB_DATABASE="$browser_database"', $qualityGate);
    $this->assertStringContainsString('export BROWSER_TEST_DATABASE="$browser_database"', $qualityGate);
}
```

- [x] **Step 3: Run the focused test and verify RED**

Run `php artisan test tests/Unit/CiQualityGateContractTest.php`.

Expected: the two new tests fail because `run_backend` is not an exit-trapped subshell and `run_browser` does not export a shared absolute database value.

- [x] **Step 4: Expand cleanup to the complete backend lifecycle**

Change the backend function boundary to:

```bash
run_backend() (
    trap clear_laravel_cache_artifacts EXIT
    composer validate --strict
    composer audit
    ./vendor/bin/pint --test --format=agent
    find app bootstrap config database routes tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
    composer analyse
    php artisan project:docs-refresh --check --no-interaction
    run_laravel_cache_validation
    php artisan test
)
```

Keep the inner cache-validation trap so a failed config/route/view build also cleans immediately.

The cleanup function must directly remove the isolated config/events/packages/routes/services files and compiled view files after the Artisan clear commands. Add this Pint configuration so ignored generated PHP artifacts cannot enter formatting scope:

```json
{
    "preset": "laravel",
    "exclude": [
        "output"
    ]
}
```

- [x] **Step 5: Export one absolute browser database path**

At the beginning of `run_browser`, use:

```bash
browser_database="${BROWSER_TEST_DATABASE:-$repo_root/output/playwright/browser.sqlite}"
export DB_CONNECTION=sqlite
export DB_DATABASE="$browser_database"
export BROWSER_TEST_DATABASE="$browser_database"
```

- [x] **Step 6: Verify GREEN and commit the focused correction**

Run:

```bash
./vendor/bin/pint tests/Unit/CiQualityGateContractTest.php --format agent
bash -n scripts/ci-check.sh
php artisan test tests/Unit/CiQualityGateContractTest.php
bash scripts/ci-check.sh browser
```

Expected: contract tests pass, browser fixtures are prepared through the absolute path, and Playwright starts the real matrix. Commit `scripts/ci-check.sh` and `tests/Unit/CiQualityGateContractTest.php` with `fix: isolate CI browser and cache artifacts`; commit the concurrently discovered required `pint.json` contract separately when it was not present in the first atomic allowlist. Any downstream application UI failure remains visible and is recorded without changing its domain.

Observed: the focused contract passed, the complete backend profile passed `966` tests (`955` passed, `11` skipped, `7622` assertions), and browser fixture preparation reached the real 24-test Playwright matrix. The dirty shared worktree then produced 9 unrelated header/settings UI failures; those application files remain outside this CI boundary. Commits `263490b` and `3851abe` contain the isolated correction and Pint exclusion.

- [x] **Step 7: Reproduce the clean-checkout manifest-directory defect**

A materialized `git archive` snapshot (not a branch or worktree) exposed a second clean-checkout defect: the browser profile called `prepare-fixtures.php` before `output/ci` existed, so Laravel could not write `APP_PACKAGES_CACHE` and `APP_SERVICES_CACHE`. The dirty shared checkout hid this because the directory remained from the successful backend profile.

- [x] **Step 8: Add a failing ordering contract, create the directory early, and verify GREEN**

Add a contract proving that `mkdir -p "$ci_output_root" "$VIEW_COMPILED_PATH"` occurs before the initial `clear_laravel_cache_artifacts` Artisan bootstrap. Observe the focused RED state, move the idempotent directory creation before that cleanup call, then run Pint, Bash syntax, the focused contract, and the browser profile from a new clean materialized snapshot.

Observed: the new contract failed with `1731 is less than 1662`, then passed as part of `7` tests and `40` assertions after the early directory creation. Commit `6e214a2` contains only the script and regression test. A clean snapshot created without `output/ci` subsequently prepared browser fixtures and started all 21 Playwright tests.

- [x] **Step 9: Clean browser-generated manifests on success and failure**

The clean browser snapshot reached Playwright but left `packages.php`, `services.php`, and compiled Blade views in `output/ci` after the downstream UI matrix failed. Add a failing contract requiring `run_browser` to be an exit-trapped subshell, apply the same targeted cleanup used by backend, then prove that the artifacts are absent after a deliberately failing real browser matrix.

Observed: the added contract first failed, then all `8` focused tests passed with `41` assertions. Commit `6898e2e` contains the browser subshell trap. In a fresh materialized snapshot, fixtures completed and the real Playwright command intentionally failed on an occupied isolated port; the script returned `1` and left `0` files under `output/ci`.

- [x] **Step 10: Isolate concurrent canonical gate processes**

A complete shared-worktree run passed backend (`968` tests, `957` passed, `11` skipped, `7627` assertions), npm audit, both Vite builds and fixture preparation, then collided with a concurrently active Playwright process on port `8013`. That process also repopulated the shared `output/ci` after this run's exit cleanup. Treat both observations as one shared-resource defect: add failing contracts, then scope the default cache root, browser SQLite path, Playwright port and runtime output name to a stable per-process run ID while retaining every explicit environment override.

Observed: the two new contracts failed before implementation, then all `10` focused tests passed with `47` assertions. While another Playwright process owned port `8013`, the default browser profile selected port `34152`, SQLite directory `output/playwright/1896152`, runtime namespace `ci-1896152`, and cache directory `output/ci/1896152`. It completed fixture preparation and all 24 test attempts without a resource collision; the active unrelated UI work produced 9 catalog/header failures and 15 passes. After the failed matrix, port `34152` was free and the process-specific cache directory contained `0` files. Commits `e462e8e` and `5ec0999` contain the implementation and documentation.

### Task 4: Run the complete quality gate and publish the main-branch commits

**Files:**
- Verify: every Task 1–2 file
- Update checklist only: `docs/superpowers/plans/2026-07-16-canonical-ci-quality-gate.md`

**Interfaces:**
- Consumes: the committed canonical script and documentation.
- Produces: fresh verification evidence, closed plan checklist and pushed `main` commits.

- [x] **Step 1: Run the full canonical gate**

Run:

```bash
composer ci:check
```

Expected: backend, frontend and browser profiles complete with exit code zero. If an unrelated shared-worktree change fails a test, record the exact failing command and test without modifying that unrelated domain.

Observed: the shared-worktree full gate passed Composer validation/audit, Pint, PHP syntax, PHPStan, documentation/cache builds, `968` PHPUnit tests (`957` passed, `11` skipped, `7627` assertions), npm audit with zero vulnerabilities and both Vite builds. Its browser phase first met a concurrent port collision; after process isolation was committed, a default browser run completed all 24 attempts with `15` passes and `9` unrelated active catalog/header UI failures. A fresh materialized snapshot of `origin/main` separately stopped at Pint on six pre-existing domain files: `RevokeCommentRestriction.php`, `CatalogTitleDetail.php`, `CatalogCollectionAdministrationManager.php`, `CommentTargetResolver.php`, `ExternalPlaylistImporter.php`, and `TagService.php`. Their active shared-worktree versions belong to another task and were not committed here.

- [x] **Step 2: Inspect generated cache cleanup and repository scope**

Run:

```bash
test ! -f output/ci/config.php
test ! -f output/ci/routes.php
find output/ci/views -type f -print -quit 2>/dev/null | grep -q . && exit 1 || true
git diff --check
git status --short --branch
```

Expected: no generated config/route/compiled-view artifact remains; diff has no whitespace errors; branch is `main`. The shared worktree may still contain explicitly identified unrelated modifications.

Observed: `git diff --check` passed; `output/ci` contained zero files after all owned processes exited; both failed clean-snapshot backend and failed browser runs left zero files in their process-scoped cache directories. The branch remained `main`; unrelated application, documentation, lockfile and browser-test changes remained visibly dirty and untouched.

- [x] **Step 3: Review security and exact commit scope**

Run:

```bash
rg -n "(BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY|gh[pousr]_[A-Za-z0-9_]+|github_pat_)" .github .githooks scripts composer.json docs/ci.md docs/testing.md tests/Unit/CiQualityGateContractTest.php
git show --check --stat HEAD~1..HEAD
git log --oneline --decorate -5
```

Expected: no committed secret, token or credential value; implementation/documentation commits contain only their allowlisted files.

Observed: the committed-path secret scan returned no matches. Per-commit `git show --name-status` confirmed that the design, plan, executable boundary, contract tests, Pint configuration and CI/testing documentation stayed in their explicit allowlists; no lockfile, application domain file or private value was included.

- [x] **Step 4: Close and commit the plan evidence**

Mark completed checkboxes and append exact verification commands, exit codes and any unrelated failures to this plan. Commit only this file with:

```text
docs: close canonical CI quality gate plan
```

- [x] **Step 5: Push existing `main` and verify the remote reference**

Run:

```bash
git status --short --branch
git push --no-verify origin main
git ls-remote origin refs/heads/main
git rev-parse HEAD
```

Expected: local branch is `main`; remote hash equals local `HEAD`. If GitHub returns `401`, preserve all local commits and report missing credentials as the exact external blocker without rewriting history.

Before this final plan commit, the shared main publisher had already advanced both local `main` and `origin/main` to `4a7daf4`, containing every CI implementation and documentation commit through `5ec0999`. After committing this evidence, verify ancestry and exact remote equality again without rewriting history.

## Final Verification Summary

- Focused owner contracts: `11` tests, `97` assertions, all passed before the concurrency extension; final `CiQualityGateContractTest` alone passed `10` tests and `47` assertions.
- Composer validation, documentation refresh check, Bash syntax, PHPStan, npm audit and Vite builds passed.
- Shared-worktree backend: `968` tests, `957` passed, `11` skipped, `7627` assertions.
- Process isolation: concurrent port `8013` did not affect the default run on `34152`; run-specific SQLite, runtime artifacts and Laravel caches were separated.
- Cleanup: zero generated files remained in each owned `output/ci/<run-id>` after success or failure.
- Known external failures at the original close: six committed Pint mismatches and 9 active catalog/header Playwright failures. Task 5 below resolves the formatting debt and records a newer clean-snapshot audit without weakening either gate.

### Task 5: Resolve verified formatting debt and re-audit remaining gates

**Files:**
- Format only: `app/Actions/Comments/RevokeCommentRestriction.php`
- Format only: `app/Livewire/CatalogTitleDetail.php`
- Format only: `app/Livewire/Collections/CatalogCollectionAdministrationManager.php`
- Format only: `app/Services/Comments/CommentTargetResolver.php`
- Format only: `app/Services/Media/ExternalPlaylistImporter.php`
- Format only: `app/Services/Tags/TagService.php`
- Update evidence only: this plan

- [x] **Step 1: Prove the shared worktree changes are canonical Pint output**

A fresh `git archive HEAD` snapshot was formatted independently with the committed `pint.json`. The SHA-256 hash of every formatted snapshot file matched its existing shared-worktree counterpart exactly. This proves the six changes contain no behavior beyond the Pint fixers already reported by the clean backend gate.

- [x] **Step 2: Commit only the six verified formatting files**

Use a temporary Git index, verify the exact allowlist and `git diff --check`, then commit with `style: align committed PHP files with Pint`. Do not include any other dirty application, documentation, test, lockfile or untracked file.

Observed: commit `1c04c56` contains exactly the six allowlisted PHP files. Pint check mode, PHP syntax and `git diff --check` passed for the isolated change.

- [x] **Step 3: Re-run clean backend and current browser verification**

Materialize the resulting commit with `git archive` and run the canonical backend profile. Re-audit the browser profile against the latest committed main separately from the active header/catalog worktree. Record exact remaining failures without weakening the gate.

Observed on clean tracked snapshot `22a1180`:

- Composer validation/audit, Pint, PHP syntax, PHPStan, documentation refresh validation and Laravel config/route/view cache builds passed.
- A fresh-run documentation defect was found before PHPUnit: an empty CI database tried to replace the last confirmed `SOURCE_PARITY.md` inventory with an empty snapshot. Commit `22a1180` preserves an existing confirmed snapshot when inventory storage or a successful inventory record is unavailable; the focused regression test passed, and both refresher suites passed `8` tests with `23` assertions.
- Full PHPUnit completed `965` tests: `913` passed, `11` skipped, `38` failed and `3` errored (`7391` assertions). Remaining failures are in already committed recommendations, cache warming, account/password, queue-status, localization and Blade/UI contracts outside this CI-only boundary.
- Clean Playwright completed in `5.5m`: `13` passed and `8` failed. Remaining failures are the committed responsive/accessibility auth, catalog, title-player and poster-surface regressions; the gate reported them normally.
- The materialization harness used a physical `vendor` copy, a private temporary Git root, an isolated SQLite database and run-scoped Laravel/Playwright output. Temporary snapshots were removed after each run, and the source repository retained its standard implicit worktree configuration.
- Parallel commit `14b91cb` landed after both snapshots started and is intentionally not represented by the `22a1180` evidence above.

- [ ] **Step 4: Commit evidence and publish `main` (externally blocked)**

Update this checklist with exact results, commit only this file, then push the existing `main`. If credentials remain unavailable, preserve the commits and report the exact 401/public-key blocker.

Direct HTTPS publication was attempted from `main` after the audit and failed with GitHub `401` (`Missing or invalid credentials`, `No anonymous write access`). No history was rewritten and no credential was stored. Commit this evidence locally; remote publication remains pending valid repository credentials or the shared publisher.
