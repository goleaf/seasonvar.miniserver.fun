<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Operations\QueueWorkerHeartbeat;
use App\Support\Cache\CacheMetricsSnapshot;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use RuntimeException;
use Tests\TestCase;

final class QueueWorkerObservabilityTest extends TestCase
{
    public function test_processing_and_failure_events_record_low_cardinality_queue_metrics(): void
    {
        $job = $this->createMock(Job::class);
        $job->method('getQueue')->willReturn('cache-warm');
        $job->method('payload')->willReturn(['createdAt' => now()->subSeconds(2)->getTimestamp()]);
        $heartbeat = app(QueueWorkerHeartbeat::class);

        $heartbeat->processing(new JobProcessing('redis', $job));
        $heartbeat->failed(new JobFailed('redis', $job, new RuntimeException('sensitive message')));

        $metrics = app(CacheMetricsSnapshot::class)->forDate();

        $this->assertSame(1, $metrics['totals']['queue-processed']);
        $this->assertSame(1, $metrics['totals']['queue-wait-count']);
        $this->assertGreaterThanOrEqual(2_000, $metrics['totals']['queue-wait-milliseconds']);
        $this->assertGreaterThanOrEqual(2_000.0, $metrics['totals']['average-queue-wait-ms']);
        $this->assertSame(1, $metrics['totals']['queue-failure']);
    }
}
