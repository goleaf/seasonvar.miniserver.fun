<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\Seasonvar\SeasonvarQueueStatusData;
use App\Models\SeasonvarImportRun;
use App\Models\SourcePage;
use App\Services\Seasonvar\SeasonvarPageClaimManager;
use App\Services\Seasonvar\SeasonvarQueueStatus;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\QueueManager;
use Mockery;
use Tests\TestCase;

class SeasonvarQueueStatusTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertEqualsWithDelta(300, $status->oldestPendingAgeSeconds(), 2);
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
            ->expectsOutputToContain('Глобальный active/last run')
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

    private function mockQueue(int $oldestPendingTimestamp): void
    {
        $queue = Mockery::mock(QueueContract::class);
        $queue->shouldReceive('pendingSize')->with('seasonvar-import')->andReturn(12);
        $queue->shouldReceive('delayedSize')->with('seasonvar-import')->andReturn(2);
        $queue->shouldReceive('reservedSize')->with('seasonvar-import')->andReturn(1);
        $queue->shouldReceive('creationTimeOfOldestPendingJob')
            ->with('seasonvar-import')
            ->andReturn($oldestPendingTimestamp);

        $manager = Mockery::mock(QueueManager::class);
        $manager->shouldReceive('connection')->with('redis')->andReturn($queue);
        $this->app->instance(QueueManager::class, $manager);
    }
}
