# Maximal Rector Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a mandatory zero-diff Rector quality gate and a separate maximum stable-rule audit across all project-owned PHP without allowing CI to rewrite files.

**Architecture:** `rector.php` is the conservative required profile used by Composer and the existing unified backend CI script. `rector-max.php` shares the same project/version/framework scope but enables every compatible stable prepared set for an operator-driven dry-run; its findings are reviewed in batches instead of being silently applied. PHPUnit contract tests pin dependencies, paths, command safety and CI delegation.

**Tech Stack:** PHP 8.3 language floor on PHP 8.5 runtime, Laravel 13, Rector 2.x, rector-laravel 2.x, Composer, PHPUnit 12, Laravel Pint, Larastan, Bash/GitHub Actions.

## Global Constraints

- Work only on the existing `main` branch; do not create a branch or worktree.
- Add `rector/rector` and `driftingly/rector-laravel` only to `require-dev`.
- Rector must infer the PHP target from `composer.json`; do not emit PHP 8.4/8.5-only syntax while the declared floor is `^8.3`.
- Process `app`, `bootstrap`, `config`, `database`, `routes`, `tests` and project-owned root PHP files; exclude generated/runtime/vendor/Blade content.
- The mandatory profile must reach a zero-diff `--dry-run`; the maximum profile may report changes but must not have configuration/internal-processing errors.
- CI, hooks and `composer ci:check` must never run Rector in write mode.
- Do not add a Rector baseline or unexplained broad skip.
- Preserve all unrelated concurrent workspace changes and stage only files owned by this plan.
- Update Russian `README.md`, `docs/development.md`, `docs/ci.md` and `CHANGELOG.md`; do not edit managed `project-docs` blocks manually.

---

### Task 1: Pin the Rector integration contract with a failing PHPUnit test

**Files:**
- Create: `tests/Unit/RectorIntegrationContractTest.php`

**Interfaces:**
- Consumes: root `composer.json`, `rector.php`, `rector-max.php`, `scripts/ci-check.sh`, `.github/workflows/ci.yml`.
- Produces: `RectorIntegrationContractTest`, the acceptance boundary for later configuration and CI tasks.

- [ ] **Step 1: Write the failing contract test**

