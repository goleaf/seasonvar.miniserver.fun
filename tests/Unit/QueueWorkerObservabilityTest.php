<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Operations\QueueWorkerHeartbeat;
use App\Support\Cache\CacheMetricsSnapshot;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\QueueManager;
use RuntimeException;
use Tests\TestCase;

final class QueueWorkerObservabilityTest extends TestCase
{
    public function test_missing_queue_configuration_uses_the_versioned_cache_queue(): void
    {
        config([
            'cache-architecture.stores.domain' => 'array',
            'cache-architecture.stores.versions' => 'array',
            'cache-architecture.warming.connection' => 'redis',
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => 'default',
            'seasonvar.queue.connection' => 'redis',
            'seasonvar.queue.queue' => 'seasonvar-import',
            'seasonvar.title_refresh.queue' => 'seasonvar-title-refresh',
        ]);
        $warming = (array) config('cache-architecture.warming');
        unset($warming['queue']);
        config(['cache-architecture.warming' => $warming]);

        $queue = $this->createMock(QueueContract::class);
        $queue->method('pendingSize')->willReturn(0);
        $queue->method('delayedSize')->willReturn(0);
        $queue->method('reservedSize')->willReturn(0);
        $queue->method('creationTimeOfOldestPendingJob')->willReturn(null);

        $manager = $this->createMock(QueueManager::class);
        $manager->method('connection')->with('redis')->willReturn($queue);
        $this->app->instance(QueueManager::class, $manager);

        $status = app(QueueWorkerHeartbeat::class)->status();

        $this->assertSame('cache-warm-v2', $status['queues']['cache_warm']['queue']);
    }

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

    public function test_status_reports_each_critical_queue_without_sharing_one_heartbeat_between_worker_pools(): void
    {
        config([
            'cache-architecture.stores.domain' => 'array',
            'cache-architecture.stores.versions' => 'array',
            'cache-architecture.warming.connection' => 'redis',
            'cache-architecture.warming.queue' => 'cache-warm',
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => 'default',
            'seasonvar.queue.connection' => 'redis',
            'seasonvar.queue.queue' => 'seasonvar-import',
            'seasonvar.queue.busy_threshold' => 5_000,
            'seasonvar.title_refresh.queue' => 'seasonvar-title-refresh',
        ]);

        $queue = $this->createMock(QueueContract::class);
        $queue->method('pendingSize')->willReturnCallback(
            fn (string $name): int => match ($name) {
                'cache-warm' => 4,
                'seasonvar-import' => 8,
                default => 0,
            },
        );
        $queue->method('delayedSize')->willReturn(0);
        $queue->method('reservedSize')->willReturn(0);
        $queue->method('creationTimeOfOldestPendingJob')->willReturn(now()->subMinute()->getTimestamp());

        $manager = $this->createMock(QueueManager::class);
        $manager->method('connection')->with('redis')->willReturn($queue);
        $this->app->instance(QueueManager::class, $manager);

        $job = $this->createMock(Job::class);
        $job->method('getQueue')->willReturn('seasonvar-title-refresh');
        $job->method('payload')->willReturn(['createdAt' => now()->getTimestamp()]);

        $heartbeat = app(QueueWorkerHeartbeat::class);
        $heartbeat->processing(new JobProcessing('redis', $job));

        $status = $heartbeat->status();

        $this->assertSame('failed', $status['status']);
        $this->assertSame('idle', $status['queues']['default']['status']);
        $this->assertSame('failed', $status['queues']['cache_warm']['status']);
        $this->assertSame(4, $status['queues']['cache_warm']['pending']);
        $this->assertSame('failed', $status['queues']['seasonvar_import']['status']);
        $this->assertSame(8, $status['queues']['seasonvar_import']['pending']);
        $this->assertSame('ok', $status['queues']['seasonvar_title_refresh']['status']);
        $this->assertArrayHasKey('last_processed_at', $status['queues']['seasonvar_title_refresh']);
    }

