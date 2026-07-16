<?php

namespace Tests\Feature;

use App\Services\Integrations\IntegrationDoctor;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class CheckIntegrationsCommandTest extends TestCase
{
    public function test_it_reports_integration_readiness_without_external_calls(): void
    {
        $this->artisan('integrations:doctor')
            ->expectsOutputToContain('Диагностика интеграций Seasonvar')
            ->expectsOutputToContain('Laravel Boost MCP')
            ->expectsOutputToContain('Context7 MCP')
            ->expectsOutputToContain('Playwright MCP')
            ->expectsOutputToContain('Google Search Console')
            ->expectsOutputToContain('Google Analytics 4')
            ->assertExitCode(0);
    }

    public function test_it_can_output_json(): void
    {
        $exitCode = Artisan::call('integrations:doctor', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"summary"', $output);
        $this->assertStringContainsString('"checks"', $output);
        $this->assertStringContainsString('"laravel_boost_mcp"', $output);
        $this->assertStringContainsString('"context7_mcp"', $output);
        $this->assertStringContainsString('"playwright_mcp"', $output);
    }

    public function test_it_requires_boost_child_processes_to_inherit_the_local_environment(): void
    {
        $check = collect(app(IntegrationDoctor::class)->checks())
            ->firstWhere('key', 'laravel_boost_mcp');

        $this->assertIsArray($check);
        $this->assertSame(IntegrationDoctor::STATUS_OK, $check['status']);
        $this->assertStringContainsString('APP_ENV=local', $check['message']);
    }

    public function test_it_accepts_an_absolute_boost_working_directory_from_another_checkout(): void
    {
        $projectConfig = <<<'TOML'
[mcp_servers.laravel-boost]
command = "php"
args = ["artisan", "boost:mcp", "--env=local"]
env = { APP_ENV = "local" }
cwd = "/srv/seasonvar"
required = false
TOML;
        $files = new class($projectConfig) extends Filesystem
        {
            public function __construct(private readonly string $projectConfig) {}

            public function isFile($file)
            {
                return $file === base_path('.codex/config.toml') || parent::isFile($file);
            }

            public function get($path, $lock = false)
            {
                if ($path === base_path('.codex/config.toml')) {
                    return $this->projectConfig;
                }

                return parent::get($path, $lock);
            }
        };
        $doctor = new IntegrationDoctor($files, app(ExecutableFinder::class));
        $check = collect($doctor->checks())->firstWhere('key', 'laravel_boost_mcp');

        $this->assertIsArray($check);
        $this->assertSame(IntegrationDoctor::STATUS_OK, $check['status']);
    }
}
