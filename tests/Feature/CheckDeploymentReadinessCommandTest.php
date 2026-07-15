<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CatalogTitle;
use App\Services\Operations\DeploymentReadinessChecker;
use App\Services\Operations\FailedJobSummaryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class CheckDeploymentReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_job_summary_is_bounded_and_never_exposes_payload_or_exception_text(): void
    {
        $empty = app(FailedJobSummaryBuilder::class)->build();

        $this->assertSame([
            'total' => 0,
            'jobs' => [],
            'categories' => [],
            'ages' => [],
        ], $empty);

        DB::table('failed_jobs')->insert([
            'uuid' => fake()->uuid(),
            'connection' => 'redis',
            'queue' => 'seasonvar-title-refresh',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\RefreshSeasonvarCatalogTitle',
                'data' => ['token' => 'private-token', 'url' => 'https://seasonvar.ru/private'],
            ], JSON_THROW_ON_ERROR),
            'exception' => 'RuntimeException: token=private-token https://seasonvar.ru/private',
            'failed_at' => now()->subHours(2),
        ]);

        $summary = app(FailedJobSummaryBuilder::class)->build();
        $serialized = json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->assertSame(1, $summary['total']);
        $this->assertSame(['refresh_catalog_title' => 1], $summary['jobs']);
        $this->assertSame(['title_refresh' => 1], $summary['categories']);
        $this->assertSame(['1_to_24_hours' => 1], $summary['ages']);
        $this->assertStringNotContainsString('private-token', $serialized);
        $this->assertStringNotContainsString('seasonvar.ru', $serialized);
        $this->assertStringNotContainsString('RuntimeException', $serialized);
    }

    public function test_failed_job_summary_classifies_the_versioned_cache_warm_queue(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => fake()->uuid(),
            'connection' => 'redis',
            'queue' => 'cache-warm-v2',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\WarmCatalogCaches',
            ], JSON_THROW_ON_ERROR),
            'exception' => 'RuntimeException: private failure',
            'failed_at' => now(),
        ]);

        $summary = app(FailedJobSummaryBuilder::class)->build();

        $this->assertSame(['warm_catalog_cache' => 1], $summary['jobs']);
        $this->assertSame(['cache' => 1], $summary['categories']);
    }

    public function test_checker_reports_unsafe_runtime_missing_index_and_fts_mismatch(): void
    {
        config([
            'app.env' => 'local',
            'app.debug' => true,
            'logging.default' => 'single',
        ]);

        DB::statement('DROP INDEX licensed_media_home_feed_idx');
        CatalogTitle::factory()->create();

        $checks = collect(app(DeploymentReadinessChecker::class)->check())->keyBy(
            fn (object $check): string => $check->name,
        );

        $this->assertSame('fail', $checks->get('environment')->status);
        $this->assertSame('fail', $checks->get('debug')->status);
        $this->assertSame('fail', $checks->get('logging')->status);
        $this->assertSame('fail', $checks->get('required_indexes')->status);
        $this->assertSame('fail', $checks->get('search_index')->status);
    }

    public function test_checker_reports_a_pending_migration_without_running_it(): void
    {
        $migration = database_path('migrations/2999_12_31_235959_deployment_check_probe.php');
        File::put($migration, "<?php\n\nreturn new class extends \\Illuminate\\Database\\Migrations\\Migration {};\n");

        try {
            $check = collect(app(DeploymentReadinessChecker::class)->check())
                ->firstWhere('name', 'migrations');

            $this->assertNotNull($check);
            $this->assertSame('fail', $check->status);
            $this->assertSame(1, $check->metadata['pending_count']);
            $this->assertTrue(File::exists($migration));
        } finally {
            File::delete($migration);
        }
    }

    public function test_json_command_has_a_stable_safe_shape_and_fails_for_unsafe_state(): void
    {
        config([
            'app.env' => 'local',
            'app.debug' => true,
            'logging.default' => 'single',
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => fake()->uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{"displayName":"UnknownJob","token":"private-token"}',
            'exception' => 'private exception text',
            'failed_at' => now(),
        ]);

        $exitCode = Artisan::call('app:deployment-check', ['--json' => true]);
        $output = Artisan::output();
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame('failed', $decoded['status']);
        $this->assertFalse($decoded['ready']);
        $this->assertIsArray($decoded['checks']);
        $this->assertArrayHasKey('name', $decoded['checks'][0]);
        $this->assertArrayHasKey('status', $decoded['checks'][0]);
        $this->assertArrayHasKey('message', $decoded['checks'][0]);
        $this->assertArrayHasKey('duration_ms', $decoded['checks'][0]);
        $this->assertIsInt($decoded['checks'][0]['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $decoded['checks'][0]['duration_ms']);
        $this->assertStringNotContainsString('private-token', $output);
        $this->assertStringNotContainsString('private exception text', $output);
    }
}