Create `tests/Unit/RectorIntegrationContractTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RectorIntegrationContractTest extends TestCase
{
    public function test_composer_exposes_safe_required_and_maximum_profiles(): void
    {
        $composer = json_decode(
            File::get(base_path('composer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertArrayHasKey('rector/rector', $composer['require-dev']);
        $this->assertArrayHasKey('driftingly/rector-laravel', $composer['require-dev']);
        $this->assertSame('rector process --dry-run --config=rector.php', $composer['scripts']['rector:check']);
        $this->assertSame('rector process --config=rector.php', $composer['scripts']['rector:fix']);
        $this->assertSame('rector process --dry-run --config=rector-max.php', $composer['scripts']['rector:max']);
    }

    #[DataProvider('rectorConfigProvider')]
    public function test_profiles_cover_all_project_owned_php_without_runtime_paths(string $file): void
    {
        $config = File::get(base_path($file));

        foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'tests'] as $path) {
            $this->assertStringContainsString("__DIR__.'/{$path}'", $config, "{$file}: {$path}");
        }

        $this->assertStringContainsString('->withRootFiles()', $config);
        $this->assertStringContainsString('->withPhpSets()', $config);
        $this->assertStringContainsString('LaravelSetProvider::class', $config);
        $this->assertStringContainsString('laravel: true', $config);
        $this->assertStringContainsString('phpunit: true', $config);

        foreach (['vendor', 'storage', 'bootstrap/cache', 'output'] as $path) {
            $this->assertStringContainsString("__DIR__.'/{$path}'", $config, "{$file}: {$path}");
        }
    }

    public function test_maximum_profile_enables_every_reviewed_stable_prepared_set(): void
    {
        $config = File::get(base_path('rector-max.php'));

        foreach ([
            'deadCode: true',
            'codeQuality: true',
            'codingStyle: true',
            'naming: true',
            'privatization: true',
            'typeDeclarations: true',
            'rectorPreset: true',
        ] as $set) {
            $this->assertStringContainsString($set, $config);
        }

        $this->assertStringContainsString('->withTreatClassesAsFinal()', $config);
    }

    public function test_backend_ci_delegates_to_required_dry_run_once_and_never_writes(): void
    {
        $script = File::get(base_path('scripts/ci-check.sh'));
        $workflow = File::get(base_path('.github/workflows/ci.yml'));

        $this->assertSame(1, substr_count($script, 'composer rector:check'));
        $this->assertStringNotContainsString('composer rector:fix', $script);
        $this->assertStringNotContainsString('rector process', $workflow);
    }

    /** @return array<string, array{string}> */
    public static function rectorConfigProvider(): array
    {
        return [
            'required' => ['rector.php'],
            'maximum' => ['rector-max.php'],
        ];
    }
}
```

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
php artisan test tests/Unit/RectorIntegrationContractTest.php
```

Expected: FAIL because the dependency entries, Composer scripts and Rector configuration files do not exist.

- [ ] **Step 3: Commit only the red contract**

```bash
git add tests/Unit/RectorIntegrationContractTest.php
SEASONVAR_SKIP_GIT_GUARD=1 git commit -m "test: define maximal Rector contract"
```

Expected: one test-only commit on `main`; unrelated dirty files remain unstaged.

---

### Task 2: Install Rector and create the required and maximum profiles

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Create: `rector.php`
- Create: `rector-max.php`

**Interfaces:**
- Consumes: `RectorIntegrationContractTest` string contracts and Composer PHP/Laravel/PHPUnit versions.
- Produces: `composer rector:check`, `composer rector:fix`, `composer rector:max` and two valid Rector configs.

- [ ] **Step 1: Install the development-only packages**

Run:

```bash
composer require --dev rector/rector:^2.4 driftingly/rector-laravel:^2.5 --no-interaction --no-progress
```

Expected: `composer.json` adds both packages under `require-dev`; `composer.lock` resolves compatible stable 2.x releases; Laravel package discovery completes without adding runtime providers.

- [ ] **Step 2: Inspect the installed configuration API before writing configs**

Run:

```bash
rg -n "function withPreparedSets|function withComposerBased|function withPhpSets|function withTypeCoverageLevel|function withSetProviders|function withCache" vendor/rector vendor/driftingly
```

Expected: the installed version exposes the methods used below. If a prepared-set named argument differs, use the installed stable method/name and update the contract test to that exact supported spelling rather than inventing compatibility code.

- [ ] **Step 3: Create the required profile**

Create `rector.php` with the installed Rector API:

```php
<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetProvider;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withRootFiles()
    ->withSkip([
        __DIR__.'/vendor',
        __DIR__.'/storage',
        __DIR__.'/bootstrap/cache',
        __DIR__.'/output',
    ])
    ->withCache(
        cacheDirectory: __DIR__.'/output/rector/required',
        cacheClass: FileCacheStorage::class,
    )
    ->withParallel(timeoutSeconds: 600, maxNumberOfProcess: 4, jobSize: 20)
    ->withSetProviders(LaravelSetProvider::class)
    ->withComposerBased(laravel: true, phpunit: true)
    ->withPhpSets()
    ->withTypeCoverageLevel(0)
    ->withDeadCodeLevel(0)
    ->withCodeQualityLevel(0)
    ->withCodingStyleLevel(0);
```

The four level calls are provisional: keep each only if its full first-pass diff is reviewed and accepted in Task 4. Removing an unsafe level from the mandatory profile requires keeping the same capability in `rector-max.php`, not adding a broad skip.

- [ ] **Step 4: Create the maximum stable profile**

Create `rector-max.php`:

```php
<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetProvider;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    ->withRootFiles()
    ->withSkip([
        __DIR__.'/vendor',
        __DIR__.'/storage',
        __DIR__.'/bootstrap/cache',
        __DIR__.'/output',
    ])
    ->withCache(
        cacheDirectory: __DIR__.'/output/rector/maximum',
        cacheClass: FileCacheStorage::class,
    )
    ->withParallel(timeoutSeconds: 600, maxNumberOfProcess: 4, jobSize: 20)
    ->withSetProviders(LaravelSetProvider::class)
    ->withComposerBased(laravel: true, phpunit: true)
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        naming: true,
        privatization: true,
        typeDeclarations: true,
        rectorPreset: true,
    )
    ->withTreatClassesAsFinal();
