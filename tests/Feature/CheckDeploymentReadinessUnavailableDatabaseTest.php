<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;
use Throwable;

final class CheckDeploymentReadinessUnavailableDatabaseTest extends TestCase
{
    public function test_command_fails_closed_when_the_sqlite_backend_is_unavailable(): void
    {
        $originalDatabase = config('database.connections.sqlite.database');
        $unavailableDirectory = storage_path('framework/testing/missing-'.Str::uuid());
        $unavailableDatabase = $unavailableDirectory.'/database.sqlite';

        File::deleteDirectory($unavailableDirectory);
        config(['database.connections.sqlite.database' => $unavailableDatabase]);
        DB::purge('sqlite');

        try {
            try {
                $exitCode = Artisan::call('app:deployment-check', ['--json' => true]);
            } catch (Throwable) {
                throw new AssertionFailedError('Deployment preflight must fail closed instead of throwing when SQLite is unavailable.');
            }

            $output = Artisan::output();
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
            $decodedChecks = $decoded['checks'] ?? null;
            $this->assertIsArray($decodedChecks);
            $checks = collect($decodedChecks)->keyBy('name');

            $this->assertSame(1, $exitCode);
            $this->assertSame('failed', $decoded['status']);
            $this->assertFalse($decoded['ready']);
            $this->assertSame('fail', $checks->get('migrations')['status']);
            $this->assertSame('fail', $checks->get('sqlite_integrity')['status']);
            $this->assertSame('fail', $checks->get('required_indexes')['status']);
            $this->assertSame('fail', $checks->get('search_index')['status']);
            $this->assertSame('fail', $checks->get('failed_jobs')['status']);
            $this->assertStringNotContainsString($unavailableDatabase, $output);
            $this->assertStringNotContainsString('SQLSTATE', $output);
        } finally {
            DB::purge('sqlite');
            config(['database.connections.sqlite.database' => $originalDatabase]);
            File::deleteDirectory($unavailableDirectory);
        }
    }

    public function test_failed_job_audit_fails_closed_without_disclosing_the_unavailable_database(): void
    {
        $originalDatabase = config('database.connections.sqlite.database');
        $unavailableDirectory = storage_path('framework/testing/missing-audit-'.Str::uuid());
        $unavailableDatabase = $unavailableDirectory.'/database.sqlite';

        File::deleteDirectory($unavailableDirectory);
        config(['database.connections.sqlite.database' => $unavailableDatabase]);
        DB::purge('sqlite');

        try {
            try {
                $exitCode = Artisan::call('app:failed-job-audit', ['--json' => true]);
            } catch (Throwable) {
                throw new AssertionFailedError('Failed-job audit must fail closed instead of throwing when SQLite is unavailable.');
            }

            $output = Artisan::output();
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(1, $exitCode);
            $this->assertSame('failed', $decoded['status']);
            $this->assertTrue($decoded['read_only']);
            $this->assertStringNotContainsString($unavailableDatabase, $output);
            $this->assertStringNotContainsString('SQLSTATE', $output);
        } finally {
            DB::purge('sqlite');
            config(['database.connections.sqlite.database' => $originalDatabase]);
            File::deleteDirectory($unavailableDirectory);
        }
    }
}
