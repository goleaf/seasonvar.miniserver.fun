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
            ->assertExitCode(0);

        $this->assertSame(1, SeasonvarImportRun::query()->count());
    }

    private function queuedRun(): SeasonvarImportRun
    {
        return SeasonvarImportRun::query()->create([
            'mode' => 'sitemap',
            'execution_mode' => 'queue',
            'status' => 'running',
            'selected' => 20,
            'parsed' => 7,
            'failed' => 1,
            'started_at' => now(),
        ]);
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
