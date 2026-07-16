<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\PublicCacheWarmBatch;
use App\Services\Catalog\PublicCatalogWarmStateStore;
use App\Services\Operations\InfrastructureHealthCheck;
use Tests\TestCase;

final class InfrastructureHealthCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache-architecture.warming.connection' => 'sync',
            'queue.default' => 'sync',
            'seasonvar.queue.connection' => 'sync',
        ]);
    }

    public function test_missing_worker_heartbeat_marks_an_otherwise_ready_service_as_degraded(): void
    {
        config([
            'cache-architecture.stores.domain' => 'array',
            'cache-architecture.stores.versions' => 'array',
        ]);

        $result = app(InfrastructureHealthCheck::class)->run();

        $this->assertSame('unknown', $result['components']['queue_workers']['status']);
        $this->assertSame('degraded', $result['status']);
        $this->assertTrue($result['ready']);
    }

    public function test_unavailable_memcached_store_degrades_health_instead_of_crashing_the_check(): void
    {
        config([
            'cache.stores.memcached-hot' => ['driver' => 'unsupported-health-test'],
        ]);

        $result = app(InfrastructureHealthCheck::class)->run();

        $this->assertSame('failed', $result['components']['memcached']['status']);
        $this->assertSame('degraded', $result['status']);
        $this->assertTrue($result['ready']);
    }

    public function test_failed_worker_observation_marks_an_otherwise_ready_service_as_degraded(): void
    {
        config([
            'cache.stores.unavailable-worker-heartbeat' => ['driver' => 'unsupported-health-test'],
            'cache-architecture.stores.domain' => 'unavailable-worker-heartbeat',
        ]);

        $result = app(InfrastructureHealthCheck::class)->run();

        $this->assertSame('failed', $result['components']['queue_workers']['status']);
        $this->assertSame('degraded', $result['status']);
        $this->assertTrue($result['ready']);
    }

    public function test_full_public_cache_warming_has_an_independent_non_blocking_health_state(): void
    {
        $health = app(InfrastructureHealthCheck::class);
        $states = app(PublicCatalogWarmStateStore::class);

        $this->assertSame('idle', $health->run()['components']['full_cache_warming']['status']);

        $state = $states->start(refresh: false, estimated: 12);
        $running = $health->run()['components']['full_cache_warming'];

        $this->assertSame('running', $running['status']);
        $this->assertSame(12, $running['estimated']);
        $this->assertSame(0, $running['attempted']);

        $states->advance(
            $state['generation'],
            new PublicCacheWarmBatch([], null, true),
            ['attempted' => 1, 'succeeded' => 0, 'failed' => 1, 'errors' => []],
        );
        $completed = $health->run()['components']['full_cache_warming'];

        $this->assertSame('degraded', $completed['status']);
        $this->assertSame(1, $completed['failed']);
        $this->assertTrue($health->run()['ready']);
    }
}
