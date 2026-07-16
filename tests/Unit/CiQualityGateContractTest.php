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
