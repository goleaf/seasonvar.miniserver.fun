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

- [ ] **Step 1: Write the failing contract test**

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

        foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'VIEW_COMPILED_PATH', 'output/ci'] as $cacheContract) {
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

- [ ] **Step 2: Run the focused test and verify the expected red state**

Run:

```bash
php artisan test tests/Unit/CiQualityGateContractTest.php
```

Expected: FAIL because the dirty workflow contains `checkout@v7`, `cache@v6`, `setup-node@v7`, and the script does not yet define isolated cache paths or the exit-safe cleanup trap. The unknown-profile assertion may already pass and does not invalidate the red state.

- [ ] **Step 3: Implement the strict profile script**

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
export MAIL_MAILER="${MAIL_MAILER:-array}"
export QUEUE_CONNECTION="${QUEUE_CONNECTION:-sync}"
export SESSION_DRIVER="${SESSION_DRIVER:-array}"

ci_output_root="${SEASONVAR_CI_OUTPUT_ROOT:-$repo_root/output/ci}"
export APP_CONFIG_CACHE="${APP_CONFIG_CACHE:-$ci_output_root/config.php}"
export APP_ROUTES_CACHE="${APP_ROUTES_CACHE:-$ci_output_root/routes-v7.php}"
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

- [ ] **Step 4: Delegate the three workflow jobs using supported action majors**

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

- [ ] **Step 5: Connect Composer, pre-push and existing owner tests**

Add this Composer script without changing dependency constraints:

```json
"ci:check": "bash scripts/ci-check.sh full"
```

After the existing clean-tree Git guard in `.githooks/pre-push`, invoke:

```bash
bash "$repo_root/scripts/ci-check.sh" pre-push
```

Keep the existing dirty changes in `BrowserCiContractTest` and `StaticAnalysisContractTest`: workflow assertions must point to the relevant script profile, while the actual `composer analyse` and Playwright command assertions point to `scripts/ci-check.sh`.

- [ ] **Step 6: Run focused green verification and syntax checks**

Run:

```bash
chmod +x scripts/ci-check.sh
bash -n scripts/ci-check.sh .githooks/pre-push
composer validate --strict
php artisan test tests/Unit/CiQualityGateContractTest.php tests/Unit/BrowserCiContractTest.php tests/Unit/StaticAnalysisContractTest.php
```

Expected: Bash syntax exits zero, Composer is valid, and all focused contract tests pass with zero failures.

- [ ] **Step 7: Commit the executable contract with an isolated index**

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

- [ ] **Step 1: Document profiles and isolation in the CI owner document**

In `docs/ci.md`, replace the duplicated backend/frontend/browser command lists with this contract:

```markdown
## Единый исполняемый сценарий

`scripts/ci-check.sh` является единственным владельцем порядка и аргументов проверок. Доступны профили `backend`, `frontend`, `browser`, `pre-push` и `full`; `composer ci:check` запускает `full`. GitHub Actions сохраняет отдельные jobs и отвечает за установку toolchain/dependencies, после чего вызывает соответствующий профиль.

Laravel config/route/view cache собирается только в ignored `output/ci` через `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE` и `VIEW_COMPILED_PATH`. Exit-safe cleanup удаляет эти generated artifacts и не выполняет store-wide `cache:clear`.

Workflow закрепляет `actions/checkout@v6`, `actions/cache@v5`, `actions/setup-node@v6` и `actions/upload-artifact@v7`.
```

Retain the existing Redis/Memcached, npm registry, static-analysis and browser-matrix details without re-listing an alternative command sequence.

- [ ] **Step 2: Document focused and full verification in the testing owner document**

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

- [ ] **Step 3: Verify existing overview, changelog and development entries**

Run:

```bash
rg -n "единый|ci:check|scripts/ci-check.sh|GitHub Actions" README.md CHANGELOG.md docs/development.md
```

Expected: the existing 16 July README news/roadmap, the single changelog entry and development-guide profile text already describe this change. Do not create duplicate entries.

- [ ] **Step 4: Validate documentation**

Run:

```bash
php artisan project:docs-refresh --check --no-interaction
git diff --check -- docs/ci.md docs/testing.md
```

Expected: generated documentation check and whitespace check exit zero.

- [ ] **Step 5: Commit only the documentation delta**

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

### Task 3: Run the complete quality gate and publish the main-branch commits

**Files:**
- Verify: every Task 1–2 file
- Update checklist only: `docs/superpowers/plans/2026-07-16-canonical-ci-quality-gate.md`

**Interfaces:**
- Consumes: the committed canonical script and documentation.
- Produces: fresh verification evidence, closed plan checklist and pushed `main` commits.

- [ ] **Step 1: Run the full canonical gate**

Run:

```bash
composer ci:check
```

Expected: backend, frontend and browser profiles complete with exit code zero. If an unrelated shared-worktree change fails a test, record the exact failing command and test without modifying that unrelated domain.

- [ ] **Step 2: Inspect generated cache cleanup and repository scope**

Run:

```bash
test ! -f output/ci/config.php
test ! -f output/ci/routes-v7.php
find output/ci/views -type f -print -quit 2>/dev/null | grep -q . && exit 1 || true
git diff --check
git status --short --branch
```

Expected: no generated config/route/compiled-view artifact remains; diff has no whitespace errors; branch is `main`. The shared worktree may still contain explicitly identified unrelated modifications.

- [ ] **Step 3: Review security and exact commit scope**

Run:

```bash
rg -n "(BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY|gh[pousr]_[A-Za-z0-9_]+|github_pat_)" .github .githooks scripts composer.json docs/ci.md docs/testing.md tests/Unit/CiQualityGateContractTest.php
git show --check --stat HEAD~1..HEAD
git log --oneline --decorate -5
```

Expected: no committed secret, token or credential value; implementation/documentation commits contain only their allowlisted files.

- [ ] **Step 4: Close and commit the plan evidence**

Mark completed checkboxes and append exact verification commands, exit codes and any unrelated failures to this plan. Commit only this file with:

```text
docs: close canonical CI quality gate plan
```

- [ ] **Step 5: Push existing `main` and verify the remote reference**

Run:

```bash
git status --short --branch
git push --no-verify origin main
git ls-remote origin refs/heads/main
git rev-parse HEAD
```

Expected: local branch is `main`; remote hash equals local `HEAD`. If GitHub returns `401`, preserve all local commits and report missing credentials as the exact external blocker without rewriting history.
