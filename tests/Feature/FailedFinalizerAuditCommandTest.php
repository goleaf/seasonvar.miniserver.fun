<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\AuditFailedSeasonvarJobs;
use App\Jobs\FinalizeSeasonvarImportTitleGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FailedFinalizerAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_command_emits_a_safe_read_only_report(): void
    {
        $this->assertTrue(class_exists(AuditFailedSeasonvarJobs::class));

        $job = new FinalizeSeasonvarImportTitleGroup(999_999);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'seasonvar-import',
            'payload' => json_encode([
                'displayName' => $job::class,
                'data' => [
                    'commandName' => $job::class,
                    'command' => serialize($job),
                    'token' => 'private-token',
                ],
            ], JSON_THROW_ON_ERROR),
            'exception' => 'RuntimeException: private exception text https://seasonvar.ru/private',
            'failed_at' => now(),
        ]);

        $exitCode = Artisan::call('app:failed-job-audit', ['--json' => true, '--samples' => '1']);
        $output = Artisan::output();
        $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('complete', $decoded['status']);
        $this->assertTrue($decoded['read_only']);
        $this->assertSame(['target_missing' => 1], $decoded['finalizers']['states']);
        $this->assertSame(0, $decoded['mutations']['retried']);
        $this->assertSame(0, $decoded['mutations']['forgotten']);
        $this->assertSame(0, $decoded['mutations']['cleared']);
        $this->assertSame(0, $decoded['mutations']['dispatched']);
        $this->assertStringNotContainsString('private-token', $output);
        $this->assertStringNotContainsString('private exception text', $output);
        $this->assertStringNotContainsString('seasonvar.ru', $output);
    }

    public function test_human_command_announces_that_no_queue_or_import_state_was_changed(): void
    {
        $this->assertTrue(class_exists(AuditFailedSeasonvarJobs::class));

        $this->artisan('app:failed-job-audit', ['--samples' => '0'])
            ->expectsOutputToContain('Read-only аудит failed jobs')
            ->expectsOutputToContain('Retry, forget, clear, dispatch и import state mutation не выполнялись.')
            ->assertExitCode(0);
    }

    public function test_command_rejects_an_invalid_sample_limit(): void
    {
        $this->assertTrue(class_exists(AuditFailedSeasonvarJobs::class));

        $this->artisan('app:failed-job-audit', ['--samples' => 'private'])
            ->expectsOutputToContain('Количество примеров должно быть целым числом от 0 до 10.')
            ->assertExitCode(1);
    }
}
