<?php

namespace Tests\Feature;

use App\Services\Integrations\IntegrationDoctor;
use Illuminate\Support\Facades\Artisan;
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
}
