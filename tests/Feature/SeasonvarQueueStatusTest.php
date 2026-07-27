<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\Seasonvar\SeasonvarQueueStatusData;
use App\Models\SeasonvarImportRun;
use App\Models\SeasonvarImportTitleGroup;
use App\Models\SourcePage;
use App\Services\Operations\QueueWorkerHeartbeat;
use App\Services\Seasonvar\SeasonvarImportAdminService;
use App\Services\Seasonvar\SeasonvarPageClaimManager;
use App\Services\Seasonvar\SeasonvarQueueStatus;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\ExpectationInterface;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class SeasonvarQueueStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_idle_admin_status_does_not_claim_dispatch_is_running(): void
    {
        $this->mockQueue(
            oldestPendingTimestamp: now()->getTimestamp(),
            pending: 0,
            delayed: 0,
            reserved: 0,
        );

        $dashboard = app(SeasonvarImportAdminService::class)->dashboard();

        $this->assertStringContainsString(
            'Распределение: нет данных',
            $dashboard['queue_status']['dispatch'],
        );
    }

    public function test_sync_run_reports_queue_dispatch_as_not_applicable(): void
    {
        $run = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'sync',
            'status' => 'completed',
            'summary' => ['media_size_only' => true],
            'finished_at' => now(),
        ]);
        $this->mockQueue(
            oldestPendingTimestamp: now()->getTimestamp(),
            pending: 0,
            delayed: 0,
            reserved: 0,
        );

        $status = app(SeasonvarQueueStatus::class)->read();

        $this->assertSame($run->id, $status->runId);
        $this->assertNull($status->dispatchCompleted);
    }

    public function test_it_returns_typed_queue_and_import_state(): void
    {
        $this->assertTrue(class_exists(SeasonvarQueueStatusData::class));
        $this->assertTrue(class_exists(SeasonvarQueueStatus::class));

        $run = $this->queuedRun();
        $claims = app(SeasonvarPageClaimManager::class);

        foreach (SourcePage::factory()->count(2)->create() as $page) {
            $this->assertNotNull($claims->claim($page, $run->id, 3600));
        }

        $this->mockQueue(oldestPendingTimestamp: now()->subMinutes(5)->getTimestamp());

        $status = app(SeasonvarQueueStatus::class)->read();

        $this->assertInstanceOf(SeasonvarQueueStatusData::class, $status);
        $this->assertSame('redis', $status->connection);
        $this->assertSame('seasonvar-import', $status->queue);
        $this->assertSame(12, $status->pending);
        $this->assertSame(2, $status->delayed);
        $this->assertSame(1, $status->reserved);
        $this->assertSame(2, $status->liveClaims);
        $this->assertSame(1, $status->activeRuns);
        $this->assertSame($run->id, $status->runId);
        $this->assertSame('running', $status->runStatus);
        $this->assertSame(20, $status->selected);
        $this->assertSame(7, $status->parsed);
        $this->assertSame(1, $status->failed);
        $this->assertSame('dispatching', $status->phase);
        $this->assertFalse($status->dispatchCompleted);
        $this->assertSame(0, $status->dispatchCursor);
        $this->assertSame('worker_missing', $status->transportState);
        $this->assertEqualsWithDelta(300, $status->oldestPendingAgeSeconds(), 2);
    }

    public function test_zero_queue_with_nonterminal_staging_requires_transport_reconciliation(): void
    {
        $run = $this->queuedRun([
            'summary' => ['dispatch_completed' => true],
            'last_progress_at' => now(),
            'last_heartbeat_at' => now(),
        ]);
        SeasonvarImportTitleGroup::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'group_key_hash' => hash('sha256', 'status-group'),
            'queue_name' => 'seasonvar-import',
            'status' => 'running',
            'expected_pages' => 3,
            'prepared_pages' => 2,
            'applied_pages' => 1,
            'failed_pages' => 0,
        ]);
        $this->mockQueue(
            oldestPendingTimestamp: now()->getTimestamp(),
            pending: 0,
            delayed: 0,
            reserved: 0,
        );

        $status = app(SeasonvarQueueStatus::class)->read();

        $this->assertSame('preparing', $status->phase);
        $this->assertTrue($status->dispatchCompleted);
        $this->assertSame(3, $status->expectedPages);
        $this->assertSame(2, $status->preparedPages);
        $this->assertSame(1, $status->appliedPages);
        $this->assertSame('reconciliation_required', $status->transportState);
        $this->assertSame('nonterminal_staging_without_transport', $status->staleReason);
    }

    public function test_fresh_worker_heartbeat_does_not_hide_stale_durable_progress(): void
    {
        $run = $this->queuedRun([
            'summary' => ['dispatch_completed' => true],
            'last_progress_at' => now()->subHours(3),
            'last_heartbeat_at' => now(),
        ]);
        SeasonvarImportTitleGroup::query()->create([
            'seasonvar_import_run_id' => $run->id,
            'group_key_hash' => hash('sha256', 'stalled-group'),
            'queue_name' => 'seasonvar-import',
            'status' => 'running',
            'expected_pages' => 2,
            'prepared_pages' => 1,
            'applied_pages' => 0,
            'failed_pages' => 0,
        ]);
        $this->mockQueue(oldestPendingTimestamp: now()->subMinute()->getTimestamp());
        app(QueueWorkerHeartbeat::class)->looping(new Looping('redis', 'seasonvar-import'));

        $status = app(SeasonvarQueueStatus::class)->read();

        $this->assertSame('ok', $status->workerStatus);
        $this->assertNotNull($status->workerHeartbeatAt);
        $this->assertSame('stalled_no_progress', $status->transportState);
        $this->assertSame('durable_progress_stale', $status->staleReason);
        $this->assertTrue($status->lastHeartbeatAt?->greaterThan($status->lastProgressAt));
    }

    public function test_status_query_budget_is_bounded_by_aggregate_queries(): void
    {
        $run = $this->queuedRun([
            'summary' => ['dispatch_completed' => true],
            'last_progress_at' => now(),
        ]);
        foreach (range(1, 50) as $group) {
            SeasonvarImportTitleGroup::query()->create([
                'seasonvar_import_run_id' => $run->id,
                'group_key_hash' => hash('sha256', 'budget-group-'.$group),
                'queue_name' => 'seasonvar-import',
                'status' => 'completed',
                'expected_pages' => 10,
                'prepared_pages' => 10,
                'applied_pages' => 10,
                'failed_pages' => 0,
            ]);
        }
        $this->mockQueue(oldestPendingTimestamp: now()->getTimestamp());

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(SeasonvarQueueStatus::class)->read();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(8, count($queries));
    }

    public function test_status_option_reports_state_without_dispatching_import(): void
    {
        $this->assertTrue(class_exists(SeasonvarQueueStatus::class));

        $this->queuedRun();
        $this->mockQueue(oldestPendingTimestamp: now()->subMinute()->getTimestamp());

        $this->artisan('seasonvar:import', ['--status' => true])
            ->expectsOutputToContain('Очередь Seasonvar')
            ->expectsOutputToContain('Ожидают обработки')
            ->expectsOutputToContain('Активных queued runs')
            ->expectsOutputToContain('Активный/последний run')
            ->expectsOutputToContain('Фаза импорта')
            ->expectsOutputToContain('Состояние транспорта')
            ->expectsOutputToContain('Последний реальный прогресс')
            ->expectsOutputToContain('Staging подготовлено')
            ->assertExitCode(0);

        $this->assertSame(1, SeasonvarImportRun::query()->count());
    }

    public function test_it_reports_the_latest_active_global_run_independently_from_live_claim_ranking(): void
    {
        $dominantRun = $this->queuedRun([
            'selected' => 100,
            'parsed' => 40,
            'failed' => 3,
        ]);
        $claims = app(SeasonvarPageClaimManager::class);

        foreach (SourcePage::factory()->count(2)->create() as $page) {
            $this->assertNotNull($claims->claim($page, $dominantRun->id, 3600));
        }

        $newerRun = $this->queuedRun([
            'selected' => 5,
            'parsed' => 0,
            'failed' => 0,
        ]);
        $newerPage = SourcePage::factory()->create();
        $this->assertNotNull($claims->claim($newerPage, $newerRun->id, 3600));
        $this->mockQueue(oldestPendingTimestamp: now()->subMinute()->getTimestamp());

        $status = app(SeasonvarQueueStatus::class)->read();

        $this->assertSame($newerRun->id, $status->runId);
        $this->assertSame(2, $status->activeRuns);
        $this->assertSame(5, $status->selected);
        $this->assertSame(0, $status->parsed);
        $this->assertSame(0, $status->failed);
    }

    public function test_it_counts_active_queue_runs_and_keeps_the_latest_global_coordinator_primary(): void
    {
        $running = $this->queuedRun();
        $page = SourcePage::factory()->create();
        $this->assertNotNull(app(SeasonvarPageClaimManager::class)->claim($page, $running->id, 3600));
        $coordinator = SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'queued',
        ]);
        $this->mockQueue(oldestPendingTimestamp: now()->getTimestamp());

        $status = app(SeasonvarQueueStatus::class)->read();

        $this->assertSame(2, $status->activeRuns);
        $this->assertSame($coordinator->id, $status->runId);
    }

    public function test_it_uses_the_newer_run_when_active_runs_have_equal_live_claims(): void
    {
        $this->queuedRun();
        $newerRun = $this->queuedRun([
            'selected' => 8,
            'parsed' => 3,
            'failed' => 1,
        ]);
        $this->mockQueue(oldestPendingTimestamp: now()->getTimestamp());

        $status = app(SeasonvarQueueStatus::class)->read();

        $this->assertSame(2, $status->activeRuns);
        $this->assertSame($newerRun->id, $status->runId);
        $this->assertSame(8, $status->selected);
        $this->assertSame(3, $status->parsed);
        $this->assertSame(1, $status->failed);
    }

    public function test_it_falls_back_to_the_latest_queued_run_when_no_active_run_exists(): void
    {
        $this->queuedRun([
            'status' => 'completed',
            'selected' => 50,
            'parsed' => 50,
            'failed' => 0,
        ]);
        $latestRun = $this->queuedRun([
            'status' => 'failed',
            'selected' => 11,
            'parsed' => 4,
            'failed' => 7,
        ]);
        $this->mockQueue(oldestPendingTimestamp: now()->getTimestamp());

        $status = app(SeasonvarQueueStatus::class)->read();

        $this->assertSame(0, $status->activeRuns);
        $this->assertSame($latestRun->id, $status->runId);
        $this->assertSame('failed', $status->runStatus);
        $this->assertSame(11, $status->selected);
        $this->assertSame(4, $status->parsed);
        $this->assertSame(7, $status->failed);
    }

    public function test_it_observes_an_active_title_refresh_run_and_its_exact_transport(): void
    {
        $completedGlobalRun = $this->queuedRun([
            'status' => 'completed',
            'execution_mode' => 'sync',
            'finished_at' => now(),
        ]);
        $unrelatedPage = SourcePage::factory()->create();
        $this->assertNotNull(app(SeasonvarPageClaimManager::class)->claim(
            $unrelatedPage,
            $completedGlobalRun->id,
            3600,
        ));
        $visitorRun = SeasonvarImportRun::query()->create([
            'mode' => 'url',
            'execution_mode' => 'queue',
            'status' => 'running',
            'selected' => 2,
            'parsed' => 1,
            'started_at' => now(),
            'last_progress_at' => now(),
            'summary' => [
                'provider' => 'seasonvar',
                'queue' => 'seasonvar-title-refresh',
            ],
        ]);
        SeasonvarImportTitleGroup::query()->create([
            'seasonvar_import_run_id' => $visitorRun->id,
            'group_key_hash' => hash('sha256', 'visitor-status-group'),
            'queue_name' => 'seasonvar-title-refresh',
            'status' => 'running',
            'expected_pages' => 2,
            'prepared_pages' => 1,
            'applied_pages' => 0,
            'failed_pages' => 0,
        ]);
        $visitorPage = SourcePage::factory()->create();
        $this->assertNotNull(app(SeasonvarPageClaimManager::class)->claim(
            $visitorPage,
            $visitorRun->id,
            3600,
        ));
        $this->mockQueue(oldestPendingTimestamp: now()->getTimestamp());

        $status = app(SeasonvarQueueStatus::class)->read();

        $this->assertSame($visitorRun->id, $status->runId);
        $this->assertSame('seasonvar-title-refresh', $status->queue);
        $this->assertSame(1, $status->liveClaims);
        $this->assertSame(1, $status->activeRuns);
        $this->assertTrue($status->dispatchCompleted);
        $this->assertSame('preparing', $status->phase);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function queuedRun(array $attributes = []): SeasonvarImportRun
    {
        return SeasonvarImportRun::query()->create(array_merge([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'running',
            'selected' => 20,
            'parsed' => 7,
            'failed' => 1,
            'started_at' => now(),
        ], $attributes));
    }

    private function mockQueue(
        int $oldestPendingTimestamp,
        int $pending = 12,
        int $delayed = 2,
        int $reserved = 1,
    ): void {
        $queue = Mockery::mock(QueueContract::class);
        $this->expectation($queue, 'pendingSize')->andReturn($pending);
        $this->expectation($queue, 'delayedSize')->andReturn($delayed);
        $this->expectation($queue, 'reservedSize')->andReturn($reserved);
        $this->expectation($queue, 'creationTimeOfOldestPendingJob')->andReturn($oldestPendingTimestamp);

        $manager = Mockery::mock(QueueManager::class);
        $this->expectation($manager, 'connection')->andReturn($queue);
        $this->app->instance(QueueManager::class, $manager);
    }

    private function expectation(MockInterface $mock, string $method): ExpectationInterface
    {
        $expectation = $mock->shouldReceive($method);

        if (! $expectation instanceof ExpectationInterface) {
            throw new RuntimeException("Mock expectation for [{$method}] was not created.");
        }

        return $expectation;
    }
}
