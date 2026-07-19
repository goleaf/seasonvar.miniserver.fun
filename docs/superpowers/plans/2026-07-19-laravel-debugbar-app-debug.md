# Laravel Debugbar APP_DEBUG Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Install the official Laravel Debugbar as a development-only dependency and make `APP_DEBUG` the only project-owned enable switch while keeping production and testing fail-closed.

**Architecture:** `fruitcake/laravel-debugbar` is auto-discovered only when development dependencies are installed. A minimal `config/debugbar.php` overrides only `enabled` and `force_allow_enable`, leaving package defaults mergeable and preventing a stale full-config fork. The package's own `canBeEnabled()` guard supplies the second boundary that excludes `production` and `testing`.

**Tech Stack:** PHP 8.5, Laravel 13.20, PHPUnit 12.5, Composer 2.10, `fruitcake/laravel-debugbar` 4.4.

## Global Constraints

- Install `fruitcake/laravel-debugbar:^4.4` in `require-dev`; never add the obsolete `barryvdh/laravel-debugbar` package name.
- `APP_DEBUG` remains the only project-owned enable switch; do not add `DEBUGBAR_ENABLED` or `DEBUGBAR_FORCE_ALLOW_ENABLE` to `.env.example`.
- `force_allow_enable` is always `false`; `production` and `testing` never expose Debugbar even if debug mode is mistakenly enabled.
- Do not read or edit `.env`, add migrations, change public routes, add Vite entrypoints, or flush application caches.
- Preserve every pre-existing user change and commit only on `main`.

---

### Task 1: Dependency, explicit configuration, and regression contract

**Files:**

- Create: `tests/Feature/DebugbarConfigurationTest.php`
- Create: `config/debugbar.php`
- Modify: `composer.json`
- Modify: `composer.lock`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `docs/maintenance/update-decisions.md`

**Interfaces:**

- Consumes: Laravel `config('app.debug')`, Laravel environment detection, package `Fruitcake\LaravelDebugbar\LaravelDebugbar::canBeEnabled()`.
- Produces: `config('debugbar.enabled'): bool`, `config('debugbar.force_allow_enable'): false`, locked development dependency and executable regression evidence.

- [x] **Step 1: Record the unresolved pre-implementation decision and compliance matrix**

Add a current-task section that records package purpose, official Laravel 13/PHP 8.5 compatibility, development-only scope, no-data migration, production `--no-dev`, config-cache rollout, package-removal rollback, all protected-domain impact states, and unresolved test/install/lock verification. Add `UD-C-017` to `docs/maintenance/update-decisions.md` with decision `add` and the same compatibility/rollback contract before changing Composer files.

- [x] **Step 2: Write the failing regression test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Tests\TestCase;

final class DebugbarConfigurationTest extends TestCase
{
    public function test_project_configuration_uses_app_debug_without_force_enable(): void
    {
        self::assertSame(config('app.debug'), config('debugbar.enabled'));
        self::assertFalse(config('debugbar.force_allow_enable'));
    }

    public function test_debugbar_can_boot_only_for_local_debug_mode(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');

        config([
            'app.env' => 'local',
            'app.debug' => true,
            'debugbar.enabled' => true,
            'debugbar.force_allow_enable' => false,
        ]);

        self::assertTrue(LaravelDebugbar::canBeEnabled());

        config([
            'app.debug' => false,
            'debugbar.enabled' => false,
        ]);

        self::assertFalse(LaravelDebugbar::canBeEnabled());
    }

    public function test_debugbar_remains_blocked_in_production_and_testing(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        config([
            'app.env' => 'production',
            'app.debug' => true,
            'debugbar.enabled' => true,
            'debugbar.force_allow_enable' => false,
        ]);

        self::assertFalse(LaravelDebugbar::canBeEnabled());

        $this->app->detectEnvironment(static fn (): string => 'testing');
        config(['app.env' => 'testing']);

        self::assertFalse(LaravelDebugbar::canBeEnabled());
    }
}
```

- [x] **Step 3: Run the test and confirm the expected red state**

Run: `php artisan test --filter=DebugbarConfigurationTest`

Expected: FAIL because `Fruitcake\LaravelDebugbar\LaravelDebugbar` and/or project `debugbar` configuration is unavailable before installation.

- [x] **Step 4: Install the smallest compatible dependency set**

Run: `composer require --dev fruitcake/laravel-debugbar:^4.4 --minimal-changes --no-interaction`

Expected: `fruitcake/laravel-debugbar` is added to `require-dev`; lock adds only it and required Debugbar bridge/core packages or otherwise every additional transitive change is reviewed before proceeding.

- [x] **Step 5: Add the minimal project configuration**

```php
<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('APP_DEBUG', false),
    'force_allow_enable' => false,
];
```

- [x] **Step 6: Run focused test and formatting checks**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=DebugbarConfigurationTest
composer validate --strict
```

