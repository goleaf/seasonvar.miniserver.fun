<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\FinalizeSeasonvarImportTitleGroup;
use App\Jobs\FinalizeSeasonvarQueuedImport;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use App\Models\SourcePage;
use App\Services\Operations\FailedFinalizerAuditBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FailedFinalizerAuditBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_maps_group_finalizers_to_current_state_without_mutation(): void
    {
        $this->assertTrue(class_exists(FailedFinalizerAuditBuilder::class));

        Queue::fake();
        $activeRun = $this->queuedRun();
        $terminalRun = $this->queuedRun('failed');
        $terminalGroup = $this->group($activeRun, 'completed');
        $readyGroup = $this->group($activeRun, 'running', expected: 1, prepared: 1);
        $workingGroup = $this->group($activeRun, 'running', expected: 1);
        $inconsistentGroup = $this->group($terminalRun, 'running', expected: 1, prepared: 1);
        $page = SourcePage::factory()->create([
            'import_claim_token' => 'opaque-claim-token',
            'import_claimed_at' => now(),
            'import_claim_expires_at' => now()->addHour(),
            'import_claim_run_id' => $activeRun->id,
        ]);
        $workingGroup->preparedPages()->create([
            'seasonvar_import_run_id' => $activeRun->id,
            'source_page_id' => $page->id,
            'status' => 'preparing',
        ]);

        $this->failed(new FinalizeSeasonvarImportTitleGroup(999_999));
        $this->failed(new FinalizeSeasonvarImportTitleGroup($terminalGroup->id));
        $this->failed(new FinalizeSeasonvarImportTitleGroup($readyGroup->id));
        $this->failed(new FinalizeSeasonvarImportTitleGroup($workingGroup->id));
        $this->failed(new FinalizeSeasonvarImportTitleGroup($inconsistentGroup->id));
        $this->failedPayload(json_encode([
            'displayName' => FinalizeSeasonvarImportTitleGroup::class,
            'data' => [
                'commandName' => FinalizeSeasonvarImportTitleGroup::class,
                'command' => 'not-a-serialized-finalizer',
            ],
        ], JSON_THROW_ON_ERROR));

        $before = DB::table('failed_jobs')->count();
        $report = app(FailedFinalizerAuditBuilder::class)->build(1);

        $this->assertSame('complete', $report['status']);
        $this->assertTrue($report['read_only']);
        $this->assertSame(6, $report['finalizers']['total']);
        $this->assertSame(5, $report['finalizers']['parsed']);
        $this->assertSame(1, $report['finalizers']['unresolved']);
        $this->assertSame(['title_group' => 6], $report['finalizers']['kinds']);
        $this->assertSame([
            'active_work' => 1,
            'canonical_signal_candidate' => 1,
            'parent_inconsistent' => 1,
            'payload_unresolved' => 1,
            'target_missing' => 1,
            'target_terminal' => 1,
        ], $report['finalizers']['states']);
        $this->assertSame([
            'canonical_signal_candidate' => 1,
            'forget_candidate' => 2,
            'manual_review' => 2,
            'retain' => 1,
        ], $report['finalizers']['dispositions']);
        $this->assertCount(6, $report['finalizers']['samples']);
        $this->assertSame([
            'retried' => 0,
            'forgotten' => 0,
            'cleared' => 0,
            'dispatched' => 0,
        ], $report['mutations']);
        $this->assertSame($before, DB::table('failed_jobs')->count());
        $this->assertSame('running', $readyGroup->fresh()->status->value);
        $this->assertSame('opaque-claim-token', $page->fresh()->import_claim_token);
        Queue::assertNothingPushed();
    }

    public function test_it_maps_global_finalizers_and_bounds_safe_samples_per_state(): void
    {
        $this->assertTrue(class_exists(FailedFinalizerAuditBuilder::class));

        $readyRun = $this->queuedRun();
        $workingRun = $this->queuedRun();
        $terminalRun = $this->queuedRun('completed');
        $this->group($workingRun, 'running', expected: 1);

        $this->failed(new FinalizeSeasonvarQueuedImport($readyRun->id));
        $this->failed(new FinalizeSeasonvarQueuedImport($workingRun->id));
        $this->failed(new FinalizeSeasonvarQueuedImport($terminalRun->id));
        $this->failed(new FinalizeSeasonvarQueuedImport(999_999));
        $this->failed(new FinalizeSeasonvarQueuedImport(999_998));

        $report = app(FailedFinalizerAuditBuilder::class)->build(1);
        $serialized = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->assertSame([
            'active_work' => 1,
            'canonical_signal_candidate' => 1,
            'target_missing' => 2,
            'target_terminal' => 1,
        ], $report['finalizers']['states']);
        $this->assertSame(4, count($report['finalizers']['samples']));
        $this->assertSame(['attempts_exhausted' => 5], $report['finalizers']['reasons']);
        $this->assertStringNotContainsString('private-token', $serialized);
        $this->assertStringNotContainsString('private exception text', $serialized);
        $this->assertStringNotContainsString('seasonvar.ru', $serialized);
        $this->assertStringNotContainsString('opaque-claim-token', $serialized);
    }

    private function queuedRun(string $status = 'running'): SeasonvarImportRun
    {
        return SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => $status,
            'started_at' => now(),
            'finished_at' => $status === 'running' ? null : now(),
        ]);
    }

    private function group(
        SeasonvarImportRun $run,
        string $status,
        int $expected = 0,
        int $prepared = 0,
    ): SeasonvarImportTitleGroup {
        return SeasonvarImportTitleGroup::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'group_key_hash' => hash('sha256', (string) Str::uuid()),
            'queue_name' => 'seasonvar-import',
            'status' => $status,
            'expected_pages' => $expected,
            'prepared_pages' => $prepared,
            'failed_pages' => 0,
            'finished_at' => in_array($status, ['completed', 'partial', 'failed'], true) ? now() : null,
        ]);
    }

    private function failed(object $job): void
    {
        $this->failedPayload(json_encode([
            'displayName' => $job::class,
            'data' => [
                'commandName' => $job::class,
                'command' => serialize($job),
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function failedPayload(string $payload): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'seasonvar-import',
            'payload' => $payload,
            'exception' => 'Illuminate\\Queue\\MaxAttemptsExceededException: private exception text https://seasonvar.ru/private?token=private-token',
            'failed_at' => now()->subDays(10),
        ]);
    }
}
