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
use Illuminate\Support\Str;
use Tests\TestCase;

final class CheckDeploymentReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_reports_ready_for_a_safe_runtime_and_consistent_sqlite_database(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'logging.default' => 'daily',
            'logging.channels.daily.level' => 'warning',
            'cache.default' => 'redis-domain',
            'cache.stores.redis-domain.driver' => 'redis',
            'cache.stores.memcached-hot.driver' => 'memcached',
            'session.driver' => 'redis',
            'session.connection' => 'sessions',
            'queue.default' => 'redis',
            'queue.connections.redis.connection' => 'queues',
        ]);

        $exitCode = Artisan::call('app:deployment-check', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $decodedChecks = $decoded['checks'] ?? null;
        $this->assertIsArray($decodedChecks);
        $checks = collect($decodedChecks)->keyBy('name');

        $this->assertSame(0, $exitCode);
        $this->assertSame('ready', $decoded['status']);
        $this->assertTrue($decoded['ready']);
        $this->assertNotContains('fail', $checks->pluck('status')->all());
        $this->assertSame('pass', $checks->get('migrations')['status']);
        $this->assertSame(0, $checks->get('migrations')['metadata']['pending_count']);
        $this->assertSame('pass', $checks->get('sqlite_integrity')['status']);
        $this->assertSame(0, $checks->get('sqlite_integrity')['metadata']['foreign_key_errors']);
    }

    public function test_failed_job_summary_is_bounded_and_never_exposes_payload_or_exception_text(): void
    {
        $empty = app(FailedJobSummaryBuilder::class)->build();

        $this->assertSame([
            'total' => 0,
            'jobs' => [],
            'categories' => [],
            'ages' => [],
            'reasons' => [],
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
        $this->assertSame(['runtime' => 1], $summary['reasons']);
        $this->assertStringNotContainsString('private-token', $serialized);
        $this->assertStringNotContainsString('seasonvar.ru', $serialized);
        $this->assertStringNotContainsString('RuntimeException', $serialized);
    }

    public function test_failed_job_summary_classifies_current_finalizers_and_stable_failure_reasons(): void
    {
        DB::table('failed_jobs')->insert([
            [
                'uuid' => fake()->uuid(),
                'connection' => 'redis',
                'queue' => 'seasonvar-title-refresh',
                'payload' => json_encode([
                    'displayName' => 'App\\Jobs\\FinalizeSeasonvarImportTitleGroup',
                    'data' => ['command' => 'private-payload'],
                ], JSON_THROW_ON_ERROR),
                'exception' => 'Illuminate\\Queue\\MaxAttemptsExceededException: private exception text',
                'failed_at' => now(),
            ],
            [
                'uuid' => fake()->uuid(),
                'connection' => 'redis',
                'queue' => 'seasonvar-import',
                'payload' => json_encode([
                    'displayName' => 'App\\Jobs\\FinalizeSeasonvarQueuedImport',
                    'data' => ['command' => 'private-payload'],
                ], JSON_THROW_ON_ERROR),
                'exception' => 'Illuminate\\Queue\\TimeoutExceededException: private exception text',
                'failed_at' => now(),
            ],
            [
                'uuid' => fake()->uuid(),
                'connection' => 'redis',
                'queue' => 'seasonvar-import',
                'payload' => '{malformed private-token https://seasonvar.ru/private',
                'exception' => 'Unexpected private exception text',
                'failed_at' => now(),
            ],
        ]);

        $summary = app(FailedJobSummaryBuilder::class)->build();
        $serialized = json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->assertSame([
            'finalize_import' => 1,
            'finalize_title_group' => 1,
            'other' => 1,
        ], $summary['jobs']);
        $this->assertSame([
            'attempts_exhausted' => 1,
            'other' => 1,
            'timeout' => 1,
        ], $summary['reasons']);
        $this->assertStringNotContainsString('private-token', $serialized);
        $this->assertStringNotContainsString('private exception text', $serialized);
        $this->assertStringNotContainsString('seasonvar.ru', $serialized);
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

    public function test_failed_job_summary_remains_complete_across_bounded_chunks(): void
    {
        $jobKinds = [
            'App\\Jobs\\ProcessSeasonvarImportPage',
            'App\\Jobs\\RefreshSeasonvarCatalogTitle',
            'App\\Jobs\\WarmCatalogCaches',
        ];
        $queues = ['seasonvar-import', 'seasonvar-title-refresh', 'cache-warm-v2'];
        $failedAt = [now()->subMinutes(10), now()->subHours(2), now()->subDays(9)];
        $rows = [];

        foreach (range(0, 449) as $index) {
            $bucket = $index % 3;
            $rows[] = [
                'uuid' => (string) Str::uuid(),
                'connection' => 'redis',
                'queue' => $queues[$bucket],
                'payload' => json_encode([
                    'displayName' => $jobKinds[$bucket],
                    'data' => ['token' => 'private-token-'.$index],
                ], JSON_THROW_ON_ERROR),
                'exception' => 'private exception '.$index,
                'failed_at' => $failedAt[$bucket],
            ];
        }

        collect($rows)->chunk(90)->each(
            fn ($chunk) => DB::table('failed_jobs')->insert($chunk->all()),
        );

        $summary = app(FailedJobSummaryBuilder::class)->build();
        $serialized = json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->assertSame(450, $summary['total']);
        $this->assertSame([
            'import_page' => 150,
            'refresh_catalog_title' => 150,
            'warm_catalog_cache' => 150,
        ], $summary['jobs']);
        $this->assertSame([
            'cache' => 150,
            'import' => 150,
            'title_refresh' => 150,
        ], $summary['categories']);
        $this->assertSame([
            '1_to_24_hours' => 150,
            'over_7_days' => 150,
            'under_1_hour' => 150,
        ], $summary['ages']);
        $this->assertStringNotContainsString('private-token', $serialized);
        $this->assertStringNotContainsString('private exception', $serialized);
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
        $checks = collect($decoded['checks'])->keyBy('name');

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
        $this->assertSame(1, $checks->get('failed_jobs')['metadata']['reason_buckets']);
        $this->assertStringNotContainsString('private-token', $output);
        $this->assertStringNotContainsString('private exception text', $output);
    }
}
