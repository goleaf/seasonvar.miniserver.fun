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
        $this->assertStringContainsString(
            '->withParallel(timeoutSeconds: 600, maxNumberOfProcess: 4, jobSize: 1)',
            $config,
        );
        $this->assertStringNotContainsString('->withoutParallel()', $config);
    }

    public function test_required_profile_keeps_the_first_audit_backlog_explicit_and_maximum_only(): void
    {
        $required = File::get(base_path('rector.php'));
        $maximum = File::get(base_path('rector-max.php'));

        foreach ([
            'AddOverrideAttributeToOverriddenMethodsRector::class',
            'ReadOnlyClassRector::class',
            'AddTypeToConstRector::class',
            'AddClosureVoidReturnTypeWhereNoReturnRector::class',
            'ClosureToArrowFunctionRector::class',
            'WithoutIncrementingPropertyToWithoutIncrementingAttributeRector::class',
            'TriesPropertyToTriesAttributeRector::class',
            'WithoutTimestampsPropertyToWithoutTimestampsAttributeRector::class',
        ] as $rule) {
            $this->assertStringContainsString($rule, $required);
            $this->assertStringNotContainsString($rule, $maximum);
        }

        $this->assertStringContainsString('without baselining paths', $required);
        $this->assertStringNotContainsString('baseline', strtolower($maximum));
    }

    public function test_backend_ci_delegates_to_required_dry_run_once_and_never_writes(): void
    {
        $script = File::get(base_path('scripts/ci-check.sh'));
        $workflow = File::get(base_path('.github/workflows/ci.yml'));

        $this->assertSame(1, substr_count($script, 'composer rector:check'));
        $this->assertStringNotContainsString('composer rector:fix', $script);
        $this->assertStringNotContainsString('rector process', $workflow);
        $this->assertStringContainsString('path: output/rector/required', $workflow);
        $this->assertStringContainsString("hashFiles('composer.lock', 'rector.php')", $workflow);
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