```

If the installed stable Rector exposes additional non-experimental prepared sets, add them here and pin their exact names in the contract test. Do not enable experimental rules.

- [ ] **Step 5: Add Composer scripts**

Add to the root `composer.json` `scripts` object:

```json
"rector:check": "rector process --dry-run --config=rector.php",
"rector:fix": "rector process --config=rector.php",
"rector:max": "rector process --dry-run --config=rector-max.php"
```

- [ ] **Step 6: Validate configs and rerun the contract**

Run:

```bash
composer validate --strict
./vendor/bin/rector list
./vendor/bin/rector process --dry-run --config=rector.php --no-progress-bar
./vendor/bin/rector process --dry-run --config=rector-max.php --no-progress-bar
php artisan test tests/Unit/RectorIntegrationContractTest.php
```

Expected: Composer/config parsing and the PHPUnit contract pass. Rector dry-runs may exit 2 only because they propose file changes; they must not report configuration, autoload, parser or worker errors.

- [ ] **Step 7: Commit the dependency/config boundary**

```bash
git add composer.json composer.lock rector.php rector-max.php tests/Unit/RectorIntegrationContractTest.php
SEASONVAR_SKIP_GIT_GUARD=1 git commit -m "build: integrate Rector profiles"
```

---

### Task 3: Make the required dry-run part of the unified backend CI gate

**Files:**
- Modify: `scripts/ci-check.sh`
- Modify: `tests/Unit/RectorIntegrationContractTest.php`
- Modify: `tests/Unit/CiQualityGateContractTest.php`

**Interfaces:**
- Consumes: Composer script `rector:check` from Task 2.
- Produces: one mandatory read-only Rector invocation in every backend/pre-push/full CI profile.

- [ ] **Step 1: Run the contract before CI modification and verify the remaining failure**

Run:

```bash
php artisan test tests/Unit/RectorIntegrationContractTest.php --filter=backend_ci
```

Expected: FAIL because `scripts/ci-check.sh` does not contain `composer rector:check`.

- [ ] **Step 2: Insert Rector into `run_backend`**

In `scripts/ci-check.sh`, keep the existing order and insert exactly one line after Pint and before syntax/Larastan:

```bash
    ./vendor/bin/pint --test --format=agent
    composer rector:check
    find app bootstrap config database routes tests -type f -name '*.php' -print0 | xargs -0 -n1 php -l
    composer analyse
