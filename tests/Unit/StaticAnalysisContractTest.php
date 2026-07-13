<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StaticAnalysisContractTest extends TestCase
{
    public function test_composer_and_ci_run_bounded_larastan_without_a_baseline(): void
    {
        $composer = json_decode(File::get(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
        $workflow = File::get(base_path('.github/workflows/ci.yml'));
        $configPath = base_path('phpstan.neon.dist');

        $this->assertArrayHasKey('analyse', $composer['scripts']);
        $this->assertStringContainsString('phpstan analyse', $composer['scripts']['analyse']);
        $this->assertStringContainsString('composer analyse', $workflow);
        $this->assertFileExists($configPath);

        $config = File::get($configPath);

        foreach ([
            'app/DTOs',
            'app/Enums',
            'app/Services/Operations',
            'app/Services/Admin/AdminAuditRecorder.php',
            'app/Console/Commands/CheckDeploymentReadiness.php',
            'app/Models/AdminAuditEvent.php',
        ] as $path) {
            $this->assertStringContainsString($path, $config);
        }

        $this->assertStringNotContainsString('baseline', mb_strtolower($config));
        $this->assertStringNotContainsString('ignoreErrors', $config);
    }
}
