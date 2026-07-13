<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ProductionOperationsDocumentationTest extends TestCase
{
    public function test_production_log_rotation_is_versioned_and_bounded(): void
    {
        $logrotate = File::get(base_path('deploy/logrotate/seasonvar'));

        foreach (['daily', 'rotate 14', 'compress', 'missingok', 'notifempty', 'copytruncate'] as $directive) {
            $this->assertStringContainsString($directive, $logrotate);
        }

        $this->assertStringContainsString('storage/logs/*.log', $logrotate);
    }

    public function test_environment_example_has_safe_production_defaults(): void
    {
        $envExample = File::get(base_path('.env.example'));

        foreach ([
            'APP_ENV=production',
            'APP_DEBUG=false',
            'LOG_CHANNEL=stack',
            'LOG_STACK=daily',
            'LOG_LEVEL=warning',
            'LOG_DAILY_DAYS=14',
        ] as $setting) {
            $this->assertStringContainsString($setting, $envExample);
        }
    }

    public function test_deployment_documentation_covers_the_production_rollout_checks(): void
    {
        $deployment = File::get(base_path('docs/deployment.md'));
        $environment = File::get(base_path('docs/environment.md'));

        foreach (['logrotate', 'php artisan config:cache', 'PHP-FPM', 'seasonvar-import-forever.service'] as $instruction) {
            $this->assertStringContainsString($instruction, $deployment);
        }

        $this->assertStringContainsString('deploy/logrotate/seasonvar', $environment);
        $this->assertStringContainsString('php artisan about --only=environment', $environment);
    }
}