    public function test_idle_worker_loop_records_heartbeats_for_each_listened_queue(): void
    {
        config([
            'cache-architecture.stores.domain' => 'array',
            'cache-architecture.stores.versions' => 'array',
            'cache-architecture.warming.connection' => 'redis',
            'cache-architecture.warming.queue' => 'cache-warm',
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => 'default',
            'seasonvar.queue.connection' => 'redis',
            'seasonvar.queue.queue' => 'seasonvar-import',
            'seasonvar.title_refresh.queue' => 'seasonvar-title-refresh',
        ]);

        $queue = $this->createMock(QueueContract::class);
        $queue->method('pendingSize')->willReturn(0);
        $queue->method('delayedSize')->willReturn(0);
        $queue->method('reservedSize')->willReturn(0);
        $queue->method('creationTimeOfOldestPendingJob')->willReturn(null);

        $manager = $this->createMock(QueueManager::class);
        $manager->method('connection')->with('redis')->willReturn($queue);
        $this->app->instance(QueueManager::class, $manager);

        $heartbeat = app(QueueWorkerHeartbeat::class);
        $heartbeat->looping(new Looping('redis', 'critical,cache-warm,default'));

        $status = $heartbeat->status();

        $this->assertSame('ok', $status['status']);
        $this->assertSame('ok', $status['queues']['default']['status']);
        $this->assertSame('ok', $status['queues']['cache_warm']['status']);
        $this->assertSame('idle', $status['queues']['seasonvar_import']['status']);
        $this->assertSame('idle', $status['queues']['seasonvar_title_refresh']['status']);
    }

    public function test_expired_base_pool_heartbeat_does_not_hide_new_backlog(): void
    {
        config([
            'cache-architecture.operations.queue_worker_heartbeat_seconds' => 30,
            'cache-architecture.stores.domain' => 'array',
            'cache-architecture.stores.versions' => 'array',
            'cache-architecture.warming.connection' => 'redis',
            'cache-architecture.warming.queue' => 'cache-warm',
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => 'default',
            'seasonvar.queue.connection' => 'redis',
            'seasonvar.queue.queue' => 'seasonvar-import',
            'seasonvar.title_refresh.queue' => 'seasonvar-title-refresh',
        ]);

        $state = new class
        {
            public int $seasonvarImportPending = 0;
        };
        $queue = $this->createMock(QueueContract::class);
        $queue->method('pendingSize')->willReturnCallback(
            static function (string $name) use ($state): int {
                return $name === 'seasonvar-import' ? $state->seasonvarImportPending : 0;
            },
        );
        $queue->method('delayedSize')->willReturn(0);
        $queue->method('reservedSize')->willReturn(0);
        $queue->method('creationTimeOfOldestPendingJob')->willReturnCallback(
            static function (string $name) use ($state): ?int {
                return $name === 'seasonvar-import' && $state->seasonvarImportPending > 0
                    ? now()->subMinute()->getTimestamp()
                    : null;
            },
        );

        $manager = $this->createMock(QueueManager::class);
        $manager->method('connection')->with('redis')->willReturn($queue);
        $this->app->instance(QueueManager::class, $manager);

        $heartbeat = app(QueueWorkerHeartbeat::class);
        $heartbeat->looping(new Looping('redis', 'seasonvar-import'));

        $this->assertSame('ok', $heartbeat->status()['queues']['seasonvar_import']['status']);

        $this->travel(31)->seconds();
        $state->seasonvarImportPending = 3;
        $status = $heartbeat->status()['queues']['seasonvar_import'];

        $this->assertSame('failed', $status['status']);
        $this->assertSame(3, $status['pending']);
        $this->assertNull($status['last_heartbeat_at']);
    }

    public function test_cache_warm_heartbeat_covers_a_long_job_and_expires_after_bounded_grace(): void
    {
        config([
            'cache-architecture.operations.queue_worker_heartbeat_seconds' => 30,
            'cache-architecture.stores.domain' => 'array',
            'cache-architecture.stores.versions' => 'array',
            'cache-architecture.warming.connection' => 'redis',
            'cache-architecture.warming.queue' => 'cache-warm',
            'cache-architecture.warming.timeout' => 120,
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => 'default',
            'seasonvar.queue.connection' => 'redis',
            'seasonvar.queue.queue' => 'seasonvar-import',
            'seasonvar.title_refresh.queue' => 'seasonvar-title-refresh',
        ]);

        $queue = $this->createMock(QueueContract::class);
        $queue->method('pendingSize')->willReturnCallback(
            static fn (string $name): int => $name === 'cache-warm' ? 3 : 0,
        );
        $queue->method('delayedSize')->willReturn(0);
        $queue->method('reservedSize')->willReturn(0);
        $queue->method('creationTimeOfOldestPendingJob')->willReturnCallback(
            static fn (string $name): ?int => $name === 'cache-warm'
                ? now()->subMinute()->getTimestamp()
                : null,
        );

        $manager = $this->createMock(QueueManager::class);
        $manager->method('connection')->with('redis')->willReturn($queue);
        $this->app->instance(QueueManager::class, $manager);

        $heartbeat = app(QueueWorkerHeartbeat::class);
        $heartbeat->looping(new Looping('redis', 'cache-warm'));

        $this->travel(31)->seconds();
        $activeStatus = $heartbeat->status()['queues']['cache_warm'];

        $this->assertSame('ok', $activeStatus['status']);
        $this->assertNotNull($activeStatus['last_heartbeat_at']);

        $this->travel(150)->seconds();
        $stoppedStatus = $heartbeat->status()['queues']['cache_warm'];

        $this->assertSame('failed', $stoppedStatus['status']);
        $this->assertNull($stoppedStatus['last_heartbeat_at']);
    }