```

Do not add direct Rector commands to `.github/workflows/ci.yml`, hooks or other profiles; those paths already delegate to `run_backend`.

- [ ] **Step 3: Extend the existing CI contract only where its exact order is asserted**

In `tests/Unit/CiQualityGateContractTest.php`, add `composer rector:check` to the expected backend command sequence between Pint and PHP syntax/static analysis. Keep all existing cache-isolation and GitHub Action assertions unchanged.

- [ ] **Step 4: Run focused contracts**

Run:

```bash
php artisan test tests/Unit/RectorIntegrationContractTest.php tests/Unit/CiQualityGateContractTest.php
```

Expected: PASS; assertions confirm exactly one dry-run and no CI write mode.

- [ ] **Step 5: Commit CI integration**

```bash
git add scripts/ci-check.sh tests/Unit/RectorIntegrationContractTest.php tests/Unit/CiQualityGateContractTest.php
SEASONVAR_SKIP_GIT_GUARD=1 git commit -m "ci: enforce Rector dry run"
```

---

### Task 4: Drive the mandatory profile to a reviewed zero diff

**Files:**
- Modify: `rector.php` only if an unsafe level must move to the maximum profile.
- Modify: project-owned PHP files selected by the accepted mandatory rules.
- Test: focused tests adjacent to every behavior-bearing file Rector changes.

**Interfaces:**
- Consumes: `composer rector:check` and `composer rector:fix`.
- Produces: a mandatory profile whose dry-run exits 0 on the current tree.

- [ ] **Step 1: Capture the mandatory JSON report without changing files**

Run:

```bash
./vendor/bin/rector process --dry-run --config=rector.php --output-format=json > output/rector/required-first-pass.json
```

Expected: valid JSON. Record changed file/rule counts from `totals`; raw report remains ignored and is not committed.

- [ ] **Step 2: Review the complete mandatory diff**

Run:

```bash
composer rector:check
```

Classify every proposed rule. Accept mechanical PHP/Laravel/PHPUnit upgrades and safe private/final type improvements. Move a whole unsafe level out of `rector.php` when it broadly changes public framework magic; use a narrow rule skip only when one documented false positive remains. Never skip an entire application directory.

- [ ] **Step 3: Apply the accepted mandatory profile**

Run:

```bash
composer rector:fix
./vendor/bin/pint --dirty --format agent
```

Expected: only proposals displayed in Step 2 are written; Pint restores project formatting.

- [ ] **Step 4: Verify static and behavioral safety**

Run:

```bash
composer rector:check
composer analyse
php artisan test tests/Unit/RectorIntegrationContractTest.php tests/Unit/CiQualityGateContractTest.php
php artisan test
```

Expected: required Rector is zero-diff, Larastan passes, focused contracts pass, and the full suite has no new failure. If an unrelated concurrent test is already failing, rerun it independently and document the external failure without weakening Rector.

- [ ] **Step 5: Commit the first reviewed Rector normalization**

Stage only `rector.php` and files actually changed by Rector/Pint, verify `git diff --cached --check`, then:

```bash
SEASONVAR_SKIP_GIT_GUARD=1 git commit -m "refactor: apply required Rector rules"
```

---

### Task 5: Document and verify the maximum audit workflow

**Files:**
- Modify: `README.md`
- Modify: `CHANGELOG.md`
- Modify: `docs/development.md`
- Modify: `docs/ci.md`
- Modify: `tests/Unit/RectorIntegrationContractTest.php` if installed stable set names changed.

**Interfaces:**
- Consumes: the three Composer scripts and actual first-pass counts.
- Produces: operator/developer instructions and final evidence for both profiles.

- [ ] **Step 1: Run the maximum profile and capture evidence**

Run:

```bash
./vendor/bin/rector process --dry-run --config=rector-max.php --output-format=json > output/rector/maximum-first-pass.json
php -r '$report = json_decode(file_get_contents("output/rector/maximum-first-pass.json"), true, flags: JSON_THROW_ON_ERROR); var_export($report["totals"] ?? []);'
```

Expected: valid JSON and deterministic totals. Exit 2 is acceptable when changes are proposed; any `errors` or processing failure must be fixed before continuing.

- [ ] **Step 2: Update Russian documentation**

Add to `README.md` quick checks:

```bash
composer rector:check
composer rector:max
```

Explain in `docs/development.md` that `rector:fix` writes only required reviewed rules and must be followed by Pint/tests, while `rector:max` is read-only and its output is handled in small batches. Explain in `docs/ci.md` that backend runs only `rector:check` through `scripts/ci-check.sh`; no workflow/hook writes files. Add actual dependency versions and first-pass result to `CHANGELOG.md`.

- [ ] **Step 3: Check README and managed documentation policy**

Run:

```bash
php artisan project:docs-refresh --check --no-interaction
scripts/check-readme-policy.sh
git diff --check
```

Expected: documentation is current, README remains Russian, visitor history stays last, and no whitespace errors exist.

- [ ] **Step 4: Run final acceptance**

Run:

```bash
composer validate --strict
composer audit
composer rector:check
./vendor/bin/pint --test --format=agent
composer analyse
php artisan test tests/Unit/RectorIntegrationContractTest.php tests/Unit/CiQualityGateContractTest.php
php artisan test
php artisan project:docs-refresh --check --no-interaction
```

Run `composer rector:max` separately and accept its changed-files exit code only after confirming there are no internal errors.

- [ ] **Step 5: Commit documentation and verification contract**

Stage only Rector-owned documentation/test changes, inspect the staged diff and run the staged README policy. Then:

```bash
SEASONVAR_SKIP_GIT_GUARD=1 git commit -m "docs: document maximal Rector workflow"
```

- [ ] **Step 6: Confirm repository state**

Run:

```bash
git status --short --branch
git log -8 --oneline
```

Expected: current branch is `main`; every Rector-owned change is committed. Any remaining dirty paths are pre-existing concurrent work and must be named as a blocker rather than staged or removed.
