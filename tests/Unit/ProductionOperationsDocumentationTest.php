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

    public function test_cache_warm_rollout_quarantines_expired_legacy_jobs_on_a_versioned_queue(): void
    {
        $cacheConfig = require config_path('cache-architecture.php');
        $envExample = File::get(base_path('.env.example'));
        $unit = File::get(base_path('deploy/systemd/seasonvar-cache-warm-worker.service'));
        $deployment = File::get(base_path('docs/deployment.md'));

        $this->assertSame('cache-warm-v2', $cacheConfig['warming']['queue']);
        $this->assertStringContainsString('CACHE_WARM_QUEUE=cache-warm-v2', $envExample);
        $this->assertStringContainsString('--queue=cache-warm-v2', $unit);
        $this->assertStringContainsString('--timeout=600', $unit);
        $this->assertStringContainsString('retryUntil', $deployment);
        $this->assertStringContainsString('cache-warm-v2', $deployment);
        $this->assertStringContainsString('не очищать', $deployment);
    }

    public function test_failed_job_runbook_requires_read_only_reconciliation_before_specific_disposition(): void
    {
        $deployment = File::get(base_path('docs/deployment.md'));
        $queues = File::get(base_path('docs/queues.md'));

        $this->assertStringContainsString('app:failed-job-audit --json --samples=1', $deployment);
        $this->assertStringContainsString('forget_candidate', $deployment);
        $this->assertStringContainsString('canonical_signal_candidate', $queues);
        $this->assertStringContainsString('без создания PHP object', $queues);
        $this->assertStringNotContainsString('php artisan queue:retry all', $deployment);
    }
}