    public function test_same_queue_name_on_another_connection_keeps_the_base_heartbeat_lease(): void
    {
        config([
            'cache-architecture.operations.queue_worker_heartbeat_seconds' => 30,
            'cache-architecture.stores.domain' => 'array',
            'cache-architecture.stores.versions' => 'array',
            'cache-architecture.warming.connection' => 'redis',
            'cache-architecture.warming.queue' => 'cache-warm',
            'cache-architecture.warming.timeout' => 120,
            'queue.default' => 'sync',
            'queue.connections.redis.queue' => 'cache-warm',
            'seasonvar.queue.connection' => 'redis',
            'seasonvar.queue.queue' => 'seasonvar-import',
            'seasonvar.title_refresh.queue' => 'seasonvar-title-refresh',
        ]);

        $state = new class
        {
            public int $pending = 0;
        };
        $queue = $this->createMock(QueueContract::class);
        $queue->method('pendingSize')->willReturnCallback(
            static fn (string $name): int => $name === 'cache-warm' ? $state->pending : 0,
        );
        $queue->method('delayedSize')->willReturn(0);
        $queue->method('reservedSize')->willReturn(0);
        $queue->method('creationTimeOfOldestPendingJob')->willReturnCallback(
            static fn (string $name): ?int => $name === 'cache-warm' && $state->pending > 0
                ? now()->subMinute()->getTimestamp()
                : null,
        );

        $manager = $this->createMock(QueueManager::class);
        $manager->method('connection')->willReturn($queue);
        $this->app->instance(QueueManager::class, $manager);

        $heartbeat = app(QueueWorkerHeartbeat::class);
        $heartbeat->looping(new Looping('sync', 'cache-warm'));

        $this->travel(31)->seconds();
        $state->pending = 3;
        $status = $heartbeat->status()['queues']['default'];

        $this->assertSame('failed', $status['status']);
        $this->assertNull($status['last_heartbeat_at']);
    }

    public function test_cache_warm_heartbeat_never_shortens_a_longer_base_lease(): void
    {
        config([
            'cache-architecture.operations.queue_worker_heartbeat_seconds' => 300,
            'cache-architecture.stores.domain' => 'array',
            'cache-architecture.stores.versions' => 'array',
            'cache-architecture.warming.connection' => 'redis',
            'cache-architecture.warming.queue' => 'cache-warm',
            'cache-architecture.warming.timeout' => 120,
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => 'default',
            'seasonvar.queue.connection' => 'redis',
            'seasonvar.queue.queue' => 'seasonvar-import',
            'seasonvar.title_refresh.queue' => 'seasonvar-title-refresh',
        ]);

        $queue = $this->createMock(QueueContract::class);
        $queue->method('pendingSize')->willReturnCallback(
            static fn (string $name): int => $name === 'cache-warm' ? 3 : 0,
        );
        $queue->method('delayedSize')->willReturn(0);
        $queue->method('reservedSize')->willReturn(0);
        $queue->method('creationTimeOfOldestPendingJob')->willReturnCallback(
            static fn (string $name): ?int => $name === 'cache-warm'
                ? now()->subMinute()->getTimestamp()
                : null,
        );

        $manager = $this->createMock(QueueManager::class);
        $manager->method('connection')->with('redis')->willReturn($queue);
        $this->app->instance(QueueManager::class, $manager);

        $heartbeat = app(QueueWorkerHeartbeat::class);
        $heartbeat->looping(new Looping('redis', 'cache-warm'));

        $this->travel(181)->seconds();
        $this->assertSame('ok', $heartbeat->status()['queues']['cache_warm']['status']);

        $this->travel(120)->seconds();
        $this->assertSame('failed', $heartbeat->status()['queues']['cache_warm']['status']);
    }
}