Expected: Pint succeeds, three Debugbar tests pass, Composer manifests validate.

- [x] **Step 7: Review lock scope and installed metadata**

Run:

```bash
git diff -- composer.json composer.lock
composer show fruitcake/laravel-debugbar
composer show php-debugbar/php-debugbar
composer show php-debugbar/symfony-bridge
composer licenses --format=json
composer audit --locked --no-interaction
```

Expected: direct package is 4.4.x, PHP/Laravel constraints cover the project, licenses are documented, audit has no known advisory/abandonment result, and no unrelated direct upgrade is hidden.

- [x] **Step 8: Stage the coherent runtime contract for one final policy-compliant commit**

```bash
git add composer.json composer.lock config/debugbar.php tests/Feature/DebugbarConfigurationTest.php docs/plans/current-task-plan.md docs/maintenance/update-decisions.md docs/superpowers/plans/2026-07-19-laravel-debugbar-app-debug.md
git commit -m "chore: add APP_DEBUG-gated Laravel Debugbar"
```

### Task 2: Canonical documentation, full verification, and delivery

**Files:**

- Modify: `docs/maintenance/dependency-inventory.md`
- Modify: `docs/maintenance/runtime-compatibility.md`
- Modify: `docs/development.md`
- Modify: `docs/environment.md`
- Modify: `docs/deployment.md`
- Modify: `docs/plans/current-task-plan.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

**Interfaces:**

- Consumes: exact installed/locked package metadata and Task 1 regression results.
- Produces: canonical package purpose/runtime/deployment/rollback inventory, completed compliance evidence, Russian visitor/developer documentation, final verified Git delivery.

- [x] **Step 1: Update dependency and runtime registries**

Document `fruitcake/laravel-debugbar` as development-only, MIT, auto-discovered, optional, excluded by production `--no-dev`, removable with config/autoload/cache rebuild, and owned by development diagnostics. Record `php-debugbar/php-debugbar` and `php-debugbar/symfony-bridge` only as material transitive development boundaries. Add a runtime row stating exact lock version, PHP `^8.2`, Illuminate `^11|^12|^13`, local/debug-only activation and production/testing denial.

- [x] **Step 2: Update development, environment, deployment, README, and changelog**

Add this operational contract in Russian:

```text
Laravel Debugbar устанавливается только через `require-dev`. В доверенной локальной среде он отображается при `APP_DEBUG=true`; при `APP_DEBUG=false`, а также в `production` и `testing`, его routes/listeners не активируются. Production использует `composer install --no-dev` и `APP_DEBUG=false`. Реальный `.env` приложение не изменяет.
```

README must mention the development panel and add a dated Russian history item without claiming a visitor-facing production toolbar. CHANGELOG adds a separate 19.07.2026 Russian item naming the exact package/config/test/production/rollback behavior.

- [x] **Step 3: Complete the compliance matrix and legacy scan**

Change verified rows to `completed`/`already_compliant`/`not_applicable` only with evidence. Keep any unavailable HTTP/production proof `unresolved`. Search:

```bash
rg -n "barryvdh/laravel-debugbar|Barryvdh\\\\Debugbar|DEBUGBAR_ENABLED|DEBUGBAR_FORCE_ALLOW_ENABLE|force_allow_enable|fruitcake/laravel-debugbar|_debugbar" . --glob '!vendor/**' --glob '!storage/**' --glob '!output/**'
```

Expected: no obsolete package/provider or independent environment override; only intended config/docs/tests/lock references remain.

- [x] **Step 4: Verify environment gates without editing `.env`**

Run each with a fresh non-existent config-cache path under a `mktemp -d` directory:

```bash
APP_ENV=local APP_DEBUG=true APP_CONFIG_CACHE="$debugbar_tmp_dir/local-true.php" php artisan route:list --path=_debugbar --json
APP_ENV=local APP_DEBUG=false APP_CONFIG_CACHE="$debugbar_tmp_dir/local-false.php" php artisan route:list --path=_debugbar --json
APP_ENV=production APP_DEBUG=true APP_CONFIG_CACHE="$debugbar_tmp_dir/production-true.php" php artisan route:list --path=_debugbar --json
```

Expected: local debug returns Debugbar routes; local non-debug and production return an empty route list.

- [x] **Step 5: Run full package, application, and documentation verification**

Run:

```bash
./vendor/bin/pint --dirty --format agent
php artisan test --filter=DebugbarConfigurationTest
php artisan test
composer validate --strict
composer check-platform-reqs
composer install --dry-run --no-dev --no-interaction
composer audit --locked --no-interaction
php artisan project:docs-refresh --check
scripts/check-readme-policy.sh README.md
scripts/check-changelog-policy.sh CHANGELOG.md
git diff --check
```

Expected: all commands pass; production dry-run excludes Debugbar and does not mutate vendor/lock; no npm build is required because no project CSS/JS/Vite file changes.

- [x] **Step 6: Re-read requirements and review final diff/status**

Re-read `docs/requirements/production-operations.md`, `docs/requirements/maintenance-and-upgrades.md`, the approved design, this plan, and `docs/plans/current-task-plan.md`. Confirm no secrets, `.env`, production paths, migrations, cache flushes, public routes, stale config copy, duplicate provider, unfinished code or unrelated dependency updates.

- [ ] **Step 7: Commit documentation/evidence and push `main`**

```bash
git add README.md CHANGELOG.md docs/development.md docs/environment.md docs/deployment.md docs/maintenance/dependency-inventory.md docs/maintenance/runtime-compatibility.md docs/plans/current-task-plan.md
git commit -m "docs: document safe Laravel Debugbar operation"
git status --short --branch
git push origin main
```

Expected: commit and push succeed from clean `main`; any external authentication failure is reported verbatim and not presented as success.

## Выполненная проверка и compliance evidence

| Область | Статус | Доказательство |
| --- | --- | --- |
| Requirements и официальная совместимость | completed | Прочитаны owners maintenance/production; `4.4.0` поддерживает PHP `^8.2`, Illuminate `^11|^12|^13` и Livewire 4 |
| Dependency boundary | completed | В `require-dev` добавлен только direct package; lock добавил ровно `fruitcake/laravel-debugbar 4.4.0`, `php-debugbar/php-debugbar 3.8.0`, `php-debugbar/symfony-bridge 1.1.0` |
| Configuration/security | completed | `APP_DEBUG` — единственный project switch; `force_allow_enable=false`; отдельные `DEBUGBAR_*` variables и provider отсутствуют |
| Environment behavior | completed | Fresh route cache: `local/true=5`, `local/false=0`, `production/true=0`; HTTP `/login`: local true содержит Debugbar, local false не содержит |
| Focused tests/style | completed | TDD RED подтверждён до install; GREEN — 3 tests, 9 assertions; targeted и `--dirty` Pint прошли |
| Composer health/production artifact | completed | Strict validate, dev/non-dev platform requirements, audit и `--no-dev --classmap-authoritative` dry-run прошли; production dry-run удаляет все 3 Debugbar packages |
| Database/storage/import/cache/queue/assets | not_applicable | Нет migration, data/storage writes, importer, persistent cache, session, queue, worker, Vite или npm change |
| Public/auth/privacy/SEO/mobile/admin/premium/regional/legal | already_compliant | Production package отсутствует и fail-closed; application routes/data/permissions/UI contracts не меняются |
| Documentation/policy/legacy search | completed | Canonical registries, operations, README и CHANGELOG обновлены; policies/diff-check прошли; obsolete integration отсутствует |
| Full repository suite | unresolved | Выполнено 1 268 tests: 1 214 passed; 37 failures и 6 errors относятся к существующим Blade violations и отсутствующему unrelated `CacheDomain::UserPortal`, не к Debugbar |
| Git delivery | unresolved | Завершается отдельным clean-index commit/push; внешний отказ аутентификации фиксируется без маскировки |

`npm run build` не запускался: пакет не добавляет project CSS/JS/Vite entry, а browser asset обслуживается самим development package. Реальный `.env`, production database/storage/cache и пользовательские данные не изменялись.
