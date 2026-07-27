<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class CiQualityGateContractTest extends TestCase
{
    public function test_workflow_pins_supported_actions_and_runner_image(): void
    {
        $workflow = File::get(base_path('.github/workflows/ci.yml'));

        foreach ([
            'actions/checkout@df4cb1c069e1874edd31b4311f1884172cec0e10 # v6',
            'actions/cache@caa296126883cff596d87d8935842f9db880ef25 # v5',
            'actions/setup-node@249970729cb0ef3589644e2896645e5dc5ba9c38 # v6',
            'actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a # v7',
            'shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240 # v2',
        ] as $action) {
            $this->assertStringContainsString($action, $workflow);
        }

        $this->assertSame(3, substr_count($workflow, 'runs-on: ubuntu-24.04'));
        $this->assertSame(3, substr_count($workflow, 'persist-credentials: false'));
        $this->assertStringNotContainsString('runs-on: ubuntu-latest', $workflow);

        foreach ([
            'actions/checkout@v6',
            'actions/checkout@v4',
            'actions/checkout@v7',
            'actions/cache@v5',
            'actions/cache@v4',
            'actions/cache@v6',
            'actions/setup-node@v6',
            'actions/setup-node@v7',
            'actions/upload-artifact@v7',
            'actions/upload-artifact@v4',
            'shivammathur/setup-php@v2',
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
        $this->assertGreaterThanOrEqual(3, substr_count($qualityGate, 'clear_laravel_cache_artifacts'));
        $this->assertStringContainsString('find "$VIEW_COMPILED_PATH" -maxdepth 1 -type f -delete', $qualityGate);
    }

    public function test_workflow_installs_the_image_extension_used_by_backend_and_browser_fixtures(): void
    {
        $workflow = File::get(base_path('.github/workflows/ci.yml'));

        $this->assertStringContainsString(
            'extensions: mbstring, dom, fileinfo, pdo_sqlite, sqlite3, gd, redis, memcached',
            $workflow,
        );
        $this->assertStringContainsString(
            'extensions: mbstring, dom, fileinfo, pdo_sqlite, sqlite3, gd',
            $workflow,
        );
    }

    public function test_unknown_profile_is_rejected_without_running_a_check(): void
    {
        $process = new Process(['bash', base_path('scripts/ci-check.sh'), 'unsupported']);
        $process->run();

        $this->assertSame(2, $process->getExitCode());
        $this->assertStringContainsString('Неизвестный профиль проверки CI', $process->getErrorOutput());
    }

    public function test_backend_profile_cleans_isolated_artifacts_after_all_backend_checks(): void
    {
        $qualityGate = File::get(base_path('scripts/ci-check.sh'));
        $pint = json_decode(File::get(base_path('pint.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertStringContainsString("run_backend() (\n    trap clear_laravel_cache_artifacts EXIT", $qualityGate);
        $this->assertContains('output', $pint['exclude']);
    }

    public function test_backend_profile_uses_in_memory_sqlite_before_booting_artisan(): void
    {
        $qualityGate = File::get(base_path('scripts/ci-check.sh'));
        $backendPosition = strpos($qualityGate, 'run_backend() (');
        $connectionPosition = strpos($qualityGate, 'export DB_CONNECTION=sqlite', $backendPosition);
        $databasePosition = strpos($qualityGate, 'export DB_DATABASE=:memory:', $backendPosition);
        $documentationPosition = strpos($qualityGate, 'run_docs', $backendPosition);

        $this->assertIsInt($backendPosition);
        $this->assertIsInt($connectionPosition);
        $this->assertIsInt($databasePosition);
        $this->assertIsInt($documentationPosition);
        $this->assertTrue($backendPosition < $connectionPosition);
        $this->assertTrue($connectionPosition < $databasePosition);
        $this->assertTrue($databasePosition < $documentationPosition);
    }

    public function test_backend_profile_runs_required_rector_after_pint_and_before_php_analysis(): void
    {
        $qualityGate = File::get(base_path('scripts/ci-check.sh'));
        $pintPosition = strpos($qualityGate, './vendor/bin/pint --test --format=agent');
        $rectorPosition = strpos($qualityGate, 'composer rector:check');
        $syntaxPosition = strpos($qualityGate, "find app bootstrap config database routes tests -path 'bootstrap/cache' -prune -o -type f -name '*.php'");
        $analysisPosition = strpos($qualityGate, 'composer analyse');

        $this->assertIsInt($pintPosition);
        $this->assertIsInt($rectorPosition);
        $this->assertIsInt($syntaxPosition);
        $this->assertIsInt($analysisPosition);
        $this->assertTrue($pintPosition < $rectorPosition);
        $this->assertTrue($rectorPosition < $syntaxPosition);
        $this->assertTrue($syntaxPosition < $analysisPosition);
        $this->assertSame(1, substr_count($qualityGate, 'composer rector:check'));
    }

    public function test_backend_syntax_check_ignores_generated_laravel_cache_manifests(): void
    {
        $qualityGate = File::get(base_path('scripts/ci-check.sh'));

        $this->assertStringContainsString("-path 'bootstrap/cache' -prune", $qualityGate);
        $this->assertStringContainsString("-o -type f -name '*.php' -print0", $qualityGate);
    }

    public function test_browser_profile_exports_one_absolute_fixture_database_path(): void
    {
        $qualityGate = File::get(base_path('scripts/ci-check.sh'));

        $this->assertStringContainsString('$repo_root/output/playwright/$ci_run_id/browser.sqlite', $qualityGate);
        $this->assertStringContainsString('export DB_DATABASE="$browser_database"', $qualityGate);
        $this->assertStringContainsString('export BROWSER_TEST_DATABASE="$browser_database"', $qualityGate);

        foreach (['CACHE_DOMAIN_STORE', 'CACHE_HOT_STORE', 'CACHE_LOCK_STORE', 'CACHE_METRICS_STORE', 'CACHE_VERSION_STORE'] as $store) {
            $this->assertStringContainsString("export {$store}=array", $qualityGate);
        }
    }

    public function test_all_profiles_prepare_isolated_manifest_paths_before_artisan_bootstrap(): void
    {
        $qualityGate = File::get(base_path('scripts/ci-check.sh'));
        $mkdirPosition = strpos($qualityGate, 'mkdir -p "$ci_output_root" "$VIEW_COMPILED_PATH"');
        $initialCleanupPosition = strpos($qualityGate, "\nclear_laravel_cache_artifacts\n\nrun_laravel_cache_validation");

        $this->assertIsInt($mkdirPosition);
        $this->assertIsInt($initialCleanupPosition);
        $this->assertLessThan($initialCleanupPosition, $mkdirPosition);
    }

    public function test_browser_profile_cleans_isolated_artifacts_after_browser_exit(): void
    {
        $qualityGate = File::get(base_path('scripts/ci-check.sh'));

        $this->assertStringContainsString("run_browser() (\n    trap clear_laravel_cache_artifacts EXIT", $qualityGate);
    }

    public function test_default_generated_paths_are_scoped_to_one_gate_process(): void
    {
        $qualityGate = File::get(base_path('scripts/ci-check.sh'));

        $this->assertStringContainsString('ci_run_id="${SEASONVAR_CI_RUN_ID:-$$}"', $qualityGate);
        $this->assertStringContainsString('$repo_root/output/ci/$ci_run_id', $qualityGate);
        $this->assertStringContainsString('$repo_root/output/playwright/$ci_run_id/browser.sqlite', $qualityGate);
    }

    public function test_all_profiles_ignore_a_shared_file_based_maintenance_marker(): void
    {
        $qualityGate = File::get(base_path('scripts/ci-check.sh'));

        $this->assertStringContainsString(
            'export APP_MAINTENANCE_DRIVER="${APP_MAINTENANCE_DRIVER:-cache}"',
            $qualityGate,
        );
        $this->assertStringContainsString(
            'export APP_MAINTENANCE_STORE="${APP_MAINTENANCE_STORE:-array}"',
            $qualityGate,
        );
    }

    public function test_browser_profile_exports_process_scoped_runtime_and_port_defaults(): void
    {
        $qualityGate = File::get(base_path('scripts/ci-check.sh'));

        $this->assertStringContainsString('export PLAYWRIGHT_PORT="$browser_port"', $qualityGate);
        $this->assertStringContainsString('export PLAYWRIGHT_RUNTIME_NAME="${PLAYWRIGHT_RUNTIME_NAME:-ci-$ci_run_id}"', $qualityGate);
        $this->assertStringContainsString('export APP_URL="http://127.0.0.1:$browser_port"', $qualityGate);
        $this->assertStringNotContainsString('PLAYWRIGHT_APP_URL', $qualityGate);
    }

    public function test_changelog_russian_policy_runs_before_commit_and_in_backend_ci(): void
    {
        $hook = File::get(base_path('.githooks/pre-commit'));
        $qualityGate = File::get(base_path('scripts/ci-check.sh'));

        $this->assertStringContainsString(
            '"$repo_root/scripts/check-changelog-policy.sh" --staged',
            $hook,
        );
        $this->assertStringContainsString(
            'bash scripts/check-changelog-policy.sh CHANGELOG.md',
            $qualityGate,
        );
    }

    public function test_pre_commit_allows_partial_commit_with_unrelated_dirty_files(): void
    {
        $repositoryPath = sys_get_temp_dir().'/seasonvar-partial-commit-'.bin2hex(random_bytes(6));

        try {
            File::ensureDirectoryExists($repositoryPath.'/.githooks/lib');
            File::ensureDirectoryExists($repositoryPath.'/scripts');
            File::put(
                $repositoryPath.'/.githooks/pre-commit',
                File::get(base_path('.githooks/pre-commit')),
            );
            File::put(
                $repositoryPath.'/.githooks/lib/git-guard.sh',
                File::get(base_path('.githooks/lib/git-guard.sh')),
            );
            File::put(
                $repositoryPath.'/scripts/task-workspace-lease.sh',
                File::get(base_path('scripts/task-workspace-lease.sh')),
            );

            foreach ([
                'scripts/update-changelog-for-staged-code.sh',
                'scripts/check-readme-policy.sh',
                'scripts/check-changelog-policy.sh',
            ] as $script) {
                File::put($repositoryPath.'/'.$script, "#!/usr/bin/env bash\nexit 0\n");
                chmod($repositoryPath.'/'.$script, 0755);
            }

            File::put($repositoryPath.'/scripts/ci-check.sh', "#!/usr/bin/env bash\nexit 0\n");
            chmod($repositoryPath.'/.githooks/pre-commit', 0755);
            chmod($repositoryPath.'/scripts/task-workspace-lease.sh', 0755);

            $this->runGit($repositoryPath, 'init', '-b', 'main');
            $this->runGit($repositoryPath, 'config', 'user.name', 'Seasonvar Test');
            $this->runGit($repositoryPath, 'config', 'user.email', 'seasonvar@example.com');
            File::put($repositoryPath.'/tracked.txt', "исходное состояние\n");
            $this->runGit($repositoryPath, 'add', '--', 'tracked.txt');
            $this->runGit($repositoryPath, 'commit', '-m', 'Исходное состояние');

            File::put($repositoryPath.'/staged.txt', "подготовлено\n");
            $this->runGit($repositoryPath, 'add', '--', 'staged.txt');
            File::append($repositoryPath.'/tracked.txt', "не в индексе\n");
            File::put($repositoryPath.'/untracked.txt', "не отслеживается\n");

            $acquire = new Process(
                ['bash', 'scripts/task-workspace-lease.sh', 'acquire', 'partial-task'],
                $repositoryPath,
            );
            $acquire->run();

            $this->assertTrue($acquire->isSuccessful(), $acquire->getErrorOutput());
            preg_match(
                '/^SEASONVAR_TASK_LEASE_TOKEN=([a-f0-9]{64})$/m',
                $acquire->getOutput(),
                $tokenMatches,
            );
            $token = $tokenMatches[1] ?? '';
            $environment = [
                'SEASONVAR_TASK_ID' => 'partial-task',
                'SEASONVAR_TASK_LEASE_TOKEN' => $token,
            ];

            $declaration = new Process(
                ['bash', 'scripts/task-workspace-lease.sh', 'declare-paths', 'partial-task'],
                $repositoryPath,
                $environment,
            );
            $declaration->setInput("staged.txt\0");
            $declaration->run();

            $this->assertTrue($declaration->isSuccessful(), $declaration->getErrorOutput());

            $approval = new Process(
                ['bash', 'scripts/task-workspace-lease.sh', 'approve-index', 'partial-task'],
                $repositoryPath,
                $environment,
            );
            $approval->run();

            $this->assertTrue($approval->isSuccessful(), $approval->getErrorOutput());

            $process = new Process(
                ['bash', '.githooks/pre-commit'],
                $repositoryPath,
                $environment,
            );
            $process->run();

            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        } finally {
            File::deleteDirectory($repositoryPath);
        }
    }

    public function test_pre_commit_rejects_a_missing_owner_before_the_changelog_updater_can_mutate(): void
    {
        $repositoryPath = sys_get_temp_dir().'/seasonvar-owner-guard-'.bin2hex(random_bytes(6));

        try {
            File::ensureDirectoryExists($repositoryPath.'/.githooks/lib');
            File::ensureDirectoryExists($repositoryPath.'/scripts');
            File::put(
                $repositoryPath.'/.githooks/pre-commit',
                File::get(base_path('.githooks/pre-commit')),
            );
            File::put(
                $repositoryPath.'/.githooks/lib/git-guard.sh',
                File::get(base_path('.githooks/lib/git-guard.sh')),
            );
            File::put(
                $repositoryPath.'/scripts/task-workspace-lease.sh',
                File::get(base_path('scripts/task-workspace-lease.sh')),
            );
            File::put(
                $repositoryPath.'/scripts/update-changelog-for-staged-code.sh',
                "#!/usr/bin/env bash\nprintf 'unexpected mutation\\n' > updater-ran.txt\n",
            );

            foreach ([
                'scripts/ci-check.sh',
                'scripts/check-readme-policy.sh',
                'scripts/check-changelog-policy.sh',
            ] as $script) {
                File::put($repositoryPath.'/'.$script, "#!/usr/bin/env bash\nexit 0\n");
            }

            chmod($repositoryPath.'/.githooks/pre-commit', 0755);
            chmod($repositoryPath.'/scripts/task-workspace-lease.sh', 0755);
            chmod($repositoryPath.'/scripts/update-changelog-for-staged-code.sh', 0755);

            $this->runGit($repositoryPath, 'init', '-b', 'main');
            $this->runGit($repositoryPath, 'config', 'user.name', 'Seasonvar Test');
            $this->runGit($repositoryPath, 'config', 'user.email', 'seasonvar@example.com');
            File::put($repositoryPath.'/tracked.txt', "исходное состояние\n");
            $this->runGit($repositoryPath, 'add', '--', 'tracked.txt');
            $this->runGit($repositoryPath, 'commit', '-m', 'Исходное состояние');
            File::put($repositoryPath.'/staged.txt', "подготовлено\n");
            $this->runGit($repositoryPath, 'add', '--', 'staged.txt');

            $process = new Process(
                ['bash', '.githooks/pre-commit'],
                $repositoryPath,
                [
                    'SEASONVAR_TASK_ID' => false,
                    'SEASONVAR_TASK_LEASE_TOKEN' => false,
                ],
            );
            $process->run();

            $this->assertFalse($process->isSuccessful());
            $this->assertFileDoesNotExist($repositoryPath.'/updater-ran.txt');
            $this->assertStringContainsString(
                'SEASONVAR_TASK_ID',
                $process->getErrorOutput(),
            );
            $this->assertStringNotContainsString('token_sha256', $process->getErrorOutput());
        } finally {
            File::deleteDirectory($repositoryPath);
        }
    }

    public function test_automatic_changelog_update_runs_after_guards_and_before_validation(): void
    {
        $hook = File::get(base_path('.githooks/pre-commit'));

        $guardPosition = strpos($hook, 'seasonvar_git_guard_require_safe_paths staged');
        $updaterPosition = strpos(
            $hook,
            '"$repo_root/scripts/update-changelog-for-staged-code.sh"',
        );
        $ownerPosition = strpos($hook, 'seasonvar_git_guard_require_workspace_lease');
        $pathsPosition = strpos($hook, 'seasonvar_git_guard_require_declared_paths');
        $approvalPosition = strpos($hook, 'seasonvar_git_guard_require_approved_index');
        $documentationPosition = strpos($hook, 'bash "$repo_root/scripts/ci-check.sh" docs');
        $readmePosition = strpos($hook, 'check-readme-policy.sh');
        $changelogPosition = strpos($hook, 'check-changelog-policy.sh');

        $this->assertIsInt($guardPosition);
        $this->assertIsInt($updaterPosition);
        $this->assertIsInt($ownerPosition);
        $this->assertIsInt($pathsPosition);
        $this->assertIsInt($approvalPosition);
        $this->assertIsInt($documentationPosition);
        $this->assertIsInt($readmePosition);
        $this->assertIsInt($changelogPosition);
        $this->assertTrue($guardPosition < $ownerPosition);
        $this->assertTrue($ownerPosition < $updaterPosition);
        $this->assertTrue($updaterPosition < $pathsPosition);
        $this->assertTrue($pathsPosition < $approvalPosition);
        $this->assertTrue($approvalPosition < $documentationPosition);
        $this->assertTrue($updaterPosition < $readmePosition);
        $this->assertTrue($updaterPosition < $changelogPosition);
        $this->assertSame(
            2,
            substr_count($hook, 'seasonvar_git_guard_require_approved_index'),
        );
        $this->assertStringNotContainsString(
            'seasonvar_git_guard_require_no_unstaged_changes',
            $hook,
        );
        $this->assertStringNotContainsString(
            'seasonvar_git_guard_require_no_untracked_files',
            $hook,
        );
    }

    public function test_documentation_freshness_gate_runs_before_commit_and_in_backend_ci(): void
    {
        $hook = File::get(base_path('.githooks/pre-commit'));
        $qualityGate = File::get(base_path('scripts/ci-check.sh'));

        $guardPosition = strpos($hook, 'seasonvar_git_guard_require_safe_paths staged');
        $documentationPosition = strpos($hook, 'bash "$repo_root/scripts/ci-check.sh" docs');
        $readmePosition = strpos($hook, 'check-readme-policy.sh');

        $this->assertIsInt($guardPosition);
        $this->assertIsInt($documentationPosition);
        $this->assertIsInt($readmePosition);
        $this->assertTrue($guardPosition < $documentationPosition);
        $this->assertTrue($documentationPosition < $readmePosition);

        $this->assertStringContainsString("run_docs() (\n    trap clear_laravel_cache_artifacts EXIT\n    export DB_CONNECTION=sqlite\n    export DB_DATABASE=:memory:", $qualityGate);
        $this->assertStringContainsString(
            'export PROJECT_DOCS_PUBLIC_BASE_URL="${PROJECT_DOCS_PUBLIC_BASE_URL:-https://seasonvar.miniserver.fun}"',
            $qualityGate,
        );
        $currentPlanPosition = strpos(
            $qualityGate,
            'bash scripts/check-current-plan-policy.sh docs/plans/current-task-plan.md',
        );
        $managedDocsPosition = strpos(
            $qualityGate,
            'php artisan project:docs-refresh --check --no-interaction',
        );

        $this->assertIsInt($currentPlanPosition);
        $this->assertIsInt($managedDocsPosition);
        $this->assertTrue($currentPlanPosition < $managedDocsPosition);
        $this->assertStringContainsString('php artisan project:docs-refresh --check --no-interaction', $qualityGate);
        $this->assertStringContainsString("    docs)\n        run_docs", $qualityGate);
        $this->assertStringContainsString("    run_docs\n    run_laravel_cache_validation", $qualityGate);
    }

    public function test_pre_push_runs_the_same_local_quality_gate_before_upload(): void
    {
        $hook = File::get(base_path('.githooks/pre-push'));
        $guard = File::get(base_path('.githooks/lib/git-guard.sh'));

        $ownerPosition = strpos($hook, 'seasonvar_git_guard_require_workspace_lease');
        $safePathsPosition = strpos($hook, 'seasonvar_git_guard_require_safe_paths tracked');
        $cleanTreePosition = strpos($hook, 'seasonvar_git_guard_require_clean_tree');
        $qualityGatePosition = strpos($hook, 'bash "$repo_root/scripts/ci-check.sh" pre-push');

        $this->assertIsInt($ownerPosition);
        $this->assertIsInt($safePathsPosition);
        $this->assertIsInt($cleanTreePosition);
        $this->assertIsInt($qualityGatePosition);
        $this->assertTrue($ownerPosition < $safePathsPosition);
        $this->assertTrue($safePathsPosition < $cleanTreePosition);
        $this->assertTrue($cleanTreePosition < $qualityGatePosition);
        $this->assertStringContainsString('seasonvar_git_guard_require_clean_tree', $hook);
        $this->assertStringContainsString('seasonvar_git_guard_require_workspace_lease()', $guard);
        $this->assertStringContainsString('seasonvar_git_guard_require_declared_paths()', $guard);
        $this->assertStringContainsString('seasonvar_git_guard_require_approved_index()', $guard);
        $this->assertStringContainsString('seasonvar_git_guard_require_clean_tree()', $guard);
        $this->assertStringContainsString('bash "$repo_root/scripts/ci-check.sh" pre-push', $hook);
        $this->assertStringNotContainsString('seasonvar_git_guard_require_no_unstaged_changes()', $guard);
        $this->assertStringNotContainsString('seasonvar_git_guard_require_no_untracked_files()', $guard);
    }

    public function test_git_doctor_is_exposed_without_replacing_versioned_hooks(): void
    {
        $composer = json_decode(
            File::get(base_path('composer.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('bash scripts/git-doctor.sh', $composer['scripts']['git:doctor'] ?? null);
        $this->assertFileExists(base_path('scripts/git-doctor.sh'));
        $this->assertTrue(is_executable(base_path('scripts/git-doctor.sh')));

        $preCommit = File::get(base_path('.githooks/pre-commit'));
        $prePush = File::get(base_path('.githooks/pre-push'));

        $this->assertStringContainsString('seasonvar_git_guard_require_safe_paths staged', $preCommit);
        $this->assertStringContainsString('seasonvar_git_guard_require_clean_tree', $prePush);
    }

    private function runGit(string $repositoryPath, string ...$arguments): void
    {
        $process = new Process(['git', ...$arguments], $repositoryPath);
        $process->mustRun();
    }
}
